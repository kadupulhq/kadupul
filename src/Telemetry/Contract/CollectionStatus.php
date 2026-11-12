<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * Overall outcome of a collection: everything used, some of it used, or none of it.
 */
enum CollectionStatus: string
{
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
}
