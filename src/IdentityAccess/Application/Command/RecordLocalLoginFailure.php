<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\AccountLockoutFailure;
use Kadupul\IdentityAccess\Application\Port\AccountLockoutStatus;
use Kadupul\IdentityAccess\Application\ReadModel\LocalLoginFailure;

final readonly class RecordLocalLoginFailure
{
    public function __construct(
        private AccountLockoutStatus $status,
        private AccountLockoutFailure $failure,
    ) {}

    public function __invoke(string $username, int|string $realm, int $timestamp, string $address): ?LocalLoginFailure
    {
        $configuredLimit = $this->status->failureLimit();
        if ($configuredLimit <= 0) {
            return null;
        }
        $limit = $configuredLimit;

        $account = $this->failure->find($username, $realm);
        if ($account === null) {
            return new LocalLoginFailure(false, false, false, null, $limit);
        }

        // Keep the counter increment atomic across concurrent failed requests.
        $this->failure->increment($username, $realm, $timestamp);
        if ($account['enabled'] === '') {
            return new LocalLoginFailure(true, true, false, null, $limit);
        }

        $failed = $this->failure->failedAttempts($username, $realm);
        $this->failure->recordProgress($username, $failed, $limit);
        $locked = $account['locked'] === 'on';
        if ($failed >= $limit && !$locked) {
            $this->failure->lock($username, $realm);
            $locked = true;
        }
        $this->failure->recordAudit($username, $account['id'], $address);

        return new LocalLoginFailure(true, false, $locked, $failed, $limit);
    }
}
