<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Query;

use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Application\Port\ConsoleHomeContent;
use Kadupul\Platform\Application\Port\ProductVersion;
use Kadupul\Platform\Application\ReadModel\ConsoleHome;

final readonly class ViewConsoleHome
{
    public function __construct(
        private AuthenticatedAccess $authenticated,
        private ConsoleAccess $console,
        private ConsoleHomeContent $content,
        private ProductVersion $version,
    ) {}

    public function __invoke(): ConsoleHome
    {
        $actor = $this->authenticated->authenticatedActor();
        if ($actor === null) {
            throw new ConsoleHomeAccessDenied(true);
        }
        $consoleActor = $this->console->consoleActor();
        if ($consoleActor === null || $consoleActor->id !== $actor->id) {
            throw new ConsoleHomeAccessDenied(false);
        }

        return new ConsoleHome($this->version->release(), $this->content->forActor($actor));
    }
}
