<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/** MapInput builds this without its constructor and assigns each public property. */
final class AddDataSourceInput
{
    #[Option(name: 'host-id', description: 'The numerical id of the device; 0 adds a data source with no device.')]
    public ?string $hostId = null;

    #[Option(name: 'data-template-id', description: 'The numerical id of the data template to add.')]
    public ?string $dataTemplateId = null;

    #[Option(description: 'On a remote collector, use its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
