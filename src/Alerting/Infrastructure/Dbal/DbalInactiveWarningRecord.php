<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Kadupul\Alerting\Application\Port\InactiveWarningRecord;

/**
 * The settings row alerting_inactive_warning_lastrun. Its "_lastrun" name
 * keeps it out of the settings replicate_out() copies to remote collectors
 * (lib/poller.php), as the other per-poller run times are. A value that is
 * not a whole number reads as no record, so the warning is logged again.
 */
final readonly class DbalInactiveWarningRecord implements InactiveWarningRecord
{
    public const string SETTING = 'alerting_inactive_warning_lastrun';

    public function __construct(private Connection $database) {}

    public function lastWarnedAt(): ?int
    {
        $value = $this->database->fetchOne('SELECT value FROM settings WHERE name = ?', [self::SETTING]);

        return is_string($value) && preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) === 1 ? (int) $value : null;
    }

    public function warnedAt(int $at): void
    {
        $this->database->executeStatement('REPLACE INTO settings (name, value) VALUES (?, ?)', [self::SETTING, (string) $at]);
    }
}
