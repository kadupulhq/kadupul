<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\Site;
use Kadupul\Inventory\Domain\NewSite;

/**
 * Converts stored site rows to Site objects.
 */
final class SiteRecord
{
    public const COLUMNS = 'id, name, address1, address2, city, state, postal_code, country, timezone, latitude, longitude, zoom, alternate_id, notes';

    /**
     * Builds a site from a database row, filling in defaults for missing columns.
     *
     * @param array $row Map from string to mixed.
     */
    public static function hydrate(array $row): Site
    {
        $fields = [];
        foreach (NewSite::DEFAULTS as $key => $default) {
            $fields[$key] = (string) ($row[$key] ?? $default);
        }
        return new Site((int) $row['id'], $fields['name'], $fields['notes'], $fields);
    }
}
