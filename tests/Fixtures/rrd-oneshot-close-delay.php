<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Graphing\Infrastructure\Rrd;

/** Model a scheduler pause immediately after a process pipe is closed. */
function fclose($pipe): bool
{
    $closed = \fclose($pipe);
    \usleep(1000000);

    return $closed;
}
