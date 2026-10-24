<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application;

use Kadupul\Monitoring\Application\Port\BindingApprovalAuthorizer;
use Kadupul\Monitoring\Application\Port\BindingRepository;
use Kadupul\Monitoring\Domain\BindingApprovalDenied;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\BindingTransitionRejected;
use Kadupul\Monitoring\Domain\MetricBinding;

/**
 * Moves a proposed binding to approved. Authorization is checked before the
 * store is read, so an unauthorized actor cannot learn which bindings exist.
 * Approval says where samples would go; it does not hand the field to a new
 * writer, so this use case has no access to writer ownership at all.
 */
final class ApproveBinding
{
    public function __construct(
        private readonly BindingRepository $bindings,
        private readonly ?BindingApprovalAuthorizer $authorizer = null,
    ) {}

    public function approve(BindingKey $key, string $actorId): MetricBinding
    {
        if ($this->authorizer === null) {
            throw new BindingApprovalDenied(sprintf('No binding approval authorizer is configured, so binding %s cannot be approved.', $key->bindingId()));
        }
        if (trim($actorId) === '') {
            throw new BindingApprovalDenied('An actor id is required to approve a binding.');
        }
        if (!$this->authorizer->isAuthorized($actorId, $key)) {
            throw new BindingApprovalDenied(sprintf('%s is not authorized to approve binding %s.', $actorId, $key->bindingId()));
        }
        $found = $this->bindings->matching($key);
        if (count($found) !== 1) {
            throw new BindingTransitionRejected($found === []
                ? sprintf('No binding %s is registered; register it before approving.', $key->bindingId())
                : sprintf('Binding %s is stored %d times; resolve the duplicate before approving.', $key->bindingId(), count($found)));
        }
        $approved = $found[0]->withStatus(BindingStatus::Approved);
        $this->bindings->save($approved);

        return $approved;
    }
}
