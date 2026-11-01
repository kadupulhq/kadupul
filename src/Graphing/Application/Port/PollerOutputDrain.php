<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** Writes the queued poller_output samples to their RRD files through the legacy poller code that owns the RRDtool pipe. */
interface PollerOutputDrain
{
    /**
     * Opens the RRDtool pipe and repeats process_poller_output() until
     * poller_output is empty, as poller_output_empty.php did, on the
     * installation's own database. A sample leaves the queue only once RRDtool
     * acknowledged it, so nothing is rolled back and a refused pass leaves the
     * rest queued.
     *
     * @param ?\Closure(string, string): void $relay called with 'out' or 'err' and the text the original printed to stdout or stderr
     *     while it ran (rrdtool's "OK" lines, library messages), in order; null drops that text
     * @return PollerOutputDrainResult how the pass ended; a refusal is a status, not an exception
     * @phpstan-impure
     * @throws \RuntimeException when the worker could not report an outcome at all
     */
    public function drain(?\Closure $relay = null): PollerOutputDrainResult;
}
