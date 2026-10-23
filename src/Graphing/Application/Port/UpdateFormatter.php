<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

/**
 * Turns the entries for one RRD file into update calls. It is a port only
 * because the implementation speaks rrdtool's argument syntax, which
 * belongs in Infrastructure; it performs no I/O.
 */
interface UpdateFormatter
{
    /**
     * @param list<UpdateEntry> $entries all for the same file
     * @param int|null $lastUpdate the file's last update in epoch seconds, if known
     */
    public function format(array $entries, ?int $lastUpdate): UpdatePlan;
}
