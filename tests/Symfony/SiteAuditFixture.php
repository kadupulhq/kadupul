<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Infrastructure\Legacy\SiteWriteAudit;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Assert;

/**
 * Drives the real site adapters against a file-backed SQLite database. A second
 * connection observes only committed rows, so each audit record can prove the
 * transaction had resolved before it was written.
 */
final class SiteAuditFixture
{
    public readonly \PDO $writer;
    public readonly \PDO $observer;
    public readonly DatabaseConnection $connection;
    public bool $allowed = true;
    /** @var list<array{event: array<string, mixed>, json: string, open: bool, sites: list<array<string, string>>}> */
    public array $records = [];
    private string $path;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kadupul-site-audit-');
        // Only the MySQL locking and upsert clauses differ from SQLite.
        $this->writer = new class ('sqlite:' . $this->path) extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return parent::prepare(str_replace([' FOR UPDATE', 'ON DUPLICATE KEY UPDATE'], ['', 'ON CONFLICT(name) DO UPDATE SET'], $query), $options);
            }
        };
        $this->writer->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->writer->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postal_code TEXT, country TEXT, timezone TEXT, latitude TEXT, longitude TEXT, zoom TEXT, alternate_id TEXT, notes TEXT)');
        $this->writer->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $this->writer->exec("CREATE TABLE host (id INTEGER PRIMARY KEY, site_id INTEGER, deleted TEXT DEFAULT '')");
        $this->observer = new \PDO('sqlite:' . $this->path);
        $writer = $this->writer;
        $this->connection = new class ($writer) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
    }

    public function __destruct()
    {
        @unlink($this->path);
    }

    public function access(): ConsoleAccess
    {
        $fixture = $this;

        return new class ($fixture) implements ConsoleAccess {
            public function __construct(private SiteAuditFixture $fixture) {}

            public function consoleActor(): ?Actor
            {
                return new Actor(42, 'operator');
            }

            public function canManageDevices(Actor $actor): bool
            {
                return $this->fixture->allowed;
            }
        };
    }

    public function audit(): SiteWriteAudit
    {
        $fixture = $this;

        return new SiteWriteAudit(new class ($fixture) implements AuditTrail {
            public function __construct(private SiteAuditFixture $fixture) {}

            public function record(AuditEvent $event): void
            {
                $this->fixture->observe($event);
            }
        });
    }

    public function observe(AuditEvent $event): void
    {
        $json = $event->json();
        $this->records[] = [
            'event' => json_decode($json, true, 8, JSON_THROW_ON_ERROR),
            'json' => $json,
            'open' => $this->writer->inTransaction(),
            'sites' => $this->observer->query('SELECT id, name, city FROM sites ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        ];
    }

    /**
     * @param list<string> $targets
     * @param list<string> $secrets
     */
    public function assertRecords(string $action, array $targets, string $decision, string $outcome, array $secrets): void
    {
        Assert::assertSame($targets, array_map(static fn(array $record): string => $record['event']['target']['id'], $this->records));
        $correlations = [];
        foreach ($this->records as $record) {
            Assert::assertSame(['schema', 'recorded_at', 'correlation_id', 'actor', 'action', 'target', 'decision', 'outcome'], array_keys($record['event']));
            Assert::assertSame(['id' => 42], $record['event']['actor']);
            Assert::assertSame($action, $record['event']['action']);
            Assert::assertSame('site', $record['event']['target']['type']);
            Assert::assertSame($decision, $record['event']['decision']);
            Assert::assertSame($outcome, $record['event']['outcome']);
            Assert::assertFalse($record['open'], 'Audit record was written inside an open transaction.');
            foreach ([...$secrets, 'private-failure-marker'] as $secret) {
                Assert::assertStringNotContainsString($secret, $record['json']);
            }
            $correlations[] = $record['event']['correlation_id'];
        }
        Assert::assertCount(1, array_unique($correlations));
        Assert::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $correlations[0]);
    }

    /** Fails the next matching statement with a message that must never reach the audit record. */
    public function failOn(string $event, string $table): void
    {
        $this->writer->exec("CREATE TRIGGER audit_failure BEFORE $event ON $table BEGIN SELECT RAISE(ABORT, 'private-failure-marker'); END");
    }
}
