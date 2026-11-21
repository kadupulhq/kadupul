<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Trusted native presentation output, created after the logout hooks run. */
#[Exclude]
final readonly class NativeLogoutPage
{
    /**
     * @param array<string, string> $policies
     * @param array{target: string, action: string, token: string, label: string}|null $confirmation
     */
    public function __construct(
        public string $head,
        public string $footer,
        public string $message,
        public string $loginPrompt,
        public string $heading,
        public string $version,
        public string $copyright,
        public string $nonce,
        public array $policies,
        public ?array $confirmation = null,
    ) {}
}
