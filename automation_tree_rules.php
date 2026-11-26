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
        automation_tree_rules_form_save();

        break;
    case 'actions':
        // The list and its bulk actions moved to Symfony; replaying an old selection must not write.
        tree_rule_settings_expired();

        break;
    case 'change_leaf':
        // Rule settings moved to Symfony; the old leaf type change must not write.
        tree_rule_settings_expired();

        break;
    case 'item_movedown':
        automation_tree_rules_item_movedown();

        header('Location: automation_tree_rules.php?action=edit&id=' . get_request_var('id'));
        break;
    case 'item_moveup':
        automation_tree_rules_item_moveup();

        header('Location: automation_tree_rules.php?action=edit&id=' . get_request_var('id'));
        break;
    case 'item_remove':
        automation_tree_rules_item_remove();

        header('Location: automation_tree_rules.php?action=edit&id=' . get_request_var('id'));
        break;
    case 'item_edit':
        top_header();
        automation_tree_rules_item_edit();
        bottom_footer();
        break;
    case 'remove':
        automation_tree_rules_remove();

        header('Location: automation_tree_rules.php');
        break;
    case 'edit':
        if (isempty_request_var('id')) {
            // Symfony creates rules. Relative targets keep subdirectory installations working.
            header('Location: app.php/automation/tree-rules/new', true, 302);
            exit;
        }
        // Redirect before top_header() prints anything, or the header is lost.
        if (!db_fetch_cell_prepared('SELECT id FROM automation_tree_rules WHERE id = ?', array(get_filter_request_var('id')))) {
            raise_message('rule_not_found', __('Rule not found.'), MESSAGE_LEVEL_ERROR);
            header('Location: automation_tree_rules.php', true, 302);
            exit;
        }

        top_header();
        automation_tree_rules_edit();
        bottom_footer();
        break;
    default:
        tree_rules_list_moved();

        break;
}

/* --------------------------
 The Save Function
 -------------------------- */

function automation_tree_rules_form_save()
{

    if (isset_request_var('save_component_automation_tree_rule')) {
        tree_rule_settings_expired();
    } elseif (isset_request_var('save_component_automation_match_item')) {
        /* ================= input validation ================= */
        get_filter_request_var('id');
        get_filter_request_var('item_id');
        /* ==================================================== */

        $save = array();
        $save['id']        = form_input_validate(get_request_var('item_id'), 'item_id', '^[0-9]+$', false, 3);
        $save['rule_id']   = form_input_validate(get_request_var('id'), 'id', '^[0-9]+$', false, 3);
        $save['rule_type'] = AUTOMATION_RULE_TYPE_TREE_MATCH;
        $save['sequence']  = form_input_validate(get_nfilter_request_var('sequence'), 'sequence', '^[0-9]+$', false, 3);
        $save['operation'] = form_input_validate(get_nfilter_request_var('operation'), 'operation', '^[-0-9]+$', true, 3);
        $save['field']     = form_input_validate(((isset_request_var('field') && get_nfilter_request_var('field') != '0') ? get_nfilter_request_var('field') : ''), 'field', '', true, 3);
        $save['operator']  = form_input_validate((isset_request_var('operator') ? get_nfilter_request_var('operator') : ''), 'operator', '^[0-9]+$', true, 3);
        $save['pattern']   = form_input_validate((isset_request_var('pattern') ? get_nfilter_request_var('pattern') : ''), 'pattern', '', true, 3);

        /* Test for SQL injections */
        $field_name = str_replace(array('ht.', 'h.', 'gt.'), '', $save['field']);

        if (!db_column_exists('host', $field_name) && !db_column_exists('host_template', $field_name) && !db_column_exists('graph_templates', $field_name)) {
            raise_message('sql_injection', __('An attempt was made to perform a SQL injection in Tree automation'), MESSAGE_LEVEL_ERROR);

            cacti_log(sprintf('ERROR: An attempt was made to perform a SQL Injection in Tree automation from client address \'%s\'', get_client_addr()), false, 'SECURITY');

            header('Location: automation_tree_rules.php?header=false&action=item_edit&id=' . get_request_var('id') . '&item_id=' . (empty($item_id) ? get_request_var('item_id') : $item_id) . '&rule_type=' . AUTOMATION_RULE_TYPE_TREE_MATCH);

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
            header('Location: automation_tree_rules.php?header=false&action=item_edit&id=' . get_request_var('id') . '&item_id=' . (empty($item_id) ? get_request_var('item_id') : $item_id) . '&rule_type=' . AUTOMATION_RULE_TYPE_TREE_MATCH);
        } else {
            header('Location: automation_tree_rules.php?header=false&action=edit&id=' . get_request_var('id') . '&rule_type=' . AUTOMATION_RULE_TYPE_TREE_MATCH);
        }
    } elseif (isset_request_var('save_component_automation_tree_rule_item')) {
        /* ================= input validation ================= */
        get_filter_request_var('id');
        get_filter_request_var('item_id');
        /* ==================================================== */

        unset($save);

        $save['id']                = form_input_validate(get_request_var('item_id'), 'item_id', '^[0-9]+$', false, 3);
        $save['rule_id']           = form_input_validate(get_request_var('id'), 'id', '^[0-9]+$', false, 3);
        $save['sequence']          = form_input_validate(get_nfilter_request_var('sequence'), 'sequence', '^[0-9]+$', false, 3);
        $save['field']             = form_input_validate((isset_request_var('field') ? get_nfilter_request_var('field') : ''), 'field', '', true, 3);
        $save['sort_type']         = form_input_validate(get_nfilter_request_var('sort_type'), 'sort_type', '^[0-9]+$', false, 3);
        $save['propagate_changes'] = (isset_request_var('propagate_changes') ? 'on' : '');
        $save['search_pattern']    = isset_request_var('search_pattern') ? form_input_validate(get_nfilter_request_var('search_pattern'), 'search_pattern', '', false, 3) : '';
        $save['replace_pattern']   = isset_request_var('replace_pattern') ? form_input_validate(get_nfilter_request_var('replace_pattern'), 'replace_pattern', '', true, 3) : '';

        if ($save['field'] != AUTOMATION_TREE_ITEM_TYPE_STRING && $save['field'] != '') {
            $field_name = str_replace(array('ht.', 'h.', 'gt.', 'gl.', 'gtg.'), '', $save['field']);
            $field_name = preg_replace('/[^a-zA-Z0-9_]/', '', $field_name);
            if (!db_column_exists('host', $field_name) &&
                !db_column_exists('host_template', $field_name) &&
                !db_column_exists('graph_templates', $field_name) &&
                !db_column_exists('graph_local', $field_name) &&
                !db_column_exists('graph_templates_graph', $field_name)) {
                raise_message('field_invalid', __('Invalid Field Name specified for Tree Rule Item.'), MESSAGE_LEVEL_ERROR);
                header('Location: automation_tree_rules.php?header=false&action=item_edit&id=' . get_request_var('id') . '&item_id=' . get_request_var('item_id') . '&rule_type=' . AUTOMATION_RULE_TYPE_TREE_ACTION);
                exit;
            }
        }

        if (!is_error_message()) {
            $automation_graph_rule_item_id = sql_save($save, 'automation_tree_rule_items');

            if ($automation_graph_rule_item_id) {
                raise_message(1);
            } else {
                raise_message(2);
            }
        }

        if (is_error_message()) {
            header('Location: automation_tree_rules.php?header=false&action=item_edit&id=' . get_request_var('id') . '&item_id=' . (empty($automation_graph_rule_item_id) ? get_request_var('item_id') : $automation_graph_rule_item_id) . '&rule_type=' . AUTOMATION_RULE_TYPE_TREE_ACTION);
        } else {
            header('Location: automation_tree_rules.php?header=false&action=edit&id=' . get_request_var('id') . '&rule_type=' . AUTOMATION_RULE_TYPE_TREE_ACTION);
        }
    } else {
        raise_message(2);
        header('Location: automation_tree_rules.php?header=false');
    }
}

/**
 * Send the list, which Symfony now serves, there. Bookmarked filter, rows,
 * page and sort values carry over and the list validates them; clear=1 and
 * the old Status filter are dropped.
 */
function tree_rules_list_moved()
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
    header('Location: app.php/automation/tree-rules' . (cacti_sizeof($query) ? '?' . http_build_query($query) : ''), true, 302);

    exit;
}

/**
 * Answer an old rule settings form, leaf type change or bulk action, which
 * Symfony now handles, without writing.
 */
function tree_rule_settings_expired()
{
    http_response_code(409);
    header('Cache-Control: private, no-store');
    print html_escape(__('This Tree Rule form has expired. Open the rule again and use its current form.'));

    exit;
}

/* --------------------------
 Rule Item Functions
 -------------------------- */

function automation_tree_rules_item_movedown()
{
    /* ================= input validation ================= */
    get_filter_request_var('id');
    get_filter_request_var('item_id');
    get_filter_request_var('rule_type');
    /* ==================================================== */

    if (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_TREE_MATCH) {
        move_item_down('automation_match_rule_items', get_request_var('item_id'), 'rule_id=' . get_request_var('id') . ' AND rule_type=' . get_request_var('rule_type'));
    } elseif (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_TREE_ACTION) {
        move_item_down('automation_tree_rule_items', get_request_var('item_id'), 'rule_id=' . get_request_var('id'));
    }
}

function automation_tree_rules_item_moveup()
{
    /* ================= input validation ================= */
    get_filter_request_var('id');
    get_filter_request_var('item_id');
    get_filter_request_var('rule_type');
    /* ==================================================== */

    if (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_TREE_MATCH) {
        move_item_up('automation_match_rule_items', get_request_var('item_id'), 'rule_id=' . get_request_var('id') . ' AND rule_type=' . get_request_var('rule_type'));
    } elseif (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_TREE_ACTION) {
        move_item_up('automation_tree_rule_items', get_request_var('item_id'), 'rule_id=' . get_request_var('id'));
    }
}

function automation_tree_rules_item_remove()
{
    /* ================= input validation ================= */
    get_filter_request_var('item_id');
    get_filter_request_var('rule_type');
    /* ==================================================== */

    if (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_TREE_MATCH) {
        db_execute_prepared('DELETE FROM automation_match_rule_items WHERE id = ?', array(get_request_var('item_id')));
    } elseif (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_TREE_ACTION) {
        db_execute_prepared('DELETE FROM automation_tree_rule_items WHERE id = ?', array(get_request_var('item_id')));
    }


}


function automation_tree_rules_item_edit()
{

    /* ================= input validation ================= */
    get_filter_request_var('id');
    get_filter_request_var('item_id');
    get_filter_request_var('rule_type');
    get_filter_request_var('show_trees');
    /* ==================================================== */

    /* handle show_trees mode */
    if (isset_request_var('show_trees')) {
        if (get_request_var('show_trees') == '0') {
            kill_session_var('automation_tree_rules_show_trees');
        } elseif (get_request_var('show_trees') == '1') {
            $_SESSION['automation_tree_rules_show_trees'] = true;
        }
    }

    if (!isempty_request_var('rule_type') && !isempty_request_var('item_id')) {
        if (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_TREE_ACTION) {
            $item = db_fetch_row_prepared('SELECT * FROM automation_tree_rule_items WHERE id = ?', array(get_request_var('item_id')));
            if ($item['field'] != AUTOMATION_TREE_ITEM_TYPE_STRING) {
                ?>
<table style='width:100%;text-align:center;'>
	<tr>
		<td class='textInfo' style='text-align:right;vertical-align:top;'><span class='linkMarker'>*</span><a class='linkEditMain' href='<?php print html_escape('automation_tree_rules.php?action=item_edit&id=' . (isset_request_var('id') ? get_request_var('id') : 0) . '&item_id=' . (isset_request_var('item_id') ? get_request_var('item_id') : 0) . '&rule_type=' . (isset_request_var('rule_type') ? get_request_var('rule_type') : 0) . '&show_trees=') . (isset($_SESSION['automation_tree_rules_show_trees']) ? '0' : '1');?>'><?php print(isset($_SESSION['automation_tree_rules_show_trees']) ? __('Don\'t Show') : __('Show'));?> <?php print __('Created Trees');?></a><br>
		</td>
	</tr>
</table>
<br>
				<?php
            }
        }
    }

    global_item_edit(get_request_var('id'), get_request_var('item_id'), get_request_var('rule_type'));

    form_hidden_box('rule_type', get_request_var('rule_type'), get_request_var('rule_type'));
    form_hidden_box('id', (isset_request_var('id') ? get_request_var('id') : '0'), '');
    form_hidden_box('item_id', (isset_request_var('item_id') ? get_request_var('item_id') : '0'), '');
    if (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_TREE_MATCH) {
        form_hidden_box('save_component_automation_match_item', '1', '');
    } else {
        form_hidden_box('save_component_automation_tree_rule_item', '1', '');
    }
    form_save_button('automation_tree_rules.php?action=edit&id=' . get_request_var('id') . '&rule_type=' . get_request_var('rule_type'));
    print '<br>';

    /* display list of matching trees */
    if (!isempty_request_var('rule_type') && !isempty_request_var('item_id')) {
        if (get_request_var('rule_type') == AUTOMATION_RULE_TYPE_TREE_ACTION) {
            if (isset($_SESSION['automation_tree_rules_show_trees']) && ($item['field'] != AUTOMATION_TREE_ITEM_TYPE_STRING)) {
                if ($_SESSION['automation_tree_rules_show_trees']) {
                    display_matching_trees(get_request_var('id'), AUTOMATION_RULE_TYPE_TREE_ACTION, $item, 'automation_tree_rules.php?action=item_edit&id=' . get_request_var('id') . '&item_id=' . get_request_var('item_id') . '&rule_type=' . get_request_var('rule_type'));
                }
            }
        }
    }

    //Now we need some javascript to make it dynamic
    ?>
<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>

applyHeaderChange();
toggle_operation();
toggle_operator();

function applyHeaderChange() {
	if ($('#rule_type').val() == '<?php print AUTOMATION_RULE_TYPE_TREE_ACTION;?>') {
		if ($('#field').val() == '<?php print AUTOMATION_TREE_ITEM_TYPE_STRING;?>') {
			$('#replace_pattern').val('');
			$('#replace_pattern').prop('disabled', true);
		} else {
			$('#replace_pattern').prop('disabled', false);
		}
	}
}

function toggle_operation() {
	// right bracket ')' does not come with a field
	if ($('operation').value == '<?php print AUTOMATION_OPER_RIGHT_BRACKET;?>') {
		//alert('Sequence is '' + document.getElementById('sequence').value + ''');
		$('#field').val('');
		$('#field').prop('disabled', true);
		$('#operator').val(0);
		$('#operator').prop('disabled', true);
		$('#pattern').val('');
		$('#pattern').prop('disabled', true);
	} else {
		$('#field').prop('disabled', false);
		$('#operator').prop('disabled', false);
		$('#pattern').prop('disabled', false)
	}
}

function toggle_operator() {
	// if operator is not 'binary', disable the 'field' for matching strings
	if ($('#operator').val() == '<?php print AUTOMATION_OPER_RIGHT_BRACKET;?>') {
		//alert('Sequence is '' + document.getElementById('sequence').value + ''');
	} else {
	}
}
</script>
<?php
}

/* ---------------------
 Rule Functions
 --------------------- */

function automation_tree_rules_remove()
{
    /* ================= input validation ================= */
    get_filter_request_var('id');
    /* ==================================================== */

    if ((read_config_option('deletion_verification') == 'on') && (!isset_request_var('confirm'))) {
        top_header();
        form_confirm(__('Are You Sure?'), __("Are you sure you want to DELETE the Rule '%s'?", db_fetch_cell_prepared('SELECT name FROM automation_tree_rules WHERE id = ?', array(get_request_var('id')))), 'automation_tree_rules.php', 'automation_tree_rules.php?action=remove&id=' . get_request_var('id'));
        bottom_footer();
        exit;
    }

    if ((read_config_option('deletion_verification') == '') || (isset_request_var('confirm'))) {
        db_execute_prepared(
            'DELETE FROM automation_match_rule_items
			WHERE rule_id = ?
			AND rule_type = ?',
            array(get_request_var('id'), AUTOMATION_RULE_TYPE_TREE_MATCH)
        );

        db_execute_prepared(
            'DELETE FROM automation_tree_rule_items
			WHERE rule_id = ?',
            array(get_request_var('id'))
        );

        db_execute_prepared(
            'DELETE FROM automation_tree_rules
			WHERE id = ?',
            array(get_request_var('id'))
        );
    }
}

function automation_tree_rules_edit()
{
    global $config, $automation_tree_item_types, $host_group_types;

    /* ================= input validation ================= */
    get_filter_request_var('id');
    get_filter_request_var('rows');
    get_filter_request_var('show_hosts');
    /* ==================================================== */

    /* remember these search fields in session vars so we don't have to keep passing them around */
    load_current_session_value('rows', 'sess_default_rows', read_config_option('num_rows_table'));

    /* handle show_hosts mode */
    if (isset_request_var('show_hosts')) {
        if (get_request_var('show_hosts') == '0') {
            kill_session_var('automation_tree_rules_show_objects');
        } elseif (get_request_var('show_hosts') == '1') {
            $_SESSION['automation_tree_rules_show_objects'] = true;
        }
    }

    if (!isempty_request_var('id')) {
        ?>
<table style='width:100%;text-align:center;'>
	<tr>
		<td class='textInfo right' style='vertical-align:top;'><span class='linkMarker'>*</span><a class='linkEditMain' href='<?php print html_escape('automation_tree_rules.php?action=edit&id=' . (isset_request_var('id') ? get_request_var('id') : 0) . '&show_hosts=') . (isset($_SESSION['automation_tree_rules_show_objects']) ? '0' : '1');?>'><?php print(isset($_SESSION['automation_tree_rules_show_objects']) ? __('Don\'t Show') : __('Show'));?> <?php print __('Eligible Objects');?></a><br>
		</td>
	</tr>
</table>
		<?php
    }

    /*
     * display the rule -------------------------------------------------------------------------------------
     */
    $rule = db_fetch_row_prepared('SELECT * FROM automation_tree_rules WHERE id = ?', array(get_request_var('id')));

    if (!cacti_sizeof($rule)) {
        // Deleted after the dispatcher's check; output has already started.
        print '<p>' . __esc('Rule not found.') . '</p>';

        return;
    }

    // The settings are edited in Symfony; this page keeps the criteria and previews.
    html_start_box(__esc('Tree Rule Selection [edit: %s]', $rule['name']), '100%', true, '3', 'center', '');

    $tree   = db_fetch_cell_prepared('SELECT name FROM graph_tree WHERE id = ?', array($rule['tree_id']));
    $header = ($rule['tree_item_id'] > 0 ? db_fetch_cell_prepared('SELECT title FROM graph_tree_items WHERE id = ?', array($rule['tree_item_id'])) : '[root]');

    foreach (array(
        __('Tree') => ($tree == '' ? __('None') : $tree),
        __('Optional: Sub-Tree Item') => ($header == '' ? __('None') : $header),
        __('Leaf Item Type') => ($automation_tree_item_types[$rule['leaf_type']] ?? __('None')),
        __('Graph Grouping Style') => ($host_group_types[$rule['host_grouping_type']] ?? __('None')),
        __('Enabled') => ($rule['enabled'] == 'on' ? __('Yes') : __('No')),
    ) as $label => $value) {
        form_alternate_row();
        print '<td>' . html_escape($label) . '</td><td>' . html_escape($value) . '</td></tr>';
    }

    form_alternate_row();
    // A Symfony page is a full navigation, not a partial load.
    print "<td colspan='2'><a class='linkEditMain' href='" . html_escape($config['url_path'] . 'app.php/automation/tree-rules/' . $rule['id'] . '/edit') . "'>" . __esc('Edit Rule Settings') . '</a></td></tr>';

    html_end_box(true, true);

    /*
     * display the rule items -------------------------------------------------------------------------------
     */
    if (!empty($rule['id'])) {
        # display tree rules for host match
        display_match_rule_items(
            __('Object Selection Criteria'),
            $rule['id'],
            AUTOMATION_RULE_TYPE_TREE_MATCH,
            'automation_tree_rules.php'
        );

        # fetch tree action rules
        display_tree_rule_items(
            __('Tree Creation Criteria'),
            $rule['id'],
            $rule['leaf_type'],
            AUTOMATION_RULE_TYPE_TREE_ACTION,
            'automation_tree_rules.php'
        );
    }

    print "<table style='width:100%;text-align:center;'><tr><td class='saveRow'><input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' data-url='automation_tree_rules.php' value='" . __esc('Return') . "'></td></tr></table><br>";

    if (!empty($rule['id'])) {
        /* display list of matching hosts */
        if (isset($_SESSION['automation_tree_rules_show_objects'])) {
            if ($_SESSION['automation_tree_rules_show_objects']) {
                if ($rule['leaf_type'] == TREE_ITEM_TYPE_HOST) {
                    display_matching_hosts($rule, AUTOMATION_RULE_TYPE_TREE_MATCH, 'automation_tree_rules.php?action=edit&id=' . get_request_var('id'));
                } elseif ($rule['leaf_type'] == TREE_ITEM_TYPE_GRAPH) {
                    display_matching_graphs($rule, AUTOMATION_RULE_TYPE_TREE_MATCH, 'automation_tree_rules.php?action=edit&id=' . get_request_var('id'));
                }
            }
        }
    }

}
