<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/** A validated edit of the automation_networks columns the General form owns. */
final readonly class DiscoveryNetworkChange
{
    public const array FLAGS = ['enabled', 'enable_netbios', 'add_to_cacti', 'same_sysname', 'rerun_data_queries', 'notification_enabled'];
    /** Single-line text columns and their widths in cacti.sql. */
    public const array TEXT = ['name' => 128, 'dns_servers' => 128, 'notification_email' => 255, 'notification_fromname' => 32, 'notification_fromemail' => 128, 'start_at' => 20];
    public const int SUBNET_RANGE_LENGTH = 1024;
    /** Inclusive integer ranges; poller and SNMP option IDs are checked against their tables when saving. */
    public const array INTEGERS = ['poller_id' => [1, 4294967295], 'site_id' => [0, 4294967295], 'snmp_id' => [1, 4294967295],
        'ping_port' => [0, 65535], 'ping_timeout' => [0, 4294967295], 'ping_retries' => [0, 100]];
    /** The choices the legacy form offered. */
    public const array CHOICES = [
        'threads' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 20, 50],
        'run_limit' => [60, 300, 600, 1200, 1800, 3600, 7200, 14400, 28800],
        // ICMP, UDP, TCP, SNMP Get and TCP Ping Closed.
        'ping_method' => [1, 2, 3, 4, 5],
        // Manual, Daily, Weekly, Monthly and Monthly on Day.
        'sched_type' => [1, 2, 3, 4, 5],
        'recur_every' => [1, 2, 3, 4, 5, 6, 7],
    ];
    /** Multi-selections stored as comma-separated values. 32 means the last day or week of the month. */
    public const array LISTS = [
        'day_of_week' => [1, 2, 3, 4, 5, 6, 7],
        'month' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        'day_of_month' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32],
        'monthly_week' => [1, 2, 3, 32],
        'monthly_day' => [1, 2, 3, 4, 5, 6, 7],
    ];
    /** poller_automation.php treats this next_start as "not scheduled yet", and the seeded network stores it as start_at too. */
    public const string UNSCHEDULED = '0000-00-00 00:00:00';

    /** @var array<string, bool|int|string|list<int>> */
    public array $settings;
    public int $totalIps;

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields)
    {
        $keys = [...self::FLAGS, ...array_keys(self::TEXT), 'subnet_range', ...array_keys(self::INTEGERS), ...array_keys(self::CHOICES), ...array_keys(self::LISTS)];
        $missing = array_diff($keys, array_keys($fields));
        $extra = array_diff(array_keys($fields), $keys);
        if ($missing !== [] || $extra !== []) {
            throw new DiscoveryNetworkRejected('The discovery network form is incomplete.');
        }
        $settings = [];
        foreach (self::FLAGS as $flag) {
            if (!is_bool($fields[$flag])) {
                throw new DiscoveryNetworkRejected('The discovery network form is incomplete.');
            }
            $settings[$flag] = $fields[$flag];
        }
        foreach (self::TEXT as $name => $width) {
            $settings[$name] = self::line($fields[$name], $name, $width);
        }
        foreach (self::INTEGERS as $name => [$min, $max]) {
            $settings[$name] = self::integer($fields[$name], $name, $min, $max);
        }
        foreach (self::CHOICES as $name => $allowed) {
            $settings[$name] = self::integer($fields[$name], $name, min($allowed), max($allowed));
            if (!in_array($settings[$name], $allowed, true)) {
                throw new DiscoveryNetworkRejected('Choose one of the listed values.', [], $name);
            }
        }
        foreach (self::LISTS as $name => $allowed) {
            $settings[$name] = self::selection($fields[$name], $name, $allowed);
        }
        if ($settings['name'] === '') {
            throw new DiscoveryNetworkRejected('Enter a name.', [], 'name');
        }
        if (preg_match('/\A[0-9A-Za-z.:\[\]-]*( [0-9A-Za-z.:\[\]-]+)*\z/', $settings['dns_servers']) !== 1) {
            throw new DiscoveryNetworkRejected('Enter DNS servers as IP addresses or host names separated by spaces.', [], 'dns_servers');
        }
        $from = $settings['notification_fromemail'];
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new DiscoveryNetworkRejected('Enter a valid email address.', [], 'notification_fromemail');
        }
        self::assertStartAt($settings['start_at']);
        $settings['subnet_range'] = self::subnetRange($fields['subnet_range']);
        [$total, $invalid] = DiscoveryRange::total($settings['subnet_range']);
        if ($total === null) {
            throw new DiscoveryNetworkRejected("Network '%network%' is invalid.", ['%network%' => (string) $invalid], 'subnet_range');
        }
        if ($total > DiscoveryRange::TOTAL_MAX) {
            throw new DiscoveryNetworkRejected('The ranges cover more than %count% addresses.', ['%count%' => (string) DiscoveryRange::TOTAL_MAX], 'subnet_range');
        }
        // Legacy saved an incomplete schedule but disabled the network; an enabled one is refused instead, so nothing changes unannounced.
        if ($settings['enabled'] === true) {
            $schedule = match ($settings['sched_type']) {
                3 => [['day_of_week'], 'You must specify the day of the week.'],
                4 => [['month', 'day_of_month'], 'You must specify both the Months and Days of Month.'],
                5 => [['month', 'monthly_week', 'monthly_day'], 'You must specify the Months, Weeks of Months, and Days of Week.'],
                default => [[], ''],
            };
            foreach ($schedule[0] as $required) {
                if ($settings[$required] === []) {
                    throw new DiscoveryNetworkRejected($schedule[1], [], $required);
                }
            }
        }
        $this->settings = $settings;
        $this->totalIps = $total;
    }

    /**
     * The automation_networks columns, in the legacy storage formats.
     *
     * @param array<string, mixed>|null $stored the locked row of an existing network
     * @return array<string, int|string>
     */
    public function columns(?array $stored): array
    {
        $columns = ['total_ips' => $this->totalIps];
        foreach ($this->settings as $name => $value) {
            $columns[$name] = match (true) {
                is_bool($value) => $value ? 'on' : '',
                is_array($value) => implode(',', $value),
                default => $value,
            };
        }
        // A new start or schedule type is picked up on the next automation poll, as legacy did.
        if ($stored !== null && (self::stored($stored, 'start_at') !== $this->settings['start_at'] || self::stored($stored, 'sched_type') !== (string) $this->settings['sched_type'])) {
            $columns['next_start'] = self::UNSCHEDULED;
        }
        return $columns;
    }

    /** @param array<string, mixed> $row */
    private static function stored(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    private static function line(mixed $value, string $name, int $width): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new DiscoveryNetworkRejected('Enter single-line text.', [], $name);
        }
        $value = trim($value);
        if (strlen($value) > $width) {
            throw new DiscoveryNetworkRejected('Enter at most %count% bytes.', ['%count%' => (string) $width], $name);
        }
        return $value;
    }

    private static function subnetRange(mixed $value): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new DiscoveryNetworkRejected('Enter valid network ranges separated by commas.', [], 'subnet_range');
        }
        // The textarea may wrap ranges onto several lines; the stored list is comma-separated.
        $value = trim((string) preg_replace('/\s*,\s*/', ',', trim($value)));
        if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new DiscoveryNetworkRejected('Enter valid network ranges separated by commas.', [], 'subnet_range');
        }
        if (strlen($value) > self::SUBNET_RANGE_LENGTH) {
            throw new DiscoveryNetworkRejected('Enter at most %count% bytes.', ['%count%' => (string) self::SUBNET_RANGE_LENGTH], 'subnet_range');
        }
        return $value;
    }

    private static function integer(mixed $value, string $name, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new DiscoveryNetworkRejected('Enter a whole number from %min% to %max%.', ['%min%' => (string) $min, '%max%' => (string) $max], $name);
        }
        return $value;
    }

    /**
     * @param list<int> $allowed
     * @return list<int>
     */
    private static function selection(mixed $value, string $name, array $allowed): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new DiscoveryNetworkRejected('Choose one of the listed values.', [], $name);
        }
        foreach ($value as $item) {
            if (!in_array($item, $allowed, true)) {
                throw new DiscoveryNetworkRejected('Choose one of the listed values.', [], $name);
            }
        }
        $value = array_values(array_unique($value));
        sort($value);
        return $value;
    }

    private static function assertStartAt(string $value): void
    {
        // strtotime() reads the value in poller_automation.php; the seeded network stores the zero date.
        if ($value === self::UNSCHEDULED) {
            return;
        }
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2}) ([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?\z/', $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new DiscoveryNetworkRejected('Enter the start as YYYY-MM-DD HH:MM.', [], 'start_at');
        }
    }
}
