<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul;

use Kadupul\Platform\Infrastructure\Symfony\CacheDirectoryLock;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    private ?CacheDirectoryLock $cacheLock = null;

    /**
     * Web requests keep the build directory from being swapped by cache:clear
     * until shutdown, because the container loads service files lazily by path.
     * Console processes are excluded: the installer worker boots a kernel and
     * then runs cache:clear as its own child.
     */
    #[\Override]
    public function boot(): void
    {
        if (!$this->booted && $this->cacheLock === null && !\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            $this->cacheLock = CacheDirectoryLock::shared($this->getBuildDir());
        }
        parent::boot();
    }

    #[\Override]
    public function shutdown(): void
    {
        parent::shutdown();
        $this->cacheLock?->release();
        $this->cacheLock = null;
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
