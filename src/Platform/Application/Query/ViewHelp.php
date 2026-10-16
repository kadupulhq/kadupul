<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Query;

use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\Platform\Application\Port\HelpTopics;
use Kadupul\Platform\Application\ReadModel\HelpTopic;

final readonly class ViewHelp
{
    public function __construct(private AuthenticatedAccess $access, private HelpTopics $topics) {}

    /** @return list<HelpTopic>|null Null means authentication is required. */
    public function __invoke(): ?array
    {
        $actor = $this->access->authenticatedActor();
        if ($actor === null) {
            return null;
        }

        return $this->topics->all();
    }
}
