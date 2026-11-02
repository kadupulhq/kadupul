<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Application\Installer;

/** Privileged native capabilities available to the authorized installer worker. */
interface InstallationActions
{
    /** Reject invalid storage configuration before cache or schema changes. */
    public function checkInstallationStorage(): void;

    /** Refresh derived production state before schema work. */
    public function refreshInstallationCache(): void;

    /** Record the native start message and initial progress. */
    public function beginInstallation(string $process): void;

    /** Initialize the shared secret before schema conversion. */
    public function initializeInstallationSecret(): void;

    /** Convert selected tables using the native maintenance contract. */
    public function convertInstallationTables(): void;

    /** Return an empty string on success or a native failure message. */
    public function installRemoteCollector(): string;

    /** Import selected packages and retain their native diagnostics. */
    public function installSelectedTemplates(): string;

    /** Initialize the primary server and return its failure message. */
    public function installPrimaryServer(): string;

    /** Run historical upgrades, normalizing their false success value. */
    public function upgradeInstallationDatabase(): string;

    /** Apply native plugin compatibility cleanup in its original position. */
    public function disableIncompatiblePlugins(): void;

    /** Apply product defaults without reporting an unconfirmed success. */
    public function migrateInstallationDefaults(): string;

    /** Read current collector identity after mode-specific operations and defaults. */
    public function isPrimaryInstallation(): bool;

    /** Install the primary-only reference contract and retain native errors. */
    public function installPrimaryReferenceContract(): string;

    /** Publish the version marker only after confirmed persistence. */
    public function confirmInstallationVersion(): string;

    /** Record the finish diagnostic and persist the operation outcome. */
    public function finishInstallation(string $process, string $failure): void;

    /** Retain collector progress and pending-work accounting. */
    public function synchronizeInstallationCollectors(): void;

    /** Select the native completion or error step exactly once. */
    public function completeInstallation(string $failure): void;
}
