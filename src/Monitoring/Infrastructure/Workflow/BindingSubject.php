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
    public function __construct(
        public readonly BindingKey $key,
        public readonly string $actorId,
        private BindingStatus $status = BindingStatus::Proposed,
    ) {}

    #[\Override]
    public function place(): string
    {
        return $this->status->value;
    }

    #[\Override]
    public function moveTo(string $place): void
    {
        $this->status = BindingStatus::from($place);
    }
}
