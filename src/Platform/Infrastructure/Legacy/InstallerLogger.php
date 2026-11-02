<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Psr\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;

/** Preserve native verbosity and progress hooks while checking installation log writes. */
final readonly class InstallerLogger
{
    /** The optional clock keeps pre-Composer compatibility and permits a shared Symfony clock. */
    public function __construct(private NativeInstallerFunctions $native, private ?ClockInterface $clock = null, private ?Filesystem $files = null) {}

    /** Keep numeric strings and fractional verbosity values accepted by the native contract. */
    public static function normalize(mixed $level, mixed $default = 1): int|float|string
    {
        if (!(bool) $level || !is_numeric($level)) {
            $level = $default;
        }
        if (!is_int($level) && !is_float($level) && !is_string($level)) {
            throw new \InvalidArgumentException('Invalid installation log level.');
        }
        if ($level < 1) {
            print 'Level too low - "' . $level . '"' . PHP_EOL;
            return 1;
        }
        if ($level > 5) {
            print 'Level too high - "' . $level . '"' . PHP_EOL;
            return 5;
        }
        return $level;
    }

    /** Preserve the historical names and the unknown-value diagnostic. */
    public static function label(int|float|string $level): string
    {
        $numeric = is_numeric($level) ? (float) $level : null;
        return match (true) {
            $numeric === 1.0 => 'always', $numeric === 2.0 => 'general', $numeric === 3.0 => 'info',
            $numeric === 4.0 => 'notice', $numeric === 5.0 => 'debug', default => 'Unknown (' . $level . ')',
        };
    }

    /** Section verbosity can raise the configured global installation verbosity. */
    public function sectionLevel(string $section): int|float|string
    {
        return max(1, $this->level('log_install', 1), $this->level('log_install_' . $section, 1));
    }

    /** Always re-read native options so configuration changes are visible within a worker. */
    public function level(string $option, mixed $default): int|float|string
    {
        return self::normalize($this->native->call('read_config_option', $option, true), $default);
    }

    /** Keep the core log hook, optional section files and background heartbeat in their native order. */
    public function record(mixed $level, string $text, string $section, bool $background, string $projectDir): void
    {
        $level = self::normalize($level);
        $name = $section === '' || $section === '0' ? 'INSTALL:' : 'INSTALL-' . strtoupper($section) . ':';
        $this->native->call('cacti_log', self::label($level) . ': ' . $text, false, $name, $level);
        $this->write($section, $text, FILE_APPEND, $level, false, defined('log_install_echo'), $projectDir);
        if ($background) {
            $this->native->call('set_config_option', 'install_updated', microtime(true));
        }
    }

    /** File diagnostics must not escape the release log directory or report a short write as success. */
    public function write(string $section, string $text, int $flags, int|float|string $level, bool $force, bool $echo, string $projectDir): void
    {
        $canLog = $level <= $this->sectionLevel($section);
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $day = $now->format('Y-m-d');
        $time = $now->format('H:i:s');
        $label = self::label($level);
        $sectionName = $section === '' || $section === '0' ? 'global' : $section;
        if (($force || $canLog) && $echo) {
            printf('[%s] [ %15s %-7s ] %s%s', $time, $sectionName, $label, $text, PHP_EOL);
        }
        if (!$canLog) {
            return;
        }
        if (preg_match('/\A[a-zA-Z0-9_-]*\z/D', $section) !== 1) {
            throw new \InvalidArgumentException('Invalid installation log section.');
        }
        $section = $section === '' || $section === '0' ? 'general' : $section;
        $this->bytes($projectDir . '/log/install-' . $section . '.log', sprintf('[%s %s] [ %-7s ] %s%s', $day, $time, $label, $text, PHP_EOL), $flags);
        $this->bytes($projectDir . '/log/install-complete.log', sprintf('[%s %s] [ %15s %-7s ] %s%s', $day, $time, $sectionName, $label, $text, PHP_EOL), $flags);
    }

    /** A caller can retry a failed append; an earlier confirmed file append is not rolled back. */
    private function bytes(string $path, string $bytes, int $flags): void
    {
        $directory = realpath(dirname($path));
        $stat = @stat($path);
        if ($directory === false || is_link($path) || ($stat !== false && $stat['nlink'] !== 1)) {
            throw new \RuntimeException('The installation diagnostic log could not be written.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . basename($path);
        if (($flags & FILE_APPEND) === 0) {
            ($this->files ?? new Filesystem())->dumpFile($path, $bytes);
            return;
        }
        if (@file_put_contents($path, $bytes, $flags | LOCK_EX) !== strlen($bytes)) {
            throw new \RuntimeException('The installation diagnostic log could not be written.');
        }
    }
}
