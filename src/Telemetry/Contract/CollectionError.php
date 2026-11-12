<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * A problem found while collecting or normalizing readings, with the instant it concerns when known.
 */
final readonly class CollectionError
{
    /**
     * Holds the error fields as given.
     */
    public function __construct(
        public CollectionErrorCode $code,
        public string $message,
        public ?\DateTimeImmutable $at = null,
    ) {}
}
