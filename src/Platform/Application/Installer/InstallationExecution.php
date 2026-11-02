<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Application\Installer;

use Kadupul\Platform\Domain\Installer\InstallationMode;

/** Coordinate the native installation once the existing worker admission has succeeded. */
final readonly class InstallationExecution
{
    /** Failures stop later phases; thrown native errors propagate without publishing completion. */
    public function run(InstallationActions $actions, InstallationMode $mode): void
    {
        $actions->checkInstallationStorage();
        $actions->refreshInstallationCache();
        $actions->beginInstallation($mode->processName());
        $actions->initializeInstallationSecret();
        $actions->convertInstallationTables();
        $failure = '';
        if ($mode === InstallationMode::Collector) {
            $failure = $actions->installRemoteCollector();
        } else {
            if ($mode === InstallationMode::Install) {
                $failure = $actions->installSelectedTemplates();
                if ($failure === '') {
                    $failure = $actions->installPrimaryServer();
                }
            } elseif ($mode === InstallationMode::Upgrade) {
                $failure = $actions->upgradeInstallationDatabase();
                if ($failure === '') {
                    $failure = $actions->installSelectedTemplates();
                }
            }
            $actions->disableIncompatiblePlugins();
        }
        if ($failure === '') {
            $failure = $actions->migrateInstallationDefaults();
        }
        if ($failure === '' && $mode !== InstallationMode::Collector && $actions->isPrimaryInstallation()) {
            $failure = $actions->installPrimaryReferenceContract();
        }
        if ($failure === '') {
            $failure = $actions->confirmInstallationVersion();
        }
        $actions->finishInstallation($mode->processName(), $failure);
        if ($failure === '' && $mode !== InstallationMode::Collector) {
            $actions->synchronizeInstallationCollectors();
        }
        $actions->completeInstallation($failure);
    }
}
