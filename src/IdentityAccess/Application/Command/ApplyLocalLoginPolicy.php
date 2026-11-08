<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\LocalLoginPolicy;

/** Run only after password verification; this operation does not issue a session. */
final readonly class ApplyLocalLoginPolicy
{
    public function __construct(private LocalLoginPolicy $policy) {}

    /** @param array<array-key, mixed> $account Verified native account fields. */
    public function __invoke(string $username, #[\SensitiveParameter] string $password, array $account): bool
    {
        if ($this->policy->requiresComplexity() && !$this->policy->satisfiesComplexity($password)) {
            $mayChange = $account['password_change'] ?? '';
            if ($mayChange === 'on' || $mayChange === true) {
                // The caller must complete login so the password-change page
                // has an authenticated session. Do not redirect from here.
                $this->policy->requirePasswordChange($account['id']);
            } else {
                $this->policy->denyComplexity($username);
                return false;
            }
        }

        if ($this->policy->tracksLastLogin()) {
            $this->policy->recordLastLogin($username);
        }

        return true;
    }
}
