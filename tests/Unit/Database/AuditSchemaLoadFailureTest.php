<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

final class AuditSchemaLoadFailureTest extends TestCase
{
    public function testAuditRefusesToCompareOrRepairWhenItsCanonicalBaselineCannotBeLoaded(): void
    {
        $root = dirname(__DIR__, 3);
        $targetVersion = trim((string) file_get_contents($root . '/include/cacti_version'));
        $cases = [
            ['missing report', '--report', 'missing', 1],
            ['missing repair', '--repair', 'missing', 1],
            ['missing alter plan', '--alters', 'missing', 1],
            ['unreadable baseline', '--report', 'unreadable', 1],
            ['failed baseline import', '--repair', 'failed', 1],
            ['valid baseline control', '--report', 'valid', 0],
        ];

        foreach ($cases as [$name, $option, $baseline, $expectedStatus]) {
            $directory = sys_get_temp_dir() . '/audit-baseline-' . bin2hex(random_bytes(8));
            foreach (['', '/cli', '/include', '/docs', '/bin'] as $suffix) {
                mkdir($directory . $suffix, 0700);
            }

            try {
                copy($root . '/cli/audit_database.php', $directory . '/cli/audit_database.php');
                if ($baseline === 'unreadable') {
                    mkdir($directory . '/docs/audit_schema.sql', 0700);
                } elseif ($baseline !== 'missing') {
                    file_put_contents(
                        $directory . '/docs/audit_schema.sql',
                        '-- Cacti Audit Schema Version: ' . $targetVersion . "\n-- fixture baseline\n-- Dump completed on 2026-09-30 00:00:00\n",
                    );
                }

                $client = $directory . '/bin/mysql';
                file_put_contents($client, <<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then
    echo "mariadb Ver 10.11.8"
    exit 0
fi
touch "$(dirname "$0")/client-invoked"
if [ "$AUDIT_TEST_LOAD_FAIL" = "1" ]; then
    echo "fixture import failure" >&2
    exit 1
fi
exit 0
SH);
                chmod($client, 0700);

                $bootstrap = "<?php\n"
                    . '$config = ' . var_export(['base_path' => $directory, 'poller_id' => 1], true) . ';'
                    . '$database_default = "fixture"; $database_username = "fixture"; $database_password = "fixture";'
                    . '$database_hostname = "localhost"; $database_port = "3306"; $database_ssl = false;'
                    . 'define("CACTI_VERSION", ' . var_export($targetVersion, true) . ');'
                    . 'function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }'
                    . 'function db_fetch_cell($sql) { return CACTI_VERSION; }'
                    . 'function db_execute($sql) { file_put_contents(dirname(__DIR__) . "/db-mutations", $sql . "\\n", FILE_APPEND); return true; }'
                    . 'function db_table_exists($table) { return true; }'
                    . 'function db_fetch_assoc($sql) { return array(); }'
                    . 'function cacti_escapeshellarg($value) { return escapeshellarg($value); }';
                file_put_contents($directory . '/include/cli_check.php', $bootstrap);

                $environment = array_merge(getenv(), [
                    'PATH' => $directory . '/bin:' . (getenv('PATH') ?: ''),
                    'CACTI_MYSQL_CLIENT' => $client,
                    'AUDIT_TEST_LOAD_FAIL' => $baseline === 'failed' ? '1' : '0',
                ]);
                $process = proc_open(
                    array_merge(
                        [PHP_BINARY],
                        $this->coverageArguments($root, $directory),
                        [$directory . '/cli/audit_database.php', $option],
                    ),
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    $directory,
                    $environment,
                );
                self::assertIsResource($process, $name . ' subprocess did not start');
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_close($process);
                $this->mergeChildCoverage($directory);

                self::assertSame($expectedStatus, $status, $name . ': ' . $output . $error);
                if ($expectedStatus !== 0) {
                    self::assertStringContainsString('FATAL:', $output . $error);
                    self::assertStringNotContainsString('Audit was clean', $output . $error);
                } else {
                    self::assertStringContainsString('Audit was clean', $output);
                }

                if (in_array($baseline, ['valid', 'failed'], true)) {
                    self::assertFileExists($directory . '/bin/client-invoked');
                }
                $mutations = is_file($directory . '/db-mutations') ? (string) file_get_contents($directory . '/db-mutations') : '';
                self::assertStringNotContainsString('ALTER TABLE', $mutations);
                if (in_array($baseline, ['missing', 'unreadable'], true)) {
                    self::assertSame('', $mutations);
                }
            } finally {
                $this->removeTree($directory);
            }
        }
    }

    /** Collect coverage from the copied CLI when the parent run enables PCOV. */
    private function coverageArguments(string $root, string $directory): array
    {
        if ($this->getTestResultObject()->getCodeCoverage() === null) {
            return [];
        }

        $cliCopy = $directory . '/cli/audit_database.php';
        $bootstrap = '<?php define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__);'
            . 'define("RRD_TEST_CLI_COVERAGE_COPY", ' . var_export($cliCopy, true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_SOURCE", ' . var_export($root . '/cli/audit_database.php', true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
        file_put_contents($directory . '/coverage.php', $bootstrap);

        return ['-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-d', 'auto_prepend_file=' . $directory . '/coverage.php'];
    }

    /** Merge coverage for one CLI invocation into PHPUnit's active report. */
    private function mergeChildCoverage(string $directory): void
    {
        $parent = $this->getTestResultObject()->getCodeCoverage();
        if ($parent === null || !is_file($directory . '/coverage.php')) {
            return;
        }

        $reports = glob($directory . '/*.coverage');
        self::assertCount(1, $reports);
        // Only the child process can write inside this private temporary directory.
        $child = unserialize((string) file_get_contents($reports[0]));
        self::assertInstanceOf(\SebastianBergmann\CodeCoverage\CodeCoverage::class, $child);
        $parent->merge($child);
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            rmdir($path);

            return;
        }

        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
