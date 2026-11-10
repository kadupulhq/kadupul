<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Application\Query\AlertingAccessDenied;
use Kadupul\IdentityAccess\Contract\Actor;

/** The Alert Rules realm (ADR 0011 decision 12): view, create, edit and delete rules. */
interface RuleAccess
{
    /** @throws AlertingAccessDenied before any rule is read */
    public function authorize(): Actor;

    /** Recheck the account, the console realm and the Alert Rules realm inside the caller's write transaction. */
    public function assertCurrent(int $actorId): void;
}
