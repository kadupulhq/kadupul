<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Holds the exclusive cache lock for every cache:clear run: the installer and
 * upgrade_database.php refresh, and operators' own deployments alike.
 */
final class CacheClearLock implements EventSubscriberInterface
{
    private ?CacheDirectoryLock $lock = null;

    public function __construct(private readonly KernelInterface $kernel) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        // cache:clear copies terminate listeners before it clears the
        // container, so the release below still runs; process exit also
        // releases the lock.
        return [
            ConsoleEvents::COMMAND => ['acquire', 1024],
            ConsoleEvents::TERMINATE => ['release', -1024],
        ];
    }

    public function acquire(ConsoleCommandEvent $event): void
    {
        if ($this->lock === null && $event->getCommand()?->getName() === 'cache:clear') {
            $this->lock = CacheDirectoryLock::exclusive($this->kernel->getBuildDir());
        }
    }

    public function release(): void
    {
        $this->lock?->release();
        $this->lock = null;
    }
}
