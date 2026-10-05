<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\LegacyReferenceWriteTransaction;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/reference_write.php';
require_once dirname(__DIR__, 2) . '/src/Platform/Contract/ReferenceWriteTransactionRunner.php';
require_once dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/NativeReferenceWriteTransactionRunner.php';

final class LegacyReferenceWriteTransactionNativeTest extends TestCase
{
    private \PDO $database;
    private string $schema;
    private bool $created = false;
    /** @var array<string, array{bool, mixed}> */
    private array $previousGlobals = [];

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
            self::markTestSkipped('An explicitly configured disposable MySQL/MariaDB server is required.');
        }
        $this->database = new \PDO(
            $dsn,
            getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'),
            getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $this->schema = 'kadupul_reference_unit_' . bin2hex(random_bytes(8));
        foreach (['database_sessions', 'database_hostname', 'database_port', 'database_default', 'config'] as $key) {
            $this->previousGlobals[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
        }
        try {
            if ($this->database->exec("CREATE DATABASE `{$this->schema}`") === false) {
                throw new \RuntimeException('Native fixture schema creation could not be confirmed.');
            }
            $this->created = true;
            if ($this->database->exec("USE `{$this->schema}`") === false
                || $this->database->exec('CREATE TABLE reference_rows (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB') === false) {
                throw new \RuntimeException('Native fixture setup could not be confirmed.');
            }
            $GLOBALS['database_hostname'] = 'owned-native';
            $GLOBALS['database_port'] = 0;
            $GLOBALS['database_default'] = $this->schema;
            $GLOBALS['database_sessions'] = ['owned-native:0:' . $this->schema => $this->database];
            $GLOBALS['config'] = ['poller_id' => 2];
        } catch (\Throwable $error) {
            try {
                $this->cleanupOwnedSchema();
            } catch (\Throwable) {
                throw new \RuntimeException('Native fixture setup and cleanup could not be confirmed.', 0, $error);
            } finally {
                $this->restoreGlobals();
            }
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupOwnedSchema();
        } finally {
            $this->restoreGlobals();
        }
    }

    private function cleanupOwnedSchema(): void
    {
        if (!isset($this->database)) {
            return;
        }
        $rollbackError = null;
        try {
            if ($this->database->inTransaction()
                && (!$this->database->rollBack() || $this->database->inTransaction())) {
                throw new \RuntimeException('Native fixture rollback could not be confirmed.');
            }
        } catch (\Throwable $error) {
            $rollbackError = $error;
        }
        if ($this->created) {
            if ($this->database->exec("DROP DATABASE `{$this->schema}`") === false
                || $this->database->errorCode() !== '00000') {
                throw new \RuntimeException('Native fixture cleanup could not be confirmed.', 0, $rollbackError);
            }
            $this->created = false;
        }
        if ($rollbackError !== null) {
            throw $rollbackError;
        }
    }

    private function restoreGlobals(): void
    {
        foreach ($this->previousGlobals as $key => [$existed, $value]) {
            if ($existed) {
                $GLOBALS[$key] = $value;
            } else {
                unset($GLOBALS[$key]);
            }
        }
    }

    public function testOwnedCommitFalseRollbackSilentFailureAndUnchangedWrite(): void
    {
        self::assertTrue(\reference_write_atomic(fn() => $this->database->exec('INSERT INTO reference_rows VALUES (1,10)') !== false, ['reference_rows']));
        self::assertFalse($this->database->inTransaction());
        self::assertSame(10, $this->value());
        self::assertSame(2, $GLOBALS['config']['poller_id']);
        $failure = function (): bool {
            $this->database->exec('UPDATE reference_rows SET value=20 WHERE id=1');
            return false;
        };
        self::assertFalse(\reference_write_atomic($failure, ['reference_rows']));
        self::assertFalse($this->database->inTransaction());
        self::assertSame(10, $this->value());
        $this->database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        self::assertFalse(\reference_write_atomic(function (): bool {
            $this->database->exec('UPDATE reference_rows SET value=30 WHERE id=1');
            return $this->database->exec('INSERT INTO reference_rows VALUES (1,40)') !== false;
        }, ['reference_rows']));
        self::assertFalse($this->database->inTransaction());
        self::assertSame(10, $this->value());
        self::assertTrue(\reference_write_atomic(fn() => $this->database->exec('UPDATE reference_rows SET value=10 WHERE id=1') !== false, ['reference_rows']));
    }

    public function testSavepointsPreserveCallerWorkAndCallerControlsCommit(): void
    {
        $this->database->exec('INSERT INTO reference_rows VALUES (1,10)');
        self::assertTrue($this->database->beginTransaction());
        $this->database->exec('INSERT INTO reference_rows VALUES (2,70)');
        self::assertFalse(\reference_write_atomic(function (): bool {
            $this->database->exec('UPDATE reference_rows SET value=20 WHERE id=1');
            return false;
        }, ['reference_rows']));
        self::assertTrue($this->database->inTransaction());
        self::assertSame(10, $this->value());
        self::assertSame(70, $this->value(2));
        self::assertFalse(\reference_write_atomic(function (): void {
            $this->database->exec('UPDATE reference_rows SET value=30 WHERE id=1');
            $this->database->exec('INSERT INTO reference_rows VALUES (1,40)');
        }, ['reference_rows']));
        self::assertTrue($this->database->inTransaction());
        self::assertSame(10, $this->value());
        self::assertSame(70, $this->value(2));
        self::assertTrue(\reference_write_atomic(fn() => $this->database->exec('UPDATE reference_rows SET value=50 WHERE id=1') !== false, ['reference_rows']));
        self::assertTrue($this->database->inTransaction());
        self::assertSame(50, $this->value());
        self::assertTrue($this->database->rollBack());
        self::assertSame(10, $this->value());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM reference_rows WHERE id=2')->fetchColumn());
    }

    public function testOriginalNativeExceptionIsPreservedAfterOwnedRollback(): void
    {
        $this->database->exec('INSERT INTO reference_rows VALUES (1,10)');
        $original = null;
        try {
            (new LegacyReferenceWriteTransaction($this->database))->run(function () use (&$original): void {
                $this->database->exec('UPDATE reference_rows SET value=20 WHERE id=1');
                try {
                    $this->database->exec('INSERT INTO reference_rows VALUES (1,30)');
                } catch (\PDOException $error) {
                    $original = $error;
                    throw $error;
                }
            }, ['reference_rows']);
            self::fail('The native duplicate-key failure must propagate.');
        } catch (\PDOException $error) {
            self::assertSame($original, $error);
            self::assertSame('23000', $error->errorInfo[0]);
            self::assertSame(1062, $error->errorInfo[1]);
        }
        self::assertFalse($this->database->inTransaction());
        self::assertSame(10, $this->value());
    }

    public function testUnsafeParticipantsFailBeforeCallbackAndPreserveCaller(): void
    {
        $this->database->exec("CREATE TABLE misleading_rows (id INT) ENGINE=MyISAM COMMENT='ENGINE=InnoDB'");
        $this->database->exec('CREATE VIEW reference_view AS SELECT * FROM reference_rows');
        foreach (['misleading_rows', 'reference_view', 'missing_rows', 'reference_rows;DROP'] as $participant) {
            self::assertTrue($this->database->beginTransaction());
            $this->database->exec('INSERT INTO reference_rows VALUES (2,70)');
            $called = false;
            self::assertFalse(\reference_write_atomic(static function () use (&$called): bool {
                $called = true;
                return true;
            }, ['reference_rows', $participant]));
            self::assertFalse($called);
            self::assertTrue($this->database->inTransaction());
            self::assertSame(70, $this->value(2));
            self::assertTrue($this->database->rollBack());
        }
        $this->database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $called = false;
        self::assertFalse(\reference_write_atomic(static function () use (&$called): bool {
            $called = true;
            return true;
        }, ['missing_rows']));
        self::assertFalse($called);
        self::assertFalse($this->database->inTransaction());
    }

    public function testTemporaryParticipantsAndMissingSelectedPdoFailClosed(): void
    {
        foreach (['InnoDB', 'MyISAM'] as $engine) {
            $this->database->exec("CREATE TEMPORARY TABLE reference_rows (id INT) ENGINE=$engine");
            $called = false;
            self::assertFalse(\reference_write_atomic(static function () use (&$called): bool {
                $called = true;
                return true;
            }, ['reference_rows']));
            self::assertFalse($called);
            self::assertFalse($this->database->inTransaction());
            $this->database->exec('DROP TEMPORARY TABLE reference_rows');
        }
        $this->database->exec("ALTER TABLE reference_rows COMMENT='ENGINE=MyISAM'");
        self::assertTrue(\reference_write_atomic(fn() => $this->database->exec('INSERT INTO reference_rows VALUES (1,10)') !== false, ['reference_rows']));
        $GLOBALS['database_sessions'] = [];
        $called = false;
        self::assertFalse(\reference_write_atomic(static function () use (&$called): bool {
            $called = true;
            return true;
        }, ['reference_rows']));
        self::assertFalse($called);
    }

    public function testRunnerUsesSelectedPdoAndPreservesCallerOwnership(): void
    {
        $runner = new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner();
        self::assertInstanceOf(\Kadupul\Platform\Contract\ReferenceWriteTransactionRunner::class, $runner);
        // An unrelated global connection must not replace the selected PDO.
        $GLOBALS['database_sessions'] = ['owned-native:0:' . $this->schema => new \PDO('sqlite::memory:')];
        self::assertSame(['committed' => true], $runner->run($this->database, function (): array {
            self::assertTrue($this->database->inTransaction());
            self::assertSame(1, $this->database->exec('INSERT INTO reference_rows VALUES (1,10)'));
            return ['committed' => true];
        }, ['reference_rows']));
        self::assertFalse($this->database->inTransaction());
        self::assertSame(10, $this->value());
        self::assertTrue($this->database->beginTransaction());
        $this->database->exec('INSERT INTO reference_rows VALUES (2,70)');
        $original = new \RuntimeException('Selected native operation refused');
        $observed = null;
        try {
            $runner->run($this->database, function () use ($original): void {
                $this->database->exec('UPDATE reference_rows SET value=20 WHERE id=1');
                throw $original;
            }, ['reference_rows']);
        } catch (\Throwable $failure) {
            $observed = $failure;
        }
        self::assertSame($original, $observed);
        self::assertTrue($this->database->inTransaction());
        self::assertSame(10, $this->value());
        self::assertSame(70, $this->value(2));
        self::assertSame(1, $runner->run($this->database, fn(): int => $this->database->exec('UPDATE reference_rows SET value=50 WHERE id=1'), ['reference_rows']));
        self::assertTrue($this->database->inTransaction());
        self::assertSame(50, $this->value());
        self::assertTrue($this->database->rollBack());
        self::assertSame(10, $this->value());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM reference_rows WHERE id=2')->fetchColumn());
    }

    public function testRunnerPreservesFalseRefusalAndNativeException(): void
    {
        $runner = new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner();
        $this->database->exec('INSERT INTO reference_rows VALUES (1,10)');
        $observed = null;
        try {
            $runner->run($this->database, function (): bool {
                $this->database->exec('UPDATE reference_rows SET value=20 WHERE id=1');
                return false;
            }, ['reference_rows']);
        } catch (\RuntimeException $failure) {
            $observed = $failure;
        }
        self::assertInstanceOf(\RuntimeException::class, $observed);
        self::assertSame('A reference write could not be confirmed.', $observed->getMessage());
        self::assertFalse($this->database->inTransaction());
        self::assertSame(10, $this->value());
        $original = $observed = null;
        try {
            $runner->run($this->database, function () use (&$original): void {
                $this->database->exec('UPDATE reference_rows SET value=30 WHERE id=1');
                try {
                    $this->database->exec('INSERT INTO reference_rows VALUES (1,40)');
                } catch (\PDOException $failure) {
                    $original = $failure;
                    throw $failure;
                }
            }, ['reference_rows']);
        } catch (\PDOException $failure) {
            $observed = $failure;
        }
        self::assertInstanceOf(\PDOException::class, $observed);
        self::assertSame($original, $observed);
        self::assertSame('23000', $observed->errorInfo[0]);
        self::assertSame(1062, $observed->errorInfo[1]);
        self::assertFalse($this->database->inTransaction());
        self::assertSame(10, $this->value());
    }

    private function value(int $id = 1): int
    {
        return (int) $this->database->query("SELECT value FROM reference_rows WHERE id=$id")->fetchColumn();
    }
}
