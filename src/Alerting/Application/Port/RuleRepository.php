<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Domain\Rule\AlertRule;

interface RuleRepository
{
    /**
     * Up to $limit rules with an id greater than $afterRuleId, in ascending id order.
     *
     * @return list<AlertRule>
     */
    public function batchAfter(int $afterRuleId, int $limit): array;
}
