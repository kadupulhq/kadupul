<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Domain;

/** Legacy links must remain paths within this installation's origin. */
final class AggregateTemplateLegacyPath
{
    public static function graphs(mixed $base): string
    {
        if (!is_string($base) || !str_starts_with($base, '/') || str_starts_with($base, '//')
            || preg_match('/[\\\\\x00-\x20\x7f?#]/', $base) !== 0) {
            $base = '/';
        }
        return rtrim($base, '/') . '/aggregate_graphs.php';
    }
}
