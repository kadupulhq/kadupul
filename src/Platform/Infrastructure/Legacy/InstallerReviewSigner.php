<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class InstallerReviewSigner
{
    public function __construct(#[Autowire('%kernel.secret%')] #[\SensitiveParameter] private readonly string $secret) {}

    public function sign(#[\SensitiveParameter] string $settings): string
    {
        if (strlen($this->secret) < 32) {
            throw new \RuntimeException('Configure an application secret of at least 32 bytes before reviewing installation plans.');
        }
        // The review fingerprint must not become an offline oracle for stored
        // SNMP credentials, even to an operator who can view installation plans.
        return hash_hmac('sha256', $settings, $this->secret);
    }
}
