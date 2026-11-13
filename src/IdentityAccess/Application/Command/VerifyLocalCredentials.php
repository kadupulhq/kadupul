<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\LocalCredentials;

/** Confirm the verified credential before the native caller may issue a session. */
final readonly class VerifyLocalCredentials
{
    public function __construct(private LocalCredentials $credentials) {}

    /** @return array<array-key, mixed> Native account fields; this operation issues no session. */
    public function __invoke(string $username): array
    {
        $verified = $this->credentials->verifyPassword($username);
        if ($verified === [] || $this->credentials->verificationFailed()) {
            return $verified;
        }

        $verifiedHash = $verified['password'] ?? null;
        $account = $this->credentials->reload($username);
        // A replacement credential must never become the credential of a login
        // that verified the earlier password. Do not verify the password twice.
        if (!is_string($verifiedHash) || !is_string($account['password'] ?? null)
            || !hash_equals($verifiedHash, $account['password'])) {
            $this->credentials->deny();
            return [];
        }

        if (!$this->credentials->rehashIfNeeded($account, $verifiedHash)) {
            $this->credentials->deny();
            return [];
        }

        return $account;
    }
}
