<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\SnmpOptionEntry;
use Kadupul\Automation\Domain\SnmpOptionEntryChange;
use Kadupul\Automation\Domain\SnmpOptionSet;
use Kadupul\Automation\Infrastructure\Persistence\DbalSnmpOptionStore;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class SnmpOptionStoreTest extends TestCase
{
    private const array V2 = ['snmp_version' => 2, 'snmp_port' => 161, 'snmp_timeout' => 500, 'snmp_retries' => 3, 'max_oids' => 10, 'bulk_walk_size' => -1,
        'snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_context' => '', 'snmp_engine_id' => ''];
    private const array NO_CREDENTIALS = ['snmp_community' => '', 'snmp_username' => '', 'snmp_password' => '', 'snmp_priv_passphrase' => ''];

    private const array V3_SECRETS = ['snmp_username' => 'copy-user', 'snmp_password' => 'copy-auth-pass', 'snmp_priv_passphrase' => 'copy-priv-pass'];

    private Connection $database;
    /** @var object{sql: list<string>} every statement the store's connection ran */
    private object $statements;
    private DbalSnmpOptionStore $store;
    private bool $revoked = false;
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    /** @var list<AuditEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $statements = new class extends AbstractLogger {
            /** @var list<string> */
            public array $sql = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (is_string($context['sql'] ?? null)) {
                    $this->sql[] = $context['sql'];
                }
            }
        };
        $this->statements = $statements;
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new Middleware($statements)]));
        foreach ([
            "CREATE TABLE automation_snmp (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '')",
            "CREATE TABLE automation_snmp_items (id INTEGER PRIMARY KEY AUTOINCREMENT, snmp_id INT NOT NULL DEFAULT 0, sequence INT NOT NULL DEFAULT 0,
                snmp_version INT NOT NULL DEFAULT 1, snmp_community TEXT NOT NULL, snmp_port INT NOT NULL DEFAULT 161, snmp_timeout INT NOT NULL DEFAULT 500,
                snmp_retries INT NOT NULL DEFAULT 3, max_oids INT DEFAULT 10, bulk_walk_size INT DEFAULT -1, snmp_username TEXT DEFAULT NULL,
                snmp_password TEXT DEFAULT NULL, snmp_auth_protocol TEXT DEFAULT '', snmp_priv_passphrase TEXT DEFAULT '', snmp_priv_protocol TEXT DEFAULT '',
                snmp_context TEXT DEFAULT '', snmp_engine_id TEXT DEFAULT '')",
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            'CREATE TABLE automation_networks (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, snmp_id INT)',
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $access = $this->createMock(AutomationTemplateAccess::class);
        $access->method('assertCurrent')->willReturnCallback(function (): void {
            $this->checkedInTransaction[] = $this->database->isTransactionActive();
            if ($this->revoked) {
                throw new AutomationTemplateAccessDenied();
            }
        });
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->audit[] = $event;
        });
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->store = new DbalSnmpOptionStore($this->database, $access, $audit, $configuration, 'test-revision-key');
    }

    public function testSetsAreCreatedRenamedAndRefuseStaleOrInvalidNames(): void
    {
        $id = $this->store->saveSet(7, null, 'Core <b> & "edge"', null);
        $set = $this->store->findSet($id);
        // Stored as entered: legacy neither trimmed nor encoded the name.
        self::assertSame('Core <b> & "edge"', $set->name);
        $this->store->saveSet(7, $id, 'Renamed', $set->revision);
        self::assertSame('Renamed', $this->database->fetchOne('SELECT name FROM automation_snmp WHERE id = ?', [$id]));
        foreach ([$set->revision, null, ''] as $stale) {
            try {
                $this->store->saveSet(7, $id, 'Stale', $stale);
                self::fail('A stale SNMP Option Set edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        foreach (['', "a\0b", "\xff", str_repeat('n', 101)] as $name) {
            try {
                $this->store->saveSet(7, null, $name, null);
                self::fail('Accepted an invalid name.');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->store->saveSet(7, null, str_repeat('é', 100), null);
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp'));
        self::assertSame(['automation.snmp_option.create', 'automation.snmp_option.edit'], [$this->audit[0]->action, $this->audit[1]->action]);
        self::assertSame([true, true], array_slice($this->checkedInTransaction, 0, 2));
    }

    public function testEntriesAppendKeepOrReplaceCredentialsAndNeverReturnThem(): void
    {
        $set = $this->store->saveSet(7, null, 'Core', null);
        // The installer and legacy saves leave ties and gaps in real tables.
        $this->database->insert('automation_snmp_items', ['snmp_id' => $set, 'sequence' => 4, 'snmp_version' => 1, 'snmp_community' => 'legacy', 'snmp_auth_protocol' => '']);
        $created = $this->store->saveEntry(7, $set, null, new SnmpOptionEntryChange(self::V2, false, ['snmp_community' => 'secret-community'] + self::NO_CREDENTIALS), null);
        $row = $this->database->fetchAssociative('SELECT * FROM automation_snmp_items WHERE id = ?', [$created]);
        self::assertSame(['5', 'secret-community', '2'], [(string) $row['sequence'], $row['snmp_community'], (string) $row['snmp_version']]);

        $entry = $this->store->findEntry($set, $created);
        self::assertStringNotContainsString('secret-community', serialize($entry));
        self::assertStringNotContainsString('secret-community', serialize($this->store->entries($set)));
        // The revision reaches the page, so it must not be a digest anyone can recompute from guessed credentials.
        $stored = $this->database->fetchAssociative('SELECT * FROM automation_snmp_items WHERE id = ?', [$created]);
        $values = array_map(static fn(string $column): ?string => $stored[$column] === null ? null : (string) $stored[$column], ['id', 'snmp_id', ...SnmpOptionEntryChange::COLUMNS]);
        self::assertNotSame(hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)), $entry->revision);
        self::assertSame(SnmpOptionEntry::revisionOf($stored, 'test-revision-key'), $entry->revision);
        self::assertNotSame(SnmpOptionEntry::revisionOf($stored, 'another-key'), $entry->revision);
        self::assertSame('[None]', $this->store->entries($set)->entries[0]->authProtocol);

        $v3 = ['snmp_version' => 3, 'snmp_auth_protocol' => 'SHA256', 'snmp_priv_protocol' => 'AES128'] + self::V2;
        $this->store->saveEntry(7, $set, $created, new SnmpOptionEntryChange($v3, false, ['snmp_community' => '', 'snmp_username' => 'ops', 'snmp_password' => 'authpass1', 'snmp_priv_passphrase' => 'privpass1']), $entry->revision);
        $entry = $this->store->findEntry($set, $created);
        // Keeping credentials changes only the settings.
        $this->store->saveEntry(7, $set, $created, new SnmpOptionEntryChange(['snmp_port' => 1161] + $v3, true, self::NO_CREDENTIALS), $entry->revision);
        $row = $this->database->fetchAssociative('SELECT snmp_port, snmp_username, snmp_password, snmp_priv_passphrase, snmp_auth_protocol FROM automation_snmp_items WHERE id = ?', [$created]);
        self::assertSame(['1161', 'ops', 'authpass1', 'privpass1', 'SHA256'], array_map('strval', array_values($row)));

        // Leaving SNMPv3 clears its credentials and protocol settings; a kept community is required.
        $entry = $this->store->findEntry($set, $created);
        try {
            $this->store->saveEntry(7, $set, $created, new SnmpOptionEntryChange(self::V2, true, self::NO_CREDENTIALS), $entry->revision);
            self::fail('A version 2 entry without a community was stored.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('SNMP versions 1 and 2 require a community.', $error->getMessage());
        }
        $this->store->saveEntry(7, $set, $created, new SnmpOptionEntryChange(self::V2, false, ['snmp_community' => 'again'] + self::NO_CREDENTIALS), $entry->revision);
        $row = $this->database->fetchAssociative('SELECT snmp_community, snmp_username, snmp_password, snmp_priv_passphrase, snmp_auth_protocol, snmp_priv_protocol FROM automation_snmp_items WHERE id = ?', [$created]);
        self::assertSame(['again', '', '', '', '[None]', '[None]'], array_values($row));
        self::assertSame('automation.snmp_option.entry_edit', $this->audit[array_key_last($this->audit)]->action);
        // Audit events name the change but never carry credential values.
        $recorded = serialize($this->audit);
        foreach (['secret-community', 'authpass1', 'privpass1', 'again'] as $secret) {
            self::assertStringNotContainsString($secret, $recorded);
        }
    }

    public function testEntriesAreScopedToTheirSetAndRefuseStaleOrNewKeptCredentials(): void
    {
        $first = $this->store->saveSet(7, null, 'First', null);
        $second = $this->store->saveSet(7, null, 'Second', null);
        $entry = $this->store->saveEntry(7, $first, null, new SnmpOptionEntryChange(self::V2, false, ['snmp_community' => 'c'] + self::NO_CREDENTIALS), null);
        $revision = $this->store->findEntry($first, $entry)->revision;
        self::assertNull($this->store->findEntry($second, $entry));
        foreach ([
            'edit through another set' => fn() => $this->store->saveEntry(7, $second, $entry, new SnmpOptionEntryChange(self::V2, true, self::NO_CREDENTIALS), $revision),
            'delete through another set' => fn() => $this->store->deleteEntry(7, $second, $entry, $revision),
            'missing set' => fn() => $this->store->saveEntry(7, 99, null, new SnmpOptionEntryChange(self::V2, false, ['snmp_community' => 'c'] + self::NO_CREDENTIALS), null),
            'stale edit' => fn() => $this->store->saveEntry(7, $first, $entry, new SnmpOptionEntryChange(self::V2, true, self::NO_CREDENTIALS), 'stale'),
            'kept credentials for a new entry' => fn() => $this->store->saveEntry(7, $first, null, new SnmpOptionEntryChange(self::V2, true, self::NO_CREDENTIALS), null),
        ] as $case => $write) {
            try {
                $write();
                self::fail('Accepted ' . $case);
            } catch (\InvalidArgumentException) {
            }
        }
        // Another administrator replacing the credentials makes an open editor stale.
        $this->database->update('automation_snmp_items', ['snmp_community' => 'rotated'], ['id' => $entry]);
        try {
            $this->store->deleteEntry(7, $first, $entry, $revision);
            self::fail('A stale delete was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }
        $this->store->deleteEntry(7, $first, $entry, $this->store->findEntry($first, $entry)->revision);
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp_items'));
    }

    public function testMovesSwapNeighboursRenumberDenselyAndRefuseBoundariesAndStaleOrders(): void
    {
        $set = $this->store->saveSet(7, null, 'Core', null);
        $other = $this->store->saveSet(7, null, 'Other', null);
        foreach ([[10, $set, 7], [11, $set, 0], [12, $set, 0], [13, $set, 3], [14, $other, 1]] as [$id, $snmpId, $sequence]) {
            $this->database->insert('automation_snmp_items', ['id' => $id, 'snmp_id' => $snmpId, 'sequence' => $sequence, 'snmp_community' => 'c']);
        }
        $entries = $this->store->entries($set);
        self::assertSame([11, 12, 13, 10], array_map(static fn($entry): int => $entry->id, $entries->entries));
        self::assertSame([11, 10], [$entries->first(), $entries->last()]);
        $this->store->moveEntry(7, $set, 12, 1, $entries->order);
        self::assertSame([[11, 1], [13, 2], [12, 3], [10, 4]], $this->sequences($set));
        self::assertSame([[14, 1]], $this->sequences($other));
        foreach ([[11, -1], [10, 1], [14, 1], [12, 2]] as [$id, $offset]) {
            try {
                $this->store->moveEntry(7, $set, $id, $offset, $this->store->entries($set)->order);
                self::fail('Accepted a move past the boundary or from another set.');
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            $this->store->moveEntry(7, $set, 13, -1, $entries->order);
            self::fail('A stale order was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('order changed', $error->getMessage());
        }
        self::assertSame([[11, 1], [13, 2], [12, 3], [10, 4]], $this->sequences($set));
    }

    public function testBulkDeleteRemovesSetsWithTheirEntriesAndLeavesNetworkReferences(): void
    {
        [$core, $edge, $kept] = $this->sets(['Core', 'Edge', 'Kept']);
        $this->database->insert('automation_networks', ['name' => 'Office', 'snmp_id' => $core]);
        $revisions = $this->revisions([$core, $edge]);
        self::assertSame([$core, $edge], array_keys($this->store->findSets([$edge, 999, $core])));
        self::assertSame([], $this->store->findSets([]));

        $before = count($this->statements->sql);
        $this->store->deleteSets(7, [$core, $edge], $revisions);
        $two = count($this->statements->sql) - $before;
        self::assertSame([(string) $kept], array_map('strval', $this->database->fetchFirstColumn('SELECT id FROM automation_snmp')));
        self::assertSame([(string) $kept], array_map('strval', $this->database->fetchFirstColumn('SELECT DISTINCT snmp_id FROM automation_snmp_items')));
        // Legacy left a network pointing at a deleted set; that is unchanged.
        self::assertSame((string) $core, (string) $this->database->fetchOne('SELECT snmp_id FROM automation_networks'));
        $event = $this->audit[array_key_last($this->audit)];
        self::assertSame(['automation.snmp_option.delete', AuditEvent::SUCCEEDED], [$event->action, $event->outcome]);
        self::assertTrue($this->checkedInTransaction[array_key_last($this->checkedInTransaction)]);

        // Fifty sets cost the same statements as two: nothing runs per selected row.
        $many = $this->sets(array_map(static fn(int $number): string => 'Set ' . $number, range(1, 50)));
        $revisions = $this->revisions($many);
        $before = count($this->statements->sql);
        $this->store->deleteSets(7, $many, $revisions);
        self::assertSame($two, count($this->statements->sql) - $before);
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp'));
    }

    public function testBulkDeleteRefusesStaleMissingAndMalformedSelectionsWithoutWriting(): void
    {
        [$core, $edge] = $this->sets(['Core', 'Edge']);
        $revisions = $this->revisions([$core, $edge]);
        $checks = count($this->checkedInTransaction);
        foreach ([
            'empty' => [[], []],
            'unsorted' => [[$edge, $core], $revisions],
            'repeated' => [[$core, $core], [$core => $revisions[$core]]],
            'zero' => [[0], [0 => 'x']],
            'beyond the column' => [[4294967296], [4294967296 => 'x']],
            'text id' => [['1'], [1 => 'x']],
            'too many' => [range(1, 101), array_fill(1, 101, 'x')],
            'missing revision' => [[$core, $edge], [$core => $revisions[$core]]],
            'non-text revision' => [[$core], [$core => 1]],
        ] as $case => [$ids, $given]) {
            try {
                $this->store->deleteSets(7, $ids, $given);
                self::fail('Accepted selection: ' . $case);
            } catch (\InvalidArgumentException) {
            }
        }
        // A malformed selection is refused before a transaction or an actor check starts.
        self::assertCount($checks, $this->checkedInTransaction);

        $this->database->update('automation_snmp', ['name' => 'Renamed'], ['id' => $edge]);
        foreach ([[[$core, $edge], $revisions, 'changed since'], [[$core, 999], [$core => $revisions[$core], 999 => 'x'], 'no longer exist']] as [$ids, $given, $message]) {
            try {
                $this->store->deleteSets(7, $ids, $given);
                self::fail('Accepted a selection that should report: ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp'));
        self::assertSame(4, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp_items'));

        // The entries go first; when the set rows cannot be removed, they come back.
        $this->database->executeStatement("CREATE TRIGGER refuse_set_delete BEFORE DELETE ON automation_snmp BEGIN SELECT RAISE(ABORT, 'refused'); END");
        try {
            $this->store->deleteSets(7, [$core], [$core => $revisions[$core]]);
            self::fail('A failed deletion reported success.');
        } catch (\InvalidArgumentException $error) {
            self::fail('A database failure was reported as a correctable refusal: ' . $error->getMessage());
        } catch (\Throwable) {
        }
        self::assertSame(4, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp_items'));
        self::assertSame(AuditEvent::FAILED, $this->audit[array_key_last($this->audit)]->outcome);

        $this->revoked = true;
        try {
            $this->store->deleteSets(7, [$core], [$core => $revisions[$core]]);
            self::fail('A revoked actor deleted.');
        } catch (AutomationTemplateAccessDenied) {
        }
        self::assertSame(AuditEvent::DENIED, $this->audit[array_key_last($this->audit)]->outcome);
    }

    public function testDuplicateCopiesEntriesWithStoredCredentialsInsideTheDatabase(): void
    {
        [$core, $empty] = $this->sets(['Core', 'Empty']);
        $this->database->executeStatement('DELETE FROM automation_snmp_items WHERE snmp_id = ?', [$empty]);
        $this->database->update('automation_snmp_items', ['bulk_walk_size' => 25, 'sequence' => 9], ['snmp_id' => $core, 'snmp_version' => 3]);
        $source = $this->entryRows($core);

        $revisions = $this->revisions([$core, $empty]);
        $before = count($this->statements->sql);
        $this->store->duplicateSets(7, [$core, $empty], $revisions, SnmpOptionSet::NAME_FORMAT);
        $two = array_slice($this->statements->sql, $before);
        $copies = $this->database->fetchAllKeyValue('SELECT name, id FROM automation_snmp WHERE id NOT IN (?, ?)', [$core, $empty]);
        self::assertSame(['Core (1)', 'Empty (1)'], array_keys($copies));
        // Every column but the entry ID and its set is carried over, bulk_walk_size included.
        self::assertSame($source, $this->entryRows((int) $copies['Core (1)']));
        self::assertSame($source, $this->entryRows($core));
        self::assertSame([], $this->entryRows((int) $copies['Empty (1)']));

        // The credentials never pass through PHP: no statement selects them, and one statement copies every entry.
        $copying = array_values(array_filter($two, static fn(string $sql): bool => str_contains($sql, 'INSERT INTO automation_snmp_items')));
        self::assertCount(1, $copying);
        foreach ($two as $sql) {
            if ($sql !== $copying[0]) {
                self::assertStringNotContainsString('snmp_password', $sql);
                self::assertStringNotContainsString('snmp_community', $sql);
            }
        }
        $event = $this->audit[array_key_last($this->audit)];
        self::assertSame(['automation.snmp_option.duplicate', AuditEvent::SUCCEEDED], [$event->action, $event->outcome]);
        foreach (['copy-community', ...array_values(self::V3_SECRETS)] as $secret) {
            self::assertStringNotContainsString($secret, serialize($this->audit));
        }

        // One insert per copied set, and nothing else grows with the selection.
        $many = $this->sets(array_map(static fn(int $number): string => 'Set ' . $number, range(1, 40)));
        $revisions = $this->revisions($many);
        $before = count($this->statements->sql);
        $this->store->duplicateSets(7, $many, $revisions, '<name> copy');
        // Lock, one copy statement for all entries, and one insert per set.
        self::assertCount(4, $two);
        self::assertSame(42, count($this->statements->sql) - $before);
        self::assertSame(40, (int) $this->database->fetchOne("SELECT COUNT(*) FROM automation_snmp WHERE name LIKE 'Set % copy'"));
        self::assertSame(80, (int) $this->database->fetchOne("SELECT COUNT(*) FROM automation_snmp_items i INNER JOIN automation_snmp s ON s.id = i.snmp_id WHERE s.name LIKE 'Set % copy'"));
    }

    public function testDuplicateNamesFollowTheLegacyFormatAndMustFitTheColumn(): void
    {
        self::assertSame('Core (1)', SnmpOptionSet::copyName('<name> (1)', 'Core'));
        self::assertSame('Core/Core', SnmpOptionSet::copyName('<name>/<name>', 'Core'));
        // Without the token every copy gets the same name, as on the legacy page; other tokens are literal text.
        self::assertSame('Fixed <nom>', SnmpOptionSet::copyName('Fixed <nom>', 'Core'));
        self::assertSame(str_repeat('é', 96) . ' (1)', SnmpOptionSet::copyName('<name> (1)', str_repeat('é', 96)));
        foreach ([['<name> (1)', str_repeat('n', 97)], ['', 'Core'], ['<name>', ''], [str_repeat('x', 256), 'Core'], ["<name>\n", 'Core'], ["<name>\0", 'Core'], ["\xff<name>", 'Core']] as [$format, $name]) {
            try {
                SnmpOptionSet::copyName($format, $name);
                self::fail('Accepted the name format ' . json_encode($format, JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (\InvalidArgumentException) {
            }
        }

        [$short, $long] = $this->sets(['Short', str_repeat('n', 97)]);
        $revisions = $this->revisions([$short, $long]);
        $sets = (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp');
        $entries = (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp_items');
        foreach ([
            'one name too long for the column' => fn() => $this->store->duplicateSets(7, [$short, $long], $revisions, '<name> (1)'),
            'empty format' => fn() => $this->store->duplicateSets(7, [$short], [$short => $revisions[$short]], ''),
            'stale' => fn() => $this->store->duplicateSets(7, [$short], [$short => 'stale'], '<name> (1)'),
            'missing' => fn() => $this->store->duplicateSets(7, [$short, 999], [$short => $revisions[$short], 999 => 'x'], '<name> (1)'),
            'unsorted' => fn() => $this->store->duplicateSets(7, [$long, $short], $revisions, '<name> (1)'),
        ] as $case => $write) {
            try {
                $write();
                self::fail('Duplicated with ' . $case);
            } catch (\InvalidArgumentException) {
            }
        }
        // A refusal for one set leaves no copy of another.
        self::assertSame($sets, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp'));
        self::assertSame($entries, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp_items'));
    }

    public function testRevocationInsideTheTransactionWritesNothingAndIsAudited(): void
    {
        $this->revoked = true;
        try {
            $this->store->saveSet(7, null, 'Core', null);
            self::fail('A revoked actor wrote.');
        } catch (AutomationTemplateAccessDenied) {
        }
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp'));
        self::assertSame(AuditEvent::DENIED, $this->audit[0]->outcome);
    }

    public function testDefaultsComeFromTheLegacySettingsAndFallBackWhenUnsupported(): void
    {
        self::assertSame(['snmp_version' => 2, 'snmp_port' => 161, 'snmp_timeout' => 500, 'snmp_retries' => 3, 'max_oids' => 10, 'bulk_walk_size' => 0,
            'snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_context' => '', 'snmp_engine_id' => ''], $this->store->entryDefaults());
        foreach (['snmp_version' => '3', 'snmp_port' => '1161', 'snmp_timeout' => '900', 'snmp_retries' => '300', 'max_get_size' => '7', 'snmp_auth_protocol' => 'SHA', 'snmp_priv_protocol' => 'AES', 'snmp_community' => 'never-a-default'] as $name => $value) {
            $this->database->insert('settings', ['name' => $name, 'value' => $value]);
        }
        $defaults = $this->store->entryDefaults();
        self::assertSame([3, 1161, 900, 3, 10, 'SHA', 'AES'], [$defaults['snmp_version'], $defaults['snmp_port'], $defaults['snmp_timeout'], $defaults['snmp_retries'], $defaults['max_oids'], $defaults['snmp_auth_protocol'], $defaults['snmp_priv_protocol']]);
        self::assertStringNotContainsString('never-a-default', serialize($defaults));
    }

    /**
     * Each set gets a community entry and an SNMPv3 entry holding credentials.
     *
     * @param list<string> $names
     * @return list<int>
     */
    private function sets(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $this->database->insert('automation_snmp', ['name' => $name]);
            $ids[] = $id = (int) $this->database->lastInsertId();
            $this->database->insert('automation_snmp_items', ['snmp_id' => $id, 'sequence' => 1, 'snmp_version' => 2, 'snmp_community' => 'copy-community']);
            $this->database->insert('automation_snmp_items', ['snmp_id' => $id, 'sequence' => 2, 'snmp_version' => 3, 'snmp_community' => '', 'snmp_auth_protocol' => 'SHA', 'snmp_priv_protocol' => 'AES', 'snmp_context' => 'ctx', 'snmp_engine_id' => '80001f88'] + self::V3_SECRETS);
        }
        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function revisions(array $ids): array
    {
        return array_map(static fn(SnmpOptionSet $set): string => $set->revision, $this->store->findSets($ids));
    }

    /** @return list<array<string, string|null>> a set's entries without their ID and set */
    private function entryRows(int $set): array
    {
        return array_map(
            static fn(array $row): array => array_map(static fn(mixed $value): ?string => $value === null ? null : (string) $value, array_diff_key($row, ['id' => true, 'snmp_id' => true])),
            $this->database->fetchAllAssociative('SELECT * FROM automation_snmp_items WHERE snmp_id = ? ORDER BY sequence, id', [$set]),
        );
    }

    /** @return list<array{int, int}> */
    private function sequences(int $set): array
    {
        return array_map(static fn(array $row): array => [(int) $row['id'], (int) $row['sequence']], $this->database->fetchAllAssociative('SELECT id, sequence FROM automation_snmp_items WHERE snmp_id = ? ORDER BY sequence, id', [$set]));
    }
}
