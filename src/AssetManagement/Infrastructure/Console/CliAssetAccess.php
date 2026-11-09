<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Console;

use Kadupul\AssetManagement\Application\Port\AssetAccess;
use Kadupul\AssetManagement\Domain\AssetError;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\CollectorTopology;

final class CliAssetAccess implements AssetAccess
{
    public ?int $expectedActor = null;

    public function __construct(private readonly bool $enabled, private readonly ConsoleOperator $operator, private readonly CollectorTopology $topology) {}

    public function requireActor(): Actor
    {
        if (!$this->enabled) {
            throw new AssetError('disabled');
        }
        $actor = $this->operator->actor();
        if ($actor === null || !$this->operator->canAdministerInstallation($actor) || $this->expectedActor !== null && $actor->id !== $this->expectedActor || $this->topology->isRemoteCollector()) {
            throw new AssetError('denied');
        }
        return $actor;
    }
}
