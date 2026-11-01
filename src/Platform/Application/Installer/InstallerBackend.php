<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Installer;

use Kadupul\Platform\Domain\Installer\InstallerOptions;

/** The native bootstrap, settings and migration engine remain behind this boundary. */
interface InstallerBackend
{
    public function authorize(): void;

    /** @return array<string, mixed> Read-only observations, excluding credentials. */
    public function inspect(): array;

    /** @return array<string, mixed> Validates and saves through the native installer. */
    public function configure(InstallerOptions $options): array;

    /** Atomically reserves a worker against the current reviewed settings. */
    public function start(string $revision, bool $retry, bool $discoveryConfirmed): void;

    /** Recover a stopped owned job, never terminate a live worker. */
    public function recover(int $attempt): void;

    /** @return array<string, mixed> Never starts, restarts or resets a worker. */
    public function status(): array;
}
