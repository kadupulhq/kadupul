<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\UserContact;
use Kadupul\IdentityAccess\Contract\UserContacts;

/** Reuses the active collector's connection during the legacy-entry migration. */
final class LegacyUserContacts implements UserContacts
{
    public function find(int $id): ?UserContact
    {
        $row = \db_fetch_row_prepared('SELECT full_name, email_address FROM user_auth WHERE id = ?', [$id]);

        return $row ? new UserContact((string) $row['email_address'], (string) $row['full_name']) : null;
    }
}
