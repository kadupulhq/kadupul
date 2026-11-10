<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Logging;

use Kadupul\Alerting\Application\Port\AlertingLog;
use Psr\Log\LoggerInterface;

/** The alerting log on the platform's PSR-3 logger. */
final readonly class PsrAlertingLog implements AlertingLog
{
    public function __construct(private LoggerInterface $logger) {}

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}
