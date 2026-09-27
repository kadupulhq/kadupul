<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

/** What a legacy shim asked for: the operation itself, or its version or help text. */
enum LegacyRequest
{
    case Run;
    case Version;
    case Help;
}
