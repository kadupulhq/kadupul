<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Domain;

/** Collector edit data never contains the stored remote database password. */
final readonly class CollectorDetails
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public int $id,
        public string $name,
        public string $hostname,
        public string $timezone,
        public string $notes,
        public int $processes,
        public int $threads,
        public int $syncInterval,
        public array $settings,
        public bool $passwordConfigured,
        public string $revision,
    ) {}
}
