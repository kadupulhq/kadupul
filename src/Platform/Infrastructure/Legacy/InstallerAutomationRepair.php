<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Repair only the three release-owned automation rules with their original names and hashes. */
final readonly class InstallerAutomationRepair
{
    /** Preserve native query routing and installation log hooks. */
    public function __construct(private NativeInstallerFunctions $native) {}

    /** User-renamed or missing rules are deliberately left untouched. */
    public function repair(): void
    {
        // Native query errors survive successful reads; start this operation independently.
        $this->native->call('db_install_reset_error');
        $this->native->call('log_install_always', '', 'Repairing Automation Rules');

        $hash_array = array(
            array(
                'name' => 'Traffic 64 bit Server',
                'automation_id' => 1,
                'snmp_query_graph_id' => 9,
                'snmp_query_id' => 1,
                'snmp_query_hash' => 'd75e406fdeca4fcef45b8be3a9a63cbc',
                'snmp_query_graph_hash' => 'ab93b588c29731ab15db601ca0bc9dec',
            ),
            array(
                'name' => 'Traffic 64 bit Server Linux',
                'automation_id' => 2,
                'snmp_query_graph_id' => 9,
                'snmp_query_id' => 1,
                'snmp_query_hash' => 'd75e406fdeca4fcef45b8be3a9a63cbc',
                'snmp_query_graph_hash' => 'ab93b588c29731ab15db601ca0bc9dec',
            ),
            array(
                'name' => 'Disk Space',
                'automation_id' => 3,
                'snmp_query_graph_id' => 18,
                'snmp_query_id' => 8,
                'snmp_query_hash' => '9343eab1f4d88b0e61ffc9d020f35414',
                'snmp_query_graph_hash' => '46c4ee688932cf6370459527eceb8ef3',
            )
        );

        foreach ($hash_array as $item) {
            $exists = $this->native->call(
                'db_fetch_row_prepared',
                'SELECT *
			FROM automation_graph_rules
			WHERE id = ?
			AND name = ?',
                array(
                    $item['automation_id'],
                    $item['name']
                )
            );

            if ((bool) $this->native->call('db_error')) {
                throw new \RuntimeException('The installation automation rule lookup failed.');
            }
            if (is_array($exists) && $exists !== []) {
                $exists_snmp_query_id = $this->native->call(
                    'db_fetch_cell_prepared',
                    'SELECT id
				FROM snmp_query
				WHERE hash = ?',
                    array($item['snmp_query_hash'])
                );
                if ((bool) $this->native->call('db_error')) {
                    throw new \RuntimeException('The installation automation template references are unavailable.');
                }

                $exists_snmp_query_graph_id = $this->native->call(
                    'db_fetch_cell_prepared',
                    'SELECT id
				FROM snmp_query_graph
				WHERE hash = ?',
                    array($item['snmp_query_graph_hash'])
                );

                if ((bool) $this->native->call('db_error')) {
                    throw new \RuntimeException('The installation automation template references are unavailable.');
                }
                // Fresh installations can select only some bundled templates.
                // Leave unrelated rules untouched until both referenced templates exist.
                if (!(bool) $exists_snmp_query_id || !(bool) $exists_snmp_query_graph_id) {
                    continue;
                }
                $updated = $this->native->call(
                    'db_execute_prepared',
                    'UPDATE automation_graph_rules
				SET snmp_query_id = ?, graph_type_id = ?
				WHERE id = ?',
                    array(
                        $exists_snmp_query_id,
                        $exists_snmp_query_graph_id,
                        $item['automation_id']
                    )
                );
                if ($updated === false) {
                    throw new \RuntimeException('The installation automation rule could not be repaired.');
                }
            }
        }
    }
}
