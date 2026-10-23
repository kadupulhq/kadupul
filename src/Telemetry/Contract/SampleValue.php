<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/** A raw reading exactly as it will be written to a data source; none of the implementations convert through float. */
interface SampleValue
{
    /** The text form accepted by an RRD update: a decimal literal, or U for unknown. */
    public function toWire(): string;
}
