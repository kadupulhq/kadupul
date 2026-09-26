<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class LegacyOperatorLogTest extends TestCase
{
    private string $root;
    private Connection $db;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-oplog-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/log', 0700, true);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    private function log(?\Closure $syslog = null, string $os = 'Linux'): LegacyOperatorLog
    {
        return new LegacyOperatorLog($this->root, new Filesystem(), new MockClock('2026-03-04 05:06:07'), $os, $syslog);
    }

    private function set(string $name, string $value): void
    {
        $this->db->executeStatement('REPLACE INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
    }

    public function testMissingDateRowsWriteOneLineInTheLegacyFormat(): void
    {
        $this->log()->record($this->db, 'SYSTEM', "ANALYSIS STATS: done.\n  Total 1");
        $line = file_get_contents($this->root . '/log/cacti.log');
        self::assertSame("03/04/2026 05:06:07 - SYSTEM ANALYSIS STATS: done. Total 1\n", $line);
    }

    /**
     * Each expectation is what include/global.php:528 defined as
     * CACTI_DATE_TIME_FORMAT for these rows in the behavior stack. It runs
     * before global_settings.php, so a missing row reads as null, not as the
     * declared default, and date_time_format() compares loosely.
     */
    #[DataProvider('dateSettings')]
    public function testDateSettingsFollowTheLegacyBootstrap(?string $format, ?string $separator, string $date): void
    {
        if ($format !== null) {
            $this->set('default_date_format', $format);
        }
        if ($separator !== null) {
            $this->set('default_datechar', $separator);
        }
        $this->log()->record($this->db, 'SYSTEM', 'x');
        self::assertSame($date . " 05:06:07 - SYSTEM x\n", file_get_contents($this->root . '/log/cacti.log'));
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function dateSettings(): iterable
    {
        yield 'no rows' => [null, null, '03/04/2026'];
        yield 'declared defaults' => ['4', '0', '2026-03-04'];
        yield 'day first with dots' => ['2', '2', '04.03.2026'];
        yield 'unknown values' => ['abc', 'x', '2026/03/04'];
        yield 'empty values' => ['', '', '2026/03/04'];
        yield 'leading spaces' => [' 1', ' 1', 'Mar/04/2026'];
        yield 'numeric prefix and padded separator' => ['4abc', '01', '2026/03/04'];
        yield 'format row only' => ['5', null, '2026/Mar/04'];
        yield 'separator row only' => [null, '2', '03.04.2026'];
    }

    public function testDateFormatSettingsAreHonoured(): void
    {
        $this->set('default_date_format', '0');
        $this->set('default_datechar', '1');
        $this->log()->record($this->db, 'SYSTEM', 'x');
        self::assertSame("03/04/2026 05:06:07 - SYSTEM x\n", file_get_contents($this->root . '/log/cacti.log'));
    }

    public function testVerbosityNoneWritesNothing(): void
    {
        $this->set('log_verbosity', '1');
        $this->log()->record($this->db, 'SYSTEM', 'x');
        self::assertFileDoesNotExist($this->root . '/log/cacti.log');
    }

    public function testUnwritableLogFileIsIgnored(): void
    {
        $this->set('path_cactilog', $this->root . '/missing/dir/cacti.log');
        $this->log()->record($this->db, 'SYSTEM', 'x');
        self::assertFileDoesNotExist($this->root . '/missing/dir/cacti.log');
    }

    public function testAFailedWriteIsIgnored(): void
    {
        // A directory in place of the file makes the append itself fail, which
        // the missing-directory case above never reaches.
        $this->set('path_cactilog', $this->root . '/log');
        $this->log()->record($this->db, 'SYSTEM', 'x');
        self::assertDirectoryExists($this->root . '/log');
    }

    public function testSyslogReceivesStatsWhenEnabled(): void
    {
        $this->set('log_destination', '3');
        $this->set('log_pstats', 'on');
        $sent = [];
        $this->log(static function (int $facility, int $priority, string $line) use (&$sent): void {
            $sent[] = [$priority, $line];
        })->record($this->db, 'SYSTEM', 'ANALYSIS STATS: done');
        self::assertSame([[LOG_INFO, 'SYSTEM: ANALYSIS STATS: done']], $sent);
        self::assertFileDoesNotExist($this->root . '/log/cacti.log');
    }

    /** @return list<array{int, string}> */
    private function syslogged(string $message): array
    {
        $sent = [];
        $this->log(static function (int $facility, int $priority, string $line) use (&$sent): void {
            $sent[] = [$priority, $line];
        })->record($this->db, 'SYSTEM', $message);

        return $sent;
    }

    #[DataProvider('offGateValues')]
    public function testGatesFollowPhpTruthiness(string $value): void
    {
        $this->set('log_destination', '3');
        foreach (['log_perror', 'log_pwarn', 'log_pstats'] as $gate) {
            $this->set($gate, $value);
        }
        self::assertSame([], $this->syslogged('ERROR: a'));
        self::assertSame([], $this->syslogged('WARNING: a'));
        self::assertSame([], $this->syslogged('STATS: a'));
    }

    /** @return iterable<string, array{string}> */
    public static function offGateValues(): iterable
    {
        yield 'zero' => ['0'];
        yield 'empty' => [''];
    }

    public function testAbsentErrorGateDefaultsToOn(): void
    {
        // include/global_settings.php declares log_perror 'on' and the others ''.
        $this->set('log_destination', '3');
        self::assertSame([[LOG_CRIT, 'SYSTEM: ERROR: a']], $this->syslogged('ERROR: a'));
        self::assertSame([], $this->syslogged('WARNING: a'));
        self::assertSame([], $this->syslogged('STATS: a'));
    }

    public function testAnOffGateOnTheHighestPrecedenceMarkerSendsNothing(): void
    {
        $this->set('log_destination', '3');
        $this->set('log_perror', '');
        $this->set('log_pstats', 'on');
        $sent = [];
        $this->log(static function (int $facility, int $priority, string $line) use (&$sent): void {
            $sent[] = [$priority, $line];
        })->record($this->db, 'SYSTEM', 'ERROR: boom STATS: also');
        self::assertSame([], $sent);
    }

    public function testALowerPrecedenceGateStillFiresWhenItsOwnMarkerLeads(): void
    {
        $this->set('log_destination', '3');
        $this->set('log_pwarn', 'on');
        $sent = [];
        $this->log(static function (int $facility, int $priority, string $line) use (&$sent): void {
            $sent[] = [$priority, $line];
        })->record($this->db, 'SYSTEM', 'WARNING: x STATS: y');
        self::assertSame([[LOG_WARNING, 'SYSTEM: WARNING: x STATS: y']], $sent);
    }

    #[DataProvider('facilities')]
    public function testSyslogFacilityFollowsTheServerOs(string $os, int $facility): void
    {
        $this->set('log_destination', '3');
        $sent = [];
        $this->log(static function (int $facility, int $priority, string $line) use (&$sent): void {
            $sent[] = [$facility, $priority, $line];
        }, $os)->record($this->db, 'SYSTEM', 'ERROR: a');
        self::assertSame([[$facility, LOG_CRIT, 'SYSTEM: ERROR: a']], $sent);
    }

    /** @return iterable<string, array{string, int}> */
    public static function facilities(): iterable
    {
        yield 'windows' => ['WINNT', LOG_USER];
        yield 'linux' => ['Linux', LOG_SYSLOG];
        // global.php matches "WIN" case-sensitively, so Darwin is not Windows.
        yield 'darwin' => ['Darwin', LOG_SYSLOG];
    }
}
