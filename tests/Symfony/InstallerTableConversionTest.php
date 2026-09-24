<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Application\Command\ConvertTables;
use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Application\Command\MaintenanceTarget;
use Kadupul\Platform\Application\Command\SchemaChangeAudit;
use Kadupul\Platform\Application\Command\TableConversionStep;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\TableCatalog;
use Kadupul\Platform\Application\Port\TableConversion;
use Kadupul\Platform\Application\ReadModel\ConversionOutcome;
use Kadupul\Platform\Application\ReadModel\TableOutcome;
use Kadupul\Platform\Application\ReadModel\TableResult;
use Kadupul\Platform\Domain\Schema\ConversionFlag;
use Kadupul\Platform\Domain\Schema\ConversionOptions;
use Kadupul\Platform\Domain\Schema\TableChange;
use Kadupul\Platform\Domain\Schema\TableCharset;
use Kadupul\Platform\Domain\Schema\TableStatus;
use Kadupul\Platform\Infrastructure\Legacy\InstallerTableConversion;
use Kadupul\Platform\Infrastructure\Legacy\InstallerTableResult;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;
use Kadupul\Platform\Infrastructure\Persistence\DbalTableConversion;
use Kadupul\Platform\Infrastructure\Persistence\MaintenanceConnections;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class InstallerTableConversionTest extends TestCase
{
    use RealMariaDb;

    private const string HEADER = "NOTE: Repairing Tables for Local Database\nConverting Database Tables to InnoDB and  utf8 with less than '1000000' Records\n";

    /** A local database with a MyISAM "host", a converted "settings" and a "big" table over the row limit. */
    private function conversion(bool $ok = true, bool $innodb = true, bool $filePerTable = true): TableConversion&MockObject
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->method('tableStatuses')->with(DatabaseTarget::Local)->willReturn(new TableCatalog([
            'host' => new TableStatus('MyISAM', 'latin1_swedish_ci', 'Compact', 2),
            'settings' => new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 40),
            'big' => new TableStatus('MyISAM', 'latin1_swedish_ci', 'Fixed', 1000000),
        ]));
        $conversion->method('innodbEnabled')->willReturn($innodb);
        $conversion->method('filePerTable')->willReturn($filePerTable);
        $conversion->method('statement')->willReturn('ALTER TABLE `host`');
        $conversion->method('convert')->willReturn($ok);

        return $conversion;
    }

    private function adapter(TableConversion $conversion): InstallerTableConversion
    {
        return new InstallerTableConversion($conversion, new TableConversionStep($conversion));
    }

    public function testItConvertsATableWithNoOperatorPresent(): void
    {
        $conversion = $this->conversion();
        // The installer's own flags: --utf8 --innodb --dynamic, on the local database.
        $conversion->expects(self::once())->method('convert')
            ->with(DatabaseTarget::Local, 'host', new TableChange(true, TableCharset::Utf8mb4, true))
            ->willReturn(true);
        $result = $this->adapter($conversion)->convert('host');
        self::assertTrue($result->dequeues());
        self::assertSame(self::HEADER . "Converting Table > 'host' Successful\n", $result->output);
        // Nothing that could name or check an operator, or audit one, is wired in.
        $parameters = array_map(
            static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            (new \ReflectionMethod(InstallerTableConversion::class, '__construct'))->getParameters(),
        );
        self::assertSame([TableConversion::class, TableConversionStep::class], $parameters);
    }

    public function testTheCliPathStillRefusesWithoutAnOperator(): void
    {
        // An installation with no admin_user and no accounts: nobody can be the operator.
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)',
            'CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)',
            'CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)',
            'CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)',
            'CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)',
        ] as $statement) {
            $db->executeStatement($statement);
        }
        $conversion = $this->createMock(TableConversion::class);
        $conversion->expects(self::never())->method(self::anything());
        $convert = new ConvertTables(new MaintenanceTarget(new CliConsoleAccess($db, $db), $this->createStub(DatabaseMaintenance::class)), $conversion, new TableConversionStep($conversion), new SchemaChangeAudit($this->createStub(AuditTrail::class)));
        $this->expectException(InstallationAccessDenied::class);
        $convert(new ConversionOptions([ConversionFlag::Utf8, ConversionFlag::Innodb, ConversionFlag::Dynamic], 'host', [], '1000000'), false, null, true);
    }

    /**
     * lib/installer.php cleared a queued table only when the script printed
     * "Converting table" and "Successful", or "Skipped table". The script never
     * printed the last one, so only a converted table was ever cleared.
     *
     * @return iterable<string, array{string, bool, bool, string}>
     */
    public static function tables(): iterable
    {
        yield 'converted' => ['host', true, true, "Converting Table > 'host' Successful\n"];
        yield 'failed' => ['host', false, false, "Converting Table > 'host' Failed\n"];
        yield 'skipped' => ['settings', true, false, "Skipping Table > 'settings'\n"];
        yield 'too large' => ['big', true, false, "Skipping Table > 'big' too many rows '1000000'\n"];
        yield 'not listed' => ['Host', true, false, "Converting Table > 'Host' Failed\n"];
    }

    #[DataProvider('tables')]
    public function testEachTableResultMapsToTheInstallersDecision(string $table, bool $ok, bool $dequeues, string $line): void
    {
        $result = $this->adapter($this->conversion($ok))->convert($table);
        self::assertSame($dequeues, $result->dequeues());
        self::assertSame(self::HEADER . $line, $result->output);
    }

    public function testEveryTableResultIsMapped(): void
    {
        $options = new ConversionOptions([ConversionFlag::Utf8, ConversionFlag::Innodb, ConversionFlag::Dynamic], 'host', [], '1000000');
        foreach (TableResult::cases() as $case) {
            $outcome = new TableOutcome('host', $case, 2, null, true);
            if ($case === TableResult::Planned) {
                // The installer always applies; a plan reaching it is a bug, and
                // InstallerTableConversion::run() turns it into a kept table.
                try {
                    InstallerTableResult::table($outcome, $options);
                    self::fail('A planned table must not reach the installer.');
                } catch (\LogicException) {
                    continue;
                }
            }
            self::assertSame($case === TableResult::Converted, InstallerTableResult::table($outcome, $options)->dequeues(), $case->value);
        }
    }

    public function testWhatStopsInnodbWorkKeepsTheTableQueued(): void
    {
        $conversion = $this->conversion(true, false);
        $conversion->expects(self::never())->method('convert');
        $result = $this->adapter($conversion)->convert('host');
        self::assertFalse($result->dequeues());
        self::assertSame(self::HEADER . "InnoDB Engine is not enabled\n", $result->output);
        // The original printed this refusal without a newline, and the log line kept that.
        $result = $this->adapter($this->conversion(true, true, false))->convert('host');
        self::assertFalse($result->dequeues());
        self::assertSame(self::HEADER . 'innodb_file_per_table not enabled', $result->output);
        $this->expectException(\LogicException::class);
        InstallerTableResult::stopped(ConversionOutcome::Completed, new ConversionOptions([ConversionFlag::Utf8], null, [], '1000000'));
    }

    public function testAFailureKeepsTheTableQueuedAndNamesOnlyTheExceptionClass(): void
    {
        $result = InstallerTableResult::failed(new \RuntimeException("SQLSTATE[28000] Access denied for user 'cacti'@'db.internal'"));
        self::assertFalse($result->dequeues());
        self::assertSame("ERROR: Table conversion failed\n", $result->output);
        // lib/installer.php logs this at normal verbosity, so it must never carry the message.
        self::assertSame(\RuntimeException::class, $result->error);
        self::assertNull($this->adapter($this->conversion())->convert('host')->error);
    }

    public function testRunFailsClosedWhenTheInstallationCannotBeRead(): void
    {
        if (is_file(dirname(__DIR__, 2) . '/include/config.php')) {
            self::markTestSkipped('This checkout has an include/config.php, so run() would reach a real database.');
        }
        // With no include/config.php the local connection cannot be configured.
        $result = InstallerTableConversion::run('host');
        self::assertFalse($result->dequeues());
        self::assertSame("ERROR: Table conversion failed\n", $result->output);
        self::assertSame(\RuntimeException::class, $result->error);
    }

    public function testTheProductionContainerExposesTheAdapterToTheInstaller(): void
    {
        $kernel = new Kernel('prod', false);
        try {
            $kernel->boot();
            self::assertInstanceOf(InstallerTableConversion::class, $kernel->getContainer()->get(InstallerTableConversion::class));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAgainstARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        $root = sys_get_temp_dir() . '/kadupul-installer-convert-' . bin2hex(random_bytes(8));
        $db->executeStatement('DROP TABLE IF EXISTS kadupul_installer_probe');
        $db->executeStatement("CREATE TABLE kadupul_installer_probe (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MyISAM DEFAULT CHARSET=latin1 ROW_FORMAT=COMPACT");
        try {
            $conversion = new DbalTableConversion($root, new Filesystem(), new MaintenanceConnections($db, $db, new LegacyOperatorLog($root, new Filesystem(), new MockClock())));
            $result = $this->adapter($conversion)->convert('kadupul_installer_probe');
            self::assertSame(self::HEADER . "Converting Table > 'kadupul_installer_probe' Successful\n", $result->output);
            self::assertTrue($result->dequeues());
            $status = $conversion->tableStatuses(DatabaseTarget::Local)->status('kadupul_installer_probe');
            self::assertSame(['InnoDB', 'utf8mb4_unicode_ci', 'Dynamic'], [$status?->engine, $status?->collation, $status?->rowFormat]);
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS kadupul_installer_probe');
            (new Filesystem())->remove($root);
        }
    }
}
