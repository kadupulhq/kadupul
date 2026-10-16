<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Application\ReadModel\HelpTopic;

interface HelpTopics
{
    /** @return list<HelpTopic> */
    public function all(): array;
}
