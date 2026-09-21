<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyLocalePreference implements LocalePreference
{
    public function __construct(private SharedSession $session, private DatabaseConnection $database) {}

    public function preferredLocale(): ?string
    {
        $snapshot = $this->session->read();
        $id = (int) ($snapshot['sess_user_id'] ?? 0);
        if ($id <= 0 || isset($snapshot['sess_change_password'])) {
            return null;
        }
        if (is_string($snapshot['sess_user_language'] ?? null) && $snapshot['sess_user_language'] !== '') {
            return $snapshot['sess_user_language'];
        }
        $query = $this->database->get()->prepare("SELECT value FROM settings_user WHERE user_id = ? AND name = 'user_language'");
        $query->execute([$id]);
        $value = $query->fetchColumn();
        return is_string($value) ? $value : null;
    }
}
