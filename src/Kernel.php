<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul;

use Kadupul\Platform\Infrastructure\Symfony\CacheDirectoryLock;
use Kadupul\Platform\Infrastructure\Symfony\CacheRefreshInProgress;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    private ?CacheDirectoryLock $cacheLock = null;
    private bool $cacheLockChecked = false;

    /**
     * A refresh that outlasts the reader bound answers 503 instead of letting
     * this request load services from a directory being replaced.
     */
    #[\Override]
    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        try {
            $this->lockCache();
        } catch (CacheRefreshInProgress) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '5', 'Cache-Control' => 'no-store']);
        }

        return parent::handle($request, $type, $catch);
    }

    /** @throws CacheRefreshInProgress for direct boots while a refresh runs */
    #[\Override]
    public function boot(): void
    {
        $this->lockCache();
        parent::boot();
    }

    #[\Override]
    public function shutdown(): void
    {
        parent::shutdown();
        $this->cacheLock?->release();
        $this->cacheLock = null;
        $this->cacheLockChecked = false;
    }

    /**
     * Web requests keep the build directory from being swapped by cache:clear
     * until shutdown, because the container loads service files lazily by path.
     * Console processes are excluded: the installer worker boots a kernel and
     * then runs cache:clear as its own child.
     */
    private function lockCache(): void
    {
        if ($this->booted || $this->cacheLockChecked || \in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            return;
        }
        $this->cacheLock = CacheDirectoryLock::shared($this->getBuildDir());
        $this->cacheLockChecked = true;
    }

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        \Kadupul\Platform\Infrastructure\Symfony\ConsoleTranslations::register($container, $this->getProjectDir());

        // In debug mode FrameworkBundle wraps every workflow in a profiler
        // decorator that needs symfony/stopwatch. Stopwatch is not installed,
        // and installing it would also trace every kernel event to stderr.
        if (!class_exists('Symfony\\Component\\Stopwatch\\Stopwatch')) {
            foreach (array_keys($container->findTaggedServiceIds('workflow')) as $id) {
                $container->removeDefinition('debug.' . $id);
            }
        }

        // The installer owns the schema; DoctrineBundle's commands would create, drop or run SQL with full credentials.
        foreach ($container->findTaggedServiceIds('console.command') as $id => $tags) {
            if (array_any($tags, static fn(array $tag): bool => str_starts_with($tag['command'] ?? '', 'doctrine:') || str_starts_with($tag['command'] ?? '', 'dbal:'))) {
                $container->removeDefinition($id);
            }
        }
    }
}
