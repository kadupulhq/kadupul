<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

final readonly class ConsoleHomeContent
{
    public function __construct(
        public bool $showIntroduction,
        public ?ConsoleCollectorStatus $collector = null,
        public ?ConsoleCompatibilityContent $compatibility = null,
    ) {}
}
