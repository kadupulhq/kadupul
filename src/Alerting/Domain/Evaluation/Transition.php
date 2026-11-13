<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Evaluation;

enum Transition: string
{
    case None = 'none';
    case Opened = 'opened';
    case Recovered = 'recovered';
    case BecameUnknown = 'became_unknown';
}
