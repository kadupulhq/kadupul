<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * The data-source types a binding may expect. COMPUTE and the DCOUNTER and
 * DDERIVE types are left out on purpose: a sample cannot be written to a
 * computed field, and no binding has needed the others yet.
 */
enum RrdDataSourceType: string
{
    case Gauge = 'GAUGE';
    case Counter = 'COUNTER';
    case Derive = 'DERIVE';
    case Absolute = 'ABSOLUTE';
}
