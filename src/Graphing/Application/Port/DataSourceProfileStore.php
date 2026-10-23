<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\DataSourceProfile;
use Kadupul\Graphing\Domain\DataSourceProfileFilters;
use Kadupul\Graphing\Domain\DataSourceProfilePage;

/**
 * Writes throw \InvalidArgumentException for a refusal the user can correct,
 * including a stale revision. Any other exception leaves the outcome uncertain.
 */
interface DataSourceProfileStore
{
    public const int MAX_SELECTION = 100;

    public const string READ_ONLY = 'Profiles that are in use by Data Sources become read only for now.';

    public function defaultRows(): int;

    public function defaultHasData(): bool;

    /** @return array{step: int, heartbeat: int} the new-profile defaults from the poller interval */
    public function defaults(): array;

    public function list(DataSourceProfileFilters $filters): DataSourceProfilePage;

    /** The profile with its functions, archives, usage and revision. */
    public function find(int $id): ?DataSourceProfile;

    /**
     * @param list<int> $ids
     * @return array<int, DataSourceProfile> keyed by ID, only for IDs that exist
     */
    public function findMany(array $ids): array;

    /**
     * A null step, heartbeat or factor, or an empty function list, keeps the
     * stored value, as an omitted legacy field did. A profile used by data
     * sources refuses a changed step, factor or function list.
     *
     * @param list<int> $functions
     * @return array{id: int, retune: bool} retune is true when existing RRDfiles keep their old heartbeat
     */
    public function save(int $actorId, ?int $id, string $name, ?int $step, ?int $heartbeat, ?string $xFilesFactor, array $functions, bool $makeDefault, ?string $revision): array;

    /** A null level or row count keeps the stored value of an existing archive. */
    public function saveArchive(int $actorId, int $profileId, ?int $archiveId, string $name, ?int $levelSeconds, ?int $rows, int $timespan, ?string $revision): int;

    public function deleteArchive(int $actorId, int $profileId, int $archiveId, string $revision): void;

    /**
     * @param list<int> $ids
     * @param array<int, string> $revisions
     */
    public function duplicate(int $actorId, array $ids, array $revisions, string $titleFormat): void;

    /**
     * @param list<int> $ids
     * @param array<int, string> $revisions
     */
    public function delete(int $actorId, array $ids, array $revisions): void;
}
