<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/** MapInput builds this without its constructor and assigns each public property. */
final class RemoveGraphsInput
{
    /** @var list<string> */
    #[Option(name: 'host-id', description: 'Select the graphs of these devices; repeat for several.')]
    public array $hostId = [];

    /** @var list<string> */
    #[Option(name: 'host-template-id', description: 'Select the graphs of devices of these device templates; repeat for several.')]
    public array $hostTemplateId = [];

    /** @var list<string> */
    #[Option(name: 'graph-template-id', description: 'Select the graphs of these graph templates; repeat for several.')]
    public array $graphTemplateId = [];

    /** @var list<string> */
    #[Option(name: 'graph-regex', description: 'Select the graphs whose name matches this expression; repeat for several.')]
    public array $graphRegex = [];

    #[Option(description: 'Select every graph. Ignores the four options above.')]
    public bool $all = false;

    #[Option(description: 'Actually remove the graphs, do not just count them.')]
    public bool $force = false;

    #[Option(description: 'Preserve the data sources. The default is to remove them where possible.')]
    public bool $preserve = false;

    #[Option(description: 'List the graphs that would be removed.')]
    public bool $list = false;

    #[Option(name: 'list-hosts', description: 'List the devices, optionally of --host-template-id.')]
    public bool $listHosts = false;

    #[Option(name: 'list-host-templates', description: 'List the device templates.')]
    public bool $listHostTemplates = false;

    #[Option(name: 'list-graph-templates', description: 'List the graph templates of the device templates, optionally of --host-template-id.')]
    public bool $listGraphTemplates = false;

    /**
     * Old options the shim passes on by name: remove_graphs.php listed --quiet and
     * --graph-type without handling them, and named each as invalid. --quiet
     * cannot be declared here because the console defines it for every command.
     *
     * @var list<string>
     */
    #[Option(description: 'Old option names to report as invalid and ignore (quiet, graph-type).')]
    public array $ignored = [];

    #[Option(description: 'On a remote collector, use its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
