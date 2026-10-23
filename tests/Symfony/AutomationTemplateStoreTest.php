<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\AutomationTemplateFilters;
use Kadupul\Automation\Infrastructure\Persistence\DbalAutomationTemplatePreferences;
use Kadupul\Automation\Infrastructure\Persistence\DbalAutomationTemplateStore;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class AutomationTemplateStoreTest extends TestCase
{
    private Connection $database;
    private DbalAutomationTemplateStore $store;
    private bool $revoked = false;
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    /** @var list<AuditEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            "CREATE TABLE automation_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, host_template INT NOT NULL DEFAULT 0, availability_method INT DEFAULT 2,
                sysDescr TEXT DEFAULT '', sysName TEXT DEFAULT '', sysOid TEXT DEFAULT '', sequence INT DEFAULT 0)",
            'CREATE TABLE host_template (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            "INSERT INTO host_template VALUES (3, 'Linux <b>'), (4, 'Switch'), (5, 'Switch')",
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
        $this->store = new DbalAutomationTemplateStore($this->database, $access, $audit, $configuration);
    }

    public function testCreateKeepsTheLegacyStorageAndEditsRefuseStaleForms(): void
    {
        $id = $this->store->save(7, null, 3, 0, '<b>Linux</b> & "x"', 'core-*', '1.3.6.1.4.1.8072', null);
        $row = $this->database->fetchAssociative('SELECT * FROM automation_templates WHERE id = ?', [$id]);
        // Only the description is HTML-encoded, as the legacy form stored it; it is the text discovery matches.
        self::assertSame(
            ['3', '0', '&lt;b&gt;Linux&lt;/b&gt; &amp; &quot;x&quot;', 'core-*', '1.3.6.1.4.1.8072', '0'],
            array_map('strval', [$row['host_template'], $row['availability_method'], $row['sysDescr'], $row['sysName'], $row['sysOid'], $row['sequence']])
        );
        $template = $this->store->find($id);
        self::assertSame('Linux <b>', $template->deviceTemplate);

        // The form shows the stored text; saving it unchanged must not encode it again.
        $this->store->save(7, $id, 4, 2, $template->sysDescr, 'edge', '', $template->revision);
        self::assertSame('&lt;b&gt;Linux&lt;/b&gt; &amp; &quot;x&quot;', $this->database->fetchOne('SELECT sysDescr FROM automation_templates'));
        foreach ([$template->revision, null, ''] as $stale) {
            try {
                $this->store->save(7, $id, 3, 2, 'stale', '', '', $stale);
                self::fail('A stale automation template edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        self::assertSame(['4', 'edge'], array_map('strval', array_values($this->database->fetchAssociative('SELECT host_template, sysName FROM automation_templates'))));
        try {
            $this->store->save(7, 99, 3, 2, '', '', '', $template->revision);
            self::fail('A missing automation template was edited.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Automation template not found.', $error->getMessage());
        }
        self::assertSame([true, true], array_slice($this->checkedInTransaction, 0, 2));
        self::assertSame(['automation.template.create', AuditEvent::SUCCEEDED], [$this->audit[0]->action, $this->audit[0]->outcome]);
        self::assertSame('automation.template.edit', $this->audit[1]->action);
    }

    public function testInvalidInputIsRefusedBeforeAnyWrite(): void
    {
        foreach ([
            'unknown device template' => [999, 2, '', '', ''],
            'device template zero' => [0, 2, '', '', ''],
            'availability method' => [3, 7, '', '', ''],
            'negative availability method' => [3, -1, '', '', ''],
            'description over 255 once encoded' => [3, 2, str_repeat('&', 52), '', ''],
            'description over 255' => [3, 2, str_repeat('d', 256), '', ''],
            'name over 255' => [3, 2, '', str_repeat('n', 256), ''],
            'OID over 60' => [3, 2, '', '', str_repeat('1', 61)],
            'description with a newline' => [3, 2, "Linux\nx", '', ''],
            'name with NUL' => [3, 2, '', "core\0", ''],
            'invalid UTF-8' => [3, 2, "\xff", '', ''],
        ] as $case => [$hostTemplate, $availability, $sysDescr, $sysName, $sysOid]) {
            try {
                $this->store->save(7, null, $hostTemplate, $availability, $sysDescr, $sysName, $sysOid, null);
                self::fail('Accepted ' . $case);
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_templates'));
        // Boundary values are accepted: 51 ampersands encode to exactly 255 characters.
        $this->store->save(7, null, 3, 6, str_repeat('&', 51), str_repeat('n', 255), str_repeat('1', 60), null);
        self::assertSame(255, mb_strlen((string) $this->database->fetchOne('SELECT sysDescr FROM automation_templates')));
    }

    public function testMovesSwapNeighboursRenumberDenselyAndRefuseBoundariesAndStaleOrders(): void
    {
        // Legacy left new templates at sequence 0, so ties and gaps exist in real tables.
        foreach ([[10, 7], [11, 0], [12, 0], [13, 3]] as [$id, $sequence]) {
            $this->database->insert('automation_templates', ['id' => $id, 'host_template' => 3, 'sequence' => $sequence]);
        }
        $page = $this->store->list(AutomationTemplateFilters::fromQuery([], 25));
        self::assertSame([11, 12, 13, 10], array_map(static fn($template): int => $template->id, $page->templates));
        self::assertSame([11, 10], [$page->first, $page->last]);

        $this->store->move(7, 12, 1, $page->order);
        self::assertSame([[11, 1], [13, 2], [12, 3], [10, 4]], $this->sequences());
        try {
            $this->store->move(7, 13, -1, $page->order);
            self::fail('A move against a stale order was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }
        $order = $this->store->list(AutomationTemplateFilters::fromQuery([], 25))->order;
        foreach ([[11, -1], [10, 1], [99, 1], [13, 2], [13, 0]] as [$id, $offset]) {
            try {
                $this->store->move(7, $id, $offset, $order);
                self::fail("Moved $id by $offset.");
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Invalid automation template order.', $error->getMessage());
            }
        }
        $this->store->move(7, 10, -1, $order);
        self::assertSame([[11, 1], [13, 2], [10, 3], [12, 4]], $this->sequences());
        $this->store->move(7, 13, -1, $this->store->list(AutomationTemplateFilters::fromQuery([], 25))->order);
        self::assertSame([[13, 1], [11, 2], [10, 3], [12, 4]], $this->sequences());
        self::assertSame('automation.template.move', end($this->audit)->action);
    }

    public function testDeleteChecksEveryRevisionAndRefusesMissingTemplates(): void
    {
        $first = $this->store->save(7, null, 3, 2, 'one', '', '', null);
        $second = $this->store->save(7, null, 4, 2, 'two', '', '', null);
        $third = $this->store->save(7, null, 5, 2, 'three', '', '', null);
        $listed = $this->store->findMany([$first, $second, $third, 99]);
        self::assertSame([$first, $second, $third], array_keys($listed));
        $revisions = array_map(static fn($template): string => $template->revision, $listed);

        $this->revoked = true;
        try {
            $this->store->delete(7, [$first], [$first => $revisions[$first]]);
            self::fail('A revoked actor deleted a template.');
        } catch (AutomationTemplateAccessDenied) {
        }
        $this->revoked = false;
        foreach ([
            [[$first, $second], [$first => $revisions[$first], $second => 'stale'], 'changed since'],
            [[$first, 99], [$first => $revisions[$first], 99 => $revisions[$first]], 'not found'],
            [[$first], [], 'changed since'],
        ] as [$ids, $expected, $message]) {
            try {
                $this->store->delete(7, $ids, $expected);
                self::fail('Deleted ' . json_encode($ids));
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertSame(3, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_templates'));
        foreach ([[], [$first, $first], [$second, $first], [0], range(1, 101)] as $invalid) {
            try {
                $this->store->delete(7, $invalid, array_fill_keys($invalid, 'x'));
                self::fail('An invalid selection was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->checkedInTransaction = [];
        $this->store->delete(7, [$first, $third], [$first => $revisions[$first], $third => $revisions[$third]]);
        self::assertSame([true], $this->checkedInTransaction);
        self::assertSame([$second], array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM automation_templates')));
        self::assertSame(['automation.template.delete', AuditEvent::SUCCEEDED], [end($this->audit)->action, end($this->audit)->outcome]);
    }

    public function testListSearchesNameAndMatchesWithLiteralWildcards(): void
    {
        $this->store->save(7, null, 3, 2, 'Linux 5%', '', '', null);
        $this->store->save(7, null, 4, 3, 'Cisco IOS', 'core_1', '', null);
        $this->store->save(7, null, 5, 0, '', '', '1.3.6.1.4.1.9', null);
        $this->database->insert('automation_templates', ['host_template' => 42, 'sysDescr' => 'orphan', 'sequence' => 9]);

        $names = fn(array $query): array => array_map(static fn($template): ?string => $template->deviceTemplate, $this->store->list(AutomationTemplateFilters::fromQuery($query, 25))->templates);
        self::assertSame(['Linux <b>', 'Switch', 'Switch', null], $names([]));
        self::assertSame(['Linux <b>'], $names(['filter' => 'linux']));
        self::assertSame(['Linux <b>'], $names(['filter' => '%']));
        self::assertSame(['Switch'], $names(['filter' => 'core_']));
        self::assertSame([], $names(['filter' => 'e%1']));
        self::assertSame(['Switch'], $names(['filter' => '.4.1.9']));
        self::assertSame(['Switch', 'Switch'], $names(['filter' => 'switch']));
        $paged = $this->store->list(AutomationTemplateFilters::fromQuery(['rows' => '1', 'page' => '2'], 25));
        self::assertSame(4, $paged->total);
        self::assertSame('Cisco IOS', $paged->templates[0]->sysDescr);
        self::assertTrue($paged->hasPrevious() && $paged->hasNext());
        self::assertSame([3, 4, 5], array_keys($this->store->deviceTemplates()));
    }

    public function testDefaultsComeFromSettingsWithinRange(): void
    {
        self::assertSame([25, 2], [$this->store->defaultRows(), $this->store->defaultAvailability()]);
        $this->database->executeStatement("INSERT INTO settings VALUES ('num_rows_table', '50'), ('availability_method', '3')");
        self::assertSame([50, 3], [$this->store->defaultRows(), $this->store->defaultAvailability()]);
        $this->database->executeStatement("UPDATE settings SET value = '9'");
        self::assertSame(2, $this->store->defaultAvailability());
    }

    public function testRegexCheckRefusesOnlyPatternsTheDatabaseCannotCompile(): void
    {
        $issued = [];
        $database = $this->createMock(Connection::class);
        $database->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $database->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use (&$issued): mixed {
            $issued[] = [$sql, $params];
            throw match ($params[0]) {
                '(' => self::driverError(1139),
                '[z-a]' => self::driverError(3697),
                default => self::driverError(2006),
            };
        });
        $store = new DbalAutomationTemplateStore($database, $this->createMock(AutomationTemplateAccess::class), $this->createMock(AuditTrail::class), $this->createMock(LegacyConfiguration::class));
        $compiles = new \ReflectionMethod($store, 'assertCompiles');
        $compiles->invoke($store, '', 'unused');
        self::assertSame([], $issued);
        foreach (['(', '[z-a]'] as $pattern) {
            try {
                $compiles->invoke($store, $pattern, 'bad pattern');
                self::fail('An uncompilable pattern was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('bad pattern', $error->getMessage());
            }
        }
        // A lost connection is not the user's mistake and must stay uncertain.
        try {
            $compiles->invoke($store, 'Linux', 'bad pattern');
            self::fail('A connection failure was reported as a bad pattern.');
        } catch (DriverException $error) {
            self::assertSame(2006, $error->getCode());
        }
        self::assertSame(["SELECT '' REGEXP CONCAT('/', ?, '/')", ['(']], $issued[0]);
    }

    public function testStoredFiltersOutOfRangeAreIgnoredInsteadOfBreakingTheList(): void
    {
        $this->database->executeStatement('CREATE TABLE settings_user (user_id INT, name TEXT, value TEXT, PRIMARY KEY (user_id, name))');
        $access = $this->createMock(AutomationTemplateAccess::class);
        $access->method('authorize')->willReturn(new Actor(7, 'operator'));
        $preferences = new DbalAutomationTemplatePreferences($access, $this->database, $this->createMock(LegacyConfiguration::class));
        foreach ([['rows' => '0'], ['rows' => '9000'], ['page' => '0'], ['sort_column' => 'id'], ['filter' => str_repeat('x', 201)], ['filter' => ['x']]] as $stored) {
            $this->database->executeStatement('REPLACE INTO settings_user (user_id, name, value) VALUES (7, ?, ?)', ['automation_template_filters', json_encode($stored)]);
            self::assertNull($preferences->load(), json_encode($stored));
        }
        $valid = ['filter' => 'linux', 'rows' => '50', 'page' => '2'];
        $this->database->executeStatement('REPLACE INTO settings_user (user_id, name, value) VALUES (7, ?, ?)', ['automation_template_filters', json_encode($valid)]);
        self::assertSame($valid, $preferences->load());
    }

    /** @return list<array{int, int}> */
    private function sequences(): array
    {
        return array_map(static fn(array $row): array => [(int) $row['id'], (int) $row['sequence']], $this->database->fetchAllAssociative('SELECT id, sequence FROM automation_templates ORDER BY sequence, id'));
    }

    private static function driverError(int $code): DriverException
    {
        $native = new \PDOException('fixture');
        $native->errorInfo = ['HY000', $code, 'fixture'];
        return new DriverException(\Doctrine\DBAL\Driver\PDO\Exception::new($native), null);
    }
}
