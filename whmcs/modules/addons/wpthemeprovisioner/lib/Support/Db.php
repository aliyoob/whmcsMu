<?php
namespace Wtp\Addon\Support;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * A thin, fully parameter-bound PDO wrapper.
 *
 * Why not Capsule/Eloquent directly: the same repository code has to run inside
 * WHMCS (where Capsule exists) and inside the test suite (where it does not).
 * Taking the PDO handle out of Capsule gives one code path for both, and keeps
 * every query explicit — there is no place in this module where SQL is built by
 * string concatenation with user input.
 */
class Db
{
    /** @var PDO */
    protected $pdo;
    /** @var int */
    protected $transactionDepth = 0;

    public function __construct(PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->pdo = $pdo;
    }

    /**
     * Obtain the PDO handle WHMCS already uses, so we share its connection
     * pool, credentials and transaction context.
     */
    public static function fromWhmcs(): self
    {
        if (!class_exists('\\Illuminate\\Database\\Capsule\\Manager')) {
            throw new RuntimeException('WHMCS database layer is unavailable');
        }
        /** @var PDO $pdo */
        $pdo = \Illuminate\Database\Capsule\Manager::connection()->getPdo();
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function isMysql(): bool
    {
        return $this->driver() === 'mysql';
    }

    /**
     * @param array<string,mixed> $bindings
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<string,mixed> $bindings
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $bindings */
    public function scalar(string $sql, array $bindings = [])
    {
        $row = $this->run($sql, $bindings)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    /**
     * @param array<string,mixed> $data column => value
     * @return int last insert id
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static function (string $c): string {
            return ':' . $c;
        }, $columns);

        $sql = 'INSERT INTO ' . $this->quoteIdentifier($table)
            . ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $this->run($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where column => value (AND-joined equality)
     * @return int affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }
        $sets = [];
        $bind = [];
        foreach ($data as $col => $val) {
            $sets[] = $this->quoteIdentifier($col) . ' = :set_' . $col;
            $bind['set_' . $col] = $val;
        }
        $conds = [];
        foreach ($where as $col => $val) {
            $conds[] = $this->quoteIdentifier($col) . ' = :w_' . $col;
            $bind['w_' . $col] = $val;
        }
        $sql = 'UPDATE ' . $this->quoteIdentifier($table)
            . ' SET ' . implode(', ', $sets)
            . ($conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds));

        return $this->run($sql, $bind)->rowCount();
    }

    /** @param array<string,mixed> $where */
    public function delete(string $table, array $where): int
    {
        $conds = [];
        $bind = [];
        foreach ($where as $col => $val) {
            $conds[] = $this->quoteIdentifier($col) . ' = :w_' . $col;
            $bind['w_' . $col] = $val;
        }
        $sql = 'DELETE FROM ' . $this->quoteIdentifier($table)
            . ($conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds));
        return $this->run($sql, $bind)->rowCount();
    }

    /** @param array<string,mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * Run $callback inside a transaction, with savepoint-based nesting so a
     * repository can safely open one even when the caller already did.
     *
     * @param callable():mixed $callback
     * @return mixed
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT wtp_sp_' . $this->transactionDepth);
        }
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT wtp_sp_' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT wtp_sp_' . $this->transactionDepth);
        }
    }

    /**
     * Row-level lock. On MySQL this is a real `FOR UPDATE`; on SQLite the
     * whole-file write lock taken by the surrounding transaction already
     * provides the same guarantee, so the suffix is simply omitted.
     */
    public function lockingSuffix(): string
    {
        return $this->isMysql() ? ' FOR UPDATE' : '';
    }

    public function tableExists(string $table): bool
    {
        try {
            $this->run('SELECT 1 FROM ' . $this->quoteIdentifier($table) . ' LIMIT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Identifiers never come from user input, but validate anyway. */
    public function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $identifier)) {
            throw new RuntimeException('Refusing to quote unsafe identifier: ' . $identifier);
        }
        return $this->isMysql() ? '`' . $identifier . '`' : '"' . $identifier . '"';
    }

    /** @param array<string,mixed> $bindings */
    protected function run(string $sql, array $bindings = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
            if (is_bool($value)) {
                $stmt->bindValue($param, $value ? 1 : 0, PDO::PARAM_INT);
            } elseif (is_int($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($param, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($param, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        return $stmt;
    }
}
