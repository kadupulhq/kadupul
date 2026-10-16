<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

/** Output of installed, trusted PHP extensions; never client-supplied markup. */
final readonly class ConsoleCompatibilityContent
{
    /**
     * @param array<string, string> $policies
     * @param array<string, string> $chrome
     */
    public function __construct(
        public string $head,
        public string $before,
        public string $after,
        public string $bottom,
        public array $policies,
        public string $nonce,
        public array $chrome = [],
        public bool $fullPage = true,
        public bool $headerContent = false,
    ) {}
}
