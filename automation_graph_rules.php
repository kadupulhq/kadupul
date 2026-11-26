<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

include('./include/auth.php');

cacti_require_post_actions(array('actions', 'item_moveup', 'item_movedown', 'item_remove', 'remove'));
include_once('./lib/data_query.php');

/* set default action */
set_default_action();

switch (get_request_var('action')) {
    case 'save':
        save();

        break;
    case 'actions':
        // The list and its bulk actions moved to Symfony; replaying an old selection must not write.
        graph_rule_settings_expired();

        break;
    case 'item_movedown':
        automation_graph_rules_item_movedown();

        header('Location: automation_graph_rules.php?action=edit&id=' . get_filter_request_var('id'));
        break;
    case 'item_moveup':
        automation_graph_rules_item_moveup();

        header('Location: automation_graph_rules.php?action=edit&id=' . get_filter_request_var('id'));
        break;
    case 'item_remove':
        automation_graph_rules_item_remove();

        header('Location: automation_graph_rules.php?action=edit&id=' . get_filter_request_var('id'));
        break;
    case 'item_edit':
        top_header();
        automation_graph_rules_item_edit();
        bottom_footer();
        break;
    case 'qedit':
        // Rule settings moved to Symfony; the old Data Query and Graph Type change links must not write.
        graph_rule_settings_expired();

        break;
    case 'remove':
        automation_graph_rules_remove();

        header('Location: automation_graph_rules.php');
        break;
    case 'edit':
        if (isempty_request_var('id')) {
            // Symfony creates rules. Relative targets keep subdirectory installations working.
            header('Location: app.php/automation/graph-rules/new', true, 302);
            exit;
        }
        // Redirect before top_header() prints anything, or the header is lost.
        if (!db_fetch_cell_prepared('SELECT id FROM automation_graph_rules WHERE id = ?', array(get_filter_request_var('id')))) {
            raise_message('rule_not_found', __('Rule not found.'), MESSAGE_LEVEL_ERROR);
            header('Location: automation_graph_rules.php', true, 302);
            exit;
        }

        top_header();
        automation_graph_rules_edit();
        bottom_footer();
        break;
    default:
        graph_rules_list_moved();

        break;
}

/* --------------------------
 The Save Function
 -------------------------- */

function save()
{
    if (isset_request_var('save_component_automation_graph_rule')) {
        graph_rule_settings_expired();
    } elseif (isset_request_var('save_component_automation_graph_rule_item')) {
        /* ================= input validation ================= */
        get_filter_request_var('id');
        get_filter_request_var('item_id');
        /* ==================================================== */

        $save = array();
        $save['id']        = form_input_validate(get_request_var('item_id'), 'item_id', '^[0-9]+$', false, 3);
        $save['rule_id']   = form_input_validate(get_request_var('id'), 'id', '^[0-9]+$', false, 3);
        $save['sequence']  = form_input_validate(get_nfilter_request_var('sequence'), 'sequence', '^[0-9]+$', false, 3);
        $save['operation'] = form_input_validate(get_nfilter_request_var('operation'), 'operation', '^[-0-9]+$', true, 3);
        $save['field']     = form_input_validate(((isset_request_var('field') && get_nfilter_request_var('field') != '0') ? get_nfilter_request_var('field') : ''), 'field', '', true, 3);
        $save['operator']  = form_input_validate((isset_request_var('operator') ? get_nfilter_request_var('operator') : ''), 'operator', '^[0-9]+$', true, 3);
        $save['pattern']   = form_input_validate((isset_request_var('pattern') ? get_nfilter_request_var('pattern') : ''), 'pattern', '', true, 3);

        /* Validate exact submitted field against the rule's data query XML input fields or host_snmp_cache */
        $is_right_bracket = ((int) $save['operation'] === AUTOMATION_OPER_RIGHT_BRACKET && $save['field'] === '');

        if (!$is_right_bracket && !api_automation_is_valid_graph_rule_field($save['field'], (int) $save['rule_id'])) {
            raise_message('sql_injection', __('An attempt was made to perform a SQL injection in Graph automation'), MESSAGE_LEVEL_ERROR);

            cacti_log(sprintf('ERROR: An attempt was made to perform a SQL Injection in Graph Automation from client address \'%s\'', get_client_addr()), false, 'SECURITY');

            header('Location: automation_graph_rules.php?header=false&action=item_edit&id=' . get_request_var('id') . '&item_id=' . get_request_var('item_id') . '&rule_type=' . AUTOMATION_RULE_TYPE_GRAPH_ACTION);

            exit;
        }

        if (!is_error_message()) {
            $item_id = sql_save($save, 'automation_graph_rule_items');

            if ($item_id) {
                raise_message(1);
            } else {
                raise_message(2);
            }
        }

        if (is_error_message()) {
            header('Location: automation_graph_rules.php?header=false&action=item_edit&id=' . get_request_var('id') . '&item_id=' . (empty($item_id) ? get_request_var('item_id') : $item_id) . '&rule_type=' . AUTOMATION_RULE_TYPE_GRAPH_ACTION);
        } else {
            header('Location: automation_graph_rules.php?header=false&action=edit&id=' . get_request_var('id') . '&rule_type=' . AUTOMATION_RULE_TYPE_GRAPH_ACTION);
        }
    } elseif (isset_request_var('save_component_automation_match_item')) {
        /* ================= input validation ================= */
        get_filter_request_var('id');
        get_filter_request_var('item_id');
        /* ==================================================== */

        unset($save);
        $save['id']        = form_input_validate(get_request_var('item_id'), 'item_id', '^[0-9]+$', false, 3);
        $save['rule_id']   = form_input_validate(get_request_var('id'), 'id', '^[0-9]+$', false, 3);
        $save['rule_type'] = AUTOMATION_RULE_TYPE_GRAPH_MATCH;
        $save['sequence']  = form_input_validate(get_nfilter_request_var('sequence'), 'sequence', '^[0-9]+$', false, 3);
        $save['operation'] = form_input_validate(get_nfilter_request_var('operation'), 'operation', '^[-0-9]+$', true, 3);
        $save['field']     = form_input_validate(((isset_request_var('field') && get_nfilter_request_var('field') != '0') ? get_nfilter_request_var('field') : ''), 'field', '', true, 3);
        $save['operator']  = form_input_validate((isset_request_var('operator') ? get_nfilter_request_var('operator') : ''), 'operator', '^[0-9]+$', true, 3);
        $save['pattern']   = form_input_validate((isset_request_var('pattern') ? get_nfilter_request_var('pattern') : ''), 'pattern', '', true, 3);

        /* Validate exact submitted alias.column against the explicit non-sensitive allowlist */
        $is_right_bracket = ((int) $save['operation'] === AUTOMATION_OPER_RIGHT_BRACKET && $save['field'] === '');

        if (!$is_right_bracket && !api_automation_column_exists($save['field'], array('host', 'host_template'))) {
            raise_message('sql_injection', __('An attempt was made to perform a SQL injection in Graph automation'), MESSAGE_LEVEL_ERROR);

            cacti_log(sprintf('ERROR: An attempt was made to perform a SQL Injection in Graph Automation from client address \'%s\'', get_client_addr()), false, 'SECURITY');

            header('Location: automation_graph_rules.php?header=false&action=item_edit&id=' . get_request_var('id') . '&item_id=' . get_request_var('item_id') . '&rule_type=' . AUTOMATION_RULE_TYPE_GRAPH_MATCH);

            exit;
        }

        if (!is_error_message()) {
            $item_id = sql_save($save, 'automation_match_rule_items');

            if ($item_id) {
                raise_message(1);
            } else {
                raise_message(2);
            }
        }

        if (is_error_message()) {
            header('Location: automation_graph_rules.php?header=false&action=item_edit&id=' . get_request_var('id') . '&item_id=' . (empty($item_id) ? get_request_var('item_id') : $item_id) . '&rule_type=' . AUTOMATION_RULE_TYPE_GRAPH_MATCH);
        } else {
            header('Location: automation_graph_rules.php?header=false&action=edit&id=' . get_request_var('id') . '&rule_type=' . AUTOMATION_RULE_TYPE_GRAPH_MATCH);
        }
    } else {
        raise_message(2);
        header('Location: automation_graph_rules.php?header=false');
    }
}

/**
 * Send the list, which Symfony now serves, there. Bookmarked filter, rows,
 * page and sort values carry over and the list validates them; clear=1 and
 * the old Data Query and Status filters are dropped.
 */
function graph_rules_list_moved()
{
    $query = array();

    if (!isset_request_var('clear')) {
        foreach (array('filter', 'rows', 'page', 'sort_column', 'sort_direction') as $name) {
            if (isset_request_var($name) && is_string(get_nfilter_request_var($name))) {
                $query[$name] = get_nfilter_request_var($name);
            }
        }
    }

    // A relative target keeps subdirectory installations working.
    header('Location: app.php/automation/graph-rules' . (cacti_sizeof($query) ? '?' . http_build_query($query) : ''), true, 302);

    exit;
}

/**
 * Answer an old rule settings form, Data Query change link or bulk action,
 * which Symfony now handles, without writing.
 */
function graph_rule_settings_expired()
{
    http_response_code(409);
    header('Cache-Control: private, no-store');
    print html_escape(__('This Graph Rule form has expired. Open the rule again and use its current form.'));

    exit;
}

/* --------------------------
 Rule Item Functions
 -------------------------- */
function automation_graph_rules_item_movedown()
{
    /* ================= input validation ================= */
    get_filter_request_var('id');
    get_filter_request_var('item_id');
    get_filter_request_var('rule_type');
    /* ==================================================== */

    if (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_GRAPH_MATCH) {
        move_item_down('automation_match_rule_items', get_request_var('item_id'), 'rule_id=' . get_request_var('id') . ' AND rule_type=' . get_request_var('rule_type'));
    } elseif (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_GRAPH_ACTION) {
        move_item_down('automation_graph_rule_items', get_request_var('item_id'), 'rule_id=' . get_request_var('id'));
    }
}

function automation_graph_rules_item_moveup()
{
    /* ================= input validation ================= */
    get_filter_request_var('id');
    get_filter_request_var('item_id');
    get_filter_request_var('rule_type');
    /* ==================================================== */

    if (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_GRAPH_MATCH) {
        move_item_up('automation_match_rule_items', get_request_var('item_id'), 'rule_id=' . get_request_var('id') . ' AND rule_type=' . get_request_var('rule_type'));
    } elseif (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_GRAPH_ACTION) {
        move_item_up('automation_graph_rule_items', get_request_var('item_id'), 'rule_id=' . get_request_var('id'));
    }
}

function automation_graph_rules_item_remove()
{
    /* ================= input validation ================= */
    get_filter_request_var('item_id');
    get_filter_request_var('rule_type');
    /* ==================================================== */

    if (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_GRAPH_MATCH) {
        db_execute_prepared('DELETE FROM automation_match_rule_items WHERE id = ?', array(get_request_var('item_id')));
    } elseif (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_GRAPH_ACTION) {
        db_execute_prepared('DELETE FROM automation_graph_rule_items WHERE id = ?', array(get_request_var('item_id')));
    }

}

function automation_graph_rules_item_edit()
{

    /* ================= input validation ================= */
    get_filter_request_var('id');
    get_filter_request_var('item_id');
    get_filter_request_var('rule_type');
    /* ==================================================== */

    global_item_edit(get_request_var('id'), get_request_var('item_id'), get_request_var('rule_type'));

    form_hidden_box('rule_type', get_request_var('rule_type'), get_request_var('rule_type'));
    form_hidden_box('id', (isset_request_var('id') ? get_request_var('id') : '0'), '');
    form_hidden_box('item_id', (isset_request_var('item_id') ? get_request_var('item_id') : '0'), '');

    if (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_GRAPH_MATCH) {
        form_hidden_box('save_component_automation_match_item', '1', '');
    } else {
        form_hidden_box('save_component_automation_graph_rule_item', '1', '');
    }

    form_save_button('automation_graph_rules.php?action=edit&id=' . get_request_var('id') . '&rule_type=' . get_request_var('rule_type'));

    ?>
	<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>

	$(function() {
		toggle_operation();
		toggle_operator();
	});

	function toggle_operation() {
		if ($('#operation').val() == '<?php print AUTOMATION_OPER_RIGHT_BRACKET;?>') {
			$('#field').val('');
			$('#field').prop('disabled', true);
			$('#operator').val(0);
			$('#operator').prop('disabled', true);
			$('#pattern').val('');
			$('#pattern').prop('disabled', true);
		} else {
			$('#field').prop('disabled', false);
			$('#operator').prop('disabled', false);
			$('#pattern').prop('disabled', false);
		}
	}

	function toggle_operator() {
		if ($('#operator').val() == '<?php print AUTOMATION_OPER_RIGHT_BRACKET;?>') {
		} else {
		}
	}
	</script>
	<?php
}

/* ---------------------
 Rule Functions
 --------------------- */

function automation_graph_rules_remove()
{
    /* ================= input validation ================= */
    get_filter_request_var('id');
    /* ==================================================== */

    if ((read_config_option('deletion_verification') == 'on') && (!isset_request_var('confirm'))) {
        top_header();
        form_confirm(__('Are You Sure?'), __("Are you sure you want to delete the Rule '%s'?", db_fetch_cell_prepared('SELECT name FROM automation_graph_rules WHERE id = ?', array(get_request_var('id')))), 'automation_graph_rules.php', 'automation_graph_rules.php?action=remove&id=' . get_request_var('id'));
        bottom_footer();
        exit;
    }

    if ((read_config_option('deletion_verification') == '') || (isset_request_var('confirm'))) {
        db_execute_prepared(
            'DELETE FROM automation_match_rule_items
			WHERE rule_id = ?
			AND rule_type = ?',
            array(get_request_var('id'), AUTOMATION_RULE_TYPE_GRAPH_MATCH)
        );

        db_execute_prepared(
            'DELETE FROM automation_graph_rule_items
			WHERE rule_id = ?',
            array(get_request_var('id'))
        );

        db_execute_prepared(
            'DELETE FROM automation_graph_rules
			WHERE id = ?',
            array(get_request_var('id'))
        );
    }
}

function automation_graph_rules_edit()
{
    global $config;

    /* ================= input validation ================= */
    get_filter_request_var('id');
    get_filter_request_var('show_graphs');
    get_filter_request_var('show_hosts');
    get_filter_request_var('show_rule');
    /* ==================================================== */

    /* handle show_rule mode */
    if (isset_request_var('show_rule')) {
        if (get_request_var('show_rule') == '0') {
            kill_session_var('automation_graph_rules_show_rule');
            $_SESSION['automation_graph_rules_show_rule'] = false;
        } elseif (get_request_var('show_rule') == '1') {
            $_SESSION['automation_graph_rules_show_rule'] = true;
        }
    } elseif (!isset($_SESSION['automation_graph_rules_show_rule'])) {
        $_SESSION['automation_graph_rules_show_rule'] = true;
    }

    /* handle show_graphs mode */
    if (isset_request_var('show_graphs')) {
        if (get_request_var('show_graphs') == '0') {
            kill_session_var('automation_graph_rules_show_graphs');
        } elseif (get_request_var('show_graphs') == '1') {
            $_SESSION['automation_graph_rules_show_graphs'] = true;
        }
    }

    /* handle show_hosts mode */
    if (isset_request_var('show_hosts')) {
        if (get_request_var('show_hosts') == '0') {
            kill_session_var('automation_graph_rules_show_hosts');
        } elseif (get_request_var('show_hosts') == '1') {
            $_SESSION['automation_graph_rules_show_hosts'] = true;
        }
    }

    /*
     * display the rule -------------------------------------------------------------------------------------
     */
    $rule = db_fetch_row_prepared('SELECT * FROM automation_graph_rules WHERE id = ?', array(get_request_var('id')));

    if (!cacti_sizeof($rule)) {
        // Deleted after the dispatcher's check; output has already started.
        print '<p>' . __esc('Rule not found.') . '</p>';

        return;
    }

    $header_label = __esc('Rule Selection [edit: %s]', $rule['name']);

    /*
     * show rule? ------------------------------------------------------------------------------------------
     */
    if (!isempty_request_var('id')) {
        ?>
<table style='width:100%;text-align:center;'>
	<tr>
		<td class='textInfo right' style='vertical-align:top;'><span class='linkMarker'>*</span><a class='linkEditMain' href='<?php print html_escape('automation_graph_rules.php?action=edit&id=' . (isset_request_var('id') ? get_request_var('id') : 0) . '&show_rule=') . ($_SESSION['automation_graph_rules_show_rule'] == true ? '0' : '1');?>'><?php print($_SESSION['automation_graph_rules_show_rule'] == true ? __('Don\'t Show') : __('Show'));?> <?php print __('Rule Details.');?></a><br>
		</td>
	</tr>
</table>

		<?php
    }

    /*
     * show hosts? ------------------------------------------------------------------------------------------
     */
    if (!isempty_request_var('id')) {
        ?>
<table style='width:100%;text-align:center;'>
	<tr>
		<td class='textInfo right' style='vertical-align:top;'><span class='linkMarker'>*</span><a class='linkEditMain' href='<?php print html_escape('automation_graph_rules.php?action=edit&id=' . (isset_request_var('id') ? get_request_var('id') : 0) . '&show_hosts=') . (isset($_SESSION['automation_graph_rules_show_hosts']) ? '0' : '1');?>'><?php print(isset($_SESSION['automation_graph_rules_show_hosts']) ? __('Don\'t Show') : __('Show'));?> <?php print __('Matching Devices.');?></a><br>
		</td>
	</tr>
</table>

		<?php
    }

    /*
     * show graphs? -----------------------------------------------------------------------------------------
     */
    if (!empty($rule['graph_type_id']) && $rule['graph_type_id'] > 0) {
        ?>
<table style='width:100%;text-align:center;'>
	<tr>
		<td class='textInfo right' style='vertical-align:top;'>
			<span class='linkMarker'>*</span><a class='linkEditMain' href='<?php print html_escape('automation_graph_rules.php?action=edit&id=' . (isset_request_var('id') ? get_request_var('id') : 0) . '&show_graphs=') . (isset($_SESSION['automation_graph_rules_show_graphs']) ? '0' : '1');?>'><?php print(isset($_SESSION['automation_graph_rules_show_graphs']) ? __('Don\'t Show') : __('Show'));?> <?php print __('Matching Objects.');?></a><br>
		</td>
	</tr>
</table>
		<?php
    }

    if ($_SESSION['automation_graph_rules_show_rule']) {
        // The settings are edited in Symfony; this page keeps the criteria and previews.
        html_start_box($header_label, '100%', true, '3', 'center', '');
        $query = db_fetch_cell_prepared('SELECT name FROM snmp_query WHERE id = ?', array($rule['snmp_query_id']));
        $graph_type = db_fetch_cell_prepared('SELECT name FROM snmp_query_graph WHERE id = ?', array($rule['graph_type_id']));
        foreach (array(
            __('Data Query') => ($query == '' ? __('None') : $query),
            __('Graph Type') => ($graph_type == '' ? __('None') : $graph_type),
            __('Enabled') => ($rule['enabled'] == 'on' ? __('Yes') : __('No')),
        ) as $label => $value) {
            form_alternate_row();
            print '<td>' . html_escape($label) . '</td><td>' . html_escape($value) . '</td></tr>';
        }
        form_alternate_row();
        // A Symfony page is a full navigation, not a partial load.
        print "<td colspan='2'><a class='linkEditMain' href='" . html_escape($config['url_path'] . 'app.php/automation/graph-rules/' . $rule['id'] . '/edit') . "'>" . __esc('Edit Rule Settings') . '</a></td></tr>';
        html_end_box(true, true);

        # display graph rules for host match
        display_match_rule_items(
            __('Device Selection Criteria'),
            $rule['id'],
            AUTOMATION_RULE_TYPE_GRAPH_MATCH,
            'automation_graph_rules.php'
        );

        # fetch graph action rules
        display_graph_rule_items(
            __('Graph Creation Criteria'),
            $rule['id'],
            AUTOMATION_RULE_TYPE_GRAPH_ACTION,
            'automation_graph_rules.php'
        );

        print "<table style='width:100%;text-align:center;'><tr><td class='saveRow'><input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' data-url='automation_graph_rules.php' value='" . __esc('Return') . "'></td></tr></table><br>";
    }

    if (!empty($rule['id'])) {
        /* display list of matching hosts */
        if (isset($_SESSION['automation_graph_rules_show_hosts'])) {
            if ($_SESSION['automation_graph_rules_show_hosts']) {
                display_matching_hosts($rule, AUTOMATION_RULE_TYPE_GRAPH_MATCH, 'automation_graph_rules.php?action=edit&id=' . get_request_var('id'));
            }
        }

        /* display list of new graphs */
        if (isset($_SESSION['automation_graph_rules_show_graphs'])) {
            if ($_SESSION['automation_graph_rules_show_graphs']) {
                display_new_graphs($rule, 'automation_graph_rules.php?action=edit&id=' . get_request_var('id'));
            }
        }
    }

}
