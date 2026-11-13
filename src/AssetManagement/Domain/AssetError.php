<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Domain;

/**
 * Raised when an asset register request is refused or fails. The reason is a stable code that callers map to a response.
 */
final class AssetError extends \RuntimeException
{
    /**
     * Keeps the reason code, which is also the exception message.
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
