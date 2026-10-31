<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/** MapInput builds this without its constructor and assigns each public property. */
final class AddTreeInput
{
    #[Option(description: 'What to add: tree or node.')]
    public ?string $type = null;

    #[Option(description: 'Name of the tree, or of a header node.')]
    public ?string $name = null;

    #[Option(name: 'sort-method', description: 'manual, alpha, natural or numeric (default: alpha).')]
    public ?string $sortMethod = null;

    #[Option(name: 'tree-id', description: 'The tree a node goes in, or whose nodes to list.')]
    public ?string $treeId = null;

    #[Option(name: 'parent-node', description: 'The header node a new node goes under (default: the tree root).')]
    public ?string $parentNode = null;

    #[Option(name: 'node-type', description: 'header, graph, host or site.')]
    public ?string $nodeType = null;

    #[Option(name: 'graph-id', description: 'The graph a graph node shows.')]
    public ?string $graphId = null;

    #[Option(name: 'host-id', description: 'The device a host node shows, or whose graphs to list.')]
    public ?string $hostId = null;

    #[Option(name: 'site-id', description: 'The site a site node shows.')]
    public ?string $siteId = null;

    #[Option(name: 'host-group-style', description: 'How a host node groups its graphs: 1 graph template, 2 data query index.')]
    public ?string $hostGroupStyle = null;

    #[Option(name: 'list-sites', description: 'List the sites.')]
    public bool $listSites = false;

    #[Option(name: 'list-hosts', description: 'List the devices.')]
    public bool $listHosts = false;

    #[Option(name: 'list-trees', description: 'List the trees.')]
    public bool $listTrees = false;

    #[Option(name: 'list-nodes', description: 'List the nodes of --tree-id.')]
    public bool $listNodes = false;

    #[Option(name: 'list-graphs', description: 'List the graphs of --host-id.')]
    public bool $listGraphs = false;

    #[Option(name: 'no-headers', description: 'Print the lists without their heading and trailing blank line.')]
    public bool $noHeaders = false;

    #[Option(description: 'On a remote collector, use its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
