<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Telemetry\Contract\RejectionReason;

/**
 * A storage port could not locate, read or write a file. The message is
 * meant for a sample outcome, so adapters keep paths and raw rrdtool output
 * out of it; the sink still replaces a message that slips one through.
 */
final class StorageRejected extends \RuntimeException
{
    public function __construct(public readonly RejectionReason $reason, string $detail)
    {
        parent::__construct($detail);
    }
}
