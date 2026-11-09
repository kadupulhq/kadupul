<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Reporting\Contract;

/**
 * @phpstan-type PlacementRecord array<string, mixed>
 */
interface DeviceReportPlacement
{
    public function defaultTimespan(int $actorId): int;

    /** @return array<string, string> Accessible destination IDs and labels. */
    public function destinations(int $actorId): array;

    /**
     * Called inside an authenticated primary transaction with all devices locked.
     *
     * @param non-empty-list<int> $deviceIds
     * @param list<PlacementRecord> $expected the receipt returned by place()
     */
    public function verify(int $actorId, array $deviceIds, int $reportId, int $timespan, int $alignment, array $expected): void;

    /**
     * @param non-empty-list<int> $deviceIds
     * @return list<PlacementRecord> the stored placement rows, in id order
     */
    public function place(int $actorId, array $deviceIds, int $reportId, int $timespan, int $alignment): array;
}
