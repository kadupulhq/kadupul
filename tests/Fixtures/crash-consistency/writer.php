<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Child process for CrashConsistencyTest: runs one production writer with
 * every filesystem call wrapped as two crash points (tests/Helpers/CrashPoints.php).
 *
 * Usage: php writer.php <whitelist|rrd-restore|boost-cache> <work directory>
 * Prints "completed" when the writer returns. KADUPUL_CRASH_AT selects the crash point.
 */

namespace {
    function boost_error_handler(...$arguments)
    {
        return false;
    }
}

namespace Kadupul\Tests\CrashScenario {
    /** Statistics are counted through the Boost MIB cache; the crash test only needs the calls to succeed. */
    final class MibCache
    {
        public function __construct(string $mib)
        {
        }

        public function object(string $name): self
        {
            return $this;
        }

        public function count(): void
        {
        }

        public function set($value): void
        {
        }
    }

    $root = dirname(__DIR__, 3);
    require $root . '/include/vendor/autoload.php';
    require $root . '/tests/Helpers/PhpSource.php';
    require $root . '/tests/Helpers/CrashPoints.php';

    [$scenario, $work] = [$argv[1] ?? '', $argv[2] ?? ''];
    if ($work === '' || !is_dir($work)) {
        fwrite(STDERR, "missing work directory\n");
        exit(2);
    }

    \Kadupul\Tests\CrashPoints\define_wrappers(__NAMESPACE__);

    function load(string $file, string ...$functions): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        if (!is_string($source)) {
            throw new \RuntimeException('Unable to read ' . $file);
        }
        foreach ($functions as $function) {
            // Test-only: evaluates a named production function in this namespace so its calls reach the wrappers.
            eval('namespace ' . __NAMESPACE__ . '; ' . test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
        }
    }

    function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = 0)
    {
    }

    switch ($scenario) {
        case 'whitelist':
            load('lib/input_whitelist.php', 'data_input_whitelist_update', 'data_input_whitelist_remove_stale');
            $records = [
                ['id' => 1, 'name' => 'one', 'hash' => 'h1', 'input_string' => 'new-one'],
                ['id' => 2, 'name' => 'two', 'hash' => 'h2', 'input_string' => 'new-two'],
            ];
            data_input_whitelist_update($work . '/whitelist.json', static fn (): array => [$records, false], false, hrtime(true) / 1e9 + 30);
            break;

        case 'rrd-restore':
            load('lib/rrd_maintenance.php', 'rrd_maintenance_directory_is_trusted', 'rrd_maintenance_remove_stale_restores', 'rrd_maintenance_restore_atomic');
            $config = ['rrd_maintenance_trusted_gids' => [], 'rrd_maintenance_trusted_uids' => []];
            // rrdtool restore writes the candidate in pieces; this callback does the same through the wrapped calls.
            $restore = static function (string $temporary): bool {
                $handle = fopen($temporary, 'wb');
                foreach (str_split(str_repeat('NEW-RRD-', 512), 1024) as $piece) {
                    fwrite($handle, $piece);
                }

                return fclose($handle);
            };
            if (!rrd_maintenance_restore_atomic($work . '/dump.xml', $work . '/graph.rrd', $restore)) {
                fwrite(STDERR, "restore returned false\n");
                exit(3);
            }
            break;

        case 'boost-cache':
            define('BOOST_PNG_TEMP_PREFIX', 'boost_png_tmp_');
            load('lib/boost.php', 'boost_graph_set_file');
            $GLOBALS['crash_settings'] = ['boost_png_cache_enable' => 'on', 'boost_png_cache_directory' => $work . '/cache'];
            function read_config_option($name)
            {
                return $GLOBALS['crash_settings'][$name] ?? '';
            }
            function boost_determine_caching_state()
            {
                return true;
            }
            $output = str_repeat("\x89PNG-NEW", 4096);
            boost_graph_set_file($output, 7, 1, [], $work . '/cache/graph.png');
            break;

        default:
            fwrite(STDERR, "unknown scenario {$scenario}\n");
            exit(2);
    }

    print "completed\n";
}
