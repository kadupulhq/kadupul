<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Installer;

final class InstallationPhase
{
    /** Native progress checkpoints are observations, not guarantees of SQL rollback. */
    public static function label(int $progress): string
    {
        if ($progress >= 100) {
            return 'Finished execution';
        }
        if ($progress >= 80) {
            return 'Confirming database version';
        }
        if ($progress >= 77) {
            return 'Synchronizing collectors';
        }
        if ($progress >= 71) {
            return 'Configuring the local device';
        }
        if ($progress >= 66) {
            return 'Configuring discovery';
        }
        if ($progress >= 61) {
            return 'Configuring collection profiles';
        }
        if ($progress >= 41) {
            return 'Importing templates';
        }
        if ($progress >= 35) {
            return 'Converting selected tables';
        }
        if ($progress >= 5) {
            return 'Applying database upgrades';
        }
        if ($progress >= 2) {
            return 'Preparing CSRF protection';
        }
        return 'Waiting for the installation worker';
    }
}
