<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Query;

use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\Platform\Application\Port\PermissionDeniedContent;
use Kadupul\Platform\Application\ReadModel\PermissionDenied;

final readonly class ViewPermissionDenied
{
    public function __construct(private AuthenticatedAccess $access, private PermissionDeniedContent $content) {}

    public function __invoke(): PermissionDenied
    {
        $actor = $this->access->authenticatedActor();
        if ($actor === null) {
            // The console's anonymous refusal: a redirect to the installation login.
            throw new ConsoleHomeAccessDenied(true);
        }

        return $this->content->forActor($actor);
    }
}
