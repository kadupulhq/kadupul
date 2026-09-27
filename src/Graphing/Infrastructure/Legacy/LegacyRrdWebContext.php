<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

/** Web request state required before a one-off local RRDtool process starts. */
final class LegacyRrdWebContext
{
    /** Release the Cacti session before checking or starting a one-off process.
     *
     * @return void
     */
    public function releaseSession(): void
    {
        \cacti_session_close();
    }

    /** Apply the request timezone immediately before a local process starts.
     *
     * @param array<string, mixed> $config Application configuration.
     *
     * @return void
     */
    public function prepareProcess(array $config): void
    {
        if ($config['is_web'] && isset($_COOKIE['CactiTimeZone'])) {
            \cacti_time_zone_set($_COOKIE['CactiTimeZone']);
        }
    }
}
