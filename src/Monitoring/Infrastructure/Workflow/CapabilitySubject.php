<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Monitoring\Infrastructure\Workflow;

use Kadupul\Monitoring\Domain\CapabilityStatus;

final class CapabilitySubject implements StatusSubject
{
    public function __construct(private CapabilityStatus $status = CapabilityStatus::Advertised) {}

    #[\Override]
    public function place(): string
    {
        return $this->status->value;
    }

    #[\Override]
    public function moveTo(string $place): void
    {
        $this->status = CapabilityStatus::from($place);
    }
}
