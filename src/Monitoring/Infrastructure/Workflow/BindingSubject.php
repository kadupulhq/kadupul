<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Monitoring\Infrastructure\Workflow;

use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingStatus;

/**
 * One request to move a binding. `$actorId` is what the caller claims; the
 * approval guard checks it against the actor IdentityAccess resolves.
 */
final class BindingSubject implements StatusSubject
{
    /**
     * @param string $actorId the actor the caller claims to be
     * @param BindingStatus $status where the move starts; a new request starts as proposed
     */
    public function __construct(
        public readonly BindingKey $key,
        public readonly string $actorId,
        private BindingStatus $status = BindingStatus::Proposed,
    ) {}

    /** The binding status as the workflow place name. */
    #[\Override]
    public function place(): string
    {
        return $this->status->value;
    }

    /**
     * Sets the status from a workflow place name.
     *
     * @throws \ValueError when the name is not a binding status
     */
    #[\Override]
    public function moveTo(string $place): void
    {
        $this->status = BindingStatus::from($place);
    }
}
