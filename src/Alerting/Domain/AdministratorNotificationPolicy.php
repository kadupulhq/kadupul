<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Domain;

final readonly class AdministratorNotificationPolicy
{
    public function __construct(public int $administratorId, public bool $enabled) {}

    public function suppression(): ?AdministratorNotificationStatus
    {
        if ($this->administratorId <= 0) {
            return AdministratorNotificationStatus::NotConfigured;
        }

        return $this->enabled ? null : AdministratorNotificationStatus::Disabled;
    }
}
