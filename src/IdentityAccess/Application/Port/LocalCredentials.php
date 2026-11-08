<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

/** Native password policy and persistence during the credential-issuer migration. */
interface LocalCredentials
{
    /** @return array<array-key, mixed> Verified native fields, or the native empty result. */
    public function verifyPassword(string $username): array;

    /** Preserve a failure already published by native policy or an extension. */
    public function verificationFailed(): bool;

    /** @return array<array-key, mixed> Current local account, including extension fields. */
    public function reload(string $username): array;

    /**
     * Read the submitted password only when an upgrade is needed. Persist through
     * the existing transaction that preserves other sessions and credential generation.
     * @param array<array-key, mixed> $account
     */
    public function rehashIfNeeded(array $account, string $verifiedHash): bool;

    public function deny(): void;
}
