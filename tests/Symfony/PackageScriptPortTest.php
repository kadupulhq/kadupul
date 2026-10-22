<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The PHP ports of the bundled Perl and Python data input scripts must print
 * what the originals printed. The Perl scripts are the oracle: each runs with
 * only its command replaced by `cat` of the same captured output.
 */
final class PackageScriptPortTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/package-scripts/';

    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        foreach (['ping', 'unix_processes', 'unix_users', 'loadavg_multi', 'linux_memory', 'query_unix_partitions', 'motorola_surfboard_SB6141'] as $script) {
            require_once self::$root . '/scripts/' . $script . '.php';
        }

        // The collector only bootstraps Kadupul when the script server has not; its file-level error_reporting(0) must not leak into the suite.
        $level = error_reporting();
        $called_by_script_server = true;
        require_once self::$root . '/scripts/ss_apache_stats.php';
        error_reporting($level);
    }

    private static function fixture(string $name): string
    {
        $contents = file_get_contents(self::FIXTURES . $name);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * Runs a Perl script from scripts/ with each command replaced by `cat` of a fixture.
     *
     * @param array<string, string> $commands Perl source text => fixture name
     * @param list<string>          $arguments
     */
    private static function perl(string $script, array $commands, array $arguments = []): string
    {
        if (!is_executable('/usr/bin/perl')) {
            self::markTestSkipped('The Perl oracle needs /usr/bin/perl.');
        }
        $source = file_get_contents(self::$root . '/scripts/' . $script);
        self::assertIsString($source);
        foreach ($commands as $command => $fixture) {
            self::assertStringContainsString($command, $source, 'The oracle must replace a command the script really runs.');
            $source = str_replace($command, 'cat ' . realpath(self::FIXTURES . $fixture), $source);
        }
        $copy = tempnam(sys_get_temp_dir(), 'kadupul-perl-oracle');
        self::assertIsString($copy);
        try {
            file_put_contents($copy, $source);
            $process = proc_open(['/usr/bin/perl', $copy, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            unlink($copy);
        }
        self::assertIsString($output);

        return $output;
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function pingCases(): array
    {
        return [
            'IPv4' => ['127.0.0.1', 'ping-linux-ipv4.txt', null],
            'transport prefix' => ['tcp:127.0.0.1', 'ping-linux-ipv4.txt', null],
            'microseconds' => ['192.0.2.1', 'ping-linux-usec.txt', null],
            'unknown host falls back to ping6' => ['nonexist.invalid', 'ping-linux-unknown-host.txt', 'ping-linux-ipv6.txt'],
            'unknown host without a ping6 reply' => ['nonexist.invalid', 'ping-linux-unknown-host.txt', 'ping-linux-unknown-host.txt'],
        ];
    }

    #[DataProvider('pingCases')]
    public function testPingMatchesPerl(string $host, string $ping, ?string $ping6): void
    {
        $hasPing6 = is_file('/bin/ping6') || is_file('/sbin/ping6');
        $commands = ['$pingcmd 2>&1' => $ping];
        if ($ping6 !== null) {
            $commands['ping6 -c 1 $host 2>&1'] = $ping6;
        }
        $expected = self::perl('ping.pl', $commands, [$host]);

        $run = static fn(array $command): string => self::fixture($command[0] === 'ping6' ? (string) $ping6 : $ping);
        self::assertSame($expected, ping_measure($host, $run, $hasPing6));
    }

    /** @return array<string, array{string}> */
    public static function refusedHosts(): array
    {
        // ping.pl ran `ping -c 1` with no host for these, which prints no time, so U.
        return ['space' => ['a b'], 'shell metacharacter' => ['host;id'], 'reads as an option' => ['-fc100000']];
    }

    #[DataProvider('refusedHosts')]
    public function testPingRefusesHostsOutsideTheAllowList(string $host): void
    {
        $ran = [];
        $result = ping_measure($host, static function (array $command) use (&$ran): string {
            $ran[] = $command;

            return self::fixture('ping-linux-ipv4.txt');
        }, true);

        self::assertSame('U', $result);
        self::assertSame([], $ran);
        self::assertSame(['ping', '-c', '1', 'host.example'], ping_command('host.example', 'Linux'));
    }

    public function testProcessCountMatchesPerl(): void
    {
        self::assertSame(self::perl('unix_processes.pl', ['ps ax' => 'ps-linux.txt']), (string) unix_processes_count(self::fixture('ps-linux.txt')));
    }

    /** @return array<string, array{string, string}> */
    public static function userCases(): array
    {
        return [
            'every session, Linux' => ['', 'who-linux.txt'],
            'one user, Linux' => ['operator', 'who-linux.txt'],
            'argument outside the allow-list counts everyone' => ['op;rm', 'who-linux.txt'],
            'one user, macOS' => ['operator', 'who-macos.txt'],
        ];
    }

    #[DataProvider('userCases')]
    public function testUserCountMatchesPerl(string $user, string $who): void
    {
        $expected = self::perl('unix_users.pl', ['who' => $who], [$user]);

        self::assertSame($expected, (string) unix_users_count(self::fixture($who), unix_users_filter($user)));
    }

    /** @return array<string, array{string}> */
    public static function uptimeCases(): array
    {
        return ['Linux' => ['uptime-linux.txt'], 'macOS, which the pattern does not match' => ['uptime-macos.txt']];
    }

    #[DataProvider('uptimeCases')]
    public function testLoadAverageMatchesPerl(string $uptime): void
    {
        self::assertSame(self::perl('loadavg_multi.pl', ['env LC_ALL=C uptime' => $uptime]), loadavg_multi_format(self::fixture($uptime)));
    }

    /** @return array<string, array{string}> */
    public static function memoryCases(): array
    {
        return ['MemFree' => ['MemFree'], 'Cached' => ['Cached'], 'SwapFree' => ['SwapFree'], 'no such field' => ['Nothing'], 'rejected' => ['Mem;Free']];
    }

    #[DataProvider('memoryCases')]
    public function testMemoryValueMatchesPerl(string $field): void
    {
        $expected = self::perl('linux_memory.pl', ['cat /proc/meminfo' => 'meminfo-linux.txt'], [$field]);

        self::assertSame($expected, linux_memory_values($field, self::fixture('meminfo-linux.txt')));
    }

    /** @return array<string, array{list<string>}> */
    public static function partitionCases(): array
    {
        return [
            'index' => [['index']],
            'num_indexes' => [['num_indexes']],
            'query mount' => [['query', 'mount']],
            'query percent' => [['query', 'percent']],
            'get available' => [['get', 'available', '/dev/vdb1']],
            'get for another device' => [['get', 'available', '/dev/sda1']],
            'unknown field' => [['query', 'size']],
        ];
    }

    /** @param list<string> $arguments */
    #[DataProvider('partitionCases')]
    public function testPartitionQueryMatchesPerl(array $arguments): void
    {
        $expected = self::perl('query_unix_partitions.pl', ['/bin/df -P -k -l' => 'df-linux.txt'], $arguments);
        $request = query_unix_partitions_request($arguments);

        self::assertNotNull($request);
        self::assertSame($expected, query_unix_partitions_render($request, self::fixture('df-linux.txt')));
    }

    public function testPartitionUsageMatchesPerl(): void
    {
        self::assertNull(query_unix_partitions_request(['delete']));
        self::assertSame(self::perl('query_unix_partitions.pl', ['/bin/df -P -k -l' => 'df-linux.txt'], ['delete']), QUERY_UNIX_PARTITIONS_USAGE);
    }

    public function testModemReportMatchesThePythonScript(): void
    {
        // Recorded from the original script under Python 2.7 with lxml, serving this page.
        $expected = explode("\n", self::fixture('sb6141-python2-output.txt'));
        $page = self::fixture('sb6141-cmSignalData.htm');

        self::assertSame($expected[0] . "\n", motorola_sb6141_report('Downstream', $page));
        self::assertSame($expected[1] . "\n", motorola_sb6141_report('Upstream', $page));
        self::assertSame($expected[2] . "\n", motorola_sb6141_report('Stats', $page));
    }

    public function testModemReportsUnknownInsteadOfFailing(): void
    {
        self::assertSame('UpstreamPower5:U UpstreamPower6:U UpstreamPower7:U UpstreamPower8:U' . "\n", motorola_sb6141_report('Upstream', false));
        self::assertSame('UpstreamPower5:U UpstreamPower6:U UpstreamPower7:U UpstreamPower8:U' . "\n", motorola_sb6141_report('Upstream', '<html><body>Login</body></html>'));
        self::assertSame('http://[2001:db8::1]/cmSignalData.htm', motorola_sb6141_url('2001:db8::1'));
        self::assertSame('http://192.168.100.1:8080/cmSignalData.htm', motorola_sb6141_url('192.168.100.1:8080'));
        self::assertNull(motorola_sb6141_url('modem/../admin'));
    }

    /** @return array<string, array{string, string}> */
    public static function apacheCases(): array
    {
        // Recorded from the shipped ss_apache_stats() fetching this page over HTTP.
        $threads = 'thread_W:74 threadS:0 threadR:0 threadW:1 threadK:0 threadD:0 threadC:0 threadL:0 threadG:0 threadI:0 thread_O:325';

        return [
            'all' => ['all', 'apache_total_hits:401 apache_total_kbytes:77 apache_busy_workers:1 apache_idle_workers:74 ' . $threads],
            'hits' => ['hits', 'apache_total_hits:401'],
            'kbytes' => ['kbytes', 'apache_total_kbytes:77'],
            'workers' => ['workers', 'apache_busy_workers:1 apache_idle_workers:74'],
            'cpuload absent from the page' => ['cpuload', ''],
            'threads' => ['threads', $threads],
        ];
    }

    #[DataProvider('apacheCases')]
    public function testApacheReportIsUnchangedForAReadablePage(string $section, string $expected): void
    {
        self::assertSame($expected, ss_apache_stats_report(self::fixture('apache-server-status-auto.txt'), $section));
    }

    public function testApacheReportsUnknownWhenThereIsNoPage(): void
    {
        // The shipped script reported eleven zero thread counts here, graphing an outage as an idle server.
        self::assertSame('thread_W:U threadS:U threadR:U threadW:U threadK:U threadD:U threadC:U threadL:U threadG:U threadI:U thread_O:U', ss_apache_stats_report(false, 'threads'));
        self::assertSame('apache_busy_workers:U apache_idle_workers:U', ss_apache_stats_report(false, 'workers'));
        self::assertSame(16, substr_count(ss_apache_stats_report(false, 'all'), ':U'));
        self::assertSame('apache_total_hits:401', ss_apache_stats_report("Total Accesses: 401\n", 'hits'));
        self::assertStringContainsString('thread_W:U', ss_apache_stats_report("Total Accesses: 401\n", 'threads'));
    }

    public function testApachePageCannotAddOutputFields(): void
    {
        $page = "Total Accesses: 401 apache_idle_workers:999\nCPULoad: .00123\nScoreboard: _W.Zx\n";

        self::assertSame('apache_total_hits:U', ss_apache_stats_report($page, 'hits'));
        self::assertSame('apache_cpuload:.00123', ss_apache_stats_report($page, 'cpuload'));
        self::assertSame('thread_W:1 threadS:0 threadR:0 threadW:1 threadK:0 threadD:0 threadC:0 threadL:0 threadG:0 threadI:0 thread_O:1', ss_apache_stats_report($page, 'threads'));
    }

    /** @return array<string, array{string}> */
    public static function steeringHosts(): array
    {
        return ['path' => ['web/x?'], 'credentials' => ['user@evil.example'], 'fragment' => ['web#x'], 'space' => ['web x']];
    }

    // Isolated so the logging stub never meets the real cacti_log() elsewhere in the suite.
    #[DataProvider('steeringHosts')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApacheRefusesHostsThatSteerTheUrl(string $host): void
    {
        if (!function_exists('cacti_log')) {
            function cacti_log(string $message): void {}
        }

        self::assertSame('apache_total_hits:U', ss_apache_stats($host, 'hits'));
    }
}
