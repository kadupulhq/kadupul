<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/**
 * MapInput builds this without its constructor and assigns each public property.
 * Both selectors are optional in the definition because a shim's help or version
 * request passes none; the command rejects a missing --qid as invalid input.
 */
final class ReorderDataQueriesInput
{
    #[Option(description: "Device whose data queries to reorder: a device id, or 'all' (default).")]
    public ?string $hostId = null;

    #[Option(description: "Data query to reorder: a data query id, or 'all'.")]
    public ?string $qid = null;

    #[Option(description: 'Print each item as it is reordered.')]
    public bool $debug = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'List the items that would be reordered without reordering them.')]
    public bool $dryRun = false;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
