<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Domain\PasswordComplexity;
use RuntimeException;

/** Preserve lazy native setting reads and the caller's translated first error. */
final readonly class NativePasswordComplexity
{
    public function __construct(private PasswordComplexity $rules) {}

    public function check(#[\SensitiveParameter] string $password): string
    {
        if (!function_exists('read_config_option') || !function_exists('__')) {
            throw new RuntimeException('Native password complexity bootstrap is unavailable.');
        }
        $minimum = \read_config_option('secpass_minlen');
        if (!is_scalar($minimum) && $minimum !== null) {
            throw new RuntimeException('Native password minimum must be a scalar setting.');
        }
        if (!$this->rules->meetsMinimumLength($password, $minimum)) {
            return self::translated(\__('Password must be at least %d characters!', $minimum));
        }
        if (self::enabled(\read_config_option('secpass_reqnum')) && !$this->rules->meetsNumberRequirement($password)) {
            return self::translated(\__('Your password must contain at least 1 numerical character!'));
        }
        if (self::enabled(\read_config_option('secpass_reqmixcase')) && !$this->rules->meetsCaseRequirement($password)) {
            return self::translated(\__('Your password must contain a mix of lower case and upper case characters!'));
        }
        if (self::enabled(\read_config_option('secpass_reqspec')) && !$this->rules->meetsSpecialRequirement($password)) {
            return self::translated(\__('Your password must contain at least 1 special character!'));
        }
        return 'ok';
    }

    private static function enabled(mixed $value): bool
    {
        return $value === 'on' || $value === true;
    }

    private static function translated(mixed $message): string
    {
        if (!is_string($message)) {
            throw new RuntimeException('Native password complexity translation is invalid.');
        }
        return $message;
    }
}
