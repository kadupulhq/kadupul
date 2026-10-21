<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

final readonly class PermissionDenied
{
    /**
     * @param ?string $returnTo the Referer after validate_redirect_url(), or null when the browser sent none
     * @param ?string $replacement a complete page printed by a custom_denied extension that asked to reskin
     * @param array<string, string> $policies the native CSP headers matching the replacement's nonces
     */
    public function __construct(
        public ?string $returnTo,
        public bool $ajax,
        public string $version,
        public string $copyright,
        public ?string $replacement = null,
        public array $policies = [],
    ) {}
}
