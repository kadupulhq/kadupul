<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\ReadModel;

/** The incident page: the incident, its rule and series, and its notifications oldest first. */
final readonly class IncidentDetail
{
    /** @param list<NotificationView> $notifications */
    public function __construct(
        public IncidentView $view,
        public array $notifications,
    ) {}
}
