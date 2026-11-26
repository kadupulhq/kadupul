<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Infrastructure\Symfony;

use Kadupul\Extensions\Contract\HookEvent;

/**
 * Mutable Symfony event envelope wrapping an immutable HookEvent so listeners
 * may transform the payload without mutating the DTO in place.
 */
final class DispatchedHookEvent
{
    public function __construct(private HookEvent $event) {}

    public function event(): HookEvent
    {
        return $this->event;
    }

    public function replacePayload(mixed $payload): void
    {
        $this->event = $this->event->withPayload($payload);
    }
}
