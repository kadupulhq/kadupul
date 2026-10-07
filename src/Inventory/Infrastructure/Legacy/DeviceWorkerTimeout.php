<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Domain\DeviceMaintenanceState;

/** Leave the existing local-work margin after every allowed remote call. */
final class DeviceWorkerTimeout
{
    public const LOCAL_WORK_SECONDS = 120;
    // Both run_data_query and the remote connectivity probe cap HTTP at 300s.
    // Budget against that cap so a concurrent configuration increase is safe.
    public const REMOTE_CALL_SECONDS = 300;

    public static function forRemoteCalls(int $calls): int
    {
        if ($calls < 0 || $calls > intdiv(PHP_INT_MAX - self::LOCAL_WORK_SECONDS, self::REMOTE_CALL_SECONDS)) {
            throw new \InvalidArgumentException('Invalid remote call count');
        }
        return self::LOCAL_WORK_SECONDS + self::REMOTE_CALL_SECONDS * $calls;
    }

    /** @param array<string, mixed> $command */
    public static function assignment(string $kind, array $command): int
    {
        $reindexes = $kind === 'associations' && ($command['kind'] ?? null) === 'query'
            && in_array($command['operation'] ?? null, ['add', 'change'], true);
        return self::forRemoteCalls($reindexes ? 1 : 0);
    }

    /** Called after the use case's visibility and revision checks. */
    public static function maintenance(DeviceMaintenanceState $state, DeviceMaintenanceRequest $request): int
    {
        if ($state->device->pollerId <= 1 || !in_array($request->operation, ['reindex', 'reload-query', 'query-diagnostics', 'connectivity'], true)) {
            return self::forRemoteCalls(0);
        }
        // Count the exact immutable state whose revision the worker must accept,
        // not a separate query that could observe a transient association set.
        $calls = $request->operation === 'reindex' ? count($state->queries) : 1;
        return self::forRemoteCalls($calls);
    }
}
