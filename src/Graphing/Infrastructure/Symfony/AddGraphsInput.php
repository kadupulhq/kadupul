<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/** MapInput builds this without its constructor and assigns each public property. */
final class AddGraphsInput
{
    #[Option(name: 'graph-type', description: 'cg for a graph template graph, ds for the graphs of a data query.')]
    public ?string $graphType = null;

    #[Option(name: 'graph-template-id', description: 'The graph template to create graphs from, or whose input fields to list.')]
    public ?string $graphTemplateId = null;

    #[Option(name: 'host-id', description: 'The device the graphs are for, or whose SNMP fields and values to list.')]
    public ?string $hostId = null;

    #[Option(name: 'graph-title', description: 'A title to give the graphs instead of the template\'s.')]
    public ?string $graphTitle = null;

    #[Option(name: 'host-template-id', description: 'With --list-graph-templates, list the graph templates of this device template.')]
    public ?string $hostTemplateId = null;

    #[Option(name: 'input-fields', description: 'Custom data for a graph template graph: "[data-template-id:]field-name=value ...".')]
    public ?string $inputFields = null;

    #[Option(name: 'snmp-query-id', description: 'The data query of the graphs.')]
    public ?string $snmpQueryId = null;

    #[Option(name: 'snmp-query-type-id', description: 'The data query graph type of the graphs.')]
    public ?string $snmpQueryTypeId = null;

    /** @var list<string> */
    #[Option(name: 'snmp-field', description: 'A data query field the graphs are selected by; repeat for several.')]
    public array $snmpField = [];

    /** @var list<string> */
    #[Option(name: 'snmp-value', description: 'The value of the matching --snmp-field; repeat for several.')]
    public array $snmpValue = [];

    /** @var list<string> */
    #[Option(name: 'snmp-value-regex', description: 'A regular expression for the matching --snmp-field; repeat for several.')]
    public array $snmpValueRegex = [];

    #[Option(name: 'reindex-method', description: 'How a newly associated data query is reindexed: 0|none, 1|uptime, 2|index, 3|fields.')]
    public ?string $reindexMethod = null;

    #[Option(description: 'Create the graph even when the device has it already.')]
    public bool $force = false;

    #[Option(name: 'list-hosts', description: 'List the devices.')]
    public bool $listHosts = false;

    #[Option(name: 'list-graph-templates', description: 'List the graph templates.')]
    public bool $listGraphTemplates = false;

    #[Option(name: 'list-input-fields', description: 'List the input fields of --graph-template-id.')]
    public bool $listInputFields = false;

    #[Option(name: 'list-snmp-queries', description: 'List the data queries.')]
    public bool $listSnmpQueries = false;

    #[Option(name: 'list-query-types', description: 'List the graph types of --snmp-query-id.')]
    public bool $listQueryTypes = false;

    #[Option(name: 'list-snmp-fields', description: 'List the data query fields of --host-id.')]
    public bool $listSnmpFields = false;

    #[Option(name: 'list-snmp-values', description: 'List the values of --snmp-field on --host-id.')]
    public bool $listSnmpValues = false;

    #[Option(name: 'no-headers', description: 'Print the lists without their heading and trailing blank line.')]
    public bool $noHeaders = false;

    /**
     * Options the shim saw more than once: the original refused a second value
     * for any option but --snmp-field, --snmp-value and --snmp-value-regex.
     *
     * @var list<string>
     */
    #[Option(name: 'repeated', description: 'Set by the cli/add_graphs.php shim; not for direct use.')]
    public array $repeated = [];

    #[Option(description: 'On a remote collector, use its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
