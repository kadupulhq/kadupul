<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once __DIR__ . '/session_data.php';
require_once __DIR__ . '/session_metadata.php';

/** Ownership markers for one native session database unit. */
final class CactiSessionTransaction
{
    public ?string $probe = null;
    public bool $mutated = false;
    public function __construct(public bool $owned, public string $marker) {}
}

/** Native session persistence, independent of Composer and application objects. */
final class CactiSessionStore
{
    // The shipped sessions.data column is MEDIUMBLOB (bytes, not characters).
    private const MAX_BYTES = 16777215;
    /** @var array<string,array{exists:bool,binding:array{int,?string}}> */
    private array $observed = [];
    /** @var array<string,bool> */
    private array $confirmed = [];
    private ?CactiSessionTransaction $unit = null;
    private bool $opened = false;
    /** @var Closure():int */
    private Closure $clock;

    /**
     * @param Closure():(PDO|false) $selected
     * @param (Closure():int)|null $clock
     */
    public function __construct(private PDO $database, private string $schema, private string $serializer, private Closure $selected, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function open(): bool
    {
        $this->observed = $this->confirmed = [];
        $this->opened = CactiSessionData::inspect('', $this->serializer) !== false && $this->ensureColumns();
        return $this->opened;
    }

    public function ensureColumns(): bool
    {
        return $this->databaseGuard(function (): bool {
            $this->context();
            $this->persistent();
            $columns = $this->columns();
            if (!isset($columns['id'], $columns['access'], $columns['data'])) return false;
            $definitions = [
                'user_id' => 'user_id int unsigned NOT NULL default 0',
                'start_time' => 'start_time timestamp NOT NULL default current_timestamp',
                'transactions' => 'transactions int unsigned NOT NULL default 1',
                'user_agent' => "user_agent VARCHAR(128) NOT NULL default '' AFTER user_id",
            ];
            $missing = array_diff_key($definitions, $columns);
            if ($missing === []) return true;
            if ($this->database->inTransaction()) return false;
            $this->context();
            $this->command('ALTER TABLE sessions ADD COLUMN ' . implode(', ADD COLUMN ', $missing));
            return array_diff_key($definitions, $this->columns()) === [];
        }) === true;
    }

    /** @return array<string,true> */
    private function columns(): array
    {
        $statement = $this->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=?', [$this->schema, 'sessions']);
        if (!$statement->execute()) throw new RuntimeException('Native session columns are unavailable.');
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN, 0);
        if ($statement->errorCode() !== '00000' || !$statement->closeCursor()) throw new RuntimeException('Native session columns are unavailable.');
        $result = [];
        foreach ($columns as $column) {
            if (!is_string($column)) throw new RuntimeException('Invalid native session column.');
            $result[$column] = true;
        }
        return $result;
    }

    public function close(): bool
    {
        $this->opened = false;
        return true;
    }

    public function writeConfirmed(string $id): bool
    {
        try {
            return ($this->selected)() === $this->database && ($this->confirmed[$id] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    public function read(string $id): string|false
    {
        if (!$this->opened || !self::validId($id)) return false;
        $result = $this->operation(function () use ($id): array {
            $row = $this->row($id);
            $data = $row === null ? '' : self::blob($row);
            $metadata = CactiSessionData::inspect($data, $this->serializer);
            if ($metadata === false) throw new RuntimeException('Native session framing is unavailable.');
            if ($row !== null) {
                $this->mutate('UPDATE sessions SET access=GREATEST(COALESCE(access,0),?) WHERE id=? AND BINARY id=BINARY ?', [($this->clock)(), $id, $id]);
            }
            return ['data' => $data, 'snapshot' => ['exists' => $row !== null, 'binding' => $metadata['binding']]];
        });
        if ($result === false) return false;
        $this->observed[$id] = $result['value']['snapshot'];
        return $result['value']['data'];
    }

    public function write(string $id, #[SensitiveParameter] string $data, string $address, string $agent): bool
    {
        $this->confirmed[$id] = false;
        if (strlen($data) > self::MAX_BYTES) return false;
        $display = CactiSessionMetadata::forStorage($address, $agent);
        if ($display === false) return false;
        $address = $display['address'];
        $agent = $display['agent'];
        if (!$this->opened || !self::validId($id) || !isset($this->observed[$id])) return false;
        $metadata = CactiSessionData::inspect($data, $this->serializer);
        if ($metadata === false) return false;
        $snapshot = $this->observed[$id];
        $result = $this->operation(function () use ($id, $data, $address, $agent, $metadata, $snapshot): bool {
            $row = $this->row($id);
            if (($row !== null) !== $snapshot['exists']) throw new RuntimeException('Native session lifecycle changed.');
            $stored = $row === null ? '' : self::blob($row);
            $current = CactiSessionData::inspect($stored, $this->serializer);
            if ($current === false || $current['binding'] !== $snapshot['binding']) throw new RuntimeException('Native session authentication changed.');
            $merged = CactiSessionData::monotonic($stored, $data, $this->serializer);
            if ($merged === false || strlen($merged) > self::MAX_BYTES) throw new RuntimeException('Native session activity is unavailable.');
            if ($row === null && $metadata['user_id'] === 0) return true;
            $now = ($this->clock)();
            $expectedAccess = self::later($row['access'] ?? null, $now);
            if ($row === null) {
                $this->mutate('INSERT INTO sessions (id,remote_addr,access,data,user_id,user_agent) VALUES (?,?,?,?,?,?)', [$id,$address,$now,$merged,$metadata['user_id'],$agent], [3]);
            } else {
                $this->mutate('UPDATE sessions SET data=?,access=GREATEST(COALESCE(access,0),?),user_id=?,user_agent=?,transactions=transactions+1 WHERE id=? AND BINARY id=BINARY ?', [$merged,$now,$metadata['user_id'],$agent,$id,$id], [0]);
            }
            $saved = $this->row($id);
            if ($saved === null || self::blob($saved) !== $merged || (string) $saved['user_id'] !== (string) $metadata['user_id']
                || (string) ($saved['access'] ?? '') !== $expectedAccess || $saved['user_agent'] !== $agent
                || ($row === null && $saved['remote_addr'] !== $address)) throw new RuntimeException('Native session storage was not confirmed.');
            return true;
        });
        if ($result === false) return false;
        $this->confirmed[$id] = $result['committed'];
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->confirmed[$id] = false;
        unset($this->observed[$id]);
        if (!self::validId($id)) return false;
        return $this->operation(function () use ($id): bool {
            $this->row($id);
            $this->mutate('DELETE FROM sessions WHERE id=? AND BINARY id=BINARY ?', [$id,$id]);
            if ($this->row($id) !== null) throw new RuntimeException('Native session deletion was not confirmed.');
            return true;
        }) !== false;
    }

    public function clean(int $maximum): bool
    {
        if ($maximum < 0) return false;
        return $this->operation(function () use ($maximum): bool {
            $this->mutate('DELETE FROM sessions WHERE access < ?', [($this->clock)() - $maximum]);
            return true;
        }) !== false;
    }

    /**
     * @template T
     * @param Closure():T $work
     * @return array{value:T,committed:bool}|false
     */
    private function operation(Closure $work): array|false
    {
        return $this->databaseGuard(function () use ($work): array|false {
            if ($this->unit !== null) return false;
            try {
                $marker = 'cacti_session_' . bin2hex(random_bytes(12));
                $this->context();
                $owned = !$this->database->inTransaction();
                if ($owned && !$this->database->beginTransaction()) return false;
                $this->unit = new CactiSessionTransaction($owned, $marker);
                try {
                    $this->command('SAVEPOINT ' . $marker);
                } catch (Throwable $error) {
                    if (!$owned) throw $error;
                    // Only our new transaction exists here: no work or callback
                    // has run since begin, and SAVEPOINT cannot replace it.
                    // Later cleanup still requires its original marker so it
                    // cannot roll back a replacement caller transaction.
                    try {
                        if ($this->database->inTransaction()) $this->database->rollBack();
                    } catch (Throwable) {
                        // The failed unit remains unconfirmed if cleanup fails.
                    }
                    return false;
                }
                $probe = 'cacti_session_' . bin2hex(random_bytes(12));
                $this->command('SAVEPOINT ' . $probe);
                $this->transaction()->probe = $probe;
                $this->persistent();
                $value = $work();
                $this->context();
                if (!$this->database->inTransaction()) throw new RuntimeException('Native session transaction changed.');
                if ($this->transaction()->probe === null) throw new RuntimeException('Native session ownership probe is unavailable.');
                // Release the later probe, keeping the earlier rollback anchor.
                // Releasing an older savepoint also invalidates later markers.
                $this->command('RELEASE SAVEPOINT ' . $this->transaction()->probe);
                $this->transaction()->probe = null;
                if ($owned) {
                    if (!$this->database->commit() || $this->database->inTransaction()) throw new RuntimeException('Native session commit was not confirmed.');
                } else {
                    $this->command('RELEASE SAVEPOINT ' . $this->transaction()->marker);
                }
                return ['value' => $value, 'committed' => $owned];
            } catch (Throwable) {
                if ($this->unit !== null) {
                    try {
                        if ($this->database->inTransaction()) {
                            $this->command('ROLLBACK TO SAVEPOINT ' . $this->transaction()->marker);
                            if ($this->transaction()->owned) $this->database->rollBack();
                            else $this->command('RELEASE SAVEPOINT ' . $this->transaction()->marker);
                        }
                    } catch (Throwable) {
                        // An unconfirmed outcome stays failed; cleanup cannot
                        // claim ownership of a replacement caller transaction.
                    }
                }
                return false;
            } finally {
                $this->unit = null;
            }
        });
    }

    /**
     * @template T
     * @param Closure():T $work
     * @return T|false
     */
    private function databaseGuard(Closure $work): mixed
    {
        $previous = null;
        $changed = false;
        try {
            $previous = $this->database->getAttribute(PDO::ATTR_ERRMODE);
            if (!is_int($previous)) return false;
            $changed = $previous !== PDO::ERRMODE_EXCEPTION;
            if ($changed && !$this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)) return false;
            try {
                $result = $work();
            } catch (Throwable) {
                $result = false;
            }
            if ($changed && !$this->database->setAttribute(PDO::ATTR_ERRMODE, $previous)) return false;
            $changed = false;
            return $result;
        } catch (Throwable) {
            return false;
        } finally {
            if ($changed && $previous !== null) {
                try {
                    $this->database->setAttribute(PDO::ATTR_ERRMODE, $previous);
                } catch (Throwable) {
                }
            }
        }
    }

    /** Each unit performs at most one mutation, after its locked reads. */
    /**
     * @param list<int|string> $parameters
     * @param list<int> $binary
     */
    private function mutate(string $sql, #[SensitiveParameter] array $parameters, array $binary = []): void
    {
        if ($this->unit === null || $this->transaction()->mutated) throw new RuntimeException('Invalid native session mutation boundary.');
        $statement = $this->prepare($sql, $parameters, $binary);
        $this->context();
        // No mutation precedes this rollback. Existing InnoDB read locks
        // survive it; reacquire/verify table metadata before the sole write.
        $this->command('ROLLBACK TO SAVEPOINT ' . $this->transaction()->marker);
        $this->transaction()->probe = null;
        $this->persistent();
        $probe = 'cacti_session_' . bin2hex(random_bytes(12));
        $this->command('SAVEPOINT ' . $probe);
        $this->transaction()->probe = $probe;
        $this->transaction()->mutated = true;
        if (!$statement->execute() || $statement->errorCode() !== '00000' || !$statement->closeCursor()) throw new RuntimeException('Native session write failed.');
    }

    /** @return array{id:string,data:string|null,user_id:int|string,access:int|string|null,user_agent:string,remote_addr:string}|null */
    private function row(string $id): ?array
    {
        $statement = $this->prepare('SELECT id,data,user_id,access,user_agent,remote_addr FROM sessions WHERE id=? AND BINARY id=BINARY ? FOR UPDATE', [$id,$id]);
        if (!$statement->execute()) throw new RuntimeException('Native session read failed.');
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($statement->errorCode() !== '00000' || !$statement->closeCursor()) throw new RuntimeException('Native session fetch failed.');
        if ($row === false) return null;
        if (($row['id'] ?? null) !== $id) throw new RuntimeException('Native session identity changed.');
        if (!array_key_exists('data', $row) || ($row['data'] !== null && !is_string($row['data']))
            || !array_key_exists('access', $row) || ($row['access'] !== null && !is_int($row['access']) && !is_string($row['access']))
            || !is_string($row['user_agent'] ?? null) || !is_string($row['remote_addr'] ?? null)
            || (!is_int($row['user_id'] ?? null) && !is_string($row['user_id'] ?? null))) throw new RuntimeException('Incomplete native session row.');
        return ['id' => $id, 'data' => $row['data'], 'user_id' => $row['user_id'], 'access' => $row['access'], 'user_agent' => $row['user_agent'], 'remote_addr' => $row['remote_addr']];
    }

    private function persistent(): void
    {
        // SHOW/Information Schema alone do not retain table metadata locks.
        // Pin the actual selected table through the unit, including GC, which
        // has no preceding row SELECT to prevent a concurrent engine change.
        $pin = $this->database->query('SELECT id FROM sessions LIMIT 0 FOR UPDATE');
        if (!$pin instanceof PDOStatement || $pin->errorCode() !== '00000' || !$pin->closeCursor()) throw new RuntimeException('Native session metadata lock is unavailable.');
        $show = $this->database->query('SHOW CREATE TABLE sessions');
        if (!$show instanceof PDOStatement) throw new RuntimeException('Native session table is unavailable.');
        $definition = $show->fetch(PDO::FETCH_NUM);
        if ($show->errorCode() !== '00000' || !$show->closeCursor() || !is_array($definition) || !is_string($definition[1] ?? null)
            || preg_match('/\ACREATE TABLE `?sessions`?\s*\(/i', $definition[1]) !== 1) throw new RuntimeException('Native session table is not persistent.');
        $metadata = $this->prepare('SELECT engine AS engine FROM information_schema.tables WHERE table_schema=? AND table_name=?', [$this->schema,'sessions']);
        if (!$metadata->execute()) throw new RuntimeException('Native session engine is unavailable.');
        $engine = $metadata->fetch(PDO::FETCH_ASSOC);
        if ($metadata->errorCode() !== '00000' || !$metadata->closeCursor() || !is_array($engine) || (!is_string($engine['engine'] ?? null) ? 1 : strcasecmp($engine['engine'], 'InnoDB')) !== 0) throw new RuntimeException('Native session storage is not transactional.');
    }

    private function context(): void
    {
        if (($this->selected)() !== $this->database) throw new RuntimeException('Native session connection changed.');
        $statement = $this->database->query('SELECT DATABASE()');
        if (!$statement instanceof PDOStatement) throw new RuntimeException('Native session schema is unavailable.');
        $schema = $statement->fetchColumn();
        if ($statement->errorCode() !== '00000' || !$statement->closeCursor() || $schema !== $this->schema) throw new RuntimeException('Native session schema changed.');
    }

    /**
     * @param list<int|string> $parameters
     * @param list<int> $binary
     */
    private function prepare(string $sql, #[SensitiveParameter] array $parameters, array $binary = []): PDOStatement
    {
        $statement = $this->database->prepare($sql);
        if (!$statement instanceof PDOStatement) throw new RuntimeException('Native session statement is unavailable.');
        foreach ($parameters as $index => $value) {
            $type = in_array($index, $binary, true) ? PDO::PARAM_LOB : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            if (!$statement->bindValue($index + 1, $value, $type)) throw new RuntimeException('Native session parameter failed.');
        }
        return $statement;
    }

    private function transaction(): CactiSessionTransaction
    {
        if ($this->unit === null) throw new RuntimeException('Native session transaction is unavailable.');
        return $this->unit;
    }

    private function command(string $sql): void
    {
        if ($this->database->exec($sql) === false) throw new RuntimeException('Native session transaction command failed.');
    }

    private static function validId(string $id): bool
    {
        return preg_match('/\A[A-Za-z0-9,-]{1,32}\z/D', $id) === 1;
    }

    /** @param array<string,mixed> $row */
    private static function blob(array $row): string
    {
        $data = $row['data'] ?? '';
        if (!is_string($data)) throw new RuntimeException('Native session blob is unavailable.');
        return $data;
    }

    private static function later(mixed $stored, int $now): string
    {
        if ($now < 0) throw new RuntimeException('Invalid native session clock.');
        if ($stored !== null && !is_int($stored) && !is_string($stored)) throw new RuntimeException('Invalid native session access time.');
        $old = $stored === null ? '0' : (string) $stored;
        if (preg_match('/\A[0-9]+\z/D', $old) !== 1) throw new RuntimeException('Invalid native session access time.');
        $new = (string) $now;
        return strlen($old) > strlen($new) || (strlen($old) === strlen($new) && strcmp($old, $new) > 0) ? $old : $new;
    }
}
