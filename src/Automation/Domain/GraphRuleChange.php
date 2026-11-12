<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/** A validated edit of the automation_graph_rules settings; criteria items are edited separately. */
final readonly class GraphRuleChange
{
    /** The width of automation_graph_rules.name in cacti.sql. */
    public const int NAME_LENGTH = 255;
    /** snmp_query_id and graph_type_id are unsigned smallint columns. */
    public const int ID_MAX = 65535;

    public string $name;

    public function __construct(string $name, public int $snmpQueryId, public int $graphTypeId, public bool $enabled)
    {
        if (preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new GraphRuleRejected('Enter single-line text.', [], 'name');
        }
        $name = trim($name);
        if ($name === '') {
            throw new GraphRuleRejected('Enter a name.', [], 'name');
        }
        if (mb_strlen($name) > self::NAME_LENGTH) {
            throw new GraphRuleRejected('Enter at most %count% characters.', ['%count%' => (string) self::NAME_LENGTH], 'name');
        }
        if ($snmpQueryId < 1 || $snmpQueryId > self::ID_MAX) {
            throw new GraphRuleRejected('Choose an existing Data Query.', [], 'snmp_query_id');
        }
        if ($graphTypeId < 1 || $graphTypeId > self::ID_MAX) {
            throw new GraphRuleRejected('Choose a Graph Type of the selected Data Query.', [], 'graph_type_id');
        }
        $this->name = $name;
    }

    /** @return array{name: string, snmp_query_id: int, graph_type_id: int, enabled: string} */
    public function columns(): array
    {
        return ['name' => $this->name, 'snmp_query_id' => $this->snmpQueryId, 'graph_type_id' => $this->graphTypeId, 'enabled' => $this->enabled ? 'on' : ''];
    }
}
