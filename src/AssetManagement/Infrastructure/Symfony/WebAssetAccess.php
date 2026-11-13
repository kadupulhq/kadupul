<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Symfony;

use Kadupul\AssetManagement\Application\Port\AssetAccess;
use Kadupul\AssetManagement\Domain\AssetError;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AssetManagementPermission;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

/**
 * Grants asset register access to a signed-in web user with the asset management permission, when the feature is enabled and the host is not a remote collector.
 */
final readonly class WebAssetAccess implements AssetAccess
{
    /**
     * Binds the check to the feature flag, the console session, the permission and the collector topology.
     */
    public function __construct(private bool $enabled, private ConsoleAccess $access, private AssetManagementPermission $permission, private CollectorTopology $topology) {}

    /**
     * Returns the signed-in user.
     * Throws AssetError when the feature is disabled, the host is a remote collector, nobody is signed in, or the user lacks the permission.
     */
    public function requireActor(): Actor
    {
        if (!$this->enabled) {
            throw new AssetError('disabled');
        }
        if ($this->topology->isRemoteCollector()) {
            throw new AssetError('denied');
        }
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new AssetError('unauthenticated');
        }
        if (!$this->permission->mayManageAssets($actor)) {
            throw new AssetError('denied');
        }
        return $actor;
    }
}
