<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\BrowserSession;

/** Expose the existing session reader through IdentityAccess's public boundary. */
final readonly class LegacyBrowserSession implements BrowserSession
{
    public function __construct(private SharedSession $session) {}

    public function read(): array
    {
        return $this->session->read();
    }
}
