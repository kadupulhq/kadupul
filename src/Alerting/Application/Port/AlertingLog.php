<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/**
 * Operator-facing log records from the alerting use cases. Messages use
 * PSR-3 {placeholder} syntax; context holds ids, counts and reasons that
 * name columns or fixed error text, never stored values or credentials.
 */
interface AlertingLog
{
    /** @param array<string, int|string|null> $context */
    public function warning(string $message, array $context = []): void;

    /** @param array<string, int|string|null> $context */
    public function error(string $message, array $context = []): void;
}
