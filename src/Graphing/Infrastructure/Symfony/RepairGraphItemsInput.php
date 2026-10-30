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
 * The template ids are optional in the definition because a shim's help or
 * version request passes none; the command rejects a missing one as invalid input.
 */
final class RepairGraphItemsInput
{
    #[Option(description: 'Graph template whose graphs to check.')]
    public ?string $graphTemplateId = null;

    #[Option(description: 'Data template whose data source the graph items must point at.')]
    public ?string $dataTemplateId = null;

    #[Option(description: 'Only graphs on this device (default: every device).')]
    public ?string $hostId = null;

    #[Option(description: 'Repoint the graph items. Without it the run only reports.')]
    public bool $execute = false;

    #[Option(description: 'Print the UPDATE statement for each repair.')]
    public bool $showSql = false;

    #[Option(description: 'On a remote collector, repair its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
