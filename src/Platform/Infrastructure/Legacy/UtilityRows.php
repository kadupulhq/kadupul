<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Render configured row choices; callers retain their own default option. */
final class UtilityRows
{
    public static function renderOptions(array $choices, mixed $selected): void
    {
        foreach ($choices as $key => $label) {
            print "<option value='" . \html_escape((string) $key) . "'" . ((string) $selected === (string) $key ? ' selected' : '') . '>' . \html_escape($label) . '</option>';
        }
    }
}
