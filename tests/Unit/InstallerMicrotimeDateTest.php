<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * PHP casts a float to a string with 14 significant digits, so a microtime(true)
 * within 50 microseconds of a whole second loses its fraction, and 'U.u' then
 * refuses it. The settings table hands the same digits back as a string.
 *
 * lib/installer.php only defines the class and pulls in lib/poller.php, which
 * only defines functions, so the helper runs in this process where coverage
 * can see it. The entry points need read_config_option() and the install log
 * stubbed, which would clash with other tests' stubs, so they run in a child.
 */

$root = dirname(__DIR__, 2);

require_once $root . '/lib/installer.php';

function installer_microtime_date($value)
{
    $parse = new ReflectionMethod(Installer::class, 'dateFromMicrotime');
    $parse->setAccessible(true);

    $date = $parse->invoke(null, $value);

    return $date === false ? false : $date->format('Y-m-d H:i:s.u');
}

function installer_microtime_scenario($root, $scenario)
{
    $stub = <<<'PHP'
        <?php
        define('CACTI_VERSION', '1.3.0');

        $config          = ['base_path' => $argv[1]];
        $install_options = [];
        $install_log     = [];
        $clock           = 1789333661;

        function __($message, ...$args)
        {
            return count($args) ? vsprintf($message, $args) : $message;
        }

        function cacti_sizeof($value)
        {
            return is_countable($value) ? count($value) : 0;
        }

        // The settings table stores every value as a string
        function read_config_option($name, $force = false)
        {
            global $install_options;

            return $install_options[$name] ?? '';
        }

        function set_install_config_option($name, $value)
        {
            global $install_options;

            $install_options[$name] = (string) $value;
        }

        function log_install_always($section, $text, $background = false)
        {
            global $install_log;

            $install_log[] = $text;
        }

        function log_install_high($section, $text, $background = false) {}

        function log_install_medium($section, $text, $background = false) {}

        function log_install_debug($section, $text, $background = false)
        {
            global $install_log;

            $install_log[] = $text;
        }

        function clean_up_lines($string)
        {
            return $string;
        }

        function tail_file($file_name, $number_of_lines, $message_type = -1, $filter = '', &$page_nr = 1, &$total_rows = 0, $matches = true)
        {
            return [];
        }

        // The parent disables the built-in, so the clock lands on a whole second
        function microtime($as_float = false)
        {
            global $clock;

            return $as_float ? (float) $clock : '0.00000000 ' . $clock;
        }

        require $argv[1] . '/lib/installer.php';

        switch ($argv[2]) {
            case 'expiry':
                $clock = time();

                $install_options['install_started'] = (string) (float) $clock;
                $install_options['install_updated'] = (string) (float) $clock;

                $installer = (new ReflectionClass(Installer::class))->newInstanceWithoutConstructor();
                Closure::bind(function () {
                    $this->buttonNext     = new stdClass();
                    $this->buttonPrevious = new stdClass();
                }, $installer, Installer::class)();

                $installer->processStepInstall();

                $result = [gmdate('Y-m-d H:i:s', $clock)];
                foreach ($install_log as $line) {
                    if (strpos($line, 'backgroundDateStarted = ') === 0) {
                        $result[] = trim(substr($line, strlen('backgroundDateStarted = ')));
                    }
                }

                break;
            case 'already started':
                $install_options['install_eula']    = 'on';
                $install_options['install_started'] = (string) (float) 1789333661;

                $result = [Installer::beginInstall((string) (float) 1789333662), end($install_log)];

                break;
            case 'completion':
                $install_options['install_eula'] = 'on';

                $installer = new class {
                    public function setDefaults() {}

                    public function install() {}
                };

                $result = [Installer::beginInstall('-b', $installer), end($install_log)];

                break;
        }

        print PHP_EOL . json_encode($result);
        PHP;

    $script = tempnam(sys_get_temp_dir(), 'kadupul_install_mt_');
    file_put_contents($script, $stub);

    $cmd = escapeshellarg(PHP_BINARY) . ' -d disable_functions=microtime '
        . escapeshellarg($script) . ' ' . escapeshellarg($root) . ' '
        . escapeshellarg($scenario) . ' 2>&1';

    $output = [];
    exec($cmd, $output);
    @unlink($script);

    $result = json_decode((string) end($output), true);

    if (!is_array($result)) {
        throw new RuntimeException(implode(PHP_EOL, $output));
    }

    return $result;
}

test('values with a fraction take the original parse', function () {
    expect(installer_microtime_date(1789333661.25))->toBe('2026-09-13 21:07:41.250000')
        ->and(installer_microtime_date('1789333661.1235'))->toBe('2026-09-13 21:07:41.123500');
});

test('whole-second floats fall back to a six-digit fraction', function () {
    expect(installer_microtime_date(1789333661.0))->toBe('2026-09-13 21:07:41.000000')
        ->and(installer_microtime_date(1789333661.99996))->toBe('2026-09-13 21:07:41.999960');
});

test('stored settings strings parse whatever their precision', function () {
    expect(installer_microtime_date((string) 1789333661.0))->toBe('2026-09-13 21:07:41.000000')
        ->and(installer_microtime_date('1789333661.0000'))->toBe('2026-09-13 21:07:41.000000')
        ->and(installer_microtime_date('1789333661.1234567'))->toBe('2026-09-13 21:07:41.123457');
});

test('non-numeric values still return false', function () {
    expect(installer_microtime_date(''))->toBeFalse()
        ->and(installer_microtime_date('-b'))->toBeFalse()
        ->and(installer_microtime_date(false))->toBeFalse();
});

test('every value the old parse accepted keeps its output', function () {
    $changed = 0;

    for ($i = 0; $i < 20000; $i++) {
        $value = 1789333661 + $i / 20000;
        $old   = DateTime::createFromFormat('U.u', $value);

        if ($old !== false && $old->format('Y-m-d H:i:s.u') !== installer_microtime_date($value)) {
            $changed++;
        }
    }

    expect($changed)->toBe(0);
});

test('the web installer expiry check reads a whole-second start time', function () use ($root) {
    [$expected, $logged] = installer_microtime_scenario($root, 'expiry');

    expect($logged)->toBe($expected);
});

test('a second background start reports both whole-second times', function () use ($root) {
    expect(installer_microtime_scenario($root, 'already started'))->toBe([
        false,
        'Background was already started at 2026-09-13 21:07:41.000000, this attempt at 2026-09-13 21:07:42.000000 was skipped',
    ]);
});

test('an install that starts and finishes on a whole second logs its times', function () use ($root) {
    expect(installer_microtime_scenario($root, 'completion'))->toBe([
        true,
        'Installation was started at 2026-09-13 21:07:41, completed at 2026-09-13 21:07:41',
    ]);
});
