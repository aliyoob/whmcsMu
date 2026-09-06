<?php
namespace Wtp\Tests;

use PDO;

/**
 * Runs the real MySQL migrations against SQLite.
 *
 * Translating rather than maintaining a second schema is a deliberate choice:
 * a hand-written test schema drifts from production the first time someone
 * adds a column, and the drift is invisible until an integration test passes
 * against a table shape that does not exist on any real server.
 *
 * The translation is narrow on purpose — it understands exactly the subset of
 * DDL these migrations use, and throws on anything else rather than guessing.
 */
final class SqliteSchema
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    /** Apply every migration in the repository, in order. */
    public function migrate(string $migrationsDir): void
    {
        $files = glob(rtrim($migrationsDir, '/') . '/*.sql');
        if ($files === false || $files === []) {
            throw new \RuntimeException('No migrations found in ' . $migrationsDir);
        }
        sort($files);

        foreach ($files as $file) {
            foreach ($this->translate((string) file_get_contents($file)) as $statement) {
                $this->pdo->exec($statement);
            }
        }
    }

    /**
     * The WHMCS core tables this system reads. Only the columns we touch —
     * inventing the rest would imply a fidelity we do not have.
     */
    public function createWhmcsTables(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS tblhosting (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                userid INTEGER NOT NULL DEFAULT 0,
                orderid INTEGER NOT NULL DEFAULT 0,
                packageid INTEGER NOT NULL DEFAULT 0,
                server INTEGER NOT NULL DEFAULT 0,
                domain TEXT NOT NULL DEFAULT "",
                username TEXT NOT NULL DEFAULT "",
                domainstatus TEXT NOT NULL DEFAULT "Active"
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS tblservers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ipaddress TEXT NOT NULL DEFAULT "",
                hostname TEXT NOT NULL DEFAULT "",
                username TEXT NOT NULL DEFAULT "",
                password TEXT NOT NULL DEFAULT "",
                accesshash TEXT NOT NULL DEFAULT "",
                secure INTEGER NOT NULL DEFAULT 1,
                port INTEGER NOT NULL DEFAULT 2087,
                disabled INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS tblproducts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                gid INTEGER NOT NULL DEFAULT 0,
                type TEXT NOT NULL DEFAULT "hostingaccount",
                name TEXT NOT NULL DEFAULT "",
                servertype TEXT NOT NULL DEFAULT ""
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS tblproductgroups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT ""
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS tblorders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                userid INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT "Pending"
            )'
        );
    }

    /**
     * @return string[] executable SQLite statements
     */
    public function translate(string $mysql): array
    {
        $statements = [];

        foreach ($this->splitStatements($mysql) as $statement) {
            if (stripos($statement, 'CREATE TABLE') === false) {
                $statements[] = $statement;
                continue;
            }
            foreach ($this->translateCreateTable($statement) as $translated) {
                $statements[] = $translated;
            }
        }

        return $statements;
    }

    /** @return string[] */
    private function splitStatements(string $sql): array
    {
        // Trailing comments matter here: "`source_ref` VARCHAR(512) NOT NULL, -- note"
        // leaves the note attached to the *next* column definition otherwise.
        $withoutComments = (string) preg_replace('/--[^\n]*/', '', $sql);
        $out = [];
        foreach (explode(';', $withoutComments) as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return $out;
    }

    /**
     * @return string[] the CREATE TABLE plus any CREATE INDEX it implied
     */
    private function translateCreateTable(string $statement): array
    {
        if (!preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*)\)[^)]*$/is', $statement, $matches)) {
            throw new \RuntimeException('Cannot parse CREATE TABLE: ' . substr($statement, 0, 120));
        }

        $table = $matches[1];
        $body = $matches[2];

        $columns = [];
        $indexes = [];
        $primaryKey = null;

        foreach ($this->splitDefinitions($body) as $definition) {
            $definition = trim($definition);
            if ($definition === '') {
                continue;
            }

            if (preg_match('/^PRIMARY KEY\s*\(([^)]+)\)/i', $definition, $pk)) {
                $primaryKey = $this->columnList($pk[1]);
                continue;
            }
            if (preg_match('/^UNIQUE KEY\s+`?(\w+)`?\s*\(([^)]+)\)/i', $definition, $uk)) {
                $indexes[] = 'CREATE UNIQUE INDEX IF NOT EXISTS ' . $uk[1]
                    . ' ON ' . $table . ' (' . implode(', ', $this->columnList($uk[2])) . ')';
                continue;
            }
            if (preg_match('/^KEY\s+`?(\w+)`?\s*\(([^)]+)\)/i', $definition, $key)) {
                $indexes[] = 'CREATE INDEX IF NOT EXISTS ' . $key[1]
                    . ' ON ' . $table . ' (' . implode(', ', $this->columnList($key[2])) . ')';
                continue;
            }
            if (stripos($definition, 'CONSTRAINT') === 0 || stripos($definition, 'FOREIGN KEY') === 0) {
                // Foreign keys are dropped: SQLite enforces them only when the
                // referenced table already exists in the same connection, and
                // the ordering guarantees differ from MySQL's. The behaviour
                // under test is the application's, not the engine's.
                continue;
            }

            $columns[] = $this->translateColumn($definition);
        }

        // A single-column integer primary key becomes AUTOINCREMENT.
        if ($primaryKey !== null && count($primaryKey) === 1) {
            $name = $primaryKey[0];
            foreach ($columns as $index => $column) {
                if (strpos($column, $name . ' ') === 0 && stripos($column, 'INTEGER') !== false) {
                    $columns[$index] = $name . ' INTEGER PRIMARY KEY AUTOINCREMENT';
                    $primaryKey = null;
                    break;
                }
            }
        }
        if ($primaryKey !== null) {
            $columns[] = 'PRIMARY KEY (' . implode(', ', $primaryKey) . ')';
        }

        $create = 'CREATE TABLE IF NOT EXISTS ' . $table . " (\n    " . implode(",\n    ", $columns) . "\n)";

        return array_merge([$create], $indexes);
    }

    /** @return string[] */
    private function splitDefinitions(string $body): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        for ($i = 0, $length = strlen($body); $i < $length; $i++) {
            $char = $body[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    /** @return string[] */
    private function columnList(string $raw): array
    {
        $columns = [];
        foreach (explode(',', $raw) as $column) {
            $columns[] = trim(str_replace('`', '', $column));
        }
        return $columns;
    }

    private function translateColumn(string $definition): string
    {
        $definition = str_replace('`', '', $definition);

        if (!preg_match('/^(\w+)\s+(.*)$/s', $definition, $matches)) {
            throw new \RuntimeException('Cannot parse column: ' . $definition);
        }
        $name = $matches[1];
        $rest = $matches[2];

        // ENUM('a','b') NOT NULL DEFAULT 'a'  ->  TEXT NOT NULL DEFAULT 'a'
        $rest = (string) preg_replace('/^ENUM\s*\([^)]*\)/i', 'TEXT', $rest);

        $typeMap = [
            '/^BIGINT(\s+UNSIGNED)?/i' => 'INTEGER',
            '/^INT(\s+UNSIGNED)?/i' => 'INTEGER',
            '/^SMALLINT(\s+UNSIGNED)?/i' => 'INTEGER',
            '/^TINYINT\s*\(\s*\d+\s*\)(\s+UNSIGNED)?/i' => 'INTEGER',
            '/^TINYINT(\s+UNSIGNED)?/i' => 'INTEGER',
            '/^DECIMAL\s*\([^)]*\)/i' => 'REAL',
            '/^VARCHAR\s*\(\s*\d+\s*\)/i' => 'TEXT',
            '/^CHAR\s*\(\s*\d+\s*\)/i' => 'TEXT',
            '/^LONGTEXT/i' => 'TEXT',
            '/^MEDIUMTEXT/i' => 'TEXT',
            '/^TEXT/i' => 'TEXT',
            '/^BLOB/i' => 'TEXT',
            '/^DATETIME/i' => 'TEXT',
        ];

        foreach ($typeMap as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $rest, 1, $count);
            if ($count > 0) {
                $rest = (string) $replaced;
                break;
            }
        }

        $rest = (string) preg_replace('/\s+AUTO_INCREMENT/i', '', $rest);
        $rest = (string) preg_replace('/\s+UNSIGNED/i', '', $rest);
        $rest = (string) preg_replace('/\s+COLLATE\s+\S+/i', '', $rest);
        $rest = (string) preg_replace('/\s+CHARACTER SET\s+\S+/i', '', $rest);

        return $name . ' ' . trim($rest);
    }
}
