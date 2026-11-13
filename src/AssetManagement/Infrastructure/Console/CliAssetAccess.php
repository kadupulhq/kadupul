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

/**
 * Grants asset register access to an installation administrator on the console, when the feature is enabled and the host is not a remote collector.
 */
final class CliAssetAccess implements AssetAccess
{
    public ?int $expectedActor = null;

    /**
     * Binds the check to the feature flag, the selected operator and the collector topology.
     */
    public function __construct(private readonly bool $enabled, private readonly ConsoleOperator $operator, private readonly CollectorTopology $topology) {}

    /**
     * Returns the selected operator.
     * When $expectedActor is set, only that account is accepted.
     *
     * @throws AssetError when the feature is disabled, no administrator is selected, the account is not the expected one, or the host is a remote collector
     */
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
