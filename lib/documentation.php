<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function cacti_documentation_url(string $page): string
{
    // Only trusted routes are used; the requested name is never part of a URL.
    $routes = array(
        'Graphs.html' => 'concepts/how-graphs-are-drawn/',
        'Graph-Overview.html' => 'concepts/how-graphs-are-drawn/',
        'Graph-a-Single-SNMP-OID.html' => 'concepts/how-graphs-are-drawn/',
        'Aggregates.html' => 'guides/build-aggregate-graphs/',
        'Aggregate-Templates.html' => 'guides/build-aggregate-graphs/',
        'Automation-Networks.html' => 'guides/discover-devices-automatically/',
        'Device-Rules.html' => 'guides/discover-devices-automatically/',
        'Discovered-Devices.html' => 'guides/discover-devices-automatically/',
        'Graph-Rules.html' => 'guides/discover-devices-automatically/',
        'CDEFs.html' => 'guides/tune-graph-appearance/',
        'VDEFs.html' => 'guides/tune-graph-appearance/',
        'Colors.html' => 'guides/tune-graph-appearance/',
        'Color-Templates.html' => 'guides/tune-graph-appearance/',
        'GPRINTs.html' => 'guides/tune-graph-appearance/',
        'Data-Collectors.html' => 'concepts/remote-data-collection/',
        'Data-Debug.html' => 'guides/troubleshoot-missing-data/',
        'Data-Input-Methods.html' => 'reference/data-input-methods/',
        'Data-Profiles.html' => 'guides/manage-data-retention/',
        'Data-Queries.html' => 'concepts/data-queries-and-indexes/',
        'Data-Sources.html' => 'concepts/data-sources-and-rras/',
        'Data-Source-Templates.html' => 'concepts/templates/',
        'Device-Templates.html' => 'concepts/templates/',
        'Graph-Templates.html' => 'concepts/templates/',
        'Devices.html' => 'guides/worked-example/',
        'Export-Template.html' => 'guides/import-and-export-templates/',
        'Import-Template.html' => 'guides/import-and-export-templates/',
        'Plugins.html' => 'guides/install-and-vet-plugins/',
        'SNMP-Options.html' => 'reference/snmp/',
        'Tree-Rules.html' => 'guides/organize-devices-with-trees/',
        'Trees.html' => 'guides/organize-devices-with-trees/',
        'Sites.html' => 'guides/organize-devices-with-trees/',
        'User-Domains.html' => 'guides/manage-users-and-permissions/',
        'User-Group-Management.html' => 'guides/manage-users-and-permissions/',
        'User-Management.html' => 'guides/manage-users-and-permissions/',
        'Cacti-Log.html' => 'reference/logging/',
    );
    $route = $routes[$page] ?? (str_starts_with($page, 'Settings-') ? 'reference/settings/' : 'map/');

    return 'https://kadupul.org/' . $route;
}
