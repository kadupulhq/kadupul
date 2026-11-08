<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Infrastructure\Persistence\DbalAutomationTemplatePreferences;
use Kadupul\GraphDefinition\Application\Port\CdefAccess;
use Kadupul\GraphDefinition\Infrastructure\Persistence\DbalCdefPreferences;
use Kadupul\Graphing\Application\Port\DataSourceProfileAccess;
use Kadupul\Graphing\Infrastructure\Persistence\DbalDataSourceProfilePreferences;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Persistence\DbalJsonFilterPreferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilterPreferencesPersistenceTest extends TestCase
{
    private Connection $database;
    private bool $denied = false;
    private bool $revoked = false;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement('CREATE TABLE settings_user (user_id INT, name TEXT, value TEXT, PRIMARY KEY (user_id, name))');
    }

    /** @return iterable<string,array{int,string}> */
    public static function features(): iterable
    {
        yield 'automation' => [0, 'automation_template_filters'];
        yield 'CDEF' => [1, 'cdef_filters'];
        yield 'profile' => [2, 'data_source_profiles_filters'];
    }

    #[DataProvider('features')]
    public function testActualPersistenceKeepsFeatureKeysAndLoadSaveValidationAsymmetry(int $feature, string $name): void
    {
        $preferences = $this->preferences($feature);
        self::assertNull($preferences->load());
        $this->database->insert('settings_user', ['user_id' => 8, 'name' => $name, 'value' => 'other actor']);
        $valid = ['filter' => 'réseau', 'rows' => '50', 'page' => '2'];
        $preferences->save($valid);
        self::assertSame($valid, $preferences->load());
        self::assertSame(json_encode($valid, JSON_UNESCAPED_UNICODE), $this->stored($name));
        self::assertSame('other actor', $this->database->fetchOne('SELECT value FROM settings_user WHERE user_id = 8'));
        $rowId = $this->database->fetchOne('SELECT rowid FROM settings_user WHERE user_id = 7 AND name = ?', [$name]);
        $this->events = [];
        $preferences->save($valid);
        self::assertNotSame($rowId, $this->database->fetchOne('SELECT rowid FROM settings_user WHERE user_id = 7 AND name = ?', [$name]));
        self::assertSame(['authorize:transaction', 'current:7:transaction'], $this->events);
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM settings_user'));
        // Save accepts string-shaped values; the list's domain parser rejects stale ranges on load.
        $preferences->save(['rows' => '9000']);
        self::assertSame('{"rows":"9000"}', $this->stored($name));
        self::assertNull($preferences->load());
        foreach (['broken', 'null', '{"unknown":"x"}', '{"filter":[]}', '{"filter":1}'] as $json) {
            $this->database->executeStatement('UPDATE settings_user SET value = ? WHERE user_id = 7 AND name = ?', [$json, $name]);
            self::assertNull($preferences->load());
        }
    }

    #[DataProvider('features')]
    public function testEmptyPreferencesRemainRawInsteadOfReceivingDomainDefaults(int $feature, string $name): void
    {
        $preferences = $this->preferences($feature);
        $preferences->save([]);
        self::assertSame('[]', $this->stored($name));
        self::assertSame([], $preferences->load());
        self::assertSame(['authorize:transaction', 'current:7:transaction', 'authorize:outside'], $this->events);
    }

    public function testStoredValidationReturnsRawValuesAndOnlyTreatsDomainRejectionAsStale(): void
    {
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->expects(self::never())->method('values');
        $preferences = new DbalJsonFilterPreferences($this->database, $configuration);
        $this->database->insert('settings_user', ['user_id' => 7, 'name' => 'protocol', 'value' => '{"filter":"0"}']);
        $received = [];
        $raw = $preferences->load(7, 'protocol', ['filter'], static function (array $filters) use (&$received): array {
            $received = $filters;
            return ['filter' => 'normalized', 'rows' => '1'];
        });
        self::assertSame(['filter' => '0'], $received);
        self::assertSame($received, $raw);
        self::assertNull($preferences->load(7, 'protocol', ['filter'], static function (array $filters): never {
            throw new \InvalidArgumentException('stored domain value is stale');
        }));
        try {
            $preferences->load(7, 'protocol', ['filter'], static function (array $filters): never {
                throw new \RuntimeException('domain dependency unavailable');
            });
            self::fail('Unexpected validator failure was hidden as an empty preference.');
        } catch (\RuntimeException $error) {
            self::assertSame('domain dependency unavailable', $error->getMessage());
        }
        $called = false;
        try {
            $preferences->save('protocol', ['filter' => []], ['filter'], 'feature shape rejected', static function () use (&$called): int {
                $called = true;
                return 7;
            });
            self::fail('Malformed map was admitted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('feature shape rejected', $error->getMessage());
            self::assertFalse($called);
            self::assertFalse($this->database->isTransactionActive());
            self::assertSame('{"filter":"0"}', $this->stored('protocol'));
        }
    }

    #[DataProvider('features')]
    public function testReadAuthorizationPrecedesProtectedLookup(int $feature, string $name): void
    {
        $preferences = $this->preferences($feature);
        $this->database->executeStatement('DROP TABLE settings_user');
        $this->denied = true;
        try {
            $preferences->load();
            self::fail('Denied read was admitted.');
        } catch (\RuntimeException $error) {
            self::assertSame('denied', $error->getMessage());
            self::assertSame(['authorize:outside'], $this->events);
        }
    }

    #[DataProvider('features')]
    public function testValidationCollectorAndCallerTransactionGuardsPrecedeAuthorization(int $feature, string $name): void
    {
        foreach ([['unknown' => 'x'], ['filter' => []]] as $invalid) {
            try {
                $this->preferences($feature)->save($invalid);
                self::fail('Invalid shape was admitted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame(['Invalid automation template filters.', 'Invalid CDEF filters.', 'Invalid profile filters.'][$feature], $error->getMessage());
            }
        }
        foreach ([2, '1', null] as $collector) {
            try {
                $this->preferences($feature, $collector)->save([]);
                self::fail('Non-primary collector was admitted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Filter preferences require the primary collector.', $error->getMessage());
            }
        }
        $this->database->beginTransaction();
        $this->database->insert('settings_user', ['user_id' => 9, 'name' => 'caller', 'value' => 'retained']);
        try {
            $this->preferences($feature)->save([]);
            self::fail('Caller transaction was admitted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Filter preference transaction unavailable.', $error->getMessage());
            self::assertTrue($this->database->isTransactionActive());
            self::assertSame('retained', $this->database->fetchOne('SELECT value FROM settings_user WHERE user_id = 9'));
        } finally {
            $this->database->rollBack();
        }
        self::assertSame([], $this->events);
    }

    #[DataProvider('features')]
    public function testDeniedRevokedAndActualWriteFailureRollbackWithoutPublishingPreferences(int $feature, string $name): void
    {
        $preferences = $this->preferences($feature);
        $preferences->save(['filter' => 'original']);
        foreach (['denied', 'revoked', 'encoding', 'write'] as $failure) {
            $this->events = [];
            $this->denied = $failure === 'denied';
            $this->revoked = $failure === 'revoked';
            if ($failure === 'write') $this->database->executeStatement("CREATE TRIGGER refuse_preferences BEFORE INSERT ON settings_user BEGIN SELECT RAISE(ABORT, 'write refused'); END");
            try {
                $preferences->save(['filter' => $failure === 'encoding' ? "\xff" : 'changed']);
                self::fail('Failed save was reported as successful.');
            } catch (\Throwable $error) {
                self::assertFalse($error instanceof \PHPUnit\Framework\AssertionFailedError);
                self::assertFalse($this->database->isTransactionActive());
                self::assertSame('{"filter":"original"}', $this->stored($name));
                self::assertSame($failure === 'denied' ? ['authorize:transaction'] : ['authorize:transaction', 'current:7:transaction'], $this->events);
            }
        }
    }

    public function testContainerUsesTheSameWebConnectionAndConfigurationForAllFeaturePreferences(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $container->set('doctrine.dbal.web_connection', $this->database);
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $container->set(LegacyConfiguration::class, $configuration);
            foreach ([AutomationTemplateAccess::class, CdefAccess::class, DataSourceProfileAccess::class] as $accessClass) {
                $access = $this->createMock($accessClass);
                $access->method('authorize')->willReturn(new Actor(7, 'operator'));
                $access->expects(self::once())->method('assertCurrent')->with(7);
                $container->set($accessClass, $access);
            }
            foreach ([\Kadupul\Automation\Application\Port\AutomationTemplatePreferences::class,
                \Kadupul\GraphDefinition\Application\Port\CdefPreferences::class,
                \Kadupul\Graphing\Application\Port\DataSourceProfilePreferences::class] as $preferencesClass) {
                $preferences = $container->get($preferencesClass);
                $preferences->save(['filter' => 'wired']);
                self::assertSame(['filter' => 'wired'], $preferences->load());
            }
            self::assertSame(3, (int) $this->database->fetchOne('SELECT COUNT(*) FROM settings_user'));
        } finally {
            $kernel->shutdown();
        }
    }

    private function preferences(int $feature, mixed $collector = 1): DbalAutomationTemplatePreferences|DbalCdefPreferences|DbalDataSourceProfilePreferences
    {
        $class = [AutomationTemplateAccess::class, CdefAccess::class, DataSourceProfileAccess::class][$feature];
        $access = $this->createMock($class);
        $access->method('authorize')->willReturnCallback(function (): Actor {
            $this->events[] = 'authorize:' . ($this->database->isTransactionActive() ? 'transaction' : 'outside');
            if ($this->denied) throw new \RuntimeException('denied');
            return new Actor(7, 'operator');
        });
        $access->method('assertCurrent')->willReturnCallback(function (int $id): void {
            $this->events[] = 'current:' . $id . ':' . ($this->database->isTransactionActive() ? 'transaction' : 'outside');
            if ($this->revoked) throw new \RuntimeException('revoked');
        });
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => $collector]);
        $implementation = [DbalAutomationTemplatePreferences::class, DbalCdefPreferences::class, DbalDataSourceProfilePreferences::class][$feature];
        return new $implementation($access, new DbalJsonFilterPreferences($this->database, $configuration));
    }

    private function stored(string $name): mixed
    {
        return $this->database->fetchOne('SELECT value FROM settings_user WHERE user_id = 7 AND name = ?', [$name]);
    }
}
