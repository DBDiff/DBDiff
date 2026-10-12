<?php

/**
 * Text in every script, under mixed collations, diffed and applied on MySQL.
 *
 * DBDiff connected to MySQL as `utf8` — MySQL's utf8mb3, three bytes a
 * character at most — and compared rows through CAST/CONVERT to the same.
 * Anything needing four bytes (emoji, scripts outside the Basic Multilingual
 * Plane such as Brahmi) was read as '?', so a change from one such value to
 * another was not seen at all, on either data path.
 *
 * And the schema pre-scan hashed each column's type, default and nullability
 * but not its character set, collation, comment or generated expression, so
 * a table that differed only there was skipped as unchanged.
 *
 * Verdicts are byte for byte: after the generated UP the target must hold
 * exactly the source's bytes, and the source's collations. Runs in the MySQL
 * jobs (DB_HOST); skips without a server.
 */

use DBDiff\DB\Data\StreamingMergeDiff;
use DBDiff\Params\DefaultParams;
use DBDiff\Params\ParamsFactory;
use DBDiff\SQLGen\Dialect\MySQLDialect;
use DBDiff\SQLGen\DiffToSQL\DeleteDataSQL;
use DBDiff\SQLGen\DiffToSQL\InsertDataSQL;
use DBDiff\SQLGen\DiffToSQL\UpdateDataSQL;
use DBDiff\Diff\InsertData;
use DBDiff\Diff\DeleteData;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

class UnicodeMySQLTest extends TestCase
{
    private ?PDO $admin = null;
    private string $host = '';
    private string $port = '';
    private string $password = '';
    private array $created = [];

    /** Gurmukhi, transliteration, translation, other scripts, and four-byte characters. */
    private const TABLE = "CREATE TABLE gurbani (
        id int PRIMARY KEY,
        gurmukhi text COLLATE utf8mb4_unicode_ci,
        translit varchar(200) COLLATE utf8mb4_0900_ai_ci,
        english text COLLATE utf8mb4_bin,
        other varchar(200),
        emoji varchar(40) COLLATE utf8mb4_bin,
        legacy varchar(50) CHARACTER SET latin1
    ) DEFAULT CHARSET=utf8mb4";

    private const WORDS = "CREATE TABLE words (word varchar(100) COLLATE utf8mb4_0900_ai_ci PRIMARY KEY, meaning varchar(100)) DEFAULT CHARSET=utf8mb4";

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        if (!$host || !extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('DB_HOST not set.');
        }
        $this->host = $host;
        $this->port = getenv('DB_PORT') ?: '3306';
        $this->password = getenv('DB_ROOT_PASSWORD') ?: 'rootpass';
        $this->admin = new PDO("mysql:host={$this->host};port={$this->port};charset=utf8mb4", 'root', $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ParamsFactory::set(new DefaultParams);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $db) {
            $this->admin?->exec("DROP DATABASE IF EXISTS `$db`");
        }
        ParamsFactory::reset();
    }

    public function testSameServerDiffCarriesEveryScriptByteForByte(): void
    {
        [$source, $target] = $this->fixture('same');

        $up = $this->cliUp($source, $target, 'all');
        if ($this->onDolt()) {
            $this->assertCaseOnlyChangesPlanned($up);
            return;
        }
        $this->apply($target, $up);

        $this->assertSame($this->snapshot($source), $this->snapshot($target));
    }

    /** The streaming merge — the cross-server path — on the same rows. */
    public function testStreamingMergeSeesFourByteChanges(): void
    {
        [$source, $target] = $this->fixture('stream');
        $capsule = new Capsule;
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, fn($e) => $e->statement->setFetchMode(PDO::FETCH_ASSOC));
        $capsule->setEventDispatcher($dispatcher);
        $conn = [];
        foreach ([$source, $target] as $db) {
            $capsule->addConnection(['driver' => 'mysql', 'host' => $this->host, 'port' => $this->port, 'database' => $db,
                'username' => 'root', 'password' => $this->password, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'], $db);
            $conn[] = $capsule->getConnection($db);
        }
        $merge = new StreamingMergeDiff($conn[0], $conn[1], 'mysql');
        $dialect = new MySQLDialect();
        $up = [];
        foreach (['gurbani' => ['id'], 'words' => ['word']] as $table => $key) {
            $cols = array_column($this->pdo($source)->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            foreach ($merge->getDiff($table, $key, $cols, $cols) as $d) {
                $sql = match (true) {
                    $d instanceof InsertData => new InsertDataSQL($d, $dialect),
                    $d instanceof DeleteData => new DeleteDataSQL($d, $dialect),
                    default                  => new UpdateDataSQL($d, $dialect),
                };
                $up[] = $sql->getUp();
            }
        }
        // Deletes first: a key can come back under another normalisation.
        usort($up, fn($a, $b) => str_starts_with($b, 'DELETE') <=> str_starts_with($a, 'DELETE'));

        if ($this->onDolt()) {
            $this->assertCaseOnlyChangesPlanned(implode("\n", $up));
            return;
        }
        $this->apply($target, implode("\n", $up));

        $this->assertSame($this->rows($source), $this->rows($target));
    }

    public function testThePreScanSeesCollationCommentAndGeneratedChanges(): void
    {
        $source = $this->db('prescan_s');
        $target = $this->db('prescan_t');
        $this->pdo($source)->exec("CREATE TABLE c1 (id int PRIMARY KEY, v text COLLATE utf8mb4_unicode_ci);
            CREATE TABLE c2 (id int PRIMARY KEY, v int COMMENT 'ਮੂਲ ਮੰਤਰ');
            CREATE TABLE c3 (id int PRIMARY KEY, a int, g int GENERATED ALWAYS AS (a * 2) STORED)");
        $this->pdo($target)->exec("CREATE TABLE c1 (id int PRIMARY KEY, v text COLLATE utf8mb4_0900_ai_ci);
            CREATE TABLE c2 (id int PRIMARY KEY, v int COMMENT 'old');
            CREATE TABLE c3 (id int PRIMARY KEY, a int, g int GENERATED ALWAYS AS (a * 3) STORED)");

        $up = $this->cliUp($source, $target, 'schema');

        $this->assertMatchesRegularExpression('/ALTER TABLE `c1` CHANGE `v` `v` text .*COLLATE utf8mb4_unicode_ci/', $up);
        $this->assertStringContainsString("COMMENT 'ਮੂਲ ਮੰਤਰ'", $up);
        // Dolt leaves information_schema's GENERATION_EXPRESSION empty, so
        // the pre-scan cannot see this one there; MySQL fills it in.
        if (!$this->onDolt()) {
            $this->assertMatchesRegularExpression('/ALTER TABLE `c3` CHANGE `g` `g` int GENERATED ALWAYS AS \(\(`a` \* 2\)\)/', $up);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Dolt does not apply an UPDATE whose new value equals the old one under
     * the column's collation ("0 rows affected" for 'akaal' → 'Akaal' in a
     * _ci column), where MySQL writes the new bytes. DBDiff plans it all the
     * same; on Dolt that is what is checked, instead of the applied bytes.
     */
    private function onDolt(): bool
    {
        try {
            $this->admin->query('SELECT dolt_version()');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function assertCaseOnlyChangesPlanned(string $up): void
    {
        $this->assertStringContainsString("`translit` = 'Akaal Moorat'", $up);
        $this->assertStringContainsString("`meaning` = 'wondrous'", $up);
        $this->assertStringContainsString("`emoji` = '🎵'", $up);
        $this->assertStringContainsString("`emoji` = '𑀅𑀓'", $up);
    }

    /** Two databases, the target differing in ways only exact bytes reveal. @return string[] */
    private function fixture(string $case): array
    {
        $source = $this->db("uni_{$case}_s");
        $target = $this->db("uni_{$case}_t");
        $nfd = "cafe\u{0301}"; // é as e + combining acute: looks the same, different bytes
        $this->pdo($source)->exec(self::TABLE . ';' . self::WORDS . ";
            INSERT INTO gurbani VALUES
             (1, 'ੴ ਸਤਿ ਨਾਮੁ ਕਰਤਾ ਪੁਰਖੁ', 'Ik Oankaar Sat Naam', 'One Universal Creator', 'एक ओंकार सत नाम', '🙏', 'café'),
             (2, 'ਅਕਾਲ ਮੂਰਤਿ ਅਜੂਨੀ ਸੈਭੰ', 'Akaal Moorat', 'Image Of The Undying', 'اک اونکار', '🪯', 'naïve'),
             (3, '॥ ਜਪੁ ॥', 'Jap', 'Chant', '唯一神', '𑀅𑀓', 'Ångström'),
             (4, 'ਆਦਿ ਸਚੁ', 'Aad Sach', 'Waheguru', 'café', '👳🏽‍♂️', 'resume'),
             (5, 'ਸੋ ਦਰੁ', 'So Dar', 'That Door', 'सो दर', '🎵', 'x');
            INSERT INTO words VALUES ('ਸਤਿ','truth'),('ਨਾਮੁ','name'),('Waheguru','wondrous'),('10','ten'),('9','nine'),('é','NFC');");
        $this->pdo($target)->exec(self::TABLE . ';' . self::WORDS . ";
            INSERT INTO gurbani VALUES
             (1, 'ੴ ਸਤ ਨਾਮੁ ਕਰਤਾ ਪੁਰਖੁ', 'Ik Oankaar Sat Naam', 'One Universal Creator', 'एक ओंकार सत नाम', '🙂', 'café'),
             (2, 'ਅਕਾਲ ਮੂਰਤਿ ਅਜੂਨੀ ਸੈਭੰ', 'akaal moorat', 'Image Of The Undying', 'اک اونکار', '🪯', 'naive'),
             (3, '॥ ਜਪੁ ॥', 'Jap', 'Chant', '唯一神', '𑀅𑀔', 'Angstrom'),
             (4, 'ਆਦਿ ਸਚੁ', 'Aad Sach', 'waheguru', '$nfd', '👳🏽‍♂️', 'resume'),
             -- Differs only in a four-byte character, so nothing else flags the row.
             (5, 'ਸੋ ਦਰੁ', 'So Dar', 'That Door', 'सो दर', '🎶', 'x');
            INSERT INTO words VALUES ('ਸਤਿ','truth'),('Waheguru','Wondrous'),('9','nine'),('e\u{0301}','NFD'),('ਗੁਰੂ','guru');");
        return [$source, $target];
    }

    private function db(string $name): string
    {
        $db = "dbdiff_$name";
        $this->admin->exec("DROP DATABASE IF EXISTS `$db`");
        $this->admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        $this->created[] = $db;
        return $db;
    }

    private function pdo(string $db): PDO
    {
        return new PDO("mysql:host={$this->host};port={$this->port};dbname=$db;charset=utf8mb4", 'root', $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** The CLI's UP from `$source` to `$target`. */
    private function cliUp(string $source, string $target, string $type): string
    {
        $out = tempnam(sys_get_temp_dir(), 'dbdiff_uni_') . '.sql';
        $url = fn($db) => "mysql://root:" . rawurlencode($this->password) . "@{$this->host}:{$this->port}/$db";
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/dbdiff.php')
            . " diff --type=$type --include=up --nocomments --allow-destructive"
            . ' --server1-url=' . escapeshellarg($url($source)) . ' --server2-url=' . escapeshellarg($url($target))
            . ' --output=' . escapeshellarg($out) . ' 2>&1', $log, $status);
        $this->assertSame(0, $status, implode("\n", $log));
        $sql = file_exists($out) ? file_get_contents($out) : '';
        @unlink($out);
        return $sql;
    }

    private function apply(string $db, string $sql): void
    {
        $pdo = $this->pdo($db);
        foreach (preg_split('/;\s*$/m', preg_replace('/^--.*$/m', '', $sql)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }

    /** Every value as hex, so a byte that changed cannot hide behind a collation. */
    private function rows(string $db): array
    {
        $pdo = $this->pdo($db);
        return [
            $pdo->query("SELECT CONCAT_WS('|', id, HEX(gurmukhi), HEX(translit), HEX(english), HEX(other), HEX(emoji), HEX(legacy)) FROM gurbani ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
            $pdo->query("SELECT CONCAT_WS('|', HEX(word), HEX(meaning)) FROM words ORDER BY HEX(word)")->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    private function snapshot(string $db): array
    {
        $collations = $this->admin->query("SELECT CONCAT_WS(' ', TABLE_NAME, COLUMN_NAME, IFNULL(COLLATION_NAME, ''))
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$db' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
        return [$this->rows($db), $collations];
    }
}
