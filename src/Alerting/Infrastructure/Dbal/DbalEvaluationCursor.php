<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Kadupul\Alerting\Application\Port\EvaluationCursor;

/**
 * The cursor is the settings row alerting_evaluation_cursor; a missing row is
 * the start. A run that ends where it began writes nothing.
 */
final readonly class DbalEvaluationCursor implements EvaluationCursor
{
    private const string SETTING = 'alerting_evaluation_cursor';

    public function __construct(private Connection $database) {}

    public function position(): int
    {
        $value = $this->database->fetchOne('SELECT value FROM settings WHERE name = ?', [self::SETTING]);
        if ($value === false) {
            return 0;
        }
        $position = StoredValue::int($value, 'settings ' . self::SETTING, 'value');
        if ($position < 0) {
            throw StoredAlertingRowUnreadable::because('settings ' . self::SETTING, 'value is negative.');
        }

        return $position;
    }

    public function moveTo(int $afterRuleId): void
    {
        if ($afterRuleId < 0) {
            throw new \InvalidArgumentException('The evaluation cursor cannot be negative.');
        }
        if ($afterRuleId === $this->position()) {
            return;
        }
        $this->database->executeStatement('REPLACE INTO settings (name, value) VALUES (?, ?)', [self::SETTING, (string) $afterRuleId]);
    }
}
