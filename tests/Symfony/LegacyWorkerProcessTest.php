<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\LegacyInstallationUpgrade;
use Kadupul\Platform\Infrastructure\Legacy\LegacyWorkerProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class LegacyWorkerProcessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-worker-' . bin2hex(random_bytes(8));
        // A worker that echoes its command, so a run proves the name was accepted.
        (new Filesystem())->dumpFile($this->root . '/bin/legacy-probe.php', "<?php\n\$in = stream_get_contents(STDIN);\nprint \"before\\n\";\nprint 'PROBE=' . json_encode(['status' => 'ok', 'in' => \$in]) . \"\\n\";\n");
        (new Filesystem())->dumpFile($this->root . '/console.php', "<?php\nprint \"PROBE={\\\"status\\\":\\\"ok\\\"}\\n\";\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testAWorkerNameRuns(): void
    {
        $run = new LegacyWorkerProcess($this->root)->run('legacy-probe.php', 'PROBE', ['a' => 1], 10.0);

        self::assertSame(['output' => "before\n", 'errors' => '', 'ok' => true], $run);
    }

    /** @return iterable<string, array{string}> */
    public static function notWorkers(): iterable
    {
        yield 'another bin file' => ['console'];
        yield 'a parent path' => ['../console.php'];
        yield 'a nested path' => ['legacy-x/../../console.php'];
        yield 'an absolute path' => ['/tmp/legacy-probe.php'];
        yield 'upper case' => ['legacy-Probe.php'];
        yield 'a trailing newline' => ["legacy-probe.php\n"];
        yield 'no prefix' => ['probe.php'];
        yield 'empty' => [''];
    }

    #[DataProvider('notWorkers')]
    public function testANameThatIsNotAWorkerIsRefusedBeforeAnythingRuns(string $script): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LegacyWorkerProcess($this->root)->run($script, 'PROBE', [], 10.0);
    }

    private function worker(string $name, string $body): void
    {
        (new Filesystem())->dumpFile($this->root . '/bin/' . $name, "<?php\n" . $body);
    }

    private function upgrade(): LegacyInstallationUpgrade
    {
        return new LegacyInstallationUpgrade(new LegacyWorkerProcess($this->root));
    }

    public function testTheUpgradeOutputIsPassedThroughWithoutTheMarker(): void
    {
        $this->worker('legacy-audit-upgrade.php', 'print "01/02/2031 03:04:05 - UPGRADE NOTE: one\n---\n"; fwrite(STDERR, "warning\n"); print "KADUPUL_UPGRADE_RESULT={\"status\":\"ok\"}\n";');

        $output = $this->upgrade()->run();

        self::assertSame(["01/02/2031 03:04:05 - UPGRADE NOTE: one\n---\n", "warning\n", true], [$output->stdout, $output->stderr, $output->completed]);
    }

    public function testAnUpgradeWithoutItsMarkerOrWithAFailedExitDidNotComplete(): void
    {
        $this->worker('legacy-audit-upgrade.php', 'print "half way\n";');
        $run = $this->upgrade()->run();
        self::assertSame(["half way\n", false], [$run->stdout, $run->completed]);

        $this->worker('legacy-audit-upgrade.php', 'print "KADUPUL_UPGRADE_RESULT={\"status\":\"ok\"}\n"; exit(1);');
        self::assertFalse($this->upgrade()->run()->completed);

        $this->worker('legacy-audit-upgrade.php', 'print "KADUPUL_UPGRADE_RESULT={\"status\":\"failed\"}\n";');
        self::assertFalse($this->upgrade()->run()->completed);
    }

    /**
     * The real bin/legacy-audit-upgrade.php, copied into a stand-in install
     * whose include/cli_check.php replaces the legacy bootstrap with the few
     * functions the upgrade calls. Each stub appends what it was asked to
     * calls.log, so the test sees the queries and deletes the worker made.
     */
    private function install(string $plugins, int $coreExit): void
    {
        $filesystem = new Filesystem();
        $filesystem->copy(dirname(__DIR__, 2) . '/bin/legacy-audit-upgrade.php', $this->root . '/bin/legacy-audit-upgrade.php');
        $filesystem->dumpFile($this->root . '/include/cli_check.php', '<?php
require ' . var_export(dirname(__DIR__, 2) . '/include/vendor/autoload.php', true) . ';
$config = ["base_path" => dirname(__DIR__)];
$plugins = ' . $plugins . ';
function stub_call(string $line): void { file_put_contents(dirname(__DIR__) . "/calls.log", $line . "\n", FILE_APPEND); }
function cacti_log($message, $output = false, $environ = "CMDPHP") { if ($output) { print "01/02/2031 03:04:05 - " . $environ . " " . $message . PHP_EOL; } }
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
function db_fetch_cell_prepared($sql, $params = array()) {
    global $plugins;
    $plugin = $plugins[$params[0]] ?? null;
    return str_contains($sql, "COUNT(*)") ? ($plugin !== null && $plugin["status"] == 1 ? 1 : 0) : ($plugin["version"] ?? false);
}
function db_fetch_assoc($sql) {
    global $plugins;
    if (isset($plugins["!throw"])) { throw new RuntimeException("database gone"); }
    return array_map(static fn(string $name): array => ["directory" => $name], array_keys($plugins));
}
function db_execute_prepared($sql, $params = array()) { stub_call($sql . " " . json_encode($params)); return true; }
function api_plugin_uninstall($plugin, $tables = true) { stub_call("uninstall " . $plugin . " " . var_export($tables, true)); }
');
        $filesystem->dumpFile($this->root . '/cli/upgrade_database.php', '<?php
file_put_contents(dirname(__DIR__) . "/core-argv.json", json_encode(array_slice($argv, 1)));
print "core line  \n\ncore last\n";
fwrite(STDERR, "core warning\n");
exit(' . $coreExit . ');
');
    }

    private function plugin(string $name, string $version, string $setup): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->root . '/plugins/' . $name . '/INFO', "[info]\nversion = " . $version . "\n");
        $filesystem->dumpFile($this->root . '/plugins/' . $name . '/setup.php', "<?php\n" . $setup);
    }

    public function testTheRealWorkerRunsTheCoreUpgradeThenEachPluginWithArgumentArrays(): void
    {
        // The recorded version reached the plugin's script through a shell
        // before; now it is one argument, so this one cannot run a command.
        $old = "1.0; touch pwned; '";
        $this->install('["thold" => ["status" => 1, "version" => ' . var_export($old, true) . '], "syslog" => ["status" => 0, "version" => "1"],'
            . ' "alpha" => ["status" => 1, "version" => "3.0"], "gone" => ["status" => 1, "version" => "1"], "stale" => ["status" => 1, "version" => "1"]]', 0);
        $this->plugin('thold', '2.0', 'function thold_upgrade_database($force) { print "thold upgraded\n"; }');
        (new Filesystem())->dumpFile($this->root . '/plugins/thold/database_upgrade.php', '<?php
file_put_contents(dirname(__DIR__, 2) . "/plugin-argv.json", json_encode(array_slice($argv, 1)));
print "plugin says hi\n";
');
        $this->plugin('syslog', '2.0', '');
        $this->plugin('alpha', '3.0', '');
        (new Filesystem())->dumpFile($this->root . '/plugins/stale/setup.php', "<?php\n");

        $run = $this->upgrade()->run();

        self::assertTrue($run->completed);
        self::assertSame(['--debug'], json_decode((string) file_get_contents($this->root . '/core-argv.json'), true));
        self::assertSame(['--type=large', '--force-ver=' . $old], json_decode((string) file_get_contents($this->root . '/plugin-argv.json'), true));
        self::assertFileDoesNotExist($this->root . '/pwned');
        self::assertFileDoesNotExist($this->root . '/plugins/thold/pwned');
        // The stub cacti_log() stamps a fixed time; only the durations vary.
        $stdout = (string) preg_replace('/ \d+\.\d{2} seconds/', ' N seconds', $run->stdout);
        $stamp = '01/02/2031 03:04:05 - UPGRADE ';
        $dashes = str_repeat('-', 93);
        self::assertSame([
            $stamp . 'NOTE: Upgrading Kadupul, this will take a few minutes.',
            $stamp . 'NOTE: Kadupul Upgrade succeeded in N seconds',
            $stamp . 'NOTE: Upgrading Plugin thold from ' . $old . ' to 2.0 using alternate upgrade path.',
            'thold upgraded',
            $stamp . 'NOTE: Upgrading Plugin thold from ' . $old . ' to 2.0 using upgrade script.',
            'plugin says hi',
            $stamp . 'NOTE: Kadupul Plugin thold Upgrade Succeeded.',
            $dashes,
            'plugin says hi',
            $dashes,
            $stamp . 'NOTE: Plugin syslog is not installed, skipping.',
            $stamp . 'NOTE: Plugin alpha Does not Require Upgrade',
            $stamp . 'WARNING: Plugin stale lacks an INFO file.  Can not upgrade!',
            $dashes,
            $stamp . 'NOTE: Pruning invalid and deprecated plugins while preserving tables',
            $stamp . 'NOTE: Uninstalling Plugin gone which is not supported and setup.php not found.  Preserving tables.',
            $stamp . 'NOTE: Uninstalling Plugin stale which is not supported.  Preserving tables.',
            $dashes,
            $stamp . 'NOTE: Kadupul Plugin Upgrades completed in N seconds',
            $stamp . 'NOTE: Audit Upgrade completed in N seconds.',
            '',
        ], explode("\n", $stdout));
        self::assertSame("core warning\n", $run->stderr);
        self::assertSame(implode("\n", [
            'DELETE FROM plugin_config WHERE directory = ? ["gone"]',
            'DELETE FROM plugin_db_changes WHERE plugin = ? ["gone"]',
            'DELETE FROM plugin_hooks WHERE name = ? ["gone"]',
            'DELETE FROM plugin_realms WHERE plugin = ? ["gone"]',
            'uninstall stale false',
        ]) . "\n", file_get_contents($this->root . '/calls.log'));
    }

    public function testTheRealWorkerPrintsAFailedCoreUpgradeAsExecReturnedItAndFails(): void
    {
        $this->install('[]', 3);

        $run = $this->upgrade()->run();

        // The worker ran to the end, but the core upgrade exited 3.
        self::assertFalse($run->completed);
        self::assertStringContainsString('NOTE: Audit Upgrade completed in', $run->stdout);
        $dashes = str_repeat('-', 93);
        // exec() kept the blank line and dropped the trailing spaces.
        self::assertStringContainsString("UPGRADE WARNING: Kadupul Upgrade Encountered Errors.  Messages below.  Details are below, but also in Kadupul upgrade log.\n"
            . $dashes . "\ncore line\n\ncore last\n" . $dashes . "\n", $run->stdout);
        self::assertSame("core warning\n", $run->stderr);
    }

    public function testTheRealWorkerReportsAnUpgradeThatThrewAsFailed(): void
    {
        $this->install('["!throw" => ["status" => 0, "version" => "1"]]', 0);

        $run = $this->upgrade()->run();

        self::assertFalse($run->completed);
        self::assertStringNotContainsString('database gone', $run->stdout . $run->stderr);
        self::assertStringNotContainsString('KADUPUL_UPGRADE_RESULT', $run->stdout);
    }

    public function testTheRealWorkerAnswersAWebServerWithNotFound(): void
    {
        $cgi = self::locateCgi();
        if ($cgi === null) {
            // Installing php-cgi on CI would mean a new package source there, so
            // the test skips; the skip count in the CI log shows whether it ran.
            self::markTestSkipped('php-cgi was not found next to ' . PHP_BINARY . ' or on PATH');
        }
        $this->install('[]', 0);

        $process = new Process([$cgi], $this->root, [
            'GATEWAY_INTERFACE' => 'CGI/1.1', 'REDIRECT_STATUS' => '200', 'REQUEST_METHOD' => 'GET',
            'SCRIPT_FILENAME' => $this->root . '/bin/legacy-audit-upgrade.php',
        ]);
        $process->run();
        [$headers, $body] = explode("\r\n\r\n", $process->getOutput(), 2) + [1 => null];

        self::assertStringStartsWith("Status: 404 Not Found\r\n", $headers);
        self::assertSame('', $body);
        self::assertFileDoesNotExist($this->root . '/core-argv.json');
    }

    /**
     * setup-php's hosted-runner path installs PHP from a php-ubuntu release
     * that is not pinned by our workflow's setup-php SHA, so php-cgi next to
     * PHP_BINARY is not guaranteed there the way it is next to a mise install.
     * A distro package instead names it php<major>.<minor>-cgi and puts it on
     * PATH rather than beside the CLI binary, so both are checked before this
     * skips.
     */
    private static function locateCgi(): ?string
    {
        $dir = dirname(PHP_BINARY);
        $versioned = $dir . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-cgi';
        foreach ([$dir . '/php-cgi', $versioned] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $entry) {
            $candidate = ($entry === '' ? '.' : $entry) . '/php-cgi';
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
