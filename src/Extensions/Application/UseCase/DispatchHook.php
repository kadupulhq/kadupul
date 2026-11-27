<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Application\UseCase;

use Kadupul\Extensions\Application\Port\HookBus;
use Kadupul\Extensions\Contract\HookDispatcher;
use Kadupul\Extensions\Contract\HookEvent;

final readonly class DispatchHook implements HookDispatcher
{
    public function __construct(private HookBus $bus) {}

    #[\Override]
    public function dispatch(HookEvent $event): HookEvent
    {
        return $this->bus->publish($event);
    }
}
