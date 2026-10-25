<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\PaletteColorPreferences;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;

final readonly class LegacyPaletteColorPreferences extends LegacyFilterPreferences implements PaletteColorPreferences
{
    public function __construct(PaletteColorAccess $access, DatabaseConnection $database, LegacyConfiguration $configuration)
    {
        parent::__construct($access, $database, $configuration, PaletteSql::class, 'palette_colors_filters', ['rows', 'page', 'filter', 'sort_column', 'sort_direction', 'has_graphs', 'named'], 'Unsupported Color preference database.', 'Color preference transaction isolation could not be confirmed.', 'Invalid color filter preferences.');
    }
}
