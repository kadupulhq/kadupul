<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Port\ColorTemplatePreferences;
use Kadupul\Graphing\Domain\ColorTemplateFilters;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ColorTemplatePreferences::class)]
final readonly class LegacyColorTemplatePreferences extends LegacyFilterPreferences implements ColorTemplatePreferences
{
    public function __construct(ColorTemplateAccess $access, DatabaseConnection $database, LegacyConfiguration $configuration)
    {
        parent::__construct($access, $database, $configuration, ColorTemplateSql::class, 'color_templates_filters', ColorTemplateFilters::KEYS, 'Unsupported color template preference database.', 'Color template preference transaction isolation could not be confirmed.', 'Invalid color template filter preferences.');
    }
}
