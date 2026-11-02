<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Installer;

/** Typed handoff to the existing, globally bootstrapped migration engine. */
interface InstallerEngine
{
    /** @param array<string, mixed> $options */
    public static function reviewed(array $options): self;

    /** @return array<string, string> */
    public static function snmpOptionNames(): array;

    /** @return array{ready: bool, complete: bool, mode: int, pending_collectors: int} */
    public function installationResult(): array;

    public function executeReviewedInstallation(): void;

    public static function synchronizeCollectors(): int;

    public static function finishReviewedInstallation(): void;
}
