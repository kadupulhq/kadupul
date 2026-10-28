<?php

declare(strict_types=1);

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
     * @param mixed $timezone Browser offset in minutes; null means no supplied value.
     *
     * @return void
     */
    public function prepareProcess(bool $isWeb, mixed $timezone): void
    {
        if ($isWeb && $timezone !== null) {
            \cacti_time_zone_set($timezone);
        }
    }
}
