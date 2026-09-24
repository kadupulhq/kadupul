<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * cacti_log() for level-less messages, without the legacy bootstrap. Operators
 * grep cacti.log for these lines, so format and destinations follow
 * lib/functions.php; the write stays best effort, as it is there.
 */
final readonly class LegacyOperatorLog
{
    private const array DATE = [0 => 'm%1$sd%1$sY', 1 => 'M%1$sd%1$sY', 2 => 'd%1$sm%1$sY', 3 => 'd%1$sM%1$sY', 4 => 'Y%1$sm%1$sd', 5 => 'Y%1$sM%1$sd'];
    private const array SEPARATOR = [0 => '-', 1 => '/', 2 => '.'];

    /**
     * @param string $os PHP_OS, which include/global.php reads to set cacti_server_os
     * @param (\Closure(int, int, string): void)|null $syslog test-only: receives the
     *     facility, priority and line instead of the system logger
     */
    public function __construct(
        private string $projectDir,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private string $os = PHP_OS,
        private ?\Closure $syslog = null,
    ) {}

    /**
     * Callers must not pass $environ = 'POLLER': there is no poller id here, so
     * cacti_log()'s "Poller[id] PID[pid]" prefix for that environ cannot be reproduced.
     */
    public function record(Connection $settings, string $environ, string $message): void
    {
        if (trim($message) === '') {
            return;
        }
        $row = static function (string $name) use ($settings): ?string {
            $found = $settings->fetchOne('SELECT value FROM settings WHERE name = ?', [$name]);

            return $found === false ? null : (string) $found;
        };
        $value = static fn(string $name, string $default): string => $row($name) ?? $default;
        $text = (string) preg_replace('/\s*[\r\n]+\s*/', ' ', $message);
        $destination = (int) $value('log_destination', '1');
        if (($destination === 1 || $destination === 2) && $value('log_verbosity', '2') !== '1') {
            $format = self::dateFormat($row('default_date_format'), $row('default_datechar'));
            $file = $value('path_cactilog', '');
            $file = $file === '' ? $this->projectDir . '/log/cacti.log' : $file;
            // cacti_log() never creates the log directory, and appendToFile()
            // would; path_cactilog comes from the database, so it must not be
            // able to create one anywhere the process can write.
            if (is_dir(dirname($file))) {
                try {
                    $this->filesystem->appendToFile($file, $this->clock->now()->format($format) . ' - ' . $environ . ' ' . $text . PHP_EOL, true);
                } catch (IOException) {
                    // Best effort, as in cacti_log(): a log failure must not fail the command.
                }
            }
        }
        if ($destination === 2 || $destination === 3) {
            // Type is picked by marker precedence first, exactly like cacti_log()'s
            // if/elseif chain, and only that type's gate is then consulted; a gate
            // that is off must not fall through to a lower-precedence marker.
            // cacti_log() tests each gate for PHP truthiness, so '0' is off too,
            // and the defaults are those declared in include/global_settings.php.
            $priority = match (true) {
                str_contains($message, 'ERROR:') => (bool) $value('log_perror', 'on') ? LOG_CRIT : null,
                str_contains($message, 'WARNING:') => (bool) $value('log_pwarn', '') ? LOG_WARNING : null,
                str_contains($message, 'STATS:'), str_contains($message, 'NOTICE:') => (bool) $value('log_pstats', '') ? LOG_INFO : null,
                default => null,
            };
            if ($priority !== null) {
                $this->send($priority, $environ . ': ' . $message);
            }
        }
    }

    /**
     * date_time_format() as include/global.php:528 runs it. That line comes
     * before global_settings.php, so a missing row reads as null rather than
     * the declared default. The loose comparisons and the key lookup are
     * PHP's own, so every value maps as it does there: null matches format 0
     * and misses every separator.
     */
    private static function dateFormat(?string $format, ?string $separator): string
    {
        $character = isset(self::SEPARATOR[$separator]) ? self::SEPARATOR[$separator] : self::SEPARATOR[1];
        foreach (self::DATE as $code => $layout) {
            if ($format == $code) {
                return sprintf($layout, $character) . ' H:i:s';
            }
        }

        return sprintf(self::DATE[4], $character) . ' H:i:s';
    }

    private function send(int $priority, string $line): void
    {
        // cacti_log() logs to LOG_USER when cacti_server_os is 'win32', which
        // global.php sets whenever PHP_OS contains "WIN".
        $facility = str_contains($this->os, 'WIN') ? LOG_USER : LOG_SYSLOG;
        if ($this->syslog !== null) {
            ($this->syslog)($facility, $priority, $line);

            return;
        }
        openlog('Cacti', LOG_NDELAY | LOG_PID, $facility);
        syslog($priority, $line);
        closelog();
    }
}
