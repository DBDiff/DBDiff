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

/**
 * Structural fingerprint of the public schema.
 *
 * The query comes from @akalforge/pg-conformance so that this harness, the
 * SupaForge convergence proof and its e2e harness all ask the database the
 * same question. Four separate definitions had accumulated between the two
 * projects and drifted apart; one of them called two schemas identical when a
 * view's predicate had been inverted.
 *
 * Returned as one entry per line rather than as a nested structure, so a
 * mismatch can be reported as exactly which objects differ.
 */
// The shared fingerprint is published to npm, which is where the corpus lives
// too. DBDiff is a Composer project, so it is required by path rather than
// autoloaded; `npm ci` in the conformance job puts it here.
$conformancePath = __DIR__ . '/../../node_modules/@akalforge/pg-conformance/src/Conformance.php';
if (!is_file($conformancePath)) {
    fwrite(STDERR, "Missing @akalforge/pg-conformance. Run `npm ci` in the repository root.\n");
    exit(1);
}
require_once $conformancePath;
use Akalforge\PgConformance\Conformance;

function getSchemaFingerprint(PDO $pdo): array {
    $row = $pdo->query(Conformance::fingerprintSql(['public']))->fetch(PDO::FETCH_ASSOC);

    // A fingerprint that came back empty would make every comparison succeed,
    // so an unexpected shape is a fault rather than "no differences".
    if (!is_array($row) || !array_key_exists('fingerprint', $row)) {
        throw new RuntimeException('fingerprint query returned no "fingerprint" column');
    }

    $text = (string) ($row['fingerprint'] ?? '');

    return $text === '' ? [] : explode("\n", $text);
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
            // Every differing entry, not just columns: the previous report
            // looked only at $fp['columns'], so a mismatch in a view body, a
            // trigger or a partition bound showed up as a bare "schema
            // mismatch" with nothing to point at.
            foreach (array_slice(array_diff($fpAfter, $fpTest), 0, 5) as $line) {
                echo "        expected: $line\n";
            }
            foreach (array_slice(array_diff($fpTest, $fpAfter), 0, 5) as $line) {
                echo "        actual:   $line\n";
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

// A baselined case passing is reported but not fatal. The pattern set is
// extracted against the live server, so it differs by version — 235 patterns
// on PostgreSQL 16 against 270 on 18 — and a case that fails on one version
// can legitimately pass on another. Failing the run for that would mean no
// single baseline could ever satisfy the whole matrix.
if (!empty($fixed)) {
    echo "\n  " . count($fixed) . " baselined case(s) pass on this server:\n";
    foreach ($fixed as $id) echo "    $id\n";
    echo "  (informational: remove from known-failures.json once they pass on every version)\n";
}
if (!empty($unexpected)) {
    echo "\n  " . count($unexpected) . " NEW failure(s) not in the baseline:\n";
    foreach ($unexpected as $e) echo "    {$e['id']} [{$e['category']}] {$e['phase']}\n";
}
echo "\n  known failures carried: " . count($known) . "\n";

// Only a failure absent from the baseline fails the run: that is a genuine
// regression. See above for why a baselined case passing is not.
$totalFail = count($unexpected);
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
