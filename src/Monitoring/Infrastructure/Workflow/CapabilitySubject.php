<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Monitoring\Infrastructure\Workflow;

use Kadupul\Monitoring\Domain\CapabilityStatus;

/** A capability whose marking is its CapabilityStatus, advertised until moved. */
final class CapabilitySubject implements StatusSubject
{
    /** Starts the subject at the given status. */
    public function __construct(private CapabilityStatus $status = CapabilityStatus::Advertised) {}

    /** The capability status as the workflow place name. */
    #[\Override]
    public function place(): string
    {
        return $this->status->value;
    }

    /**
     * Sets the status from a workflow place name.
     *
     * @throws \ValueError when the name is not a capability status
     */
    #[\Override]
    public function moveTo(string $place): void
    {
        $this->status = CapabilityStatus::from($place);
    }
}
