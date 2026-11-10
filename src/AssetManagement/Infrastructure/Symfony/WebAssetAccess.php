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

final readonly class WebAssetAccess implements AssetAccess
{
    public function __construct(private bool $enabled, private ConsoleAccess $access, private AssetManagementPermission $permission, private CollectorTopology $topology) {}

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
