<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

final readonly class PackViolation
{
    /**
     * @param string $pointer RFC 6901 JSON pointer of the failing location, or "(document)" for the root
     */
    public function __construct(public string $pointer, public string $message) {}

    public function toString(): string
    {
        return $this->pointer . ': ' . $this->message;
    }
}
