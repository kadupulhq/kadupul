<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\DataInput\Application;

use Kadupul\DataInput\Application\Port\DataQueryAccess;
use Kadupul\DataInput\Application\Port\DataQueryGateway;
use Kadupul\DataInput\Domain\DataQuerySettings;

/** Data query settings use cases; each authorizes before the worker reads or writes. */
final readonly class DataQueries
{
    public function __construct(private DataQueryAccess $access, private DataQueryGateway $gateway) {}

    /** @return array{query: ?array{id: int, name: string, description: string, xml_path: string, data_input_id: int}, revision: string, xml: string, inputs: array<int, string>} */
    public function find(int $id): array
    {
        return $this->gateway->find($this->access->authorize()->id, $id);
    }

    /** @return array{id: int, partial: bool} */
    public function save(int $id, DataQuerySettings $settings, string $revision): array
    {
        return $this->gateway->save($this->access->authorize()->id, $id, $settings, $revision);
    }
}
