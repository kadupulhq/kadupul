<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Application\Port\DataSourceProfileAccess;
use Kadupul\Graphing\Application\Port\DataSourceProfileStore;
use Kadupul\Graphing\Application\Query\DataSourceProfileAccessDenied;
use Kadupul\Graphing\Domain\DataSourceProfileFilters;
use Kadupul\Graphing\Domain\DataSourceProfileRra;
use Kadupul\Graphing\Infrastructure\Persistence\DbalDataSourceProfileAccess;
use Kadupul\Graphing\Infrastructure\Persistence\DbalDataSourceProfileStore;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataSourceProfileStoreTest extends TestCase
{
    private Connection $database;
    private DbalDataSourceProfileStore $store;
    private bool $revoked = false;
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    /** @var list<AuditEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            "CREATE TABLE data_source_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '', step INT NOT NULL DEFAULT 300, heartbeat INT NOT NULL DEFAULT 600, x_files_factor REAL DEFAULT 0.5, `default` TEXT DEFAULT '')",
            "CREATE TABLE data_source_profiles_rra (id INTEGER PRIMARY KEY AUTOINCREMENT, data_source_profile_id INT NOT NULL DEFAULT 0, name TEXT NOT NULL DEFAULT '', steps INT DEFAULT 1, `rows` INT NOT NULL DEFAULT 700, timespan INT NOT NULL DEFAULT 0)",
            'CREATE TABLE data_source_profiles_cf (data_source_profile_id INT NOT NULL, consolidation_function_id INT NOT NULL, PRIMARY KEY (data_source_profile_id, consolidation_function_id))',
            "CREATE TABLE data_template_data (id INTEGER PRIMARY KEY AUTOINCREMENT, local_data_id INT NOT NULL DEFAULT 0, data_template_id INT NOT NULL DEFAULT 0, data_source_profile_id INT NOT NULL DEFAULT 1, name TEXT NOT NULL DEFAULT '')",
            'CREATE TABLE data_template_rrd (id INTEGER PRIMARY KEY AUTOINCREMENT, local_data_id INT NOT NULL DEFAULT 0, data_template_id INT NOT NULL DEFAULT 0, rrd_heartbeat INT NOT NULL DEFAULT 600)',
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            // The installed default: 5 minute collection with four archives and every function.
            "INSERT INTO data_source_profiles VALUES (1, 'd62c52891f4f9688729a5bc9fad91b18', '5 Minute Collection', 300, 600, 0.5, 'on')",
            "INSERT INTO data_source_profiles_rra (data_source_profile_id, name, steps, `rows`, timespan) VALUES (1, 'Daily', 1, 600, 86400), (1, 'Weekly', 6, 700, 604800)",
            'INSERT INTO data_source_profiles_cf VALUES (1, 1), (1, 2), (1, 3), (1, 4)',
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $access = $this->createMock(DataSourceProfileAccess::class);
        $access->method('assertCurrent')->willReturnCallback(function (): void {
            $native = $this->database->getNativeConnection();
            $this->checkedInTransaction[] = $native instanceof \PDO && $native->inTransaction();
            if ($this->revoked) {
                throw new DataSourceProfileAccessDenied(false);
            }
        });
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->audit[] = $event;
        });
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->store = new DbalDataSourceProfileStore($this->database, $access, $audit, $configuration);
    }

    public function testCreationStoresTheNameAsEnteredWithAFreshHashAndSortedFunctions(): void
    {
        $saved = $this->store->save(7, null, '<b>Fast & "raw"</b>', 60, 120, '0.25', [4, 1, 4], false, null);
        self::assertFalse($saved['retune']);
        $profile = $this->store->find($saved['id']);
        self::assertSame(['<b>Fast & "raw"</b>', 60, 120, 0.25, false, [1, 4]], [$profile->name, $profile->step, $profile->heartbeat, $profile->xFilesFactor, $profile->isDefault, $profile->consolidationFunctions]);
        $hash = (string) $this->database->fetchOne('SELECT hash FROM data_source_profiles WHERE id = ?', [$saved['id']]);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $hash);
        self::assertNotSame('d62c52891f4f9688729a5bc9fad91b18', $hash);
        self::assertSame([true], $this->checkedInTransaction);
        self::assertSame(['graphing.data_source_profile.create', AuditEvent::ALLOWED, AuditEvent::SUCCEEDED], [$this->audit[0]->action, $this->audit[0]->decision, $this->audit[0]->outcome]);
    }

    public function testMarkingADefaultMovesItAndUncheckingNeverClearsIt(): void
    {
        $other = $this->store->save(7, null, 'Other', 300, 600, '0.5', [1], true, null)['id'];
        self::assertSame([$other], array_map('intval', $this->database->fetchFirstColumn("SELECT id FROM data_source_profiles WHERE `default` = 'on'")));
        $this->store->save(7, $other, 'Other', null, null, null, [], false, $this->store->find($other)->revision);
        self::assertTrue($this->store->find($other)->isDefault);
        $this->store->save(7, 1, '5 Minute Collection', null, null, null, [], true, $this->store->find(1)->revision);
        self::assertSame([1], array_map('intval', $this->database->fetchFirstColumn("SELECT id FROM data_source_profiles WHERE `default` = 'on'")));
    }

    public function testEditsRequireTheCurrentRevisionAndKeepAValidHash(): void
    {
        $revision = $this->store->find(1)->revision;
        $this->store->save(7, 1, 'Renamed', null, null, null, [], false, $revision);
        foreach ([$revision, null, ''] as $stale) {
            try {
                $this->store->save(7, 1, 'Stale', null, null, null, [], false, $stale);
                self::fail('A stale profile edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        self::assertSame('Renamed', $this->store->find(1)->name);
        self::assertSame('d62c52891f4f9688729a5bc9fad91b18', $this->database->fetchOne('SELECT hash FROM data_source_profiles WHERE id = 1'));

        // A damaged hash is replaced, as get_hash_data_source_profile() did.
        $this->database->executeStatement("UPDATE data_source_profiles SET hash = 'not-a-hash' WHERE id = 1");
        $this->store->save(7, 1, 'Renamed', null, null, null, [], false, $this->store->find(1)->revision);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (string) $this->database->fetchOne('SELECT hash FROM data_source_profiles WHERE id = 1'));

        // An archive edit elsewhere makes an open profile form stale too.
        $revision = $this->store->find(1)->revision;
        $this->database->executeStatement("UPDATE data_source_profiles_rra SET name = 'Changed' WHERE data_source_profile_id = 1 AND steps = 1");
        $this->expectExceptionMessage('changed since');
        $this->store->save(7, 1, 'Again', null, null, null, [], false, $revision);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function forgedStructure(): array
    {
        return [
            'step' => [['step' => 60]],
            'x-files factor' => [['factor' => '0.25']],
            'consolidation functions' => [['functions' => [2]]],
        ];
    }

    #[DataProvider('forgedStructure')]
    public function testAProfileUsedByDataSourcesRefusesEveryStructuralChange(array $change): void
    {
        $this->reference(1, 5, 0);
        $before = $this->rows();
        try {
            $this->store->save(7, 1, 'Forged', $change['step'] ?? null, 900, $change['factor'] ?? null, $change['functions'] ?? [], false, $this->store->find(1)->revision);
            self::fail('A structural change to a read-only profile was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(DataSourceProfileStore::READ_ONLY, $error->getMessage());
        }
        // The whole save is refused, the heartbeat and name included.
        self::assertSame($before, $this->rows());
        self::assertSame(AuditEvent::FAILED, end($this->audit)->outcome);
    }

    public function testAProfileUsedByDataSourcesAcceptsUnchangedStructureAndPropagatesItsHeartbeat(): void
    {
        $this->reference(1, 0, 3);
        $source = $this->reference(1, 5, 3);
        $other = $this->store->save(7, null, 'Other', 300, 1200, '0.5', [1], false, null)['id'];
        $this->reference($other, 0, 4);
        $this->reference($other, 6, 4);
        $this->database->executeStatement('INSERT INTO data_template_rrd (local_data_id, data_template_id, rrd_heartbeat) VALUES (0, 3, 600), (5, 3, 700), (0, 4, 1200), (6, 4, 1400)');
        $saved = $this->store->save(7, 1, 'Renamed', 300, 900, '0.50', [4, 3, 2, 1], false, $this->store->find(1)->revision);
        self::assertTrue($saved['retune']);
        self::assertSame(['300', '900', '0.5'], array_map('strval', array_values($this->database->fetchAssociative('SELECT step, heartbeat, x_files_factor FROM data_source_profiles WHERE id = 1'))));
        self::assertSame(['900', '900', '1200', '1400'], array_map('strval', $this->database->fetchFirstColumn('SELECT rrd_heartbeat FROM data_template_rrd ORDER BY id')));
        self::assertSame($source, (int) $this->database->fetchOne('SELECT id FROM data_template_data WHERE local_data_id = 5'));

        // An unchanged heartbeat leaves metadata alone and asks for no tuning.
        $this->database->executeStatement('UPDATE data_template_rrd SET rrd_heartbeat = 777 WHERE local_data_id = 5');
        self::assertFalse($this->store->save(7, 1, 'Renamed', null, 900, null, [], false, $this->store->find(1)->revision)['retune']);
        self::assertSame('777', (string) $this->database->fetchOne('SELECT rrd_heartbeat FROM data_template_rrd WHERE local_data_id = 5'));
    }

    public function testATemplateOnlyProfileAcceptsStructuralChanges(): void
    {
        $this->reference(1, 0, 3);
        $saved = $this->store->save(7, 1, 'Template only', 60, 120, '0.25', [2, 4], false, $this->store->find(1)->revision);
        self::assertFalse($saved['retune']);
        $profile = $this->store->find(1);
        self::assertSame([60, 120, 0.25, [2, 4]], [$profile->step, $profile->heartbeat, $profile->xFilesFactor, $profile->consolidationFunctions]);
        self::assertFalse($profile->isReadOnly());
        self::assertFalse($profile->isDeletable());
    }

    /** @return array<string, array{string, ?int, ?int, ?string, list<mixed>}> */
    public static function invalidProfiles(): array
    {
        return [
            'empty name' => ['', 300, 600, '0.5', [1]],
            'blank name' => ['   ', 300, 600, '0.5', [1]],
            'long name' => [str_repeat('é', 256), 300, 600, '0.5', [1]],
            'control character' => ["a\nb", 300, 600, '0.5', [1]],
            'invalid UTF-8' => ["\xff", 300, 600, '0.5', [1]],
            'factor of one' => ['P', 300, 600, '1', [1]],
            'negative factor' => ['P', 300, 600, '-0.1', [1]],
            'factor text' => ['P', 300, 600, 'half', [1]],
            'factor exponent' => ['P', 300, 600, '5e-1', [1]],
            'unlisted step' => ['P', 45, 600, '0.5', [1]],
            'unlisted heartbeat' => ['P', 300, 601, '0.5', [1]],
            'missing step' => ['P', null, 600, '0.5', [1]],
            'missing factor' => ['P', 300, 600, null, [1]],
            'unknown function' => ['P', 300, 600, '0.5', [9]],
            'zero function' => ['P', 300, 600, '0.5', [0]],
            'function text' => ['P', 300, 600, '0.5', ['1']],
            'too many functions' => ['P', 300, 600, '0.5', [1, 2, 3, 4, 1]],
        ];
    }

    /** @param list<mixed> $functions */
    #[DataProvider('invalidProfiles')]
    public function testInvalidProfilesAreRefusedWithoutWrites(string $name, ?int $step, ?int $heartbeat, ?string $factor, array $functions): void
    {
        $before = $this->rows();
        try {
            $this->store->save(7, null, $name, $step, $heartbeat, $factor, $functions, false, null);
            self::fail('An invalid profile was saved.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame($before, $this->rows());
    }

    public function testArchivesConvertTheLevelToStepsAndRefuseStaleForms(): void
    {
        $id = $this->store->save(7, null, 'Fresh', 300, 600, '0.5', [1], false, null)['id'];
        $first = $this->store->saveArchive(7, $id, null, '', 1, 600, 86400, null);
        $second = $this->store->saveArchive(7, $id, null, 'Hourly', 3600, 775, 604800, null);
        self::assertSame([[$first, 1, 600], [$second, 12, 775]], array_map(static fn(DataSourceProfileRra $archive): array => [$archive->id, $archive->steps, $archive->rows], $this->store->find($id)->archives));
        foreach ([[400, 'whole multiple'], [7, 'whole multiple']] as [$level, $message]) {
            try {
                $this->store->saveArchive(7, $id, null, 'Odd', $level, 10, 3600, null);
                self::fail('An aggregation level off the step was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        $this->store->saveArchive(7, $id, null, 'Thirds', 900 * 2, 10, 3600, null);
        foreach ([0, DataSourceProfileRra::MAX_ROWS + 1] as $rows) {
            try {
                $this->store->saveArchive(7, $id, null, 'Rows', 3600, $rows, 3600, null);
                self::fail('An out of range row count was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('rows from 1', $error->getMessage());
            }
        }
        $archive = $this->archive($id, $second);
        $this->store->saveArchive(7, $id, $second, 'Renamed', null, null, 1209600, $archive->revision);
        $saved = $this->archive($id, $second);
        self::assertSame(['Renamed', 12, 775, 1209600], [$saved->name, $saved->steps, $saved->rows, $saved->timespan]);
        try {
            $this->store->saveArchive(7, $id, $second, 'Stale', null, null, 3600, $archive->revision);
            self::fail('A stale RRA edit was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }
        // An archive of another profile is not reachable through this one.
        $this->expectExceptionMessage('RRA not found.');
        $this->store->saveArchive(7, 1, $second, 'Elsewhere', null, null, 3600, $saved->revision);
    }

    public function testAProfileUsedByDataSourcesKeepsItsArchiveStructure(): void
    {
        $this->reference(1, 5, 0);
        $daily = $this->store->find(1)->archives[0];
        foreach ([
            fn() => $this->store->saveArchive(7, 1, null, 'More', 86400, 10, 3600, null),
            fn() => $this->store->saveArchive(7, 1, $daily->id, 'Daily', null, 601, 86400, $daily->revision),
            fn() => $this->store->saveArchive(7, 1, $daily->id, 'Daily', 1800, 600, 86400, $daily->revision),
            fn() => $this->store->deleteArchive(7, 1, $daily->id, $daily->revision),
        ] as $change) {
            try {
                $change();
                self::fail('A read-only archive structure changed.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame(DataSourceProfileStore::READ_ONLY, $error->getMessage());
            }
        }
        // Name and timespan stay editable, as on the legacy form; an unchanged level is admitted,
        // including the polling interval itself, which is one step.
        $this->store->saveArchive(7, 1, $daily->id, 'Renamed', 300, 600, 172800, $daily->revision);
        self::assertSame(['Renamed', 1, 600, 172800], [$this->store->find(1)->archives[0]->name, $this->store->find(1)->archives[0]->steps, $this->store->find(1)->archives[0]->rows, $this->store->find(1)->archives[0]->timespan]);
        self::assertCount(2, $this->store->find(1)->archives);
    }

    public function testArchiveDeletionNeedsTheCurrentRevision(): void
    {
        $id = $this->store->save(7, null, 'Fresh', 300, 600, '0.5', [1], false, null)['id'];
        $first = $this->store->saveArchive(7, $id, null, 'One', 1, 600, 86400, null);
        $archive = $this->store->find($id)->archives[0];
        $this->database->executeStatement('UPDATE data_source_profiles_rra SET `rows` = 601 WHERE id = ?', [$first]);
        try {
            $this->store->deleteArchive(7, $id, $first, $archive->revision);
            self::fail('A stale RRA deletion was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }
        $this->store->deleteArchive(7, $id, $first, $this->store->find($id)->archives[0]->revision);
        self::assertSame([], $this->store->find($id)->archives);
    }

    public function testDeletionRefusesTheDefaultAndProfilesInUseForTheWholeSelection(): void
    {
        $unused = $this->store->save(7, null, 'Unused', 300, 600, '0.5', [1], false, null)['id'];
        $this->store->saveArchive(7, $unused, null, 'One', 1, 600, 86400, null);
        $template = $this->store->save(7, null, 'Template', 300, 600, '0.5', [1], false, null)['id'];
        $this->reference($template, 0, 3);
        $source = $this->store->save(7, null, 'Source', 300, 600, '0.5', [1], false, null)['id'];
        $this->reference($source, 9, 0);
        foreach ([[1, $unused], [$unused, $template], [$unused, $source]] as $selection) {
            try {
                $this->store->delete(7, $selection, $this->revisions($selection));
                self::fail('A default or referenced profile was deleted.');
            } catch (\InvalidArgumentException $error) {
                self::assertMatchesRegularExpression('/default Data Source Profile|in use/', $error->getMessage());
            }
            self::assertNotNull($this->store->find($unused));
        }
        $revisions = $this->revisions([$unused]);
        $this->database->executeStatement('INSERT INTO data_source_profiles_cf VALUES (?, 3)', [$unused]);
        try {
            $this->store->delete(7, [$unused], $revisions);
            self::fail('A profile changed after confirmation was deleted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }
        $this->store->delete(7, [$unused], $this->revisions([$unused]));
        self::assertNull($this->store->find($unused));
        foreach (['data_source_profiles_rra', 'data_source_profiles_cf'] as $table) {
            self::assertSame(0, (int) $this->database->fetchOne("SELECT COUNT(*) FROM $table WHERE data_source_profile_id = ?", [$unused]));
        }
    }

    public function testDuplicationCopiesFunctionsAndArchivesUnderANewHash(): void
    {
        $this->store->duplicate(7, [1], $this->revisions([1]), 'Copy of <profile_title>');
        $copy = (int) $this->database->fetchOne("SELECT id FROM data_source_profiles WHERE name = 'Copy of 5 Minute Collection'");
        $profile = $this->store->find($copy);
        self::assertSame([300, 600, 0.5, false, [1, 2, 3, 4]], [$profile->step, $profile->heartbeat, $profile->xFilesFactor, $profile->isDefault, $profile->consolidationFunctions]);
        self::assertSame([['Daily', 1, 600, 86400], ['Weekly', 6, 700, 604800]], array_map(static fn(DataSourceProfileRra $archive): array => [$archive->name, $archive->steps, $archive->rows, $archive->timespan], $profile->archives));
        self::assertNotSame('d62c52891f4f9688729a5bc9fad91b18', $this->database->fetchOne('SELECT hash FROM data_source_profiles WHERE id = ?', [$copy]));

        $stale = $this->revisions([1]);
        $this->store->save(7, 1, str_repeat('n', 100), null, null, null, [], false, $this->store->find(1)->revision);
        foreach ([[$stale, '<profile_title> (1)', 'changed since'], [$this->revisions([1]), str_repeat('x', 256), 'title format'], [$this->revisions([1]), str_repeat('x', 200) . '<profile_title>', 'at most 255']] as [$revisions, $format, $message]) {
            try {
                $this->store->duplicate(7, [1], $revisions, $format);
                self::fail('An invalid duplication was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM data_source_profiles'));
    }

    public function testSelectionsAreBoundedSortedAndCarryEveryRevision(): void
    {
        foreach ([[], [2, 1], [1, 1], [0], range(1, DataSourceProfileStore::MAX_SELECTION + 1)] as $ids) {
            try {
                $this->store->delete(7, $ids, array_fill_keys($ids, 'x'));
                self::fail('An invalid selection was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Invalid profile selection.', $error->getMessage());
            }
        }
        $this->expectExceptionMessage('changed since');
        $this->store->duplicate(7, [1], [], '<profile_title> (1)');
    }

    public function testRevokedAccessWritesNothing(): void
    {
        $this->revoked = true;
        $before = $this->rows();
        try {
            $this->store->save(7, null, 'Denied', 300, 600, '0.5', [1], false, null);
            self::fail('A revoked actor saved a profile.');
        } catch (DataSourceProfileAccessDenied) {
        }
        self::assertSame($before, $this->rows());
        self::assertSame([AuditEvent::DENIED, AuditEvent::DENIED], [$this->audit[0]->decision, $this->audit[0]->outcome]);
    }

    public function testListCountsDistinctUsesAndFiltersProfilesWithDataSources(): void
    {
        $other = $this->store->save(7, null, 'A 50%_ profile', 60, 120, '0.5', [1], false, null)['id'];
        $this->reference(1, 0, 3);
        $this->reference(1, 0, 3);
        $this->reference(1, 5, 3);
        $this->reference(1, 6, 3);
        $this->reference($other, 0, 4);
        $page = $this->store->list(DataSourceProfileFilters::fromQuery([], 25));
        self::assertSame([[$other, 0, 1], [1, 2, 1]], array_map(static fn($profile): array => [$profile->id, $profile->dataSources, $profile->templates], $page->profiles));
        self::assertSame([1], array_map(static fn($profile): int => $profile->id, $this->store->list(DataSourceProfileFilters::fromQuery(['has_data' => 'true'], 25))->profiles));
        self::assertSame([$other], array_map(static fn($profile): int => $profile->id, $this->store->list(DataSourceProfileFilters::fromQuery(['filter' => '50%_'], 25))->profiles));
        self::assertSame([], $this->store->list(DataSourceProfileFilters::fromQuery(['filter' => '%x'], 25))->profiles);
        $sorted = $this->store->list(DataSourceProfileFilters::fromQuery(['sort_column' => 'data_sources', 'sort_direction' => 'DESC', 'rows' => '1', 'page' => '2'], 25));
        self::assertSame([2, [$other]], [$sorted->total, array_map(static fn($profile): int => $profile->id, $sorted->profiles)]);
    }

    public function testAccessNeedsTheProfilesRealmAndRechecksBothRealmsForWrites(): void
    {
        foreach ([
            'CREATE TABLE user_auth (id INT PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)',
            'CREATE TABLE user_auth_realm (user_id INT, realm_id INT)',
            'CREATE TABLE user_auth_group (id INT, enabled TEXT)',
            'CREATE TABLE user_auth_group_members (group_id INT, user_id INT)',
            'CREATE TABLE user_auth_group_realm (group_id INT, realm_id INT)',
            "INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', 'guest')",
            "INSERT INTO user_auth VALUES (9, 'operator', 'on', '', ''), (10, 'guest', 'on', '', '')",
            'INSERT INTO user_auth_realm VALUES (9, 8), (10, 8), (10, 9)',
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $actor = new Actor(9, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(static function () use (&$actor): Actor {
            return $actor;
        });
        $access = new DbalDataSourceProfileAccess($console, $this->database);
        $denied = static function (callable $check): bool {
            try {
                $check();
            } catch (DataSourceProfileAccessDenied) {
                return true;
            }
            return false;
        };
        self::assertTrue($denied(static fn() => $access->authorize()));
        $this->database->executeStatement("INSERT INTO user_auth_group VALUES (3, 'on'); INSERT INTO user_auth_group_members VALUES (3, 9); INSERT INTO user_auth_group_realm VALUES (3, 9)");
        self::assertSame(9, $access->authorize()->id);

        $this->database->beginTransaction();
        $access->assertCurrent(9);
        self::assertTrue($denied(static fn() => $access->assertCurrent(10)));
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id = 9 AND realm_id = 8');
        self::assertTrue($denied(static fn() => $access->assertCurrent(9)));
        $this->database->rollBack();

        $actor = new Actor(10, 'guest');
        self::assertTrue($denied(static fn() => $access->authorize()));
        $this->expectException(\LogicException::class);
        $access->assertCurrent(9);
    }

    private function archive(int $profileId, int $archiveId): DataSourceProfileRra
    {
        $archive = array_find($this->store->find($profileId)->archives, static fn(DataSourceProfileRra $candidate): bool => $candidate->id === $archiveId);
        self::assertNotNull($archive);
        return $archive;
    }

    private function reference(int $profileId, int $localDataId, int $templateId): int
    {
        $this->database->insert('data_template_data', ['local_data_id' => $localDataId, 'data_template_id' => $templateId, 'data_source_profile_id' => $profileId]);
        return (int) $this->database->lastInsertId();
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function revisions(array $ids): array
    {
        return array_map(static fn($profile): string => $profile->revision, $this->store->findMany($ids));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(): array
    {
        $rows = [];
        foreach (['data_source_profiles', 'data_source_profiles_rra', 'data_source_profiles_cf', 'data_template_rrd'] as $table) {
            $rows[$table] = $this->database->fetchAllAssociative("SELECT * FROM $table ORDER BY 1, 2");
        }
        return $rows;
    }
}
