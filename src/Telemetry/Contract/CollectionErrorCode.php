<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

enum CollectionErrorCode: string
{
    case Conflict = 'conflict';
    /** The source could not be reached or did not answer; nothing it would have reported was used. */
    case Unavailable = 'unavailable';
}
