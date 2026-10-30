<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Monitoring\Infrastructure\Workflow;

use Kadupul\Monitoring\Application\Port\BindingApprovalAuthorizer;
use Kadupul\Monitoring\Domain\BindingKey;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Asks the `metric_binding` state machine whether `approve` is enabled. The
 * subject starts as proposed so only the guard, which holds the permission
 * rule, can say no; whether the stored binding may move is ApproveBinding's
 * and the domain's decision.
 */
final readonly class WorkflowBindingApprovalAuthorizer implements BindingApprovalAuthorizer
{
    public function __construct(private WorkflowInterface $bindingWorkflow) {}

    #[\Override]
    public function isAuthorized(string $actorId, BindingKey $key): bool
    {
        return $this->bindingWorkflow->can(new BindingSubject($key, $actorId), 'approve');
    }
}
