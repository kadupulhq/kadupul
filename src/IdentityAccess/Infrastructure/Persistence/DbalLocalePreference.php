<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\IdentityAccess\Infrastructure\Legacy\SharedSession;

/**
 * Doctrine DBAL replacement for LegacyLocalePreference reading user_language
 * from settings_user on the web connection.
 */
final readonly class DbalLocalePreference implements LocalePreference
{
    public function __construct(
        private SharedSession $session,
        private Connection $database,
    ) {}

    #[\Override]
    public function preferredLocale(): ?string
    {
        $snapshot = $this->session->read();
        $id = is_int($snapshot['sess_user_id'] ?? null) || (is_string($snapshot['sess_user_id'] ?? null) && ctype_digit($snapshot['sess_user_id']))
            ? (int) $snapshot['sess_user_id']
            : 0;
        if ($id <= 0 || isset($snapshot['sess_change_password'])) {
            return null;
        }

        $sessionLocale = $snapshot['sess_user_language'] ?? null;
        if (is_string($sessionLocale) && $sessionLocale !== '') {
            return $sessionLocale;
        }

        $value = $this->database->fetchOne(
            "SELECT value FROM settings_user WHERE user_id = ? AND name = 'user_language'",
            [$id],
        );

        return is_string($value) ? $value : null;
    }
}
