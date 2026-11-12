<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\DataInput\Application\Port;

use Kadupul\DataInput\Domain\DataQuerySettings;

/**
 * Reads and saves a data query's own settings through the legacy code that
 * owns its side effects: the Data Input Method push-out to data templates,
 * data sources and the poller cache, and the replication checksum.
 */
interface DataQueryGateway
{
    /**
     * @return array{query: ?array{id: int, name: string, description: string, xml_path: string, data_input_id: int}, revision: string, xml: string, inputs: array<int, string>}
     *     query is null for a new data query; xml is "found", "missing" or "none"
     * @throws \Kadupul\DataInput\Domain\DataInputNotFound
     */
    public function find(int $actorId, int $id): array;

    /**
     * Saves in one primary-database transaction and returns the data query's id.
     *
     * @return array{id: int, partial: bool} partial when a remote collector could not take the poller cache change
     * @throws \Kadupul\DataInput\Application\DataInputConflict when the stored settings no longer match $revision
     * @throws \Kadupul\DataInput\Domain\DataQueryRejected when the worker refuses the settings
     */
    public function save(int $actorId, int $id, DataQuerySettings $settings, string $revision): array;
}
