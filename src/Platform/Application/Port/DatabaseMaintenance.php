<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

interface DatabaseMaintenance
{
    public function isRemoteCollector(): bool;

    /** @return list<string> */
    public function tables(DatabaseTarget $target): array;

    public function binlogEnabled(DatabaseTarget $target): bool;

    public function analyze(DatabaseTarget $target, string $table, bool $noBinlog): bool;

    public function recordStats(DatabaseTarget $target, string $message): void;
}
