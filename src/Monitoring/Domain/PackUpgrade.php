<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * What an upgrade changed. Existing plans are not touched by an upgrade;
 * replanning is a separate, explicit step that reports these differences.
 */
final readonly class PackUpgrade
{
    /**
     * @param list<string> $addedMetrics
     * @param list<string> $removedMetrics
     * @param list<string> $changedMetrics
     */
    public function __construct(
        public SemanticVersion $previousVersion,
        public InstalledPack $pack,
        public array $addedMetrics,
        public array $removedMetrics,
        public array $changedMetrics,
    ) {}
}
