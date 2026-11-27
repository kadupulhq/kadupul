<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Extensions;

use Kadupul\Extensions\Contract\HookEvent;
use Kadupul\Extensions\Infrastructure\Symfony\DispatchedHookEvent;
use Kadupul\Extensions\Infrastructure\Symfony\SymfonyEventHookDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SymfonyEventHookDispatcherTest extends TestCase
{
    public function testDispatchesTypedHookEventAndAllowsListenerPayloadUpdates(): void
    {
        $symfonyDispatcher = new EventDispatcher();
        $observed = [];

        $symfonyDispatcher->addListener(
            SymfonyEventHookDispatcher::EVENT_PREFIX . 'top_header_tabs',
            static function (DispatchedHookEvent $envelope) use (&$observed): void {
                $observed[] = $envelope->event()->hook;
                $payload = is_array($envelope->event()->payload) ? $envelope->event()->payload : [];
                $payload[] = 'monitor';
                $envelope->replacePayload($payload);
            },
        );

        $dispatcher = new SymfonyEventHookDispatcher($symfonyDispatcher);
        $initial = new HookEvent('top_header_tabs', ['console']);
        $result = $dispatcher->dispatch($initial);

        $this->assertSame(['top_header_tabs'], $observed);
        $this->assertSame(['console'], $initial->payload);
        $this->assertSame(['console', 'monitor'], $result->payload);
    }

    public function testUnlistenedHookReturnsOriginalEventUnchanged(): void
    {
        $dispatcher = new SymfonyEventHookDispatcher(new EventDispatcher());
        $event = new HookEvent('poller_bottom', 'done');

        $this->assertSame($event, $dispatcher->dispatch($event));
    }
}
