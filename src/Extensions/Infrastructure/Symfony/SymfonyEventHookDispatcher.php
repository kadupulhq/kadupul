<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Infrastructure\Symfony;

use Kadupul\Extensions\Application\Port\HookBus;
use Kadupul\Extensions\Contract\HookDispatcher;
use Kadupul\Extensions\Contract\HookEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final readonly class SymfonyEventHookDispatcher implements HookBus, HookDispatcher
{
    public const string EVENT_PREFIX = 'kadupul.hook.';

    public function __construct(private EventDispatcherInterface $dispatcher) {}

    #[\Override]
    public function publish(HookEvent $event): HookEvent
    {
        $envelope = new DispatchedHookEvent($event);
        $this->dispatcher->dispatch($envelope, self::EVENT_PREFIX . $event->hook);

        return $envelope->event();
    }

    #[\Override]
    public function dispatch(HookEvent $event): HookEvent
    {
        return $this->publish($event);
    }
}
