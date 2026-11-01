<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/**
 * The options of kadupul:poller-cache:rebuild. MapInput builds this without
 * its constructor and assigns each public property. There is no --as and no
 * --local: the children the master starts through cli/push_out_hosts.php
 * could not be given either.
 */
final class RebuildPollerCacheInput
{
    #[Option(name: 'host-id', description: 'Rebuild only this device.')]
    public ?string $hostId = null;

    #[Option(name: 'host-template-id', description: 'Rebuild only the devices of this device template.')]
    public ?string $hostTemplateId = null;

    #[Option(name: 'data-template-id', description: 'Rebuild only the data sources of this data template.')]
    public ?string $dataTemplateId = null;

    #[Option(description: 'Processes to rebuild with (default: the Poller Command Concurrent Processes setting).')]
    public ?string $threads = null;

    #[Option(description: 'System controlled: rmaster (the default) or child.')]
    public ?string $type = null;

    #[Option(description: 'System controlled: the thread number of a child.')]
    public ?string $child = null;

    #[Option(description: 'Run even while another rebuild of the same type and thread is registered.')]
    public bool $force = false;

    #[Option(description: 'Print each step instead of one dot per device.')]
    public bool $debug = false;

    #[Option(description: 'Emit a machine-readable result instead of the progress lines.')]
    public bool $json = false;
}
