<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

function native_composition_application_only(int $choice): int
{
    global $nativeCompositionFixture;
    $nativeCompositionFixture = $choice;
    return match ($choice) {
        1 => 11,
        default => 22,
    };
}
