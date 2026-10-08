<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Platform\Contract\CdefReferenceReadiness;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\CdefReferenceReadiness as NativeReadiness;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Runs the installer-verified native check on the web connection's own session. */
#[AsAlias(CdefReferenceReadiness::class)]
final readonly class DbalCdefReferenceReadiness implements CdefReferenceReadiness
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private LegacyConfiguration $configuration,
    ) {}

    public function assertReady(): void
    {
        $native = $this->database->getNativeConnection();
        if (!$native instanceof \PDO) {
            throw new \RuntimeException('The CDEF reference contract requires a PDO connection.');
        }
        (new NativeReadiness($native, $this->configuration->values()['collector_id'] ?? null))->assertReady();
    }
}
