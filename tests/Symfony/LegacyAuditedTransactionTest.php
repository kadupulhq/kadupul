<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyColorTemplateStore;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetStore;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorStore;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Each store's write keeps its own messages and audit names through the shared transaction. */
final class LegacyAuditedTransactionTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string, string, string}> */
    public static function stores(): iterable
    {
        yield 'Color' => ['palette', 'Color', 'Color operation', 'Colors', 'graphing.palette.create', 'palette', 'palette'];
        yield 'GPRINT Preset' => ['gprint', 'GPRINT', 'GPRINT operation', 'GPRINT Presets', 'graphing.gprint.create', 'gprint_preset', 'gprint_preset'];
        yield 'Color Template' => ['template', 'Color template', 'Color template', 'Color Templates', 'graphing.color_template.create', 'color_template', 'new'];
    }

    #[DataProvider('stores')]
    public function testConfirmedWriteIsAuditedUnderTheStoreName(string $store, string $subject, string $operation, string $plural, string $action, string $type, string $target): void
    {
        [$database, $audit, $save] = $this->fixture($store, 1);
        self::assertGreaterThan(0, $save());
        self::assertFalse($database->inTransaction());
        self::assertSame([[$action, $type, $target, AuditEvent::ALLOWED, AuditEvent::SUCCEEDED]], $audit->records);
    }

    #[DataProvider('stores')]
    public function testEveryRefusalKeepsTheStoreMessage(string $store, string $subject, string $operation, string $plural, string $action, string $type, string $target): void
    {
        $cases = [
            'caller transaction' => [$subject . ' transaction unavailable.', AuditEvent::DENIED, AuditEvent::DENIED],
            'commit' => [$operation . ' commit was not confirmed.', AuditEvent::ALLOWED, AuditEvent::FAILED],
            'rollback' => [$operation . ' rollback was not confirmed.', AuditEvent::ALLOWED, AuditEvent::FAILED],
            'collector' => [$plural . ' must be changed on the primary collector.', AuditEvent::DENIED, AuditEvent::DENIED],
            'engine' => [$subject . ' writes require transactional tables.', AuditEvent::DENIED, AuditEvent::DENIED],
            'isolation' => [$subject . ' transaction isolation was not confirmed.', AuditEvent::DENIED, AuditEvent::DENIED],
        ];
        foreach ($cases as $case => [$message, $decision, $outcome]) {
            [$database, $audit, $save] = $this->fixture($store, $case === 'collector' ? 2 : 1);
            match ($case) {
                'caller transaction' => $database->beginTransaction(),
                'commit' => $database->commits = false,
                'rollback' => [$database->commits, $database->rollbacks] = [false, false],
                'collector', 'engine' => $database->driver = 'mysql',
                'isolation' => [$database->driver, $database->definition] = ['mysql', "CREATE TABLE `t` (\n  `id` int\n) ENGINE=InnoDB"],
            };
            try {
                $save();
                self::fail($case . ' was accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame($message, $error->getMessage(), $case);
            }
            self::assertSame([[$action, $type, $target, $decision, $outcome]], $audit->records, $case);
        }
    }

    /** @return array{AuditedTransactionPdo, object{records: list<list<string>>}, \Closure(): int} */
    private function fixture(string $store, int $collector): array
    {
        $database = new AuditedTransactionPdo('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $database->exec("CREATE TABLE colors(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,hex TEXT UNIQUE,read_only TEXT);
            CREATE TABLE graph_templates_gprint(id INTEGER PRIMARY KEY AUTOINCREMENT,hash TEXT,name TEXT,gprint_text TEXT);
            CREATE TABLE color_templates(color_template_id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT)");
        $connection = new class ($database) implements DatabaseConnection {
            public function __construct(private \PDO $database) {}
            public function get(): \PDO
            {
                return $this->database;
            }
        };
        $audit = new class implements AuditTrail {
            /** @var list<list<string>> */
            public array $records = [];
            public function record(AuditEvent $event): void
            {
                $this->records[] = [$event->action, $event->targetType, $event->targetId, $event->decision, $event->outcome];
            }
        };
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => $collector]);
        $save = match ($store) {
            'palette' => fn(): int => (new LegacyPaletteColorStore($connection, $this->createMock(PaletteColorAccess::class), $audit, $configuration))->save(9, null, 'Fixture', 'abc', null),
            'gprint' => fn(): int => (new LegacyGprintPresetStore($connection, $this->createMock(GprintPresetAccess::class), $audit, $configuration))->save(9, null, 'Fixture', '%s', null),
            'template' => fn(): int => (new LegacyColorTemplateStore($connection, $this->createMock(ColorTemplateAccess::class), $audit, $configuration))->save(9, null, 'Fixture', null),
        };

        return [$database, $audit, $save];
    }
}

/** SQLite that can report the MySQL driver and refuse a commit, rollback or isolation change. */
final class AuditedTransactionPdo extends \PDO
{
    public string $driver = 'sqlite';
    public ?string $definition = null;
    public bool $commits = true;
    public bool $rollbacks = true;

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === \PDO::ATTR_DRIVER_NAME ? $this->driver : parent::getAttribute($attribute);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        if (str_starts_with($query, 'SHOW CREATE TABLE ')) {
            return $this->definition === null ? false : parent::query('SELECT ' . $this->quote(substr($query, 18)) . ', ' . $this->quote($this->definition));
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return str_starts_with($statement, 'SET TRANSACTION ') ? false : parent::exec($statement);
    }

    public function commit(): bool
    {
        return $this->commits && parent::commit();
    }

    public function rollBack(): bool
    {
        return $this->rollbacks && parent::rollBack();
    }
}
