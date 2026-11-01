<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

function native_composition_target(int $choice): int
{
    if ($choice === 1) {
        return 11;
    }
    return 22;
}
