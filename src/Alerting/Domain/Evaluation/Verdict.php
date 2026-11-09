<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Evaluation;

/**
 * The result of evaluating one rule once. Unknown is not Ok: it means there was
 * no usable reading. Hold means an open incident's value sits between the clear
 * and trigger thresholds, which counts as neither Ok nor Breach.
 */
enum Verdict: string
{
    case Ok = 'ok';
    case Breach = 'breach';
    case Unknown = 'unknown';
    case Hold = 'hold';
}
