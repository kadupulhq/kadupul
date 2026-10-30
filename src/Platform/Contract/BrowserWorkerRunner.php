<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/** Authenticated browser handoff; feature gateways choose a fixed owned worker. */
interface BrowserWorkerRunner
{
    /**
     * Mutation callers authorize the use case and validate CSRF before dispatch.
     * Their worker rechecks mutable authorization and revisions at its mutation
     * boundary. Read mode accepts GET/HEAD; mutation mode requires POST.
     *
     * @param array<string, mixed> $entry Fixed native page path and operation data, without session-binding keys.
     * @return array<string, mixed> Confirmed worker envelope; feature gateways validate their operation outcome.
     */
    public function run(string $script, #[\SensitiveParameter] array $entry, int $actorId, float $timeout = 60, bool $forwardRequest = true, BrowserWorkerMode $mode = BrowserWorkerMode::Read): array;
}
