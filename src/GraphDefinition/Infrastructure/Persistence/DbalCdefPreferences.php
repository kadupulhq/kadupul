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

/** Keeps a user's CDEF list filters in the per-user settings table. */
#[AsAlias(CdefPreferences::class)]
final readonly class DbalCdefPreferences implements CdefPreferences
{
    private const string NAME = 'cdef_filters';

    /** Wires the access check and the shared filter preference storage. */
    public function __construct(
        private CdefAccess $access,
        private JsonFilterPreferences $preferences,
    ) {}

    /**
     * Returns the stored filters, or null when none are stored or they no longer validate.
     *
     * @throws \Kadupul\GraphDefinition\Application\Query\CdefAccessDenied when the user may not manage CDEFs
     */
    public function load(): ?array
    {
        return $this->preferences->load(
            $this->access->authorize()->id,
            self::NAME,
            CdefFilters::KEYS,
            static fn(array $filters) => CdefFilters::fromQuery($filters, 1),
        );
    }

    /** Stores the filters after rechecking access inside the write. */
    public function save(array $filters): void
    {
        $this->preferences->save(self::NAME, $filters, CdefFilters::KEYS, 'Invalid CDEF filters.', function (): int {
            $actor = $this->access->authorize();
            $this->access->assertCurrent($actor->id);
            return $actor->id;
        });
    }
}
