<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application;

/**
 * The stored switch and bounds of a run (ADR 0011 decisions 8 and 10). The
 * defaults are the shipped settings defaults; Task 5 measured them.
 */
final readonly class AlertingConfiguration
{
    public const int DEFAULT_BATCH_SIZE = 500;
    public const float DEFAULT_BUDGET_SECONDS = 30.0;
    /** The largest batch size and budget a setting or command option may name. */
    public const int MAX_BATCH_SIZE = 10000;
    public const int MAX_BUDGET_SECONDS = 3600;

    public function __construct(
        public bool $enabled,
        public int $batchSize = self::DEFAULT_BATCH_SIZE,
        public float $budgetSeconds = self::DEFAULT_BUDGET_SECONDS,
    ) {}
}
