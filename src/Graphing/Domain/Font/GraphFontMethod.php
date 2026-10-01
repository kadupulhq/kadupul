<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain\Font;

/** Where graph fonts come from, as the font_method setting stores it. */
enum GraphFontMethod: int
{
    case System = 0;
    case Theme = 1;
}
