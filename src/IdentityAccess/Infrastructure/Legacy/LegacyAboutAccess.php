<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;

final readonly class LegacyAboutAccess implements AuthenticatedAccess
{
    public function __construct(private SharedSession $session, private LegacyBrowserAuthentication $authentication) {}

    public function authenticatedActor(): ?Actor
    {
        $snapshot = $this->session->read();
        if (array_key_exists('sess_change_password', $snapshot)) {
            return null;
        }
        if (array_key_exists('sess_user_id', $snapshot)) {
            $id = $snapshot['sess_user_id'];
            $valid = is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) <= strlen((string) PHP_INT_MAX) && (strlen($id) < strlen((string) PHP_INT_MAX) || strcmp($id, (string) PHP_INT_MAX) <= 0));
            $actor = $valid ? $this->authentication->existingActor((int) $id, $snapshot['sess_user_credential'] ?? null) : null;
            if ($actor === null) {
                $this->session->revoke();
            }
            return $actor;
        }
        return $this->authentication->restore();
    }
}
