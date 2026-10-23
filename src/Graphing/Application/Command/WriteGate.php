<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Command;

/**
 * Whether model-driven samples may be written to RRD files at all. It is
 * closed unless a deployment opens it on purpose, so wiring the sink in by
 * accident cannot start writing to existing graphs.
 */
final readonly class WriteGate
{
    public function __construct(private bool $writesEnabled = false) {}

    public static function disabled(): self
    {
        return new self(false);
    }

    public static function enabled(): self
    {
        return new self(true);
    }

    public function permitsWrites(): bool
    {
        return $this->writesEnabled;
    }
}
