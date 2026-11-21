<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Logging;

use Kadupul\Alerting\Application\Port\AlertingLog;
use Kadupul\Platform\Contract\OperatorLog;

/**
 * The alerting log in cacti.log under the ALERTING category, the category the
 * delivery worker uses. The poller starts the evaluator with stdout and
 * stderr sent to /dev/null, so cacti.log is where its warnings and errors
 * survive. Warnings are written as "WARNING: ...", errors as "ERROR: ...",
 * which cacti_log() also uses to pick the syslog priority.
 */
final readonly class OperatorAlertingLog implements AlertingLog
{
    public const string CATEGORY = 'ALERTING';

    public function __construct(private OperatorLog $log) {}

    public function warning(string $message, array $context = []): void
    {
        $this->log->record(self::CATEGORY, 'WARNING: ' . self::interpolate($message, $context));
    }

    public function error(string $message, array $context = []): void
    {
        $this->log->record(self::CATEGORY, 'ERROR: ' . self::interpolate($message, $context));
    }

    /**
     * PSR-3 {placeholder} substitution. The port keeps context to ids, counts
     * and fixed reasons; the operator log neutralizes control characters.
     *
     * @param array<string, int|string|null> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }

        return strtr($message, $replacements);
    }
}
