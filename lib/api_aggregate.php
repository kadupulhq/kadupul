<?php

declare(strict_types=1);
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once __DIR__ . '/reference_write.php';

/** Keep nested graph callbacks on the exact connection whose participants were checked. */
function aggregate_graph_connection_scope(string $action, ?PDO $database = null, ?string $schema = null): ?array
{
    static $connections = [];
    if ($action === 'enter') {
        $connections[] = ['database' => $database, 'schema' => $schema];
    } elseif ($action === 'leave') {
        array_pop($connections);
    }
    return $connections === [] ? null : $connections[array_key_last($connections)];
}

/** Run the entire regeneration, including nested caller scopes, atomically. */
function aggregate_graph_mutation(callable $operation): bool
{
    try {
        $database = aggregate_graph_database();
        $schema = aggregate_graph_schema($database);
        if (!aggregate_graph_selected_schema_matches($database, $schema)) return false;
    } catch (Throwable) {
        return false;
    }
    aggregate_graph_connection_scope('enter', $database, $schema);
    try {
        return reference_write_atomic(static function () use ($operation, $database): bool {
            if (aggregate_graph_database() !== $database) {
                return false;
            }
            $result = $operation();
            return aggregate_graph_database() === $database && $result === true;
        }, ['graph_local', 'graph_templates_graph', 'graph_templates_item', 'aggregate_graphs',
            'aggregate_graphs_items', 'aggregate_graphs_graph_item', 'cdef', 'cdef_items', 'settings']) === true;
    } finally {
        aggregate_graph_connection_scope('leave');
    }
}

/** Resolve the same selected local PDO used by the legacy graph writers. */
function aggregate_graph_database(): PDO
{
    global $database_sessions, $database_hostname, $database_port, $database_default;

    $database = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    if (!$database instanceof PDO || (($active = aggregate_graph_connection_scope('current')) !== null && $active['database'] !== $database)) {
        throw new RuntimeException('The aggregate graph connection is unavailable.');
    }

    if ($active !== null && aggregate_graph_schema($database) !== $active['schema']) {
        throw new RuntimeException('The aggregate graph schema changed during its mutation.');
    }
    if ($active === null && $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        && !aggregate_graph_selected_schema_matches($database, aggregate_graph_schema($database))) {
        throw new RuntimeException('The aggregate graph schema differs from its configured target.');
    }

    return $database;
}

/** Confirm the native schema on the guarded connection without recursive helper calls. */
function aggregate_graph_schema(PDO $database): string
{
    $statement = $database->prepare('SELECT DATABASE()');
    if ($statement === false || !$statement->execute() || $statement->errorCode() !== '00000') {
        throw new RuntimeException('The aggregate graph schema could not be confirmed.');
    }
    $schema = $statement->fetchColumn();
    $state = $statement->errorCode();
    if (!$statement->closeCursor() || $statement->errorCode() !== '00000' || $state !== '00000'
        || !is_string($schema) || $schema === '') {
        throw new RuntimeException('The aggregate graph schema could not be confirmed.');
    }

    return $schema;
}

/** A new operation cannot adopt a plugin-selected schema under the old connection key. */
function aggregate_graph_selected_schema_matches(PDO $database, string $schema): bool
{
    global $database_default;
    if (!is_string($database_default) || $database_default === '') return false;
    if ($schema === $database_default) return true;
    $statement = $database->prepare('SELECT @@lower_case_table_names');
    if ($statement === false || !$statement->execute() || $statement->errorCode() !== '00000') return false;
    $mode = $statement->fetchColumn();
    $state = $statement->errorCode();
    if (!$statement->closeCursor() || $statement->errorCode() !== '00000' || $state !== '00000') return false;
    return in_array($mode, [1, 2, '1', '2'], true) && strtolower($schema) === strtolower($database_default);
}

function aggregate_graph_statement(string $sql, array $parameters): PDOStatement
{
    $statement = aggregate_graph_database()->prepare($sql);
    if ($statement === false || !$statement->execute($parameters) || $statement->errorCode() !== '00000') {
        throw new RuntimeException('An aggregate graph SQL operation could not be confirmed.');
    }

    return $statement;
}

function aggregate_graph_execute(string $sql, array $parameters = []): bool
{
    $statement = aggregate_graph_statement($sql, $parameters);
    if (!$statement->closeCursor() || $statement->errorCode() !== '00000') {
        throw new RuntimeException('The aggregate graph write close could not be confirmed.');
    }

    return true;
}

function aggregate_graph_fetch_rows(string $sql, array $parameters = []): array
{
    $statement = aggregate_graph_statement($sql, $parameters);
    $failure = null;
    try {
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($statement->errorCode() !== '00000') {
            throw new RuntimeException('The aggregate graph read could not be confirmed.');
        }

        return $rows;
    } catch (Throwable $error) {
        $failure = $error;
        throw $error;
    } finally {
        try {
            if (!$statement->closeCursor() || $statement->errorCode() !== '00000') {
                throw new RuntimeException('The aggregate graph read close could not be confirmed.');
            }
        } catch (Throwable $cleanup) {
            throw new RuntimeException('The aggregate graph read close could not be confirmed.', 0, $failure ?? $cleanup);
        }
    }
}

function aggregate_graph_fetch_row(string $sql, array $parameters = []): array
{
    return aggregate_graph_fetch_rows($sql, $parameters)[0] ?? [];
}

function aggregate_graph_fetch_value(string $sql, array $parameters = []): mixed
{
    $rows = aggregate_graph_fetch_rows($sql, $parameters);

    return $rows === [] ? false : reset($rows[0]);
}

/** Preserve sql_save's legacy field normalization; reject every unconfirmed ID. */
function aggregate_graph_save_row(array $row, string $table): int
{
    aggregate_graph_database();
    $id = sql_save($row, $table);
    if ((!is_int($id) && !is_string($id)) || preg_match('/^[1-9][0-9]*$/D', (string) $id) !== 1
        || (int) $id < 1) {
        throw new RuntimeException('The aggregate graph row could not be saved.');
    }

    return (int) $id;
}

/** Aggregate graph_local_save deliberately clears host/query templating. */
function aggregate_graph_refresh_title(int $localGraphId): void
{
    $graph = aggregate_graph_fetch_row(
        'SELECT gl.host_id, gl.snmp_query_id, gtg.title, gtg.title_cache
        FROM graph_templates_graph AS gtg INNER JOIN graph_local AS gl ON gtg.local_graph_id=gl.id
        WHERE gl.id=?',
        [$localGraphId]
    );
    if ($graph === [] || (int) $graph['host_id'] !== 0 || (int) $graph['snmp_query_id'] !== 0
        || !is_string($graph['title']) || !is_string($graph['title_cache'])) {
        throw new RuntimeException('The aggregate graph title source could not be confirmed.');
    }
    if ((!str_contains($graph['title'], '|query_') && !str_contains($graph['title'], '|host_'))
        || $graph['title_cache'] === '') {
        aggregate_graph_execute(
            'UPDATE graph_templates_graph SET title_cache=? WHERE local_graph_id=?',
            [$graph['title'], $localGraphId]
        );
    }
}

/** Preserve legacy substitutions while confirming their actual database reads. */
function aggregate_graph_substitute_host_data($string, $l_escape_string, $r_escape_string, $host_id)
{
    if (!empty($host_id)) {
        $host = aggregate_graph_fetch_row('SELECT ' . SQL_NO_CACHE . ' h.*, s.name AS site_name
			FROM host AS h
			LEFT JOIN sites AS s
			ON h.site_id = s.id
			WHERE h.id = ?', array($host_id));

        if (!cacti_sizeof($host)) {
            return $string;
        }

        $search  = array();
        $replace = array();

        $search[]  = $l_escape_string . 'host_management_ip' . $r_escape_string; /* for compatibility */
        $replace[] = $host['hostname']; /* for compatibility */

        /* common host columns */
        $search[]  = $l_escape_string . 'host_id' . $r_escape_string;
        $replace[] = $host['id'];
        $search[]  = $l_escape_string . 'host_hostname' . $r_escape_string;
        $replace[] = $host['hostname'];
        $search[]  = $l_escape_string . 'host_description' . $r_escape_string;
        $replace[] = $host['description'];
        $search[]  = $l_escape_string . 'host_site' . $r_escape_string;
        $replace[] = $host['site_name'];
        $search[]  = $l_escape_string . 'host_notes' . $r_escape_string;
        $replace[] = $host['notes'];
        $search[]  = $l_escape_string . 'host_location' . $r_escape_string;
        $replace[] = $host['location'];
        $search[]  = $l_escape_string . 'host_polling_time' . $r_escape_string;
        $replace[] = $host['polling_time'];
        $search[]  = $l_escape_string . 'host_avg_time' . $r_escape_string;
        $replace[] = $host['avg_time'];
        $search[]  = $l_escape_string . 'host_cur_time' . $r_escape_string;
        $replace[] = $host['cur_time'];
        $search[]  = $l_escape_string . 'host_availability' . $r_escape_string;
        $replace[] = $host['availability'];
        $search[]  = $l_escape_string . 'host_uptime' . $r_escape_string;
        $replace[] = get_uptime($host);

        /* snmp connectivity information */
        $search[]  = $l_escape_string . 'host_snmp_community' . $r_escape_string;
        $replace[] = $host['snmp_community'];
        $search[]  = $l_escape_string . 'host_snmp_version' . $r_escape_string;
        $replace[] = $host['snmp_version'];
        $search[]  = $l_escape_string . 'host_snmp_username' . $r_escape_string;
        $replace[] = $host['snmp_username'];
        $search[]  = $l_escape_string . 'host_snmp_password' . $r_escape_string;
        $replace[] = $host['snmp_password'];
        $search[]  = $l_escape_string . 'host_snmp_auth_protocol' . $r_escape_string;
        $replace[] = $host['snmp_auth_protocol'];
        $search[]  = $l_escape_string . 'host_snmp_priv_passphrase' . $r_escape_string;
        $replace[] = $host['snmp_priv_passphrase'];
        $search[]  = $l_escape_string . 'host_snmp_priv_protocol' . $r_escape_string;
        $replace[] = $host['snmp_priv_protocol'];
        $search[]  = $l_escape_string . 'host_snmp_context' . $r_escape_string;
        $replace[] = $host['snmp_context'];
        $search[]  = $l_escape_string . 'host_snmp_engine_id' . $r_escape_string;
        $replace[] = $host['snmp_engine_id'];
        $search[]  = $l_escape_string . 'host_snmp_port' . $r_escape_string;
        $replace[] = $host['snmp_port'];
        $search[]  = $l_escape_string . 'host_snmp_timeout' . $r_escape_string;
        $replace[] = $host['snmp_timeout'];

        /* snmp system information */
        $search[]  = $l_escape_string . 'host_snmp_sysDescr' . $r_escape_string;
        $replace[] = $host['snmp_sysDescr'];
        $search[]  = $l_escape_string . 'host_snmp_sysObjectID' . $r_escape_string;
        $replace[] = $host['snmp_sysObjectID'];
        $search[]  = $l_escape_string . 'host_snmp_sysContact' . $r_escape_string;
        $replace[] = $host['snmp_sysContact'];
        $search[]  = $l_escape_string . 'host_snmp_sysLocation' . $r_escape_string;
        $replace[] = $host['snmp_sysLocation'];
        $search[]  = $l_escape_string . 'host_snmp_sysName' . $r_escape_string;
        $replace[] = $host['snmp_sysName'];
        $search[]  = $l_escape_string . 'host_snmp_sysUpTimeInstance' . $r_escape_string;
        $replace[] = $host['snmp_sysUpTimeInstance'];

        $search[]  = $l_escape_string . 'host_ping_retries' . $r_escape_string;
        $replace[] = $host['ping_retries'];
        $search[]  = $l_escape_string . 'host_max_oids' . $r_escape_string;
        $replace[] = $host['max_oids'];

        /* handle the external id */
        $search[]  = $l_escape_string . 'host_external_id' . $r_escape_string;
        $replace[] = $host['external_id'];

        $string = str_replace($search, $replace, $string);

        $temp = api_plugin_hook_function(
            'substitute_host_data',
            array('string' => $string, 'l_escape_string' => $l_escape_string, 'r_escape_string' => $r_escape_string, 'host_id' => $host_id)
        );

        $string = $temp['string'];
    }

    return $string;
}

function aggregate_graph_substitute_query_data($string, $host_id, $snmp_query_id, $snmp_index, $max_chars = 0)
{
    if ($host_id > 0) {
        $snmp_cache_data = aggregate_graph_fetch_rows(
            'SELECT ' . SQL_NO_CACHE . ' field_name, field_value
			FROM host_snmp_cache
			WHERE host_id = ?
			AND snmp_query_id = ?
			AND snmp_index = ?',
            array($host_id, $snmp_query_id, $snmp_index)
        );
    } else {
        $snmp_cache_data = aggregate_graph_fetch_rows(
            'SELECT DISTINCT ' . SQL_NO_CACHE . ' field_name, field_value
			FROM host_snmp_cache
			WHERE snmp_query_id = ?
			AND snmp_index = ?
			AND host_id = 0',
            array($snmp_query_id, $snmp_index)
        );
    }

    if (cacti_sizeof($snmp_cache_data)) {
        foreach ($snmp_cache_data as $data) {
            if (trim($data['field_value']) != '') {
                if ($max_chars > 0) {
                    $data['field_value'] = substr($data['field_value'], 0, $max_chars);
                }

                $string = stri_replace('|query_' . $data['field_name'] . '|', $data['field_value'], $string);

                if (strpos($string, 'query_') === false) {
                    break;
                }
            }
        }
    }

    return $string;
}

/**
 * Create or update aggregate graph.
 * Save all graph definitions, but omit graph items. Wipe out host_id and graph_template_id.
 *
 * @param int $local_graph_id        - ID of an already existing aggregate graph.
 * @param int $graph_template_id     - ID of the corresponding graph_template.
 * @param string $graph_title        - Title for new graph.
 * @param int $aggregate_template_id - ID of aggregate template (0 if no template).
 * @param array $new_data            - Key/value pairs with new graph data.
 *
 * @return int ID of the new graph.
 */
function aggregate_graph_save($_local_graph_id, $_graph_template_id, $_graph_title, $_aggregate_template_id = 0, $graph_data = array())
{
    $aggregate_previous_reporting = error_reporting();
    $aggregate_handler_active = false;
    try {

        /* suppress warnings */
        error_reporting(E_ALL);

        /* install own error handler */
        set_error_handler('aggregate_error_handler');
        $aggregate_handler_active = true;

        cacti_log(__FUNCTION__ . ' local_graph: ' . $_local_graph_id . ' template: ' . $_graph_template_id . ' graph title: ' . $_graph_title . ' aggregate template: ' . $_aggregate_template_id, true, 'AGGREGATE', POLLER_VERBOSITY_DEVDBG);

        /* store basic graph info */
        $local_graph_id = aggregate_graph_local_save($_local_graph_id);

        /* store extra graph data */
        $graph_templates_graph_id = aggregate_graph_templates_graph_save($local_graph_id, $_graph_template_id, $_graph_title, $_aggregate_template_id, $graph_data);


        /* return the id of the newly inserted graph */
        return $local_graph_id;
    } finally {
        if ($aggregate_handler_active) {
            restore_error_handler();
        }
        error_reporting($aggregate_previous_reporting);
    }

}

/**
 * Creates or updates basic aggregate graph data in graph_local.
 *
 * @param int $id - ID of existing aggregate graph if updating or 0 if creating a new one.
 *
 * @return int ID of graph.
 */
function aggregate_graph_local_save($id = 0)
{
    cacti_log(__FUNCTION__ . ' local_graph: ' . $id, true, 'AGGREGATE', POLLER_VERBOSITY_DEVDBG);

    /* create or update entry: graph_local */
    $local_graph['id']                = (isset($id) ? $id : 0);
    $local_graph['graph_template_id'] = 0;  # no templating
    $local_graph['host_id']           = 0;  # no host to be referred to
    $local_graph['snmp_query_id']     = 0;  # no templating
    $local_graph['snmp_index']        = ''; # no templating, may hold string data

    return aggregate_graph_save_row($local_graph, 'graph_local');
}

/**
 * Create or update aggregate graphs data in graph_templates_graph.
 * Graph must already exist in graph_local eg. local_graph_id must never be 0
 *
 * @param int $local_graph_id        - ID of graph.
 * @param int $graph_template_id     - Graph template this graph is based on.
 * @param string $graph_title        - Title of graph. Used only for new graphs.
 * @param int $aggregate_template_id - ID of aggregate template this graph is based on (0 if not aggregate template based).
 * @param array $new_data            - Key/value pairs with new graph data.
 *
 * @return int ID of record in graph_templates_graph
 */
function aggregate_graph_templates_graph_save($local_graph_id, $graph_template_id, $graph_title = '', $aggregate_template_id = 0, $new_data = array())
{
    cacti_log(__FUNCTION__ . ' local_graph: ' . $local_graph_id . ' template: ' . $graph_template_id . ' title: ' . $graph_title . ' aggregate template: ' . $aggregate_template_id, true, 'AGGREGATE', POLLER_VERBOSITY_DEVDBG);

    /* base graph must exist */
    if ($local_graph_id < 1) {
        return 0;
    }

    $graph_data = array();
    $existing_data = aggregate_graph_fetch_row(
        'SELECT *
		FROM graph_templates_graph
		WHERE local_graph_id = ?',
        array($local_graph_id)
    );

    $template_data = aggregate_graph_fetch_row(
        'SELECT *
		FROM graph_templates_graph
		WHERE graph_template_id = ?
		AND local_graph_id=0',
        array($graph_template_id)
    );

    if ($aggregate_template_id > 0) {
        /* override selected fields from template data with aggregate template data */
        $aggregate_data = aggregate_graph_fetch_row(
            'SELECT *
			FROM aggregate_graph_templates_graph
			WHERE aggregate_template_id = ?',
            array($aggregate_template_id)
        );

        if (cacti_sizeof($aggregate_data)) {
            foreach ($aggregate_data as $field => $value) {
                if (substr($field, 0, 2) == 't_' && $value == 'on') {
                    $value_field_name = substr($field, 2);
                    $template_data[$value_field_name] = $aggregate_data[$value_field_name];
                }
            }
        }
    }

    if (cacti_sizeof($existing_data) == 0) {
        /* this is a new graph, use template data */
        $graph_data = $template_data;

        $graph_data['id']          = 0;
        $graph_data['title']       = $graph_title;
        $graph_data['title_cache'] = $graph_title;
    } elseif ($aggregate_template_id > 0) {
        /* this graph exists and is templated from aggregate template,
         * use template data */
        $graph_data = $template_data;

        $graph_data['id']          = $existing_data['id'];
        $graph_data['title']       = $existing_data['title'];
        $graph_data['title_cache'] = $existing_data['title_cache'];
    } else {
        /* this is an existing graph and not templated from aggregate,
         * reuse its old data */
        $graph_data = $existing_data;
    }

    if ($aggregate_template_id == 0) {
        /* now use new data */
        $graph_data = array_merge($graph_data, $new_data);
    }

    /* safety check - don't allow empty titles */
    if ($graph_title != '') {
        $graph_data['title'] = $graph_title;
        $graph_data['title_cache'] = $graph_title;
    }

    $graph_data['auto_padding']                  = 'on';
    $graph_data['local_graph_id']                = $local_graph_id;
    $graph_data['local_graph_template_graph_id'] = 0; # no templating
    $graph_data['graph_template_id']             = 0; # no templating

    $graph_templates_graph_id = aggregate_graph_save_row($graph_data, 'graph_templates_graph');

    /* update title cache */
    if (!empty($graph_templates_graph_id)) {
        aggregate_graph_refresh_title((int) $local_graph_id);
    }

    return $graph_templates_graph_id;
}

/** aggregate_graphs_insert_graph_items	- inserts all graph items of an existing graph
 * @param int $_new_graph_id			- id of the new graph
 * @param int $_old_graph_id			- id of the old graph
 * @param int $_graph_template_id		- template id of the old graph if the old graph is 0
 * @param int $_skip					- graph items to be skipped, array starts at 1
 * @param int $_totali                  - graph items to be totaled, array starts at 1
 * @param int $_graph_item_sequence		- sequence number of the next graph item to be inserted
 * @param int $_selected_graph_index	- index of current graph to be inserted
 * @param array $_color_templates		- the color templates to be used
 * @param array $_graph_item_types		- graph_type_ids to override types from original graph item
 * @param array $_cdefs					- cdef_ids to override cdef from original graph item
 * @param int $_graph_type				- conversion to AREA/STACK or LINE required?
 * @param int $_gprint_prefix			- prefix for the legend line
 * @param int $_gprint_format			- flag to determine if the source graphs GPRINT title should be included
 * @param int $_total					- Totalling: graph items AND/OR legend
 * @param int $_total_type				- Totalling: SIMILAR/ALL data sources
 * @param array $member_graph			- Totalling: Used for determining the consolidation function id
 * @return int							- id of the next graph item to be inserted
 *  */
function aggregate_graphs_insert_graph_items(
    $_new_graph_id,
    $_old_graph_id,
    $_graph_template_id,
    $_skip,
    $_totali,
    $_graph_item_sequence,
    $_selected_graph_index,
    $_color_templates,
    $_graph_item_types,
    $_cdefs,
    $_graph_type,
    $_gprint_prefix,
    $_gprint_format,
    $_total,
    $_total_type = '',
    $member_graphs = array()
) {
    $aggregate_previous_reporting = error_reporting();
    $aggregate_handler_active = false;
    try {


        global $struct_graph_item, $graph_item_types, $config;

        // Remove filter item
        unset($struct_graph_item['data_template_id']);

        include_once($config['base_path'] . '/lib/api_aggregate.php');

        /* suppress warnings */
        error_reporting(E_ALL);

        /* install own error handler */
        set_error_handler('aggregate_error_handler');
        $aggregate_handler_active = true;

        cacti_log(__FUNCTION__ . ' called. Insert example graph:' . $_old_graph_id . ' Graph Template:' . $_graph_template_id . ' into Graph:' . $_new_graph_id . ' at Sequence:' . $_graph_item_sequence . ' Graph_No:' . $_selected_graph_index . ' Type Action: ' . $_graph_type, true, 'AGGREGATE', POLLER_VERBOSITY_DEVDBG);

        cacti_log(__FUNCTION__ . ' skipping: ' . serialize($_skip), true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);

        # take graph item data from old one
        if (!empty($_old_graph_id)) {
            $graph_items = aggregate_graph_fetch_rows(
                'SELECT *
			FROM graph_templates_item
			WHERE local_graph_id = ?
			ORDER BY sequence',
                array($_old_graph_id)
            );

            $graph_local = aggregate_graph_fetch_row(
                'SELECT host_id, graph_template_id,
			snmp_query_id, snmp_index
			FROM graph_local
			WHERE id = ?',
                array($_old_graph_id)
            );
        } else {
            $graph_items = aggregate_graph_fetch_rows(
                'SELECT *
			FROM graph_templates_item
			WHERE local_graph_id = ?
			AND graph_template_id = ?
			ORDER BY sequence',
                array($_old_graph_id, $_graph_template_id)
            );

            $graph_local = array();
        }

        if ($graph_items === [] || (!empty($_old_graph_id) && $graph_local === [])) {
            throw new RuntimeException('An aggregate member has no confirmed graph items.');
        }

        /* create new entry(s): graph_templates_item */
        $num_items = cacti_sizeof($graph_items);
        if ($num_items > 0) {
            # take care of items having a HR that shall be skipped
            $i = 0;
            for ($i; $i < $num_items; $i++) {
                # remember existing hard returns (array must start at 1 to match $skipped_items
                $_hr[$i + 1] = ($graph_items[$i]['hard_return'] != '');
            }
            # move 'skipped hard returns' to previous graph item
            $_hr = auto_hr($_skip, $_hr);

            # next entry will have to have a prepended text format
            $prepend     = true;
            $prepend_cnt = 0;
            $skip_graph  = false;
            $make0_cdef  = aggregate_cdef_make0();
            $i           = 0;

            foreach ($graph_items as $graph_item) {
                # loop starts at 0, but $_skip starts at 1, so increment before comparing
                $i++;

                # go ahead, if this graph item has to be skipped
                if (isset($_skip[$i]) && !empty($_skip[$i])) {
                    continue;
                }

                // HRULES will only appear in the total one way or nother
                if ($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_HRULE) {
                    continue;
                }

                if ($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_COMMENT) {
                    if (preg_match('/(:bits:|:bytes:|\|sum:)/', $graph_item['text_format'])) {
                        // Only skip nth percentile COMMENT values
                        $parts = explode('|', $graph_item['text_format']);

                        if (isset($parts[1])) {
                            $pparts = explode(':', $parts[1]);
                            if (is_numeric($pparts[0])) {
                                continue;
                            }
                        }
                    }
                }

                if ($_total == AGGREGATE_TOTAL_ONLY) {
                    # if we only need the totalling legend, ...
                    if (($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_GPRINT) ||
                        ($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_GPRINT_LAST) ||
                        ($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_GPRINT_MIN) ||
                        ($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_GPRINT_MAX) ||
                        ($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_GPRINT_AVERAGE) ||
                        ($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_TEXTALIGN) ||
                        ($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_TIC) ||
                        ($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_COMMENT)) {
                        # and this is a legend entry (GPRINT, COMMENT), skip
                        continue;
                    } else {
                        # this is a graph entry, remove text to make it disappear
                        # do NOT skip!
                        # we need this entry as a DEF
                        # and as long as cacti does not provide for a 'pure DEF' graph item type
                        # we need this workaround
                        $graph_item['text_format'] = '';

                        # make sure, that this entry does not have a HR,
                        # else a single colored mark will be drawn
                        $graph_item['hard_return'] = '';
                        $_hr[$i] = '';

                        # make sure, that data of this item will be suppressed: make 0!
                        $graph_item['cdef_id'] = $make0_cdef;

                        # try to pick the best totaling cf id
                        $graph_item['consolidation_function_id'] = aggregate_graph_fetch_value('SELECT consolidation_function_id
						FROM graph_templates_item
						WHERE color_id > 0
						AND sequence = ' . $graph_item['sequence'] .
                            (cacti_sizeof($member_graphs) ? ' AND ' . array_to_sql_or($member_graphs, 'local_graph_id') : '') .
                            (cacti_sizeof($_skip) ? ' AND sequence NOT IN (' . implode(',', $_skip) . ')' : '') .
                            (cacti_sizeof($_totali) ? ' AND sequence IN (' . implode(',', $_totali) . ')' : '') . '
						ORDER BY sequence ASC
						LIMIT 1');
                    }
                } elseif ($graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_AREA ||
                    $graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_LINE1 ||
                    $graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_LINE2 ||
                    $graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_LINE3 ||
                    $graph_item['graph_type_id'] == GRAPH_ITEM_TYPE_STACK) {

                    if ($_total_type == AGGREGATE_TOTAL_TYPE_ALL) {
                        $graph_item['text_format'] = $_gprint_prefix;
                    } elseif ($_gprint_format != '') {
                        $graph_item['text_format'] = $_gprint_prefix . ' ' . $graph_item['text_format'];
                    } else {
                        $graph_item['text_format'] = $_gprint_prefix;
                    }
                }

                # use all data from 'old' graph ...
                $save = $graph_item;

                # now it's time for some 'special purpose' processing
                # selected fields will need special treatment

                # take care of color changes only if not set to None
                if (isset($_color_templates[$i])) {
                    if ($_color_templates[$i] > 0) {
                        # get the size of the color templates array
                        # if number of colored items exceed array size, use round robin
                        $num_colors = aggregate_graph_fetch_value(
                            'SELECT COUNT(color_id)
						FROM color_template_items
						WHERE color_template_id = ?',
                            array($_color_templates[$i])
                        );

                        # templating required, get color for current graph item
                        $sql = 'SELECT color_id
						FROM color_template_items
						WHERE color_template_id=' . $_color_templates[$i] . '
						ORDER BY sequence
						LIMIT ' . ($_selected_graph_index % $num_colors) . ',1';

                        $save['color_id'] = aggregate_graph_fetch_value($sql);
                    } else {
                        /* set a color even if no color templating is required */
                        $save['color_id'] = $graph_item['color_id'];
                    }
                } /* else: no color templating defined, e.g. GPRINT entry */

                # do we want to override cdef of this item
                # certainly not if it was set to $make0_cdef above
                if ($_cdefs[$i] > 0 && $graph_item['cdef_id'] != $make0_cdef) {
                    $save['cdef_id'] = $_cdefs[$i];
                }

                # take care of the graph_item_type
                # user may want to override types (ex. LINEx to AREA)
                # do this before we try start converting stuff to AREAs and such
                if ($_graph_item_types[$i] > 0) {
                    $save['graph_type_id'] = $_graph_item_types[$i];
                } else {
                    $save['graph_type_id'] = $graph_item['graph_type_id'];
                }

                /* change graph types, if requested */
                $save['graph_type_id'] = aggregate_change_graph_type($_selected_graph_index, $save['graph_type_id'], $_graph_type);

                # new item text format required?
                if ($_total_type != '') {
                    if ($prepend) {
                        if ($_total_type == AGGREGATE_TOTAL_TYPE_ALL && $prepend_cnt > 0) {
                            continue;
                        }

                        # pointless to add any data source item name here, cause ALL are totaled
                        if ($_gprint_format != '') {
                            $save['text_format'] = $graph_item['text_format'];
                        } else {
                            $save['text_format'] = '';
                        }

                        # no more prepending until next line break is encountered
                        $prepend = false;
                        $prepend_cnt++;
                    } elseif (strpos($save['text_format'], ':current:')) {
                        if ($_total_type == AGGREGATE_TOTAL_TYPE_ALL) {
                            $save['text_format'] = str_replace(':current:', ':aggregate_sum:', $save['text_format']);
                        } elseif ($_total_type == AGGREGATE_TOTAL_TYPE_SIMILAR) {
                            $save['text_format'] = str_replace(':current:', ':aggregate_current:', $save['text_format']);
                        } else {
                            cacti_log(__FUNCTION__ . ' unhandled total_type ' . $_total_type . ' for :current: in text_format', true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);
                        }
                    } elseif (strpos($save['text_format'], ':max:')) {
                        if ($_total_type == AGGREGATE_TOTAL_TYPE_ALL) {
                            $save['text_format'] = str_replace(':max:', ':aggregate_sum_peak:', $save['text_format']);
                        } elseif ($_total_type == AGGREGATE_TOTAL_TYPE_SIMILAR) {
                            $save['text_format'] = str_replace(':max:', ':aggregate_current_peak:', $save['text_format']);
                        } else {
                            cacti_log(__FUNCTION__ . ' unhandled total_type ' . $_total_type . ' for :max: in text_format', true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);
                        }
                    }
                }

                if ($save['text_format'] != '') {
                    $save['text_format'] = aggregate_graph_substitute_host_data($save['text_format'], '|', '|', (isset($graph_local['host_id']) ? $graph_local['host_id'] : 0));
                    cacti_log(__FUNCTION__ . ' substituted:' . $save['text_format'], true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);

                    /* if this is a data query graph type, try to substitute */
                    if (isset($graph_local['snmp_query_id']) && $graph_local['snmp_query_id'] > 0 && $graph_local['snmp_index'] != '') {
                        $save['text_format'] = aggregate_graph_substitute_query_data($save['text_format'], $graph_local['host_id'], $graph_local['snmp_query_id'], $graph_local['snmp_index'], read_config_option('max_data_query_field_length'));

                        cacti_log(__FUNCTION__ . ' substituted:' . $save['text_format'] . ' for ' . $graph_local['host_id'] . ',' . $graph_local['snmp_query_id'] . ',' . $graph_local['snmp_index'], true, 'AGGREGATE', POLLER_VERBOSITY_DEVDBG);
                    }
                }

                # <HR> wanted?
                if (isset($_hr[$i]) && $_hr[$i] > 0) {
                    $save['hard_return'] = 'on';
                }

                # if this item defines a line break, remember to prepend next line
                if ($save['text_format'] != '') {
                    $prepend = ($save['hard_return'] == 'on');
                }

                # provide new sequence number
                $save['sequence'] = $_graph_item_sequence;
                cacti_log(__FUNCTION__ . '  hard return: ' . $save['hard_return'] . ' sequence: ' . $_graph_item_sequence, true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);

                $save['id'] 							= 0;
                $save['local_graph_template_item_id']	= 0;	# disconnect this graph item from the graph template item
                $save['local_graph_id'] 				= (isset($_new_graph_id) ? $_new_graph_id : 0);
                $save['graph_template_id'] 				= 0;	# disconnect this graph item from the graph template
                $save['hash']                           = '';   # remove any template attribs

                $graph_item_mappings[$graph_item['id']] = aggregate_graph_save_row($save, 'graph_templates_item');

                $_graph_item_sequence++;
            }
        }


        # return with next sequence number to be filled
        return $_graph_item_sequence;
    } finally {
        if ($aggregate_handler_active) {
            restore_error_handler();
        }
        error_reporting($aggregate_previous_reporting);
    }

}

/**
 * insert or update aggregate graph items in DB tables
 * @param array $items
 * @param string $table
 * @return bool true if save was successful, false otherwise
 */
function aggregate_graph_items_save($items, $table)
{
    global $database_sessions, $database_hostname, $database_port, $database_default;

    $db = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    if (!$db instanceof PDO || !is_array($items) || !$items) {
        return false;
    }

    $defaults = array();
    if ($table == 'aggregate_graphs_graph_item') {
        $defaults['aggregate_graph_id'] = null;
        $id_field = 'aggregate_graph_id';
    } elseif ($table == 'aggregate_graph_templates_item') {
        $defaults['aggregate_template_id'] = null;
        $id_field = 'aggregate_template_id';
    } else {
        return false;
    }

    $defaults['graph_templates_item_id'] = null;
    $defaults['sequence']        = 0;
    $defaults['color_template']  = 0;
    $defaults['t_graph_type_id'] = '';
    $defaults['graph_type_id']   = 0;
    $defaults['t_cdef_id']       = '';
    $defaults['cdef_id']         = 0;
    $defaults['item_skip']       = '';
    $defaults['item_total']      = '';

    $items_sql = array();
    $parameters = array();
    $aggregate_id = (int) ($items[0][$id_field] ?? 0);
    foreach ($items as $item) {
        if (!is_array($item) || (int) ($item[$id_field] ?? -1) !== $aggregate_id) {
            return false;
        }
        // substitute any missing fields with defaults
        $item = array_merge($defaults, $item);

        // remove any extra fields
        $item = array_intersect_key($item, $defaults);

        // without these graph item makes no sense
        if (!isset($item[$id_field]) || !isset($item['graph_templates_item_id'])) {
            return false;
        }

        // Keep values out of SQL while preserving the existing integer fields.
        $items_sql[] = ' (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        array_push(
            $parameters,
            (int) $item[$id_field],
            (int) $item['graph_templates_item_id'],
            (int) $item['sequence'],
            (int) $item['color_template'],
            $item['t_graph_type_id'],
            (int) $item['graph_type_id'],
            $item['t_cdef_id'],
            (int) $item['cdef_id'],
            $item['item_skip'],
            $item['item_total']
        );
    }

    $sql = 'INSERT INTO ' . $table;
    $sql .= '(' . implode(', ', array_keys($defaults)) . ') VALUES ';
    $sql .= implode(', ', $items_sql);

    cacti_log(__FUNCTION__ . ' called. SQL: ' . $sql, true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);

    // Protect the complete replacement on the same selected connection. A
    // native reference refusal must not commit the preceding cache deletion.
    $started = false;
    $savepoint = null;
    try {
        if (aggregate_graph_database() !== $db) {
            throw new RuntimeException('Aggregate cache connection changed during regeneration.');
        }
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $metadata = $db->query('SHOW CREATE TABLE `' . $table . '`');
            if ($metadata === false) {
                throw new RuntimeException('Aggregate cache metadata is unavailable.');
            }
            $row = $metadata->fetch(PDO::FETCH_ASSOC);
            if ($metadata->errorCode() !== '00000' || $db->errorCode() !== '00000' || !is_array($row)
                || !is_string($row['Create Table'] ?? null)
                || preg_match('/\ACREATE TABLE\s/i', $row['Create Table']) !== 1) {
                throw new RuntimeException('Aggregate cache requires a persistent transactional table.');
            }
            if (!$metadata->closeCursor() || $metadata->errorCode() !== '00000') {
                throw new RuntimeException('Aggregate cache metadata close could not be confirmed.');
            }
            // Table comments may contain an arbitrary ENGINE=InnoDB string.
            // Read the actual persistent engine on this selected native PDO.
            $status = $db->query("SHOW TABLE STATUS WHERE Name = '$table'");
            if ($status === false || $db->errorCode() !== '00000') {
                throw new RuntimeException('Aggregate cache engine metadata is unavailable.');
            }
            $engine = $status->fetch(PDO::FETCH_ASSOC);
            $additional = $status->fetch(PDO::FETCH_ASSOC);
            if ($status->errorCode() !== '00000' || !is_array($engine)
                || ($engine['Name'] ?? null) !== $table || ($engine['Engine'] ?? null) !== 'InnoDB'
                || $additional !== false) {
                throw new RuntimeException('Aggregate cache requires a persistent transactional table.');
            }
            if (!$status->closeCursor() || $status->errorCode() !== '00000') {
                throw new RuntimeException('Aggregate cache engine metadata close could not be confirmed.');
            }
        } elseif ($driver !== 'sqlite') {
            return false;
        }
        if ($db->inTransaction()) {
            $candidate = 'kadupul_aggregate_items_' . bin2hex(random_bytes(8));
            if ($db->exec('SAVEPOINT ' . $candidate) === false) {
                throw new RuntimeException('Aggregate cache savepoint could not be confirmed.');
            }
            $savepoint = $candidate;
            if ($db->errorCode() !== '00000') {
                throw new RuntimeException('Aggregate cache savepoint could not be confirmed.');
            }
        } else {
            if (!$db->beginTransaction()) {
                throw new RuntimeException('Aggregate cache transaction could not start.');
            }
            $started = true;
            if ($db->errorCode() !== '00000') {
                throw new RuntimeException('Aggregate cache transaction could not be confirmed.');
            }
        }
        foreach (array(
            array("DELETE FROM $table WHERE " . $id_field . ' = ?', array($aggregate_id)),
            array($sql, $parameters),
        ) as [$statement_sql, $statement_parameters]) {
            $statement = $db->prepare($statement_sql);
            if ($statement === false || !$statement->execute($statement_parameters)
                || $statement->errorCode() !== '00000' || $db->errorCode() !== '00000') {
                throw new RuntimeException('Aggregate cache replacement could not be confirmed.');
            }
            if (!$statement->closeCursor() || $statement->errorCode() !== '00000') {
                throw new RuntimeException('Aggregate cache replacement close could not be confirmed.');
            }
        }
        if ($started) {
            if (!$db->commit() || $db->errorCode() !== '00000') {
                throw new RuntimeException('Aggregate cache commit could not be confirmed.');
            }
        } elseif ($db->exec('RELEASE SAVEPOINT ' . $savepoint) === false || $db->errorCode() !== '00000') {
            throw new RuntimeException('Aggregate cache savepoint release could not be confirmed.');
        }
        return true;
    } catch (Throwable $error) {
        try {
            if ($db->inTransaction()) {
                if ($started) {
                    if (!$db->rollBack() || $db->errorCode() !== '00000') {
                        throw new RuntimeException('Aggregate cache rollback could not be confirmed.');
                    }
                } elseif ($savepoint !== null) {
                    if ($db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint) === false || $db->errorCode() !== '00000'
                        || $db->exec('RELEASE SAVEPOINT ' . $savepoint) === false || $db->errorCode() !== '00000') {
                        throw new RuntimeException('Aggregate cache savepoint rollback could not be confirmed.');
                    }
                }
            }
        } catch (Throwable $cleanup) {
            // A failed cleanup must never become a successful replacement result.
        }
        return false;
    }
}

/**
 * Validate extra graph parameters posted from graph edit form.
 * You can check for validation errors with cacti function is_error_message
 * @param array $posted      - values posted from form
 * @param bool $has_override - form had override checkboxes
 * @return array             - cleaned up graph parameters
 */
function aggregate_validate_graph_params($posted, $has_override = false)
{
    $check_post_params = array(
        'alt_y_grid'           => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'auto_padding'         => array('type' => 'bool', 'allow_empty' => true,  'default' => '', 'regex' => ''),
        'auto_scale'           => array('type' => 'bool', 'allow_empty' => true,  'default' => '', 'regex' => ''),
        'auto_scale_log'       => array('type' => 'bool', 'allow_empty' => true,  'default' => '', 'regex' => ''),
        'auto_scale_opts'      => array('type' => 'int',  'allow_empty' => false, 'default' => 0,  'regex' => ''),
        'auto_scale_rigid'     => array('type' => 'bool', 'allow_empty' => true,  'default' => '', 'regex' => ''),
        'base_value'           => array('type' => 'int',  'allow_empty' => true,  'default' => 0,  'regex' => '^[0-9]+$'),
        'dynamic_labels'       => array('type' => 'str',  'allow_empty' => true,  'default' => 0,  'regex' => ''),
        'force_rules_legend'   => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'grouping'             => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'height'               => array('type' => 'int',  'allow_empty' => false, 'default' => 0,  'regex' => '^[0-9]+$'),
        'image_format_id'      => array('type' => 'int',  'allow_empty' => false, 'default' => 0,  'regex' => ''),
        'left_axis_formatter'  => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'legend_direction'     => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'legend_position'      => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'lower_limit'          => array('type' => 'int',  'allow_empty' => true,  'default' => 0,  'regex' => '^(-?([0-9]+(\.[0-9]*)?|[0-9]*\.[0-9]+)([eE][+\-]?[0-9]+)?)|U$'),
        'no_gridfit'           => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'right_axis'           => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'right_axis_format'    => array('type' => 'int',  'allow_empty' => true,  'default' => '', 'regex' => '^[0-9]+$'),
        'right_axis_formatter' => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'right_axis_label'     => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'scale_log_units'      => array('type' => 'bool', 'allow_empty' => true,  'default' => '', 'regex' => ''),
        'slope_mode'           => array('type' => 'bool', 'allow_empty' => true,  'default' => '', 'regex' => ''),
        'tab_width'            => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => '^[0-9]+$'),
        'unit_exponent_value'  => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'unit_length'          => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'unit_value'           => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'upper_limit'          => array('type' => 'int',  'allow_empty' => true,  'default' => 0,  'regex' => '^(-?([0-9]+(\.[0-9]*)?|[0-9]*\.[0-9]+)([eE][+\-]?[0-9]+)?)|U$'),
        'vertical_label'       => array('type' => 'str',  'allow_empty' => true,  'default' => '', 'regex' => ''),
        'width'                => array('type' => 'int',  'allow_empty' => false, 'default' => 0,  'regex' => '^[0-9]+$')
    );

    $params_new = array();

    /* validate posted form fields */
    foreach ($check_post_params as $field => $defs) {
        if ($has_override && !isset($posted['t_' . $field])) {
            /* override checkbox off - use default value */
            $params_new['t_' . $field] = '';
            $params_new[$field] = $defs['default'];
            continue;
        }

        if ($has_override) {
            /* override checkbox was on */
            $params_new['t_' . $field] = 'on';
        }

        /* validate value */
        if ($defs['type'] == 'bool') {
            $params_new[$field] = (isset($posted[$field])) ? 'on' : '';
        } else {
            $params_new[$field] = (isset($posted[$field]) ? form_input_validate(html_escape($posted[$field]), $field, $defs['regex'], $defs['allow_empty'], 3) : $defs['default']);
        }
    }

    return $params_new;
}

/**
 * Populate graph items array with posted values.
 * $graph_items array must be keyed on graph item id.
 * @param array $posted      - values posted from form
 * @param array $graph_items - reference to graph items array to update with form values
 *
 */
function aggregate_validate_graph_items($posted, &$graph_items)
{
    foreach ($_POST as $field => $value) {
        if (!is_string($field) || preg_match('/^agg_(color|skip|total)_([0-9]+)$/D', $field, $match) !== 1) continue;
        if (!isset($graph_items[$match[2]])) continue;
        if ($match[1] === 'color') {
            if ((!is_int($value) && !is_string($value)) || preg_match('/^[0-9]+$/D', (string) $value) !== 1) {
                throw new InvalidArgumentException('Aggregate color selection must be an unsigned decimal ID.');
            }
            $decimal = ltrim((string) $value, '0');
            if (strlen($decimal) > 10 || (strlen($decimal) === 10 && strcmp($decimal, '4294967295') > 0)) {
                throw new InvalidArgumentException('Aggregate color selection exceeds its stored ID range.');
            }
        } elseif (!is_string($value) || !in_array($value, ['', 'on'], true)) {
            throw new InvalidArgumentException('Aggregate checkbox selection must use its scalar form value.');
        }
    }

    foreach ($_POST as $var => $val) {
        if (!is_string($var)) continue;
        /* work on color_templates */
        if (preg_match('/^agg_color_([0-9]+)$/', $var, $matches)) {
            /* ================= input validation ================= */
            input_validate_input_number($matches[1]);
            /* ==================================================== */
            $graph_templates_item_id = str_replace('agg_color_', '', $var);
            if (isset($graph_items[$graph_templates_item_id])) {
                $graph_items[$graph_templates_item_id]['color_template'] = $val;
            } else {
                cacti_log('Something fubar in agg_color');
            }
        }

        /* work on checkboxed for skipping items */
        if (preg_match('/^agg_skip_([0-9]+)$/', $var, $matches)) {
            /* ================= input validation ================= */
            input_validate_input_number($matches[1]);
            /* ==================================================== */
            $graph_templates_item_id = str_replace('agg_skip_', '', $var);
            if (isset($graph_items[$graph_templates_item_id])) {
                $graph_items[$graph_templates_item_id]['item_skip'] = $val;
            } else {
                cacti_log('Something fubar in agg_skip');
            }
        }

        /* work on checkboxed for totalling items */
        if (preg_match('/^agg_total_([0-9]+)$/', $var, $matches)) {
            /* ================= input validation ================= */
            input_validate_input_number($matches[1]);
            /* ==================================================== */
            $graph_templates_item_id = str_replace('agg_total_', '', $var);
            if (isset($graph_items[$graph_templates_item_id])) {
                $graph_items[$graph_templates_item_id]['item_total'] = $val;
            } else {
                cacti_log('Something fubar in agg_total');
            }
        }
    }
}

/**
 * cleanup of graph items of the new graph
 * @param int $base			- base graph id
 * @param int $aggregate	- graph id of aggregate
 * @param int $reorder		- type of reordering
 */
function aggregate_graphs_cleanup($base, $aggregate, $reorder)
{
    $aggregate_previous_reporting = error_reporting();
    $aggregate_handler_active = false;
    try {

        global $config;

        include_once($config['base_path'] . '/lib/api_aggregate.php');

        cacti_log(__FUNCTION__ . ' called. Base ' . $base . ' Aggregate ' . $aggregate . ' Reorder: ' . $reorder, true, 'AGGREGATE', POLLER_VERBOSITY_DEVDBG);

        /* suppress warnings */
        error_reporting(E_ALL);

        /* install own error handler */
        set_error_handler('aggregate_error_handler');
        $aggregate_handler_active = true;

    } finally {
        if ($aggregate_handler_active) {
            restore_error_handler();
        }
        error_reporting($aggregate_previous_reporting);
    }

}

/**
 * reorder graph items
 * @param int $base              - base graph id
 * @param int $aggregate         - graph id of aggregate
 * @param int $reorder           - type of reordering
 * @param int $graph_type        - type of graph
 */
function aggregate_reorder_ds_graph($base, $graph_template_id, $aggregate, $reorder, $graph_type)
{
    $aggregate_previous_reporting = error_reporting();
    $aggregate_handler_active = false;
    try {

        global $config;

        cacti_log(__FUNCTION__ . ' called. Base Graph ' . $base . ' Graph Template ' . $graph_template_id . ' Aggregate Graph ' . $aggregate . ' Reorder: ' . $reorder, true, 'AGGREGATE', POLLER_VERBOSITY_DEVDBG);

        /* suppress warnings */
        error_reporting(E_ALL);

        /* install own error handler */
        set_error_handler('aggregate_error_handler');
        $aggregate_handler_active = true;

        $new_seq      = 1;
        $base_handler = false;
        $updates      = array();

        // Get the order of items to re-arrange on the graph
        if ($reorder == AGGREGATE_ORDER_NONE) {

            return true;
        } elseif ($reorder == AGGREGATE_ORDER_DS_GRAPH) {
            $sql_order = 'dtr.data_source_name, gti.sequence';
        } elseif ($reorder == AGGREGATE_ORDER_BASE_GRAPH) {
            $base_handler = true;
        } else {
            $sql_order = 'gtg.title_cache, gti.sequence';
        }

        if ($base_handler) {
            /* get all different local_data_template_rrd_id's
             * respecting the order that the aggregated graph has
             */
            $sql_where     = "WHERE gti.local_graph_id = $base" . ($base == 0 ? " AND gti.graph_template_id = $graph_template_id" : '');
            $sql_id_column = ($base == 0 ? 'id' : 'local_data_template_rrd_id');
            $sql = "SELECT DISTINCT dtr.$sql_id_column AS local_data_template_rrd_id
			FROM data_template_rrd AS dtr
			LEFT JOIN graph_templates_item AS gti
			ON gti.task_item_id = dtr.id
			$sql_where
			ORDER BY gti.sequence";

            $ds_ids = aggregate_graph_fetch_rows($sql);

            foreach ($ds_ids as $ds_id) {
                cacti_log('local_data_template_rrd_id: ' . $ds_id['local_data_template_rrd_id'], false, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);
                /* get all different task_item_id's
                 * respecting the order that the aggregated graph has
                 */
                $sql = "SELECT gti.id, gti.task_item_id
				FROM graph_templates_item AS gti
				LEFT JOIN data_template_rrd AS dtr
				ON gti.task_item_id = dtr.id
				WHERE gti.local_graph_id = $aggregate
				AND dtr.local_data_template_rrd_id = " . $ds_id['local_data_template_rrd_id'] . "
				ORDER BY sequence";

                cacti_log(__FUNCTION__ . ' sql: ' . $sql, false, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);

                $items = aggregate_graph_fetch_rows($sql);

                foreach ($items as $item) {
                    # accumulate the updates to avoid interfering the next loops
                    $updates[] = 'UPDATE graph_templates_item SET sequence = ' . $new_seq++ . ' WHERE id = ' . $item['id'];
                }
            }
        } else {
            $aggregate_graph_id = aggregate_graph_fetch_value(
                'SELECT id
			FROM aggregate_graphs
			WHERE local_graph_id = ?',
                array($aggregate)
            );

            $list = array_rekey(
                aggregate_graph_fetch_rows(
                    "SELECT DISTINCT dtr.local_data_id
				FROM data_template_rrd AS dtr
				INNER JOIN graph_templates_item AS gti
				ON gti.task_item_id = dtr.id
				INNER JOIN aggregate_graphs_items AS agi
				ON gti.local_graph_id = agi.local_graph_id
				INNER JOIN graph_templates_graph AS gtg
				ON gtg.local_graph_id = gti.local_graph_id
				WHERE agi.aggregate_graph_id = ?
				ORDER BY $sql_order",
                    array($aggregate_graph_id)
                ),
                'local_data_id',
                'local_data_id'
            );

            if (cacti_sizeof($list)) {
                $sql_order = 'FIELD(dtr.local_data_id, ' . implode(', ', $list) . '), gti.sequence';
            } else {
                $sql_order = 'gti.sequence';
            }

            $sql = "SELECT gti.id, gti.task_item_id, graph_type_id
			FROM graph_templates_item AS gti
			LEFT JOIN data_template_rrd AS dtr
			ON gti.task_item_id = dtr.id
			WHERE gti.local_graph_id = $aggregate
			AND dtr.local_data_id IN (" . (empty($list) ? 'NULL' : implode(', ', $list)) . ")
			ORDER BY $sql_order";

            if ($reorder != AGGREGATE_ORDER_NONE && $reorder != AGGREGATE_ORDER_DS_GRAPH) {
                $color_ids = aggregate_graph_fetch_rows("SELECT color_id
				FROM graph_templates_item AS gti
				LEFT JOIN data_template_rrd AS dtr
				ON gti.task_item_id = dtr.id
				WHERE gti.local_graph_id = $aggregate
				AND dtr.local_data_id IN (" . (empty($list) ? 'NULL' : implode(', ', $list)) . ")
				ORDER BY gti.sequence");
            } else {
                $color_ids = array();
            }

            cacti_log(__FUNCTION__ . ' sql: ' . $sql, false, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);

            $items = aggregate_graph_fetch_rows($sql);
            $i = 0;

            foreach ($items as $item) {
                $new_graph_type = aggregate_change_graph_type($i, $item['graph_type_id'], $graph_type);

                if (isset($color_ids[$i])) {
                    $color_id = $color_ids[$i]['color_id'];
                } else {
                    $color_id = '';
                }

                # accumulate the updates to avoid interfering the next loops
                $updates[] = 'UPDATE graph_templates_item
				SET sequence = ' . $new_seq . ",
				graph_type_id = $new_graph_type " .
                    ($color_id != '' ? ', color_id = ' . $color_id : '') . "
				WHERE id = " . $item['id'];

                $i++;
                $new_seq++;
            }
        }

        # now get all 'empty' local_data_template_rrd_id's
        # = those graph items without associated data source (e.g. COMMENT)
        $sql = "SELECT id
		FROM graph_templates_item AS gti
		WHERE gti.local_graph_id = $aggregate
		AND gti.task_item_id = 0
		ORDER BY sequence";

        cacti_log($sql, false, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);

        $empty_task_items = aggregate_graph_fetch_rows($sql);

        # now add those 'empty' one's to the end
        foreach ($empty_task_items as $item) {
            # accumulate the updates to avoid interfering the next loops
            $updates[] = 'UPDATE graph_templates_item SET sequence = ' . $new_seq++ . ' WHERE id = ' . $item['id'];
        }

        # now run all updates
        if (cacti_sizeof($updates)) {
            foreach ($updates as $update) {
                cacti_log(__FUNCTION__ . ' update: ' . $update, false, 'AGGREGATE', POLLER_VERBOSITY_DEVDBG);
                aggregate_graph_execute($update);
            }
        }

    } finally {
        if ($aggregate_handler_active) {
            restore_error_handler();
        }
        error_reporting($aggregate_previous_reporting);
    }

}

/**
 * push_out_aggregates				- update all aggregates based upon the template
 * @param int aggregate_template_id	- the aggregate template id
 * @param int local_graph_id		- the specific aggregate graph to update
 *  */
function push_out_aggregates($aggregate_template_id, $local_graph_id = 0): bool
{
    return aggregate_graph_mutation(static function () use ($aggregate_template_id, $local_graph_id): bool {
        return push_out_aggregates_operation($aggregate_template_id, $local_graph_id) === true;
    });
}

function push_out_aggregates_operation($aggregate_template_id, $local_graph_id = 0): bool
{
    $attribs                    = array();
    $attribs['skipped_items']   = array();
    $attribs['total_items']     = array();
    $attribs['color_templates'] = array();
    $attribs['graph_item_types'] = array();
    $attribs['cdefs']           = array();
    $member_graphs              = array();

    if ($local_graph_id > 0 && $aggregate_template_id == 0) {
        $id = aggregate_graph_fetch_value(
            'SELECT id
			FROM aggregate_graphs
			WHERE local_graph_id = ?',
            array($local_graph_id)
        );

        $attribs['graph_title'] = aggregate_graph_fetch_value(
            'SELECT title_format
			FROM aggregate_graphs
			WHERE id = ?',
            array($id)
        );

        $attribs['skipped_items'] = array_rekey(
            aggregate_graph_fetch_rows(
                'SELECT sequence
				FROM aggregate_graphs_graph_item
				WHERE item_skip="on"
				AND aggregate_graph_id = ?
				ORDER BY sequence',
                array($id)
            ),
            'sequence',
            'sequence'
        );

        $attribs['total_items'] = array_rekey(
            aggregate_graph_fetch_rows(
                'SELECT sequence
				FROM aggregate_graphs_graph_item
				WHERE item_total="on"
				AND aggregate_graph_id = ?
				ORDER BY sequence',
                array($id)
            ),
            'sequence',
            'sequence'
        );

        $attribs['color_templates'] = array_rekey(
            aggregate_graph_fetch_rows(
                'SELECT sequence, color_template
				FROM aggregate_graphs_graph_item
				WHERE color_template>=0
				AND aggregate_graph_id = ?
				ORDER BY sequence',
                array($id)
            ),
            'sequence',
            'color_template'
        );

        $attribs['graph_item_types'] = array_rekey(
            aggregate_graph_fetch_rows(
                'SELECT sequence, graph_type_id
				FROM aggregate_graphs_graph_item
				WHERE t_graph_type_id="on"
				AND aggregate_graph_id = ?
				ORDER BY sequence',
                array($id)
            ),
            'sequence',
            'graph_type_id'
        );

        $attribs['cdefs'] = array_rekey(
            aggregate_graph_fetch_rows(
                'SELECT sequence, cdef_id
				FROM aggregate_graphs_graph_item
				WHERE t_cdef_id="on"
				AND aggregate_graph_id = ?
				ORDER BY sequence',
                array($id)
            ),
            'sequence',
            'cdef_id'
        );

        $attribs['aggregate_graph_id']   = $aggregate_template_id;
        $attribs['template_propogation'] = '';

        $template_data                   = aggregate_graph_fetch_row('SELECT * FROM aggregate_graphs WHERE id = ?', array($id));
        if ($template_data === []) {
            return false;
        }
        $attribs['graph_template_id']    = $template_data['graph_template_id'];
        $attribs['gprint_prefix']        = $template_data['gprint_prefix'];
        $attribs['gprint_format']        = $template_data['gprint_format'];
        $attribs['graph_type']           = $template_data['graph_type'];
        $attribs['total']                = $template_data['total'];
        $attribs['total_type']           = $template_data['total_type'];
        $attribs['total_prefix']         = $template_data['total_prefix'];
        $attribs['reorder']              = $template_data['order_type'];
        $attribs['item_no']              = aggregate_graph_fetch_value('SELECT COUNT(*) FROM aggregate_graphs_graph_item WHERE aggregate_graph_id = ?', array($id));
    } else {
        $attribs['graph_title'] = '';

        $attribs['skipped_items'] = array_rekey(
            aggregate_graph_fetch_rows(
                'SELECT sequence
				FROM aggregate_graph_templates_item
				WHERE item_skip="on"
				AND aggregate_template_id = ?
				ORDER BY sequence',
                array($aggregate_template_id)
            ),
            'sequence',
            'sequence'
        );

        $attribs['total_items'] = array_rekey(
            aggregate_graph_fetch_rows(
                'SELECT sequence
				FROM aggregate_graph_templates_item
				WHERE item_total="on"
				AND aggregate_template_id = ?
				ORDER BY sequence',
                array($aggregate_template_id)
            ),
            'sequence',
            'sequence'
        );

        $attribs['color_templates'] = array_rekey(
            aggregate_graph_fetch_rows(
                'SELECT sequence, color_template
				FROM aggregate_graph_templates_item
				WHERE color_template>=0
				AND aggregate_template_id = ?
				ORDER BY sequence',
                array($aggregate_template_id)
            ),
            'sequence',
            'color_template'
        );

        $attribs['graph_item_types'] = array_rekey(
            aggregate_graph_fetch_rows(
                'SELECT sequence, graph_type_id
				FROM aggregate_graph_templates_item
				WHERE t_graph_type_id="on"
				AND aggregate_template_id = ?
				ORDER BY sequence',
                array($aggregate_template_id)
            ),
            'sequence',
            'graph_type_id'
        );

        $attribs['cdefs'] = array_rekey(
            aggregate_graph_fetch_rows(
                'SELECT sequence, cdef_id
				FROM aggregate_graph_templates_item
				WHERE t_cdef_id="on"
				AND aggregate_template_id = ?
				ORDER BY sequence',
                array($aggregate_template_id)
            ),
            'sequence',
            'cdef_id'
        );

        $attribs['aggregate_template_id'] = $aggregate_template_id;

        $template_data = aggregate_graph_fetch_row(
            "SELECT *
			FROM aggregate_graph_templates
			WHERE id = ?",
            array($aggregate_template_id)
        );

        $attribs['template_propogation'] = 'on';
        if ($template_data === []) {
            return false;
        }
        $attribs['graph_template_id']    = $template_data['graph_template_id'];
        $attribs['gprint_prefix']        = $template_data['gprint_prefix'];
        $attribs['gprint_format']        = $template_data['gprint_format'];
        $attribs['graph_type']           = $template_data['graph_type'];
        $attribs['total']                = $template_data['total'];
        $attribs['total_type']           = $template_data['total_type'];
        $attribs['total_prefix']         = $template_data['total_prefix'];
        $attribs['reorder']              = $template_data['order_type'];

        $attribs['item_no'] = aggregate_graph_fetch_value(
            'SELECT COUNT(*)
			FROM aggregate_graph_templates_item
			WHERE aggregate_template_id = ?',
            array($aggregate_template_id)
        );
    }

    $aggregate_graphs = array();
    if ($local_graph_id > 0) {
        $aggregate_graphs[] = $local_graph_id;
    } else {
        $graphs = aggregate_graph_fetch_rows(
            'SELECT local_graph_id
			FROM aggregate_graphs
			WHERE aggregate_template_id = ?',
            array($aggregate_template_id)
        );

        if (cacti_sizeof($graphs)) {
            foreach ($graphs as $g) {
                $aggregate_graphs[] = $g['local_graph_id'];
            }
        }
    }

    if (cacti_sizeof($aggregate_graphs)) {
        foreach ($aggregate_graphs as $ag) {
            $member_graphs = array();

            $graphs = aggregate_graph_fetch_rows(
                'SELECT DISTINCT agi.local_graph_id
				FROM aggregate_graphs AS ag
				INNER JOIN aggregate_graphs_items AS agi
				ON ag.id=agi.aggregate_graph_id
				WHERE ag.local_graph_id = ?',
                array($ag)
            );

            if (cacti_sizeof($graphs)) {
                foreach ($graphs as $mg) {
                    $member_graphs[] = $mg['local_graph_id'];
                }

                if (!aggregate_create_update($ag, $member_graphs, $attribs)) {
                    return false;
                }
            } else {
                return false;
            }
        }
    } else {
        return false;
    }

    return true;
}

/**
 * aggregate_create_update - either create or update an aggregate based on criteria
 * @param int $local_graph_id  - the local graph id of the existing graph.  0 if one needs to be created
 * @param array $member_graphs - the graphs that will be included in this aggregate
 * @return array $attribs      - the attributes for this new graph
 *  */
function aggregate_create_update(&$local_graph_id, $member_graphs, $attribs): bool
{
    $previous = $local_graph_id;
    $saved = aggregate_graph_mutation(static function () use (&$local_graph_id, $member_graphs, $attribs): bool {
        return aggregate_create_update_operation($local_graph_id, $member_graphs, $attribs) === true;
    });
    if (!$saved) {
        $local_graph_id = $previous;
    }

    return $saved;
}

function aggregate_create_update_operation(&$local_graph_id, $member_graphs, $attribs): bool
{
    $aggregate_previous_reporting = error_reporting();
    $aggregate_handler_active = false;
    try {

        global $config;

        if (!is_array($member_graphs) || $member_graphs === []) {
            return false;
        }

        cacti_log(__FUNCTION__ . ' called. Graph id: ' . $local_graph_id, true, 'AGGREGATE', POLLER_VERBOSITY_DEVDBG);

        /* suppress warnings */
        error_reporting(E_ALL);

        /* install own error handler */
        set_error_handler('aggregate_error_handler');
        $aggregate_handler_active = true;

        if (cacti_sizeof($member_graphs)) {
            $graph_title          = (isset($attribs['graph_title']) ? $attribs['graph_title'] : '');
            $aggregate_template   = (isset($attribs['aggregate_template_id']) ? $attribs['aggregate_template_id'] : 0);
            $graph_template_id    = (isset($attribs['graph_template_id']) ? $attribs['graph_template_id'] : 0);
            $aggregate_graph      = (isset($attribs['aggregate_graph_id']) ? $attribs['aggregate_graph_id'] : 0);
            $template_propogation = (isset($attribs['template_propogation']) ? $attribs['template_propogation'] : 'on');
            $gprint_prefix        = (isset($attribs['gprint_prefix']) ? $attribs['gprint_prefix'] : '');
            $gprint_format        = (isset($attribs['gprint_format']) ? $attribs['gprint_format'] : '');
            $_graph_type          = (isset($attribs['graph_type']) ? $attribs['graph_type'] : 0);
            $_total               = (isset($attribs['total']) ? $attribs['total'] : 0);
            $_total_type          = (isset($attribs['total_type']) ? $attribs['total_type'] : 0);
            $_total_prefix        = (isset($attribs['total_prefix']) ? $attribs['total_prefix'] : '');
            $_reorder             = (isset($attribs['reorder']) ? $attribs['reorder'] : 0);
            $item_no              = (isset($attribs['item_no']) ? $attribs['item_no'] : 0);
            $color_templates      = (is_array($attribs['color_templates']) ? $attribs['color_templates'] : array());
            $graph_item_types     = (is_array($attribs['graph_item_types']) ? $attribs['graph_item_types'] : array());
            $cdefs                = (is_array($attribs['cdefs']) ? $attribs['cdefs'] : array());
            $skipped_items        = (is_array($attribs['skipped_items']) ? $attribs['skipped_items'] : array());
            $total_items          = (is_array($attribs['total_items']) ? $attribs['total_items'] : array());
            $example_graph_id     = 0;

            /* save the aggregate information */
            $save1 = array();
            if ($local_graph_id == 0) {
                # create new graph based on first graph selected
                $local_graph_id = aggregate_graph_save($example_graph_id, $graph_template_id, $graph_title, $aggregate_template);
                $save1['id']    = '';
                $new_aggregate  = true;
            } else {
                # update graph params of existing aggregate graph
                $local_graph_id = aggregate_graph_save($local_graph_id, $graph_template_id, $graph_title, $aggregate_template);

                $save1['id'] = aggregate_graph_fetch_value(
                    'SELECT id
				FROM aggregate_graphs
				WHERE local_graph_id = ?',
                    array($local_graph_id)
                );

                $new_aggregate  = false;
            }

            $save1['aggregate_template_id'] = $aggregate_template;
            $save1['template_propogation']  = $template_propogation;

            if (isset($graph_title)) {
                $save1['title_format'] = $graph_title;
            }

            $save1['local_graph_id']    = $local_graph_id;
            $save1['graph_template_id'] = $graph_template_id;
            $save1['gprint_prefix']     = $gprint_prefix;
            $save1['gprint_format']     = $gprint_format;
            $save1['graph_type']        = $_graph_type;
            $save1['total']             = $_total;
            $save1['total_type']        = $_total_type;
            $save1['total_prefix']      = $_total_prefix;
            $save1['order_type']        = $_reorder;
            $save1['user_id']           = $_SESSION['sess_user_id'];
            $aggregate_graph_id         = aggregate_graph_save_row($save1, 'aggregate_graphs');

            # sequence number of next graph item to be added, index starts at 1
            $next_item_sequence = 1;
            $j = 1;
            $i = 0;

            /* remove all old graph items first */
            if ($local_graph_id > 0) {
                aggregate_graph_execute(
                    'DELETE FROM graph_templates_item
				WHERE local_graph_id = ?',
                    array($local_graph_id)
                );
            }

            /* now add the graphs one by one to the newly created graph
             * program flow is governed by
             * - totalling
             * - new graph type: convert graph to e.g. AREA
             */
            # loop for all selected graphs
            foreach ($member_graphs as $graph_id) {
                # insert all graph items of selected graph
                # next items to be inserted have to be in sequence
                $next_item_sequence = aggregate_graphs_insert_graph_items(
                    $local_graph_id,
                    $graph_id,
                    $graph_template_id,
                    $skipped_items,
                    $total_items,
                    $next_item_sequence,
                    $i,
                    $color_templates,
                    $graph_item_types,
                    $cdefs,
                    $_graph_type,
                    $gprint_prefix,
                    $gprint_format,
                    $_total,
                    '',
                    $member_graphs
                );

                aggregate_graph_execute(
                    'REPLACE INTO aggregate_graphs_items
				(aggregate_graph_id, local_graph_id, sequence)
				VALUES (?, ?, ?)',
                    array($aggregate_graph_id, $graph_id, $j)
                );

                $j++;
                $i++;
            }

            cacti_log(__FUNCTION__ . '  all items inserted, next item seq: ' . $next_item_sequence . ' selGraph: ' . $i, true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);

            /* post processing for pure LINEx graphs
             * if we convert to AREA/STACK, the function aggregate_graphs_insert_graph_items
             * created a pure STACK graph (see comments in that function)
             * so let's find out, if we have a pure STACK now ...
             */
            if (aggregate_is_pure_stacked_graph($local_graph_id)) {
                /* ... and convert to AREA */
                aggregate_conditional_convert_graph_type($local_graph_id, GRAPH_ITEM_TYPE_STACK, GRAPH_ITEM_TYPE_AREA);
            }

            if (aggregate_is_stacked_graph($local_graph_id)) {
                /* reorder graph items, if requested
                 * for STACKed graphs, reorder before adding totals */
                aggregate_reorder_ds_graph(
                    $example_graph_id,
                    $graph_template_id,
                    $local_graph_id,
                    $_reorder,
                    $_graph_type
                );
            }

            $_orig_graph_type = $_graph_type;

            // special code to add totalling graph items
            switch ($_total) {
                case AGGREGATE_TOTAL_NONE: # no totalling
                    // do NOT add any totalling items

                    break;
                case AGGREGATE_TOTAL_ALL: # any totalling option was selected ...
                    $_graph_type = GRAPH_ITEM_TYPE_LINE1;

                    $cf_id = aggregate_graph_fetch_value('SELECT consolidation_function_id
					FROM graph_templates_item
					WHERE color_id > 0' .
                        (cacti_sizeof($member_graphs) ? ' AND ' . array_to_sql_or($member_graphs, 'local_graph_id') : '') .
                        (cacti_sizeof($skipped_items) ? ' AND sequence NOT IN(' . implode(',', $skipped_items) . ')' : '') . '
					ORDER BY sequence ASC
					LIMIT 1');

                    // add an empty line before total items
                    aggregate_graph_execute("INSERT INTO graph_templates_item
					(local_graph_id, graph_type_id, consolidation_function_id, text_format, value, hard_return, gprint_id, sequence)
					VALUES (?, 1, ?, '', '', 'on', 2, ?)", array($local_graph_id, $cf_id, $next_item_sequence++));

                case AGGREGATE_TOTAL_ONLY:
                    // use the prefix for totalling GPRINTs as given by the user
                    switch ($_total_type) {
                        case AGGREGATE_TOTAL_TYPE_SIMILAR:
                        case AGGREGATE_TOTAL_TYPE_ALL:
                            $gprint_prefix = $_total_prefix;
                            break;
                    }

                    // now skip all items, that are
                    // - explicitly marked as skipped (based on $skipped_items)
                    // - OR NOT marked as 'totalling' items
                    for ($k = 1; $k <= $item_no; $k++) {
                        cacti_log(__FUNCTION__ . ' old skip: ' . (isset($skipped_items[$k]) ? $skipped_items[$k] : ''), true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);

                        // skip all items, that shall not be totalled
                        if (!isset($total_items[$k])) {
                            $skipped_items[$k] = $k;
                        }

                        cacti_log(__FUNCTION__ . ' new skip: ' . (isset($skipped_items[$k]) ? $skipped_items[$k] : ''), true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);
                    }

                    // add the 'templating' graph to the new graph, honoring skipped, hr and color
                    aggregate_graphs_insert_graph_items(
                        $local_graph_id,
                        $example_graph_id,
                        $graph_template_id,
                        $skipped_items,
                        $total_items,
                        $next_item_sequence,
                        $i,
                        $color_templates,
                        $graph_item_types,
                        $cdefs,
                        $_graph_type, #TODO: user may choose LINEx instead of assuming LINE1
                        $gprint_prefix,
                        $gprint_format,
                        AGGREGATE_TOTAL_ALL, # now add the totalling line(s)
                        $_total_type,
                        $member_graphs
                    );

                    // now pay attention to CDEFs
                    // next_item_sequence still points to the first totalling graph item
                    aggregate_cdef_totalling(
                        $local_graph_id,
                        $next_item_sequence,
                        $_total_type
                    );
            }

            /* post processing for pure LINEx graphs
             * if we convert to AREA/STACK, the function aggregate_graphs_insert_graph_items
             * created a pure STACK graph (see comments in that function)
             * so let's find out, if we have a pure STACK now ...
             */
            if (aggregate_is_pure_stacked_graph($local_graph_id)) {
                /* ... and convert to AREA */
                aggregate_conditional_convert_graph_type($local_graph_id, GRAPH_ITEM_TYPE_STACK, GRAPH_ITEM_TYPE_AREA);
            }

            if (!aggregate_is_stacked_graph($local_graph_id)) {
                /* reorder graph items, if requested
                 * for non-STACKed graphs, we want to reorder the totals as well */
                aggregate_reorder_ds_graph(
                    $example_graph_id,
                    $graph_template_id,
                    $local_graph_id,
                    $_reorder,
                    $_graph_type
                );
            }

            // Handle stacked lines properly
            aggregate_handle_stacked_lines($local_graph_id, $_orig_graph_type, $_total, $_total_type, $_total_prefix);

            // Handle aggregate nth percentiles
            if ($_total != AGGREGATE_TOTAL_NONE) {
                aggregate_handle_ptile_type($member_graphs, $skipped_items, $local_graph_id, $_total, $_total_type);
            }
        }


        return true;
    } finally {
        if ($aggregate_handler_active) {
            restore_error_handler();
        }
        error_reporting($aggregate_previous_reporting);
    }

}

function aggregate_handle_ptile_type($member_graphs, $skipped_items, $local_graph_id, $_total, $_total_type)
{
    $special_comments  = null;
    $special_hrules    = null;
    $graph_template_id = 0;

    $agg_info = aggregate_graph_fetch_row(
        'SELECT *
		FROM aggregate_graphs
		WHERE local_graph_id = ?',
        array($local_graph_id)
    );

    if (cacti_sizeof($agg_info)) {
        $comments_hrules = aggregate_graph_fetch_rows(
            'SELECT *
			FROM graph_templates_item
			WHERE graph_type_id IN (?, ?)
			AND graph_template_id = ?
			AND local_graph_id = 0' .
            (cacti_sizeof($skipped_items) ? ' AND sequence NOT IN (' . implode(',', $skipped_items) . ')' : '') . '
			AND (text_format != "" || value != "")
			ORDER BY sequence ASC',
            array(GRAPH_ITEM_TYPE_COMMENT, GRAPH_ITEM_TYPE_HRULE, $agg_info['graph_template_id'])
        );

        $graph_template_id = $agg_info['graph_template_id'];
    } else {
        if (cacti_sizeof($member_graphs)) {
            $template_graph[] = $member_graphs[0];
        } else {
            $template_graph   = array();
        }

        $comments_hrules = aggregate_graph_fetch_rows('SELECT *
			FROM graph_templates_item
			WHERE graph_type_id IN(' . GRAPH_ITEM_TYPE_COMMENT . ',' . GRAPH_ITEM_TYPE_HRULE . ')' .
            (cacti_sizeof($template_graph) ? ' AND ' . array_to_sql_or($template_graph, 'local_graph_id') : '') .
            (cacti_sizeof($skipped_items) ? ' AND sequence NOT IN(' . implode(',', $skipped_items) . ')' : '') . '
			AND (text_format != "" || value != "")
			ORDER BY local_graph_id, sequence ASC');

        if (cacti_sizeof($comments_hrules)) {
            $graph_template_id = $comments_hrules[0]['graph_template_id'];
        }
    }

    $next_item_sequence = aggregate_graph_fetch_value(
        'SELECT MAX(sequence)
		FROM graph_templates_item
		WHERE local_graph_id = ?',
        array($local_graph_id)
    );

    if (cacti_sizeof($comments_hrules)) {
        foreach ($comments_hrules as $item) {
            switch ($item['graph_type_id']) {
                case GRAPH_ITEM_TYPE_COMMENT:
                    if (!isset($special_comments[$item['text_format'] . '|' . $item['value'] . '|' . $item['task_item_id']])) {
                        if (preg_match('/(:bits:|:bytes:)/', $item['text_format'])) {
                            $special_comments[$item['text_format'] . '|' . $item['value'] . '|' . $item['task_item_id']] = true;

                            $parts = explode('|', $item['text_format']);

                            if (isset($parts[1])) {
                                $pparts = explode(':', $parts[1]);

                                if (isset($pparts[3])) {
                                    if ($_total_type == AGGREGATE_TOTAL_TYPE_ALL) {
                                        $pparts[3] = str_replace('current', 'aggregate_sum', $pparts[3]);
                                        $pparts[3] = str_replace('max', 'aggregate_sum_peak', $pparts[3]);
                                    } elseif ($_total_type == AGGREGATE_TOTAL_TYPE_SIMILAR) {
                                        $pparts[3] = str_replace('current', 'aggregate_current', $pparts[3]);
                                        $pparts[3] = str_replace('max', 'aggregate_current_peak', $pparts[3]);
                                    } else {
                                        cacti_log(__FUNCTION__ . ' unhandled total_type ' . $_total_type . ' for pparts[3] in text_format', true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);
                                    }

                                    switch ($pparts[3]) {
                                        case 'current':
                                            $new_ppart = 'current';
                                            break;
                                        case 'total':
                                            $new_ppart = 'aggregate_sum';
                                            break;
                                        case 'max':
                                            $new_ppart = 'max';
                                            break;
                                        case 'total_peak':
                                            $new_ppart = 'total_peak';
                                            break;
                                        case 'all_max_current':
                                        case 'all_max_peak':
                                        case 'aggregate_max':
                                            $new_ppart = 'aggregate_peak';
                                            break;
                                        case 'aggregate_sum':
                                        case 'aggregate_sum_peak':
                                        case 'aggregate_current':
                                        case 'aggregate_current_peak':
                                        case 'aggregate':
                                        case 'aggregate_peak':
                                            $new_ppart = $pparts[3];
                                            break;
                                        default:
                                            $new_ppart = 'max';
                                            break;
                                    }

                                    $pparts[3] = $new_ppart;

                                    $parts[1] = implode(':', $pparts);
                                    $item['text_format'] = implode('|', $parts);
                                }
                            }

                            aggregate_graph_execute(
                                "INSERT INTO graph_templates_item
								(graph_template_id, local_graph_id, task_item_id, graph_type_id, consolidation_function_id, text_format, value, hard_return, gprint_id, sequence)
								VALUES (?, ?, ?, ?, 1, ?, '', ?, 2, ?)",
                                array(
                                    $graph_template_id,
                                    $local_graph_id,
                                    $item['task_item_id'],
                                    GRAPH_ITEM_TYPE_COMMENT,
                                    $item['text_format'],
                                    $item['hard_return'],
                                    $next_item_sequence++
                                )
                            );
                        }
                    }

                    break;
                case GRAPH_ITEM_TYPE_HRULE:
                    if (!isset($special_hrules[$item['text_format'] . '|' . $item['value'] . '|' . $item['task_item_id']])) {
                        if (preg_match('/(:bits:|:bytes:)/', $item['value'])) {
                            $special_hrules[$item['text_format'] . '|' . $item['value'] . '|' . $item['task_item_id']] = true;

                            $parts = explode('|', $item['value']);
                            if (isset($parts[1])) {
                                $pparts = explode(':', $parts[1]);

                                if (isset($pparts[3])) {
                                    if ($_total_type == AGGREGATE_TOTAL_TYPE_ALL) {
                                        $pparts[3] = str_replace('current', 'aggregate_sum', $pparts[3]);
                                        $pparts[3] = str_replace('max', 'aggregate_sum_peak', $pparts[3]);
                                    } elseif ($_total_type == AGGREGATE_TOTAL_TYPE_SIMILAR) {
                                        $pparts[3] = str_replace('current', 'aggregate_current', $pparts[3]);
                                        $pparts[3] = str_replace('max', 'aggregate_current_peak', $pparts[3]);
                                    } else {
                                        cacti_log(__FUNCTION__ . ' unhandled total_type ' . $_total_type . ' for pparts[3] in value', true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);
                                    }

                                    switch ($pparts[3]) {
                                        case 'current':
                                            $new_ppart = 'current';
                                            break;
                                        case 'total':
                                            $new_ppart = 'aggregate_sum';
                                            break;
                                        case 'max':
                                            $new_ppart = 'max';
                                            break;
                                        case 'total_peak':
                                            $new_ppart = 'total_peak';
                                            break;
                                        case 'all_max_current':
                                        case 'all_max_peak':
                                        case 'aggregate_max':
                                            $new_ppart = 'aggregate_peak';
                                            break;
                                        case 'aggregate_peak':
                                        case 'aggregate_sum':
                                        case 'aggregate_sum_peak':
                                        case 'aggregate_current':
                                        case 'aggregate_current_peak':
                                        case 'aggregate':
                                            $new_ppart = $pparts[3];
                                            break;
                                        default:
                                            $new_ppart = 'max';
                                            break;
                                    }

                                    $pparts[3] = $new_ppart;

                                    $parts[1] = implode(':', $pparts);
                                    $item['value'] = implode('|', $parts);
                                }
                            }

                            // add an empty line before nth percentile for the first item only
                            if (cacti_sizeof($special_hrules) == 1) {
                                aggregate_graph_execute("INSERT INTO graph_templates_item
									(graph_template_id, local_graph_id, graph_type_id, consolidation_function_id, text_format, value, hard_return, gprint_id, sequence)
									VALUES (?, ?, 1, 1, '', '', 'on', 2, ?)", array($graph_template_id, $local_graph_id, $next_item_sequence++));
                            }

                            aggregate_graph_execute(
                                "INSERT INTO graph_templates_item
								(graph_template_id, local_graph_id, task_item_id, graph_type_id, color_id, consolidation_function_id, text_format, value, hard_return, gprint_id, sequence)
								VALUES (?, ?, ?, ?, ?, 1, ?, ?, '', 2, ?)",
                                array(
                                    $graph_template_id,
                                    $local_graph_id,
                                    $item['task_item_id'],
                                    GRAPH_ITEM_TYPE_HRULE,
                                    $item['color_id'],
                                    $item['text_format'],
                                    $item['value'],
                                    $next_item_sequence++
                                )
                            );
                        }
                    }

                    break;
            }
        }
    }
}

function aggregate_handle_stacked_lines($local_graph_id, $_orig_graph_type, $_total, $_total_type, $_total_prefix)
{
    // Handle the stacked line cases switch line widths
    $width = '0.01';
    $special_type = '';
    $special_line = false;

    switch ($_orig_graph_type) {
        case AGGREGATE_GRAPH_TYPE_LINE1_STACK:
            $width = '1.00';
            $special_type = GRAPH_ITEM_TYPE_LINE1;
            $special_line = true;
            break;
        case AGGREGATE_GRAPH_TYPE_LINE2_STACK:
            $width = '2.00';
            $special_type = GRAPH_ITEM_TYPE_LINE2;
            $special_line = true;
            break;
        case AGGREGATE_GRAPH_TYPE_LINE3_STACK:
            $width = '3.00';
            $special_type = GRAPH_ITEM_TYPE_LINE3;
            $special_line = true;
            break;
    }

    if ($special_line) {
        if ($_total == AGGREGATE_TOTAL_NONE) {
            aggregate_graph_execute(
                'UPDATE graph_templates_item
				SET line_width = ?
				WHERE local_graph_id = ?
				AND graph_type_id IN (?)',
                array($width, $local_graph_id, GRAPH_ITEM_TYPE_LINESTACK)
            );
        }

        // Handle special case total prefix
        aggregate_graph_execute(
            'UPDATE graph_templates_item
			SET graph_type_id = ?
			WHERE local_graph_id = ?
			AND text_format = ?',
            array($special_type, $local_graph_id, $_total_prefix)
        );

        if ($_total == AGGREGATE_TOTAL_ALL) {
            aggregate_graph_execute(
                'UPDATE graph_templates_item
				SET graph_type_id = ?, line_width = "0.01"
				WHERE local_graph_id = ?
				AND graph_type_id = ?
				AND text_format != ?',
                array(
                    GRAPH_ITEM_TYPE_LINESTACK,
                    $local_graph_id,
                    $special_type,
                    $_total_prefix
                )
            );
        }
    }

    /* handle any missing lines */
    aggregate_graph_execute(
        'UPDATE graph_templates_item
		SET line_width="0.01"
		WHERE graph_type_id = ?
		AND line_width="0.00"
		AND local_graph_id = ?',
        array(GRAPH_ITEM_TYPE_LINESTACK, $local_graph_id)
    );
}

function aggregate_get_data_sources(&$graph_array, &$data_sources, &$graph_template)
{
    /* find out which (if any) data sources are being used by this graph, so we can tell the user */
    if (isset($graph_array)) {
        # fetch all data sources for all selected graphs
        $data_sources = db_fetch_assoc('SELECT
			data_template_data.local_data_id,
			data_template_data.name_cache
			FROM (data_template_rrd,data_template_data,graph_templates_item)
			WHERE graph_templates_item.task_item_id=data_template_rrd.id
			AND data_template_rrd.local_data_id=data_template_data.local_data_id
			AND ' . array_to_sql_or($graph_array, 'graph_templates_item.local_graph_id') . '
			AND data_template_data.local_data_id>0
			GROUP BY data_template_data.local_data_id
			ORDER BY data_template_data.name_cache');

        # verify, that only a single graph template is used, else
        # aggregate will look funny
        $sql = 'SELECT DISTINCT graph_templates.id, graph_templates.name
			FROM graph_local
			LEFT JOIN graph_templates ON (graph_local.graph_template_id=graph_templates.id)
			WHERE (' . array_to_sql_or($graph_array, 'graph_local.id') . ')
			AND graph_local.graph_template_id>0';
        $used_graph_templates = db_fetch_assoc($sql);

        if (cacti_sizeof($used_graph_templates) > 1) {
            # this is invalid! STOP
            print "<tr><td colspan='2' class='textArea'>
			<p>" . __('The Graphs chosen for the Aggregate Graph below represent Graphs from multiple Graph Templates.  Aggregate does not support creating Aggregate Graphs from multiple Graph Templates.') . "</p>";
            print "<p>" . __('Press \'Return\' to return and select different Graphs') . "</p>";
            print "<ul>";
            foreach ($used_graph_templates as $graph_template) {
                print '<li>' . html_escape($graph_template['name']) . "</li>";
            }
            print '</ul></td></tr>';

            ?>
			<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>
			$(function() {
				$('#continue').hide();
				$('#cancel').attr('value', '<?php print __esc('Return');?>');
			});
			</script>
			<?php
        } elseif (cacti_sizeof($used_graph_templates) < 1) {
            /* selected graphs do not use templates */
            print "<tr><td colspan='2' class='textArea'>
			<p>" . __('The Graphs chosen for the Aggregate Graph do not use Graph Templates.  Aggregate does not support creating Aggregate Graphs from non-templated graphs.') . "</p>";
            print "<p>" . __('Press \'Return\' to return and select different Graphs') . "</p>";
            print '</td></tr>';

            ?>
			<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>
			$(function() {
				$('#continue').hide();
				$('#cancel').attr('value', '<?php print __esc('Return');?>');
			});
			</script>
			<?php
        } else {
            $graph_template = $used_graph_templates[0]['id'];

            return true;
        }
    }

    return false;
}

/**
 * draw_aggregate_template_graph_items_list - draw graph item list
 *
 * @param int $_graph_template_id - id of the graph for which the items shall be listed
 # @param int $_object            - either the aggregate or aggregate_template
 */
function draw_aggregate_graph_items_list($_graph_id = 0, $_graph_template_id = 0, $_object = array())
{
    global $config, $graph_item_types, $consolidation_functions;

    /**
     * @var array $consolidation_functions
     * @var array $graph_item_types
     */
    include($config['include_path'] . '/global_arrays.php');

    cacti_log(__FUNCTION__ . '  called. graph: ' . $_graph_id . ' template: ' . $_graph_template_id, true, 'AGGREGATE', POLLER_VERBOSITY_DEVDBG);

    if ($_graph_id == 0 && $_graph_template_id == 0) {
        return null;
    }

    $item_list_where = '';

    /* fetch graph items */
    if ($_graph_id == 0) {
        $item_list_where = "gti.local_graph_id=0 AND graph_template_id=$_graph_template_id ";
    }

    if ($_graph_template_id == 0) {
        $item_list_where = "gti.local_graph_id=$_graph_id ";
    }

    $item_list = db_fetch_assoc("SELECT
		gti.id, gti.text_format, gti.value, gti.hard_return, gti.graph_type_id,
		gti.consolidation_function_id, cdef.name as cdef_name, colors.hex
		FROM graph_templates_item AS gti
		LEFT JOIN cdef ON (gti.cdef_id=cdef.id)
		LEFT JOIN colors ON (gti.color_id=colors.id)
		WHERE $item_list_where
		ORDER BY gti.sequence");

    /* fetch color templates */
    $color_templates = db_fetch_assoc('SELECT color_template_id, name FROM color_templates ORDER BY name');

    $current_vals = array();
    $is_edit = false;
    $is_templated = false;
    if (cacti_sizeof($_object) > 0 && $_object['id'] > 0) {
        /* drawing items for existing aggregate graph/template */
        $is_edit = true;
        /* fetch existing item values */
        if (isset($_object['aggregate_template_id']) && $_object['aggregate_template_id'] == 0) {
            /* this is aggregate graph with no aggregate template */
            $current_vals = db_fetch_assoc_prepared(
                'SELECT *
				FROM aggregate_graphs_graph_item
				WHERE aggregate_graph_id = ?',
                array($_object['id'])
            );

            $item_editor_link_param = 'aggregate_graph_id=' . $_object['id'] . '&local_graph_id=' . $_object['local_graph_id'];
            $is_templated = false;
        } elseif (isset($_object['aggregate_template_id'])) {
            /* this is aggregate graph from aggregate template */
            $current_vals = db_fetch_assoc_prepared(
                'SELECT *
				FROM aggregate_graph_templates_item
				WHERE aggregate_template_id = ?',
                array($_object['aggregate_template_id'])
            );

            $item_editor_link_param = 'aggregate_template_id=' . $_object['aggregate_template_id'];
            $is_templated = true;
        } else {
            /* this is aggregate template */
            $current_vals = db_fetch_assoc_prepared(
                'SELECT *
				FROM aggregate_graph_templates_item
				WHERE aggregate_template_id = ?',
                array($_object['id'])
            );

            $item_editor_link_param = 'aggregate_template_id=' . $_object['id'];
            $is_templated = true;
        }
        /* key results on item id */
        $current_vals = array_rekey(
            $current_vals,
            'graph_templates_item_id',
            array('color_template', 'item_skip', 'item_total', 't_graph_type_id', 'graph_type_id')
        );
    }

    # draw list of graph items
    html_start_box(($is_templated ? __('Graph Template Items') : __('Graph Items')), '100%', '', '3', 'center', '');

    # print column header
    print "<tr class='tableHeader'>";
    DrawMatrixHeaderItem(__('Graph Item'), '', 1);
    DrawMatrixHeaderItem(__('Data Source'), '', 1);
    DrawMatrixHeaderItem(__('Graph Item Type'), '', 1);
    DrawMatrixHeaderItem(__('CF Type'), '', 1);
    DrawMatrixHeaderItem(__('Item Color'), '', 2);
    DrawMatrixHeaderItem(__('Color Template'), '', 1);
    DrawMatrixHeaderItem(__('Skip'), '', 1);
    DrawMatrixHeaderItem(__('Total'), '', 1);
    print '</tr>';

    $group_counter = 0;
    $_graph_type_name = '';
    $i = 0;

    if (cacti_sizeof($item_list)) {
        foreach ($item_list as $item) {
            /* graph grouping display logic */
            $this_row_style   = '';
            $use_custom_class = false;
            $hard_return      = '';
            $matrix_title     = '';
            $customClass      = '';

            if (!preg_match('/(GPRINT|TEXTALIGN|HRULE|VRULE|TICK)/', $graph_item_types[$item['graph_type_id']])) {
                $this_row_style = 'font-weight: bold;';
                $use_custom_class = true;

                if ($group_counter % 2 == 0) {
                    $customClass  = 'graphItem';
                } else {
                    $customClass  = 'graphItemAlternate';
                }

                $group_counter++;
            }

            /* column "Data Source" */
            $_graph_type_name = $graph_item_types[$item['graph_type_id']];
            $force_skip = false;

            switch (true) {
                case preg_match('/(TEXTALIGN)/', $_graph_type_name):
                    $matrix_title = 'TEXTALIGN: ' . ucfirst($item['textalign']);
                    break;
                case preg_match('/(TICK)/', $_graph_type_name):
                    $matrix_title = '(' . $item['data_source_name'] . '): ' . $item['text_format'];
                    break;
                case preg_match('/(AREA|STACK|GPRINT|LINE[123])/', $_graph_type_name):
                    $matrix_title = $item['text_format'];
                    break;
                case preg_match('/(HRULE)/', $_graph_type_name):
                    $matrix_title = 'HRULE: ' . $item['value'];
                    if (preg_match('/(:bits:|:bytes:|\|sum:)/', $item['value'])) {
                        $force_skip = false;
                    } else {
                        $force_skip = true;
                    }
                    break;
                case preg_match('/(VRULE)/', $_graph_type_name):
                    $force_skip = true;
                    $matrix_title = 'VRULE: ' . $item['value'];
                    break;
                case preg_match('/(COMMENT)/', $_graph_type_name):
                    $matrix_title = 'COMMENT: ' . $item['text_format'];
                    if (preg_match('/(:bits:|:bytes:|\|sum:)/', $item['text_format'])) {
                        $force_skip = false;
                    } elseif ($item['text_format'] != '') {
                        $force_skip = false;
                    } else {
                        $force_skip = true;
                    }
                    break;
            }

            /* values can be overridden in aggregate graph/template */
            if ($is_edit && isset($current_vals[$item['id']]['t_graph_type_id']) && $current_vals[$item['id']]['t_graph_type_id'] == 'on') {
                $item['graph_type_id'] = $current_vals[$item['id']]['graph_type_id'];
            }

            /* alternating row color */
            if ($use_custom_class == false) {
                form_alternate_row();
            } else {
                print "<tr class='tableRowGraph $customClass'>";
            }

            /* column 'Graph Item' */
            print '<td title="' . __esc('Aggregate Items are not modifiable') . '">';
            if ($is_edit == false) {
                /* no existing aggregate graph/template */
                print __('Item # %d', ($i + 1));
            } elseif (isset($_object['template_propogation']) && $_object['template_propogation']) {
                /* existing aggregate graph with template propagation enabled */
                print __('Item # %d', ($i + 1));
            } else {
                /* existing aggregate template or graph with no templating */
                /* create a link to graph item editor */
                print '<span title="' . __esc('Aggregate Items are not editable') . '">' . __('Item # %d', ($i + 1)) . '</span>';
            }
            print "</td>";

            if ($item['hard_return'] == 'on') {
                $hard_return = '<strong><span style="color:#FF0000;">&lt;HR&gt;</span></strong>';
            }

            print "<td style='$this_row_style'>" . html_escape($matrix_title) . $hard_return . "</td>";

            /* column 'Graph Item Type' */
            print "<td style='$this_row_style'>" . $graph_item_types[$item['graph_type_id']] . "</td>";

            /* column 'CF Type' */
            print "<td style='$this_row_style'>" . $consolidation_functions[$item['consolidation_function_id']] . "</td>";

            /* column 'Item Color' */
            print "<td style='width:1%;" . ((!empty($item['hex'])) ? 'background-color:#' . $item['hex'] . ";'" : "'") . ">&nbsp;</td>";
            print "<td style='$this_row_style'>" . $item['hex'] . "</td>";

            /* column 'Color Template' */
            print '<td>';
            if (!empty($item['hex'])) {
                print "<select id='agg_color_" . $item['id'] . "' name='agg_color_" . $item['id'] . "'>";
                print "<option value='0' selected>None</option>";
                html_create_list($color_templates, "name", "color_template_id", ($is_edit && isset($current_vals[$item['id']]['color_template']) ? $current_vals[$item['id']]['color_template'] : ''));
                print "</select>";
            }
            print '</td>';

            /* column "Skip" */
            if (!$force_skip) {
                print "<td style='width:1%;text-align:center;'>";
                print "<input class='checkbox' id='agg_skip_" . $item['id'] . "' type='checkbox' name='agg_skip_" . $item['id'] . "' title='" . html_escape($item['text_format']) . "' " . ($is_edit && (!isset($current_vals[$item['id']]['item_total']) || (isset($current_vals[$item['id']]['item_skip']) && $current_vals[$item['id']]['item_skip'] == 'on')) ? 'checked' : '') . "><label class='formCheckboxLabel' for='agg_skip_" . $item['id'] . "'>";
                print '</td>';

                /* column 'Total' */
                print "<td style='width:1%;text-align:center;'>";
                print "<input class='checkbox' id='agg_total_" . ($item['id']) . "' type='checkbox' name='agg_total_" . ($item['id']) . "' title='" . html_escape($item['text_format']) . "' " . ($is_edit && isset($current_vals[$item['id']]['item_total']) && $current_vals[$item['id']]['item_total'] == 'on' ? 'checked' : '') . "><label class='formCheckboxLabel' for='agg_total_" . $item['id'] . "'>";
                print '</td>';
            } else {
                print "<td style='width:1%;text-align:center;'><input class='checkbox' id='dummy_" . $item['id'] . "' disabled='disabled' type='checkbox' name='dummy_" . $item['id'] . "'" . ($is_edit ? 'checked' : '') . '></td>';
                print "<td style='width:1%;text-align:center;'><input class='checkbox' id='dummy1_" . $item['id'] . "' disabled='disabled' type='checkbox' name='dummy1_" . $item['id'] . "'>";
                print "<input style='display:none;' class='checkbox' id='agg_skip_" . $item['id'] . "' type='checkbox' name='agg_skip_" . $item['id'] . "' title='" . html_escape($item['text_format']) . "' " . ($is_edit ? 'checked' : '') . "><label class='formCheckboxLabel' for='agg_skip_" . $item['id'] . "'>";
                print "<input style='display:none;' class='checkbox' id='agg_total_" . ($item['id']) . "' type='checkbox' name='agg_total_" . ($item['id']) . "' title='" . html_escape($item['text_format']) . "'><label class='formCheckboxLabel' for='agg_total_" . ($item['id']) . "'></label></td>";
            }

            print '</tr>';

            $i++;
        }
    } else {
        print "<tr><td colspan='7'><em>" . __('No Items') . "</em></td></tr>";
    }

    html_end_box();

    form_hidden_box('item_no', cacti_sizeof($item_list), cacti_sizeof($item_list));
}

/**
 * draw graph configuration form so user can override some graph template parameters
 *
 * @param int $aggregate_template_id - aggregate graph template being edited
 * @param int $graph_template_id     - graph template this aggregate template is based on
 */
function draw_aggregate_template_graph_config($aggregate_template_id, $graph_template_id)
{
    global $struct_graph;

    html_start_box(__('Graph Configuration'), '100%', true, '3', 'center', '');

    $aggregate_templates_graph = db_fetch_row_prepared(
        'SELECT *
		FROM aggregate_graph_templates_graph
		WHERE aggregate_template_id = ?',
        array($aggregate_template_id)
    );

    $graph_templates_graph = db_fetch_row_prepared(
        'SELECT *
		FROM graph_templates_graph
		WHERE graph_template_id = ?',
        array($graph_template_id)
    );

    $form_array = array();

    foreach ($struct_graph as $field_name => $field_array) {
        if ($field_name == 'title')
            continue;

        if ($field_array['method'] != 'spacer') {
            $form_array += array($field_name => $struct_graph[$field_name]);

            /* value from graph template or aggregate graph template
            (based on value of t_$field_name of aggregate_template_graph) */
            if (cacti_sizeof($aggregate_templates_graph) && $aggregate_templates_graph['t_' . $field_name] == 'on') {
                $value = $aggregate_templates_graph[$field_name];
            } else {
                $value = $graph_templates_graph[$field_name];
            }

            $form_array[$field_name]['value'] = $value;
            $form_array[$field_name]['sub_checkbox'] = array(
                'name' => 't_' . $field_name,
                'friendly_name' => __('Override this Value') . '<br>',
                'value' => (cacti_sizeof($aggregate_templates_graph) ? $aggregate_templates_graph['t_' . $field_name] : ''),
                'on_change' => 'toggleFieldEnabled(this.id);'
            );
        } else {
            $form_array += array($field_name => $struct_graph[$field_name]);
        }
    }

    draw_edit_form(
        array(
            'config' => array('no_form_tag' => true),
            'fields' => $form_array
        )
    );

    html_end_box(false, true);

    /* some javascript do dynamically disable non-overridden fields */
    ?>
	<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>

	$(function() {
		setFieldsDisabled();
	});

	// disable all items with sub-checkboxes except
	// where sub-checkbox checked
	function setFieldsDisabled() {
		$('input[id^="t_"]').each(function() {
			if (!$(this).is(':checked')) {
				var fieldId = $(this).attr('id').substr(2);

				$('#'+fieldId).prop('disabled', true);
				$('#'+fieldId).addClass('ui-state-disabled');

				if ($('#'+fieldId).selectmenu('instance')) {
					$('#'+fieldId).selectmenu('disable');
				}
			}
		});
	}

	// enable or disable form field based on state of corresponding checkbox
	function toggleFieldEnabled(toggleFieldId) {
		fieldId  = toggleFieldId.substr(2);

		if ($('#'+fieldId).hasClass('ui-state-disabled')) {
			$('#'+fieldId).prop('disabled', false).removeClass('ui-state-disabled');

			if ($('#'+fieldId).selectmenu('instance')) {
				$('#'+fieldId).selectmenu('enable');
			}
		} else {
			$('#'+fieldId).prop('disabled', true).addClass('ui-state-disabled');

			if ($('#'+fieldId).selectmenu('instance')) {
				$('#'+fieldId).selectmenu('disable');
			}
		}
	}

	</script>
	<?php
}
