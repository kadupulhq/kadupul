<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/** Opaque digest of a profile, its functions and archives; any change makes an open form stale. */
final class DataSourceProfileRevision
{
    /**
     * @param array<string, mixed> $parent id, hash, name, step, heartbeat, x_files_factor and default
     * @param list<int> $functions consolidation function IDs in ascending order
     * @param list<DataSourceProfileRra> $archives
     */
    public static function of(array $parent, array $functions, array $archives): string
    {
        return hash('sha256', json_encode([
            (int) $parent['id'], (string) $parent['hash'], (string) $parent['name'], (int) $parent['step'], (int) $parent['heartbeat'],
            // The column is a double; compare the value, not the driver's text.
            (float) $parent['x_files_factor'], (string) ($parent['default'] ?? ''),
            $functions,
            array_map(static fn(DataSourceProfileRra $archive): string => $archive->revision, $archives),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
