<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/** MapInput builds this without its constructor and assigns each public property. */
final class AssociateDataQueryInput
{
    #[Option(name: 'host-id', description: 'The numerical id of the device.')]
    public ?string $hostId = null;

    #[Option(name: 'data-query-id', description: 'The numerical id of the data query to add.')]
    public ?string $dataQueryId = null;

    #[Option(name: 'reindex-method', description: 'How the data query is reindexed: 0|none, 1|uptime, 2|index or 3|fields.')]
    public ?string $reindexMethod = null;

    #[Option(name: 'list-hosts', description: 'List the devices.')]
    public bool $listHosts = false;

    #[Option(name: 'list-data-queries', description: 'List the data queries.')]
    public bool $listDataQueries = false;

    #[Option(name: 'no-headers', description: 'Print the lists without their heading and trailing blank line.')]
    public bool $noHeaders = false;

    #[Option(description: 'On a remote collector, use its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
