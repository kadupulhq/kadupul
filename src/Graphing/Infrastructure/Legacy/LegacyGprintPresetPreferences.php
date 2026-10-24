<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\GprintPresetPreferences;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(GprintPresetPreferences::class)]
final readonly class LegacyGprintPresetPreferences extends LegacyFilterPreferences implements GprintPresetPreferences
{
    public function __construct(GprintPresetAccess $access, DatabaseConnection $database, LegacyConfiguration $configuration)
    {
        parent::__construct($access, $database, $configuration, GprintPresetSql::class, 'gprint_presets_filters', GprintPresetFilters::KEYS, 'Unsupported GPRINT preference database.', 'GPRINT preference transaction isolation could not be confirmed.', 'Invalid GPRINT preset filter preferences.');
    }
}
