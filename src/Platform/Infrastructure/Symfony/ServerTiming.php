<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\Platform\Infrastructure\Profiling\RequestProfileLog;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Exposes request timing for the migration comparison. Legacy pages stream
 * their output, so only the migrated side can carry this in a header.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class ServerTiming
{
    public function __construct(
        private RequestProfileLog $log,
        // DoctrineBundle defines the holder in every environment but attaches
        // its middleware only to connections profiled under kernel.debug.
        #[Autowire(param: 'kernel.debug')]
        private bool $profiling,
        #[Autowire(service: 'doctrine.debug_data_holder')]
        private ?DebugDataHolder $queries = null,
    ) {}

    #[AsEventListener(event: 'kernel.response')]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $parts = [sprintf('app;dur=%s', $this->milliseconds($event->getRequest()->server->get('REQUEST_TIME_FLOAT')))];
        $queries = $this->queryCount();
        if ($queries !== null) {
            $parts[] = sprintf('db;desc="%d queries"', $queries);
        }
        $parts[] = sprintf('mem;desc="%d"', memory_get_peak_usage(true));

        $event->getResponse()->headers->set('Server-Timing', implode(', ', $parts));
    }

    #[AsEventListener(event: 'kernel.terminate')]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $id = $request->headers->get('X-Kadupul-Profile-Id');
        if ($id === null) {
            return;
        }

        $this->log->append(
            $id,
            $request->getPathInfo(),
            (float) $this->milliseconds($request->server->get('REQUEST_TIME_FLOAT')),
            $this->queryCount(),
            memory_get_peak_usage(true),
        );
    }

    // Null means the counter is unavailable; a zero here would read as a real
    // measurement and let a query regression pass.
    private function queryCount(): ?int
    {
        if (!$this->profiling || !$this->queries instanceof DebugDataHolder) {
            return null;
        }

        return array_sum(array_map(count(...), $this->queries->getData()));
    }

    private function milliseconds(mixed $start): string
    {
        return sprintf('%.3f', is_numeric($start) ? (microtime(true) - (float) $start) * 1000 : 0.0);
    }
}
