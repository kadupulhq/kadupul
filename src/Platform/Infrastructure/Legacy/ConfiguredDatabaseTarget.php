<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseTarget;
use Doctrine\DBAL\Connection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(DatabaseTarget::class)]
final readonly class ConfiguredDatabaseTarget implements DatabaseTarget
{
    public function __construct(private LegacyConfiguration $configuration, #[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    public function capture(): string
    {
        $values = $this->configuration->values();
        // Use the exact read session, including web credentials and USE drift.
        $native = $this->database->getNativeConnection();
        if (!$native instanceof \PDO) {
            throw new \RuntimeException('The database target could not be confirmed.');
        }
        return DatabaseFingerprint::capture($native, [
            'collector_id' => $values['collector_id'] ?? null,
            'connection_status' => $values['connection_status'] ?? 'online',
            'host' => $values['host'] ?? null,
            'port' => $values['port'] ?? null,
        ]);
    }
}
