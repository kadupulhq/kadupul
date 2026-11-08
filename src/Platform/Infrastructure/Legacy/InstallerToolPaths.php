<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\Filesystem\Filesystem;

/** Executable discovery and form metadata shared by native and Symfony installation. */
final readonly class InstallerToolPaths
{
    /** Bind native settings and diagnostic hooks without bootstrapping an installation. */
    public function __construct(private NativeInstallerFunctions $native, private ?Filesystem $filesystem = null) {}

    /**
     * Environment entries precede platform defaults; duplicate keys retain their first identity.
     * @return array<int, string>
     */
    public function searchPaths(string $os = 'unix', ?string $phpPath = null): array
    {
        $windows = $os === 'win32';
        $separator = $windows ? ';' : ':';
        $paths = $windows ? [
            'c:/usr/bin', 'c:/cacti', 'c:/rrdtool', 'c:/spine', 'c:/php', 'c:/net-snmp/bin',
            'c:/progra~1/net-snmp/bin', 'c:/progra~1/php', 'c:/progra~1/spine', 'c:/progra~1/spine/bin',
            'd:/usr/bin', 'd:/cacti', 'd:/rrdtool', 'd:/spine', 'd:/php', 'd:/net-snmp/bin',
            'd:/progra~1/net-snmp/bin', 'd:/progra~1/php', 'd:/progra~1/spine', 'd:/progra~1/spine/bin',
        ] : ['/bin', '/sbin', '/usr/bin', '/usr/sbin', '/usr/local/bin', '/usr/local/sbin', '/usr/local/spine/bin', '/usr/spine/bin'];
        $environment = getenv('PATH');
        if ($environment !== false && $environment !== '' && $environment !== '0') {
            $entries = explode($separator, $environment);
            if ($windows) {
                $entries = array_map(static fn(string $path): string => str_replace('\\', '/', $path), $entries);
            }
            $paths = array_merge($entries, $paths);
        }
        $bindir = getenv('PHP_BINDIR');
        if ($bindir !== false && $bindir !== '' && $bindir !== '0') {
            $paths = array_merge(explode($separator, $bindir), $paths);
        }
        if ($phpPath !== null && $phpPath !== '' && $phpPath !== '0') {
            $paths = array_merge(explode($separator, $phpPath), $paths);
        }
        return array_unique(array_filter($paths, static fn(string $path): bool => $path !== ''));
    }

    /** Preserve readable-file discovery; submitted executables are validated by the engine. */
    public function bestPath(string $binary, string $os, ?string $phpPath = null): string
    {
        foreach ($this->searchPaths($os, $phpPath) as $path) {
            $candidate = $path . '/' . $binary;
            if ($this->exists($candidate) && @is_readable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Keep saved configuration ahead of discovery and retain optional field metadata.
     * @param array<string, string> $defaults
     * @param array<string, mixed> $configuration
     * @param array<string, array<string, array<string, mixed>>> $settings
     * @return array<string, mixed>|false
     */
    public function tool(string $name, array $defaults, array $configuration, array $settings): array|false
    {
        $os = $this->native->text($configuration['cacti_server_os'] ?? null);
        if (!isset($defaults[$os])) {
            return false;
        }
        if (!function_exists('__')) {
            throw new \RuntimeException('The authenticated native installer runtime is required.');
        }
        $tool = ['friendly_name' => $name, 'description' => \__('Path for %s', $name),
            'method' => 'filepath', 'max_length' => 255, 'default' => ''];
        $this->native->call('log_install_debug', 'file', "$name: Locations ($os), Paths: " . $this->native->text($this->native->call('clean_up_lines', var_export($defaults, true))));
        $tool = $settings['path']['path_' . $name] ?? $settings['mail'][$name] ?? $tool;
        $selected = '';
        if ((bool) $this->native->call('config_value_exists', 'path_' . $name)) {
            $selected = $this->native->text($this->native->call('read_config_option', 'path_' . $name, true));
            $this->native->call('log_install_high', 'file', 'Using config location: ' . $selected);
        }
        if ($selected === '' || $selected === '0') {
            $default = $defaults[$os];
            $this->native->call('log_install_debug', 'file', 'Searching best path with location: ' . $default);
            $selected = $this->bestPath(basename($default), $os, $this->phpPath($configuration));
            $this->native->call('log_install_debug', 'file', 'Searching best path with location return: ' . $selected);
            if ($selected === '') {
                $selected = $default;
                $this->native->call('log_install_high', 'file', 'Nothing found defaulting to ' . $default);
            }
        }
        $tool['default'] = $selected;
        return $tool;
    }

    /**
     * Preserve registration order, XAMPP/BSD overrides and legacy log omission rules.
     * @param array<string, mixed> $configuration
     * @param array<string, array<string, array<string, mixed>>> $settings
     * @return array<string, array<string, mixed>>
     */
    public function definitions(array $configuration, array $settings): array
    {
        $os = $this->native->text($configuration['cacti_server_os'] ?? null);
        $root = $this->native->text($configuration['base_path'] ?? null);
        $tools = [
            'path_php_binary' => ['php_binary', ['unix' => '/bin/php', 'win32' => 'c:/php/php.exe']],
            'path_rrdtool' => ['rrdtool', ['unix' => '/usr/bin/rrdtool', 'win32' => 'c:/rrdtool/rrdtool.exe']],
            'path_snmpwalk' => ['snmpwalk', ['unix' => '/usr/bin/snmpwalk', 'win32' => 'c:/usr/bin/snmpwalk.exe']],
            'path_snmpget' => ['snmpget', ['unix' => '/usr/bin/snmpget', 'win32' => 'c:/usr/bin/snmpget.exe']],
            'path_snmpbulkwalk' => ['snmpbulkwalk', ['unix' => '/usr/bin/snmpbulkwalk', 'win32' => 'c:/usr/bin/snmpbulkwalk.exe']],
            'path_snmpgetnext' => ['snmpgetnext', ['unix' => '/usr/bin/snmpgetnext', 'win32' => 'c:/usr/bin/snmpgetnext.exe']],
            'path_snmptrap' => ['snmptrap', ['unix' => '/usr/bin/snmptrap', 'win32' => 'c:/usr/bin/snmptrap.exe']],
            'settings_sendmail_path' => ['settings_sendmail_path', ['unix' => '/usr/sbin/sendmail']],
            'path_spine' => ['spine', ['unix' => '/usr/local/spine/bin/spine', 'win32' => 'c:/spine/bin/spine.exe']],
        ];
        $input = [];
        foreach ($tools as $key => [$name, $defaults]) {
            $definition = $this->tool($name, $defaults, $configuration, $settings);
            if ($definition !== false) {
                $input[$key] = $definition;
            }
            if ($key === 'path_php_binary' && $os === 'win32') {
                foreach (['c:/php/php.exe', 'd:/php/php.exe', 'c:/xampp/php/php.exe', 'd:/xampp/php/php.exe'] as $path) {
                    if ($this->exists($path)) {
                        $input[$key]['default'] = $path;
                        break;
                    }
                }
            }
        }
        if ($os === 'unix') {
            foreach (['/usr/local/spine/bin/spine', '/usr/local/bin/spine'] as $path) {
                if ($this->exists($path)) {
                    $input['path_spine']['default'] = $path;
                    break;
                }
            }
        }
        $input['path_spine_config'] = $settings['path']['path_spine_config'];
        $configuredLog = (bool) $this->native->call('config_value_exists', 'path_cactilog');
        $input['path_cactilog'] = $settings['path']['path_cactilog'];
        if ($configuredLog) {
            $input['path_cactilog']['default'] = $this->native->call('read_config_option', 'path_cactilog');
        }
        if (in_array($input['path_cactilog']['default'] ?? null, [null, false, '', '0', 0], true)) {
            $input['path_cactilog']['default'] = $root . '/log/cacti.log';
        }
        $input['path_stderrlog'] = $settings['path']['path_stderrlog'];
        if ($configuredLog) {
            $input['path_stderrlog']['default'] = $this->native->call('read_config_option', 'path_stderrlog');
        } elseif (in_array($input['path_stderrlog']['default'] ?? null, [null, false, '', '0', 0], true)) {
            $input['path_stderrlog']['default'] = $root . '/log/cacti.stderr.log';
        }
        $rrd = $this->native->text($input['path_rrdtool']['default'] ?? null);
        if ($this->exists($rrd) && ($os === 'win32' || is_executable($rrd))) {
            $input['rrdtool_version'] = $settings['general']['rrdtool_version'];
            $version = $this->native->call('get_installed_rrdtool_version');
            if ($version !== null && $version !== false && $version !== '' && $version !== '0') {
                $input['rrdtool_version']['default'] = $version;
            }
        }
        return $input;
    }

    /** Use Symfony's filesystem when injected and retain pre-Composer compatibility. */
    private function exists(string $path): bool
    {
        return $this->filesystem?->exists($path) ?? @file_exists($path);
    }

    /** @param array<string, mixed> $configuration */
    private function phpPath(array $configuration): ?string
    {
        return isset($configuration['php_path']) ? $this->native->text($configuration['php_path']) : null;
    }
}
