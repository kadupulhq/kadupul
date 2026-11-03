<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Kadupul\Graphing\Application\Port\DataSourceProfileAccess;
use Kadupul\Graphing\Application\Port\DataSourceProfilePreferences;
use Kadupul\Graphing\Domain\DataSourceProfileFilters;
use Kadupul\Platform\Contract\JsonFilterPreferences;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DataSourceProfilePreferences::class)]
final readonly class DbalDataSourceProfilePreferences implements DataSourceProfilePreferences
{
    private const string NAME = 'data_source_profiles_filters';

    public function __construct(
        private DataSourceProfileAccess $access,
        private JsonFilterPreferences $preferences,
    ) {}

    public function load(): ?array
    {
        return $this->preferences->load(
            $this->access->authorize()->id,
            self::NAME,
            DataSourceProfileFilters::KEYS,
            static fn(array $filters) => DataSourceProfileFilters::fromQuery($filters, 1),
        );
    }

    public function save(array $filters): void
    {
        $this->preferences->save(self::NAME, $filters, DataSourceProfileFilters::KEYS, 'Invalid profile filters.', function (): int {
            $actor = $this->access->authorize();
            $this->access->assertCurrent($actor->id);
            return $actor->id;
        });
    }
}
