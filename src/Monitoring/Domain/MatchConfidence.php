<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

enum MatchConfidence: string
{
    case Confirmed = 'confirmed';
    case Ambiguous = 'ambiguous';
    case Heuristic = 'heuristic';
    case None = 'none';
}
