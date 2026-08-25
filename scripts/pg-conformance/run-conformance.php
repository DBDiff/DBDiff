#!/usr/bin/env php
<?php
/**
 * Postgres Conformance Test Runner for DBDiff
 *
 * Takes the extracted DDL patterns from patterns.json and runs each one
 * through DBDiff to verify it can produce a correct diff.
 *
 * For each pattern:
 *   1. Create db_before with the "before" schema
 *   2. Create db_after  with the "before + alteration" schema
 *   3. Run DBDiff to generate UP migration
 *   4. Apply UP migration to a copy of db_before
 *   5. Compare schemas — if they match, DBDiff handled it correctly
 *
 * Usage:
 *   php run-conformance.php [--host=localhost] [--port=5432]
 *                           [--user=dbdiff] [--pass=rootpass]
 *                           [--category=add_column] [--verbose]
 *                           [--stop-on-failure]
 *
 * Requires: pdo_pgsql, a running PostgreSQL instance
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// ── CLI args ────────────────────────────────────────────────────────────
$opts = getopt('', [
    'host::', 'port::', 'user::', 'pass::',
    'category::', 'verbose', 'stop-on-failure', 'limit::'
]);

$host     = $opts['host'] ?? getenv('DB_HOST_POSTGRES') ?: 'localhost';
$port     = $opts['port'] ?? '5432';
$user     = $opts['user'] ?? 'dbdiff';
$pass     = $opts['pass'] ?? 'rootpass';
$category = $opts['category'] ?? null;
$verbose  = isset($opts['verbose']);
$stopFail = isset($opts['stop-on-failure']);
$limit    = isset($opts['limit']) ? (int)$opts['limit'] : 0;

$defaultDb = getenv('DB_NAME') ?: 'diff1';

// ── Load patterns ───────────────────────────────────────────────────────
$patternsFile = __DIR__ . '/../../tests/pg-conformance/patterns.json';
if (!file_exists($patternsFile)) {
    fwrite(STDERR, "ERROR: $patternsFile not found.\n");
    fwrite(STDERR, "Run extract-patterns.php first.\n");
    exit(1);
}

$patterns = json_decode(file_get_contents($patternsFile), true);

// A second, hand-authored corpus covering the two axes the extractor cannot
// reach: creating objects from nothing (every extracted pattern starts from an
// existing table and ALTERs it), and object kinds other than tables, columns
// and constraints. Both gaps hid real defects.
$objectsFile = __DIR__ . '/../../tests/pg-conformance/patterns-objects.json';
if (file_exists($objectsFile)) {
    $objectPatterns = json_decode(file_get_contents($objectsFile), true) ?: [];
    $patterns = array_merge($patterns, $objectPatterns);
    echo "Loaded " . count($objectPatterns) . " object/create patterns\n";
}
if ($category) {
    $patterns = array_values(array_filter($patterns, fn($p) => $p['category'] === $category));
}
if ($limit > 0) {
    $patterns = array_slice($patterns, 0, $limit);
}

// ── Connect ─────────────────────────────────────────────────────────────
try {
    $admin = new PDO("pgsql:host=$host;port=$port;dbname=$defaultDb", $user, $pass);
    $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to Postgres: {$e->getMessage()}\n");
    exit(1);
}

$pgVersion = $admin->query("SELECT current_setting('server_version')")->fetchColumn();
$pgVersionNum = (int) $admin->query("SELECT current_setting('server_version_num')")->fetchColumn();
$pgMajor = intdiv($pgVersionNum, 10000);

echo "Postgres Conformance Tests for DBDiff\n";
echo "======================================\n";
echo "Host: $host:$port | Postgres $pgVersion (major: $pgMajor)\n";
echo "Patterns: " . count($patterns) . ($category ? " (category: $category)" : '') . "\n";
echo "======================================\n\n";

// ── Helpers ─────────────────────────────────────────────────────────────
function createDb(PDO $admin, string $name): void {
    $admin->exec("DROP DATABASE IF EXISTS $name");
    $admin->exec("CREATE DATABASE $name");
}

function dropDb(PDO $admin, string $name): void {
    // Terminate connections first
    $admin->exec(
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity " .
        "WHERE datname = '$name' AND pid <> pg_backend_pid()"
    );
    $admin->exec("DROP DATABASE IF EXISTS $name");
}

function connectDb(string $host, string $port, string $user, string $pass, string $db): PDO {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function getSchemaFingerprint(PDO $pdo): array {
    // Get columns for all user tables
    $cols = $pdo->query(
        "SELECT table_name, column_name, data_type, is_nullable, column_default,
                character_maximum_length, numeric_precision, numeric_scale
         FROM information_schema.columns
         WHERE table_schema = 'public'
         ORDER BY table_name, ordinal_position"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Get constraints
    $constraints = $pdo->query(
        "SELECT conname, contype, conrelid::regclass::text as table_name,
                pg_get_constraintdef(oid) as definition
         FROM pg_constraint
         WHERE connamespace = 'public'::regnamespace
         ORDER BY conrelid::regclass::text, conname"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Get indexes
    $indexes = $pdo->query(
        "SELECT indexname, tablename, indexdef
         FROM pg_indexes
         WHERE schemaname = 'public'
         ORDER BY tablename, indexname"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Relation kind and partition bound. information_schema cannot express
    // either: a partitioned table and an ordinary one are indistinguishable
    // there, which is how a migration that silently flattened partitioning
    // passed this suite. relkind and relpartbound are the only evidence.
    $relations = $pdo->query(
        "SELECT c.relname, c.relkind::text AS relkind,
                COALESCE(pg_get_expr(c.relpartbound, c.oid), '-') AS partition_bound,
                c.relrowsecurity::text AS rls
         FROM pg_class c
         WHERE c.relnamespace = 'public'::regnamespace
           AND c.relkind IN ('r','p','v','m','S','f')
         ORDER BY c.relname"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Views compare by their stored definition, not the text that created them:
    // PostgreSQL rewrites both sides the same way, so this is stable.
    $views = $pdo->query(
        "SELECT viewname, definition FROM pg_views WHERE schemaname = 'public'
         ORDER BY viewname"
    )->fetchAll(PDO::FETCH_ASSOC);

    // tgparentid = 0 excludes the copies PostgreSQL clones onto partitions;
    // they are not separately declared and re-creating them is an error.
    $triggers = $pdo->query(
        "SELECT t.tgname, c.relname AS table_name, pg_get_triggerdef(t.oid) AS definition
         FROM pg_trigger t
         JOIN pg_class c ON c.oid = t.tgrelid
         WHERE c.relnamespace = 'public'::regnamespace
           AND NOT t.tgisinternal AND t.tgparentid = 0
         ORDER BY c.relname, t.tgname"
    )->fetchAll(PDO::FETCH_ASSOC);

    $routines = $pdo->query(
        "SELECT p.proname, pg_get_function_identity_arguments(p.oid) AS args,
                pg_get_functiondef(p.oid) AS definition
         FROM pg_proc p
         WHERE p.pronamespace = 'public'::regnamespace AND p.prokind IN ('f','p')
         ORDER BY p.proname, args"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Enum labels in sort order, so a reordered or renamed label is drift.
    $types = $pdo->query(
        "SELECT t.typname, t.typtype::text AS typtype,
                COALESCE((SELECT string_agg(e.enumlabel, ',' ORDER BY e.enumsortorder)
                          FROM pg_enum e WHERE e.enumtypid = t.oid), '-') AS labels
         FROM pg_type t
         WHERE t.typnamespace = 'public'::regnamespace AND t.typtype IN ('e','c','d','r')
         ORDER BY t.typname"
    )->fetchAll(PDO::FETCH_ASSOC);

    $policies = $pdo->query(
        "SELECT tablename, policyname, cmd, roles::text AS roles,
                COALESCE(qual,'-') AS qual, COALESCE(with_check,'-') AS with_check
         FROM pg_policies WHERE schemaname = 'public'
         ORDER BY tablename, policyname"
    )->fetchAll(PDO::FETCH_ASSOC);

    $sequences = $pdo->query(
        "SELECT c.relname, s.seqstart::text, s.seqincrement::text,
                s.seqmin::text, s.seqmax::text, s.seqcycle::text
         FROM pg_sequence s
         JOIN pg_class c ON c.oid = s.seqrelid
         WHERE c.relnamespace = 'public'::regnamespace
         ORDER BY c.relname"
    )->fetchAll(PDO::FETCH_ASSOC);

    return [
        'columns' => $cols,
        'constraints' => $constraints,
        'indexes' => $indexes,
        'relations' => $relations,
        'views' => $views,
        'triggers' => $triggers,
        'routines' => $routines,
        'types' => $types,
        'policies' => $policies,
        'sequences' => $sequences,
    ];
}

function schemasMatch(array $a, array $b): bool {
    return $a === $b;
}

// Failures recorded as known, so the suite fails on NEW regressions rather than
// on an existing backlog. An entry that starts passing is reported too, so the
// list cannot quietly rot.
$knownFile = __DIR__ . '/../../tests/pg-conformance/known-failures.json';
$known = [];
if (file_exists($knownFile)) {
    $decoded = json_decode(file_get_contents($knownFile), true);
    $known = array_flip($decoded['known_failures'] ?? []);
}

// ── Run tests ───────────────────────────────────────────────────────────
$results = [
    'pass' => 0,
    'fail_diff' => 0,      // DBDiff couldn't produce a diff
    'fail_apply' => 0,     // Generated SQL couldn't be applied
    'fail_mismatch' => 0,  // Applied SQL didn't match target schema
    'skip' => 0,           // SQL couldn't run on this Postgres version
    'skip_version' => 0,   // Requires newer PG version
    'skip_excluded' => 0,  // Excluded by skip_reason
    'errors' => [],
];

$dbBefore = 'pgconf_before';
$dbAfter  = 'pgconf_after';
$dbTest   = 'pgconf_test';

foreach ($patterns as $i => $pattern) {
    $num = $i + 1;
    $id  = $pattern['id'];
    $cat = $pattern['category'];
    $desc = substr($pattern['description'], 0, 80);

    // Skip patterns excluded by reason
    if (!empty($pattern['skip_reason'])) {
        if ($verbose) echo "  SKIP [$cat] $desc (excluded: {$pattern['skip_reason']})\n";
        $results['skip_excluded']++;
        continue;
    }

    // Skip patterns requiring newer PG version
    if (!empty($pattern['min_pg_version']) && $pgMajor < $pattern['min_pg_version']) {
        if ($verbose) echo "  SKIP [$cat] $desc (requires PG {$pattern['min_pg_version']}+)\n";
        $results['skip_version']++;
        continue;
    }

    // 1. Create before and after databases
    try {
        createDb($admin, $dbBefore);
        createDb($admin, $dbAfter);
        createDb($admin, $dbTest);

        // Execute setup SQL (types, domains, functions) in all databases
        if (!empty($pattern['setup_sql'])) {
            foreach ([$dbBefore, $dbAfter, $dbTest] as $dbName) {
                $pdo = connectDb($host, $port, $user, $pass, $dbName);
                foreach ($pattern['setup_sql'] as $setupStmt) {
                    $pdo->exec($setupStmt);
                }
                unset($pdo);
            }
        }

        $pdoBefore = connectDb($host, $port, $user, $pass, $dbBefore);
        $pdoAfter  = connectDb($host, $port, $user, $pass, $dbAfter);

        // before = CREATE TABLE + cumulative ALTERs
        if (trim($pattern['before_sql']) !== '') $pdoBefore->exec($pattern['before_sql']);

        // after = before + the ALTER under test
        if (trim($pattern['before_sql']) !== '') $pdoAfter->exec($pattern['before_sql']);
        $pdoAfter->exec($pattern['alter_sql']);

        unset($pdoBefore, $pdoAfter);
    } catch (PDOException $e) {
        if ($verbose) {
            echo "  SKIP [$cat] $desc\n";
            echo "        SQL error: {$e->getMessage()}\n";
        }
        $results['skip']++;
        dropDb($admin, $dbBefore);
        dropDb($admin, $dbAfter);
        dropDb($admin, $dbTest);
        continue;
    }

    // 2. Run DBDiff
    $outputFile = tempnam(sys_get_temp_dir(), 'pgconf_');
    $args = [
        '--driver=pgsql',
        "--server1=$user:$pass@$host:$port",
        '--type=schema',
        '--include=up',
        '--nocomments',
        // Conformance exercises DBDiff's SQL generation for DDL patterns
        // (including DROP COLUMN/TABLE), not the destructive-change linter, so
        // allow those the same way the comprehensive test harness does.
        '--allow-destructive',
        "--output=$outputFile",
        "server1.$dbAfter:server1.$dbBefore",
    ];
    $GLOBALS['argv'] = array_merge([''], $args);

    ob_start();
    try {
        $dbdiff = new DBDiff\DBDiff;
        $dbdiff->run();
    } catch (\Throwable $e) {
        // Catch any error from DBDiff
    }
    ob_end_clean();

    $rawDiff = file_exists($outputFile) ? trim(file_get_contents($outputFile)) : '';
    @unlink($outputFile);

    // Strip DBDiff section markers and extract only the UP block
    $diffSQL = '';
    if (!empty($rawDiff)) {
        // Remove comment-style section markers
        $lines = explode("\n", $rawDiff);
        $inUp = false;
        $upLines = [];
        foreach ($lines as $line) {
            if (preg_match('/^#-+\s*UP\s*-+$/', $line)) {
                $inUp = true;
                continue;
            }
            if (preg_match('/^#-+\s*DOWN\s*-+$/', $line)) {
                $inUp = false;
                continue;
            }
            if ($inUp || !preg_match('/^#-+/', $line)) {
                $upLines[] = $line;
            }
        }
        // If no UP/DOWN markers found, use the raw output (--include=up should prevent markers)
        $diffSQL = trim(implode("\n", $inUp || !empty($upLines) ? $upLines : $lines));
    }

    if (empty($diffSQL)) {
        // Check if schemas are actually identical (no diff needed)
        $fpBefore = getSchemaFingerprint(connectDb($host, $port, $user, $pass, $dbBefore));
        $fpAfter  = getSchemaFingerprint(connectDb($host, $port, $user, $pass, $dbAfter));

        if (schemasMatch($fpBefore, $fpAfter)) {
            if ($verbose) echo "  PASS [$cat] $desc (no-op: schemas identical)\n";
            $results['pass']++;
        } else {
            echo "  FAIL [$cat] $desc\n";
            echo "        DBDiff produced empty output but schemas differ\n";
            $results['fail_diff']++;
            $results['errors'][] = [
                'id' => $id, 'category' => $cat, 'phase' => 'diff',
                'error' => 'Empty diff output but schemas differ',
                'alter_sql' => $pattern['alter_sql'],
            ];
            if ($stopFail) break;
        }
        dropDb($admin, $dbBefore);
        dropDb($admin, $dbAfter);
        dropDb($admin, $dbTest);
        continue;
    }

    // 3. Apply the UP migration to a copy of before
    try {
        $pdoTest = connectDb($host, $port, $user, $pass, $dbTest);
        if (trim($pattern['before_sql']) !== '') $pdoTest->exec($pattern['before_sql']);  // Start from before state
        $pdoTest->exec($diffSQL);                // Apply DBDiff's UP migration
        unset($pdoTest);
    } catch (PDOException $e) {
        echo "  FAIL [$cat] $desc\n";
        echo "        Generated SQL failed to apply: {$e->getMessage()}\n";
        if ($verbose) {
            echo "        SQL: $diffSQL\n";
        }
        $results['fail_apply']++;
        $results['errors'][] = [
            'id' => $id, 'category' => $cat, 'phase' => 'apply',
            'error' => $e->getMessage(),
            'diff_sql' => $diffSQL,
            'alter_sql' => $pattern['alter_sql'],
        ];
        if ($stopFail) break;
        dropDb($admin, $dbBefore);
        dropDb($admin, $dbAfter);
        dropDb($admin, $dbTest);
        continue;
    }

    // 4. Compare schemas
    $fpAfter = getSchemaFingerprint(connectDb($host, $port, $user, $pass, $dbAfter));
    $fpTest  = getSchemaFingerprint(connectDb($host, $port, $user, $pass, $dbTest));

    if (schemasMatch($fpAfter, $fpTest)) {
        if ($verbose) echo "  PASS [$cat] $desc\n";
        $results['pass']++;
    } else {
        echo "  FAIL [$cat] $desc\n";
        echo "        Schema mismatch after applying UP migration\n";
        if ($verbose) {
            echo "        ALTER: {$pattern['alter_sql']}\n";
            echo "        DIFF:  $diffSQL\n";
            $diff = array_diff_assoc(
                array_map('json_encode', $fpAfter['columns']),
                array_map('json_encode', $fpTest['columns'])
            );
            if ($diff) {
                echo "        Column differences: " . json_encode(array_keys($diff)) . "\n";
            }
        }
        $results['fail_mismatch']++;
        $results['errors'][] = [
            'id' => $id, 'category' => $cat, 'phase' => 'mismatch',
            'alter_sql' => $pattern['alter_sql'],
            'diff_sql' => $diffSQL,
        ];
        if ($stopFail) break;
    }

    // Cleanup
    dropDb($admin, $dbBefore);
    dropDb($admin, $dbAfter);
    dropDb($admin, $dbTest);
}

// Final cleanup
dropDb($admin, $dbBefore);
dropDb($admin, $dbAfter);
dropDb($admin, $dbTest);

// ── Report ──────────────────────────────────────────────────────────────
echo "\n======================================\n";
echo "Results\n";
echo "======================================\n";
$total = $results['pass'] + $results['fail_diff'] + $results['fail_apply'] + $results['fail_mismatch'];
echo "  Tested:            $total\n";
echo "  Passed:            {$results['pass']}\n";
echo "  Failed (no diff):  {$results['fail_diff']}\n";
echo "  Failed (apply):    {$results['fail_apply']}\n";
echo "  Failed (mismatch): {$results['fail_mismatch']}\n";
echo "  Skipped (SQL err): {$results['skip']}\n";
echo "  Skipped (PG ver):  {$results['skip_version']}\n";
echo "  Excluded:          {$results['skip_excluded']}\n";
echo "======================================\n";

$unexpected = [];
$fixed = [];
foreach ($results['errors'] as $e) {
    if (!isset($known[$e['id']])) $unexpected[] = $e;
}
$failedIds = array_flip(array_column($results['errors'], 'id'));
foreach (array_keys($known) as $id) {
    if (!isset($failedIds[$id])) $fixed[] = $id;
}

if (!empty($fixed)) {
    echo "\n  " . count($fixed) . " known failure(s) now PASS — remove from known-failures.json:\n";
    foreach ($fixed as $id) echo "    $id\n";
}
if (!empty($unexpected)) {
    echo "\n  " . count($unexpected) . " NEW failure(s) not in the baseline:\n";
    foreach ($unexpected as $e) echo "    {$e['id']} [{$e['category']}] {$e['phase']}\n";
}
echo "\n  known failures carried: " . count($known) . "\n";

$totalFail = count($unexpected) + count($fixed);
if ($totalFail > 0) {
    echo "\nFailed patterns:\n";
    foreach ($results['errors'] as $err) {
        echo "  [{$err['category']}] {$err['phase']}: {$err['alter_sql']}\n";
    }
}

// Write detailed report
$reportFile = __DIR__ . '/../../tests/pg-conformance/report.json';
file_put_contents($reportFile, json_encode($results, JSON_PRETTY_PRINT) . "\n");
echo "\nDetailed report: $reportFile\n";

exit($totalFail > 0 ? 1 : 0);
