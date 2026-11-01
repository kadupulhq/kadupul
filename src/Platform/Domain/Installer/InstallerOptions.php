<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Installer;

/** Input shared by the browser and CLI. Existing validators decide valid choices. */
final class InstallerOptions
{
    public const SNMP_SECRETS = ['SnmpCommunity', 'SnmpPassword', 'SnmpPrivPassphrase'];
    private const SCALARS = ['Mode', 'Eula', 'Language', 'Profile', 'CronInterval', 'DefaultTemplate',
        'AutomationMode', 'AutomationOverride', 'AutomationRange', 'RRDVersion', 'Theme'];
    private const MAPS = ['Paths', 'Templates', 'Tables', 'SnmpOptions'];

    /** @param array<string, mixed> $values */
    private function __construct(#[\SensitiveParameter] public readonly array $values) {}

    /** @param array<array-key, mixed> $input */
    public static function fromArray(#[\SensitiveParameter] array $input, bool $retainBlankSecrets = false): self
    {
        if (count($input) > 20) {
            throw new \InvalidArgumentException('Too many installer options.');
        }
        $values = [];
        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Invalid installer option name.');
            }
            if (in_array($key, self::SCALARS, true)) {
                self::scalar($value);
                if (in_array($key, ['Profile', 'DefaultTemplate', 'CronInterval'], true) && $value !== null) {
                    self::integer($value, $key !== 'DefaultTemplate');
                }
            } elseif (in_array($key, self::MAPS, true) && is_array($value) && count($value) <= 500) {
                foreach ($value as $name => $item) {
                    if (!is_string($name) || strlen($name) > 255) {
                        throw new \InvalidArgumentException('Invalid installer option name.');
                    }
                    self::scalar($item);
                }
            } else {
                throw new \InvalidArgumentException('Unknown or malformed installer option.');
            }
            $values[$key] = $value;
        }
        if (!in_array($input['Eula'] ?? null, [true, 1, '1', 'on', 'true'], true)) {
            throw new \InvalidArgumentException('Accept the license before saving the installation plan.');
        }
        if ($retainBlankSecrets && isset($values['SnmpOptions']) && is_array($values['SnmpOptions'])) {
            foreach (self::SNMP_SECRETS as $name) {
                if (($values['SnmpOptions'][$name] ?? null) === '') {
                    unset($values['SnmpOptions'][$name]);
                }
            }
        }
        return new self($values);
    }

    private static function scalar(mixed $value): void
    {
        if (!is_string($value) && !is_bool($value) && !is_int($value) && $value !== null) {
            throw new \InvalidArgumentException('Installer options must be scalar values.');
        }
        if (is_string($value) && (strlen($value) > 4096 || str_contains($value, "\0"))) {
            throw new \InvalidArgumentException('Installer option is too long or contains a null byte.');
        }
    }

    private static function integer(mixed $value, bool $positive): void
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/\A[0-9]+\z/D', (string) $value) !== 1) {
            throw new \InvalidArgumentException('Installer IDs and intervals must be whole numbers.');
        }
        $digits = ltrim((string) $value, '0');
        $maximum = (string) PHP_INT_MAX;
        if (($positive && $digits === '') || strlen($digits) > strlen($maximum)
            || strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0) {
            throw new \InvalidArgumentException('Installer ID or interval is outside its supported range.');
        }
    }
}
