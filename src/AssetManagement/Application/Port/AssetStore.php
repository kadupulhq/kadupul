<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Application\Port;

/**
 * @phpstan-import-type Snapshot from \Kadupul\AssetManagement\Domain\AssetData
 * @phpstan-import-type Result from \Kadupul\AssetManagement\Domain\AssetData
 * @phpstan-import-type History from \Kadupul\AssetManagement\Domain\AssetData
 */
interface AssetStore
{
    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public function atomic(\Closure $operation): mixed;
    /**
     * @phpstan-return Snapshot
     */
    public function snapshot(): array;
    /**
     * @phpstan-param Snapshot $before
     * @phpstan-param Snapshot $after
     */
    public function save(array $before, array $after): void;
    /**
     * @phpstan-return array{actor_id: int, command_hash: string, result: Result}|null
     */
    public function receipt(string $requestId): ?array;
    /**
     * @param array<string, mixed> $event
     */
    public function journal(array $event): void;
    /**
     * @param list<array<string, mixed>> $observations
     */
    public function observe(array $observations): void;
    /**
     * @phpstan-return list<History>
     */
    public function history(int $before, int $limit): array;
}
