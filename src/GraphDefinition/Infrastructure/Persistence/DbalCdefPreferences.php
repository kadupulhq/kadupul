<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Persistence;

use Kadupul\GraphDefinition\Application\Port\CdefAccess;
use Kadupul\GraphDefinition\Application\Port\CdefPreferences;
use Kadupul\GraphDefinition\Domain\CdefFilters;
use Kadupul\Platform\Contract\JsonFilterPreferences;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CdefPreferences::class)]
final readonly class DbalCdefPreferences implements CdefPreferences
{
    private const string NAME = 'cdef_filters';

    public function __construct(
        private CdefAccess $access,
        private JsonFilterPreferences $preferences,
    ) {}

    public function load(): ?array
    {
        $filters = $this->preferences->load($this->access->authorize()->id, self::NAME);
        if ($filters === null || !self::valid($filters)) {
            return null;
        }
        try {
            // A stored value that is no longer in range must not lock the list.
            CdefFilters::fromQuery($filters, 1);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return $filters;
    }

    public function save(array $filters): void
    {
        if (!self::valid($filters)) {
            throw new \InvalidArgumentException('Invalid CDEF filters.');
        }
        $this->preferences->save(self::NAME, $filters, function (): int {
            $actor = $this->access->authorize();
            $this->access->assertCurrent($actor->id);
            return $actor->id;
        });
    }

    /** @param array<array-key, mixed> $filters
     * @phpstan-assert-if-true =array<string, string> $filters
     */
    private static function valid(array $filters): bool
    {
        return array_diff(array_keys($filters), CdefFilters::KEYS) === []
            && array_filter($filters, static fn(mixed $value): bool => !is_string($value)) === [];
    }
}
