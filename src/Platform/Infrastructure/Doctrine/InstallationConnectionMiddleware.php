<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

#[AsMiddleware(connections: ['local', 'main', 'web'])]
final readonly class InstallationConnectionMiddleware implements Middleware
{
    /**
     * Connections are built with the services that use them, including for
     * requests that are refused before authentication. The closure defers
     * even instantiating the configuration until a connection opens.
     *
     * @param \Closure(): InstallationConfiguration $configuration
     */
    public function __construct(#[AutowireServiceClosure(InstallationConfiguration::class)] private \Closure $configuration) {}

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new InstallationConnectionDriver($driver, $this->configuration);
    }
}
