<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Kadupul\Alerting\Application\AlertingConfiguration;
use Kadupul\Alerting\Application\Port\AlertingSettings;

/**
 * Reads the alerting settings rows that include/global_settings.php defines.
 * As read_config_option() reads them, a missing row is the field's default:
 * alerting_enable is off unless it is exactly 'on'. The bounds are drop-down
 * choices, so a value that is not a canonical whole number within the caps
 * was not written by the form and falls back to its default.
 */
final readonly class DbalAlertingSettings implements AlertingSettings
{
    public function __construct(private Connection $database) {}

    public function current(): AlertingConfiguration
    {
        $rows = $this->database->fetchAllKeyValue(
            'SELECT name, value FROM settings WHERE name IN (?)',
            [['alerting_enable', 'alerting_batch_size', 'alerting_time_budget']],
            [ArrayParameterType::STRING],
        );

        return new AlertingConfiguration(
            ($rows['alerting_enable'] ?? '') === 'on',
            self::bounded($rows['alerting_batch_size'] ?? null, AlertingConfiguration::MAX_BATCH_SIZE) ?? AlertingConfiguration::DEFAULT_BATCH_SIZE,
            (float) (self::bounded($rows['alerting_time_budget'] ?? null, AlertingConfiguration::MAX_BUDGET_SECONDS) ?? AlertingConfiguration::DEFAULT_BUDGET_SECONDS),
        );
    }

    private static function bounded(mixed $value, int $max): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,8}$/D', $value) !== 1) {
            return null;
        }
        $number = (int) $value;

        return $number <= $max ? $number : null;
    }
}
