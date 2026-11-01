<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseTarget;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DatabaseTarget::class)]
final readonly class ConfiguredDatabaseTarget implements DatabaseTarget
{
    public function __construct(private LegacyConfiguration $configuration, private DatabaseConnection $database) {}

    public function capture(): string
    {
        $values = $this->configuration->values();
        // Both admission and this identity use the same native connection.
        return DatabaseFingerprint::capture($this->database->get(), [
            'collector_id' => $values['collector_id'] ?? null,
            'connection_status' => $values['connection_status'] ?? 'online',
            'host' => $values['host'] ?? null,
            'port' => $values['port'] ?? null,
        ]);
    }
}
