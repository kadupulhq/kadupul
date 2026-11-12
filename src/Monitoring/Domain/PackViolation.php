<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** One reason a pack document is invalid, with the JSON pointer of the failing location. */
final readonly class PackViolation
{
    /**
     * @param string $pointer RFC 6901 JSON pointer of the failing location, or "(document)" for the root
     */
    public function __construct(public string $pointer, public string $message) {}

    /** The pointer and message on one line. */
    public function toString(): string
    {
        return $this->pointer . ': ' . $this->message;
    }
}
