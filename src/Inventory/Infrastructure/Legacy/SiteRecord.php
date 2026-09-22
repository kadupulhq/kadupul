<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\Site;
use Kadupul\Inventory\Domain\NewSite;

final class SiteRecord
{
    public const COLUMNS = 'id, name, address1, address2, city, state, postal_code, country, timezone, latitude, longitude, zoom, alternate_id, notes';

    public static function hydrate(array $row): Site
    {
        $fields = [];
        foreach (NewSite::DEFAULTS as $key => $default) {
            $fields[$key] = (string) ($row[$key] ?? $default);
        }
        return new Site((int) $row['id'], $fields['name'], $fields['notes'], $fields);
    }
}
