<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

include('./include/auth.php');

cacti_require_post_actions(array('actions'));
include_once('./lib/snmp.php');

$automation_snmp_actions = array(
    1 => __('Delete'),
    2 => __('Duplicate'),
);

/* set default action */
set_default_action();

/* correct for a cancel button */
if (isset_request_var('cancel')) {
    set_request_var('action', '');
}

switch (get_request_var('action')) {
    case 'actions':
        form_automation_snmp_actions();

        break;
    case 'edit':
        // Symfony owns editing. Relative targets keep subdirectory installations working.
        $id = get_filter_request_var('id');
        header('Location: ' . (empty($id) ? 'app.php/automation/snmp-options/new' : 'app.php/automation/snmp-options/' . $id . '/edit'), true, 302);

        exit;
    case 'item_edit':
        $id      = get_filter_request_var('id');
        $item_id = get_filter_request_var('item_id');

        if (empty($id)) {
            header('Location: app.php/automation/snmp-options', true, 302);
        } else {
            header('Location: app.php/automation/snmp-options/' . $id . (empty($item_id) ? '/entries/new' : '/entries/' . $item_id . '/edit'), true, 302);
        }

        exit;
    case 'save':
    case 'ajax_dnd':
    case 'item_movedown':
    case 'item_moveup':
    case 'item_remove_confirm':
    case 'item_remove':
        // These forms moved to Symfony; replaying an old submission must not write.
        http_response_code(409);
        header('Cache-Control: private, no-store');
        print html_escape(__('This SNMP Option Set form has expired. Open the SNMP Option Set again and use its current form.'));

        exit;
    default:
        top_header();

        automation_snmp();

        bottom_footer();
        break;
}

function form_automation_snmp_actions()
{
    global $automation_snmp_actions;

    /* ================= input validation ================= */
    get_filter_request_var('drp_action');
    /* ==================================================== */

    /* if we are to save this form, instead of display it */
    if (isset_request_var('selected_items')) {
        $selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

        if ($selected_items != false) {
            if (get_nfilter_request_var('drp_action') == '1') { /* delete */
                db_execute('DELETE FROM automation_snmp WHERE ' . array_to_sql_or($selected_items, 'id'));
                db_execute('DELETE FROM automation_snmp_items WHERE ' . str_replace('id', 'snmp_id', array_to_sql_or($selected_items, 'id')));
            } elseif (get_nfilter_request_var('drp_action') == '2') { /* duplicate */
                for ($i = 0;($i < cacti_count($selected_items));$i++) {
                    automation_duplicate_snmp_option($selected_items[$i], get_nfilter_request_var('name_format'));
                }
            }
        }

        header('Location: automation_snmp.php?header=false');
        exit;
    }

    /* setup some variables */
    $snmp_groups = '';
    $i = 0;
    /* loop through each of the graphs selected on the previous page and get more info about them */
    foreach ($_POST as $var => $val) {
        if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
            /* ================= input validation ================= */
            input_validate_input_number($matches[1]);
            /* ==================================================== */
            $snmp_groups .= '<li>' . html_escape(db_fetch_cell_prepared('SELECT name FROM automation_snmp WHERE id = ?', array($matches[1]))) . '</li>';
            $automation_array[$i] = $matches[1];
            $i++;
        }
    }

    general_header();

    ?>
	<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>
	function goTo(location) {
		document.location = location;
	}
	</script>
	<?php

    form_start('automation_snmp.php', 'automation_filter');

    html_start_box(escape_page_action($automation_snmp_actions, get_nfilter_request_var('drp_action')), '60%', '', '3', 'center', '');

    if (!isset($automation_array)) {
        raise_message(40);
        header('Location: automation_snmp.php?header=false');
        exit;
    } else {
        $save_html = "<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' name='save'>";

        if (get_nfilter_request_var('drp_action') == '1') { /* delete */
            print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to delete the following SNMP Option(s).') . "</p>
					<div class='itemlist'><ul>$snmp_groups</ul></div>
				</td>
			</tr>";
        } elseif (get_nfilter_request_var('drp_action') == '2') { /* duplicate */
            print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to duplicate the following SNMP Options. You can optionally change the title format for the new SNMP Options.') . "</p>
					<div class='itemlist'><ul>$snmp_groups</ul></div>
					<p>" . __('Name Format') . '<br>';
            form_text_box('name_format', '<' . __('name') . '> (1)', '', '255', '30', 'text');
            print "</p>
				</td>
			</tr>";
        }
    }

    print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($automation_array) ? serialize($automation_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . html_escape(get_nfilter_request_var('drp_action')) . "'>
			<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='" . ($save_html == '' ? __esc('Return') : __esc('Cancel')) . "' name='cancel'>
			$save_html
		</td>
	</tr>";

    html_end_box();

    form_end();

    bottom_footer();
}

/* --------------------------
 SNMP Options Functions
 -------------------------- */

function automation_duplicate_snmp_option($id, $new_name)
{
    $name = db_fetch_cell_prepared('SELECT name
		FROM automation_snmp
		WHERE id = ?', array($id));

    $new_name = str_replace('<name>', $name, $new_name);

    $save['id'] = 0;
    $save['name'] = $new_name;

    $newid = sql_save($save, 'automation_snmp');

    if ($newid > 0 && $id > 0) {
        db_execute_prepared(
            "INSERT INTO automation_snmp_items
		(snmp_id, sequence, snmp_version, snmp_community, snmp_port, snmp_timeout,
        snmp_retries, max_oids, snmp_username, snmp_password, snmp_auth_protocol,
		snmp_priv_passphrase, snmp_priv_protocol, snmp_context, snmp_engine_id)
		SELECT $newid AS snmp_id, sequence, snmp_version, snmp_community, snmp_port, snmp_timeout,
        snmp_retries, max_oids, snmp_username, snmp_password, snmp_auth_protocol,
        snmp_priv_passphrase, snmp_priv_protocol, snmp_context, snmp_engine_id
		FROM automation_snmp_items
		WHERE snmp_id = ?",
            array($id)
        );

        raise_message('option_duplicated', __('Automation SNMP Options has been Duplicated.'), MESSAGE_LEVEL_INFO);
    } else {
        raise_message('missing_options', __('Automation Item does not exist.  Can not Duplicate.'), MESSAGE_LEVEL_ERROR);
    }
}

function automation_snmp()
{
    global $item_rows, $automation_snmp_actions;

    /* ================= input validation and session storage ================= */
    $filters = array(
        'rows' => array(
            'filter' => FILTER_VALIDATE_INT,
            'pageset' => true,
            'default' => '-1'
        ),
        'page' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => '1'
        ),
        'filter' => array(
            'filter' => FILTER_DEFAULT,
            'pageset' => true,
            'default' => ''
        ),
        'sort_column' => array(
            'filter' => FILTER_CALLBACK,
            'default' => 'name',
            'options' => array('options' => 'sanitize_search_string')
        ),
        'sort_direction' => array(
            'filter' => FILTER_CALLBACK,
            'default' => 'ASC',
            'options' => array('options' => 'sanitize_search_string')
        )
    );

    validate_store_request_vars($filters, 'sess_autom_snmp');

    if (get_request_var('rows') == -1) {
        $rows = read_config_option('num_rows_table');
    } else {
        $rows = get_request_var('rows');
    }

    html_start_box(__('Automation SNMP Options'), '100%', '', '3', 'center', 'app.php/automation/snmp-options/new');

    ?>
	<tr class='even'>
		<td>
		<form id='snmp_form'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search');?>
					</td>
					<td>
						<input type='text' class='ui-state-default ui-corner-all' id='filter' size='25' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('SNMP Rules');?>
					</td>
                    <td>
                        <select id='rows'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>' : '>') . __('Default');?></option>
                            <?php
                            if (cacti_sizeof($item_rows)) {
                                foreach ($item_rows as $key => $value) {
                                    print "<option value='" . $key . "'";
                                    if (get_request_var('rows') == $key) {
                                        print ' selected';
                                    } print '>' . html_escape($value) . "</option>";
                                }
                            }
    ?>
                        </select>
                    </td>
					<td>
						<span>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='refresh' value='<?php print __esc('Go');?>' title='<?php print __esc('Set/Refresh Filters');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='clear' value='<?php print __esc('Clear');?>' title='<?php print __esc('Clear Filters');?>'>
						</span>
					</td>
				</tr>
			</table>
		</form>
		</td>
	</tr>
	<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>
	function applyFilter() {
		strURL  = 'automation_snmp.php?header=false';
		strURL += '&filter='+$('#filter').val();
		strURL += '&rows='+$('#rows').val();
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL = 'automation_snmp.php?clear=1&header=false';
		loadPageNoHeader(strURL);
	}

	$(function() {
		$('#refresh').on('click', function() {
			applyFilter();
		});

		$('#rows').on('change', function() {
			applyFilter();
		});

		$('#clear').on('click', function() {
			clearFilter();
		});

		$('#snmp_form').on('submit', function(event) {
			event.preventDefault();
			applyFilter();
		});
	});
	</script>
	<?php

    html_end_box();

    /* form the 'where' clause for our main sql query */
    if (get_request_var('filter') != '') {
        $sql_where = 'WHERE asnmp.name LIKE ' . db_qstr('%' . get_request_var('filter') . '%');
    } else {
        $sql_where = '';
    }

    $total_rows = db_fetch_cell("SELECT
		COUNT(DISTINCT asnmp.id)
		FROM automation_snmp AS asnmp
		LEFT JOIN automation_networks AS anw
		ON asnmp.id=anw.snmp_id
		LEFT JOIN automation_snmp_items AS asnmpi
		ON asnmp.id=asnmpi.snmp_id
		$sql_where
		GROUP BY asnmp.id");

    $sql_order = get_order_string();
    $sql_limit = ' LIMIT ' . ($rows * (get_request_var('page') - 1)) . ',' . $rows;

    $snmp_groups = db_fetch_assoc("SELECT asnmp.*, COUNT(anw.id) AS networks,
		COUNT(asnmpi.snmp_id) AS totals,
		SUM(CASE WHEN asnmpi.snmp_version=1 THEN 1 ELSE 0 END) AS v1entries,
		SUM(CASE WHEN asnmpi.snmp_version=2 THEN 1 ELSE 0 END) AS v2entries,
		SUM(CASE WHEN asnmpi.snmp_version=3 THEN 1 ELSE 0 END) AS v3entries
		FROM automation_snmp AS asnmp
		LEFT JOIN automation_networks AS anw
		ON asnmp.id=anw.snmp_id
		LEFT JOIN automation_snmp_items AS asnmpi
		ON asnmp.id=asnmpi.snmp_id
		$sql_where
		GROUP BY asnmp.id
		$sql_order
		$sql_limit");

    $nav = html_nav_bar('automation_snmp.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 12, __('SNMP Option Sets'), 'page', 'main');

    form_start('automation_snmp.php', 'chk');

    print $nav;

    html_start_box('', '100%', '', '3', 'center', '');

    $display_text = array(
        'name'      => array('display' => __('SNMP Option Set'), 'align' => 'left',  'sort' => 'ASC'),
        'networks'  => array('display' => __('Networks Using'),  'align' => 'right', 'sort' => 'DESC'),
        'totals'    => array('display' => __('SNMP Entries'),    'align' => 'right', 'sort' => 'DESC'),
        'v1entries' => array('display' => __('V1 Entries'),      'align' => 'right', 'sort' => 'DESC'),
        'v2entries' => array('display' => __('V2 Entries'),      'align' => 'right', 'sort' => 'DESC'),
        'v3entries' => array('display' => __('V3 Entries'),      'align' => 'right', 'sort' => 'DESC')
    );

    html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

    if (cacti_sizeof($snmp_groups)) {
        foreach ($snmp_groups as $snmp_group) {
            form_alternate_row('line' . $snmp_group['id'], true);

            form_selectable_cell(filter_value($snmp_group['name'], get_request_var('filter'), 'app.php/automation/snmp-options/' . $snmp_group['id'] . '/edit'), $snmp_group['id']);
            form_selectable_cell($snmp_group['networks'], $snmp_group['id'], '', 'text-align:right;');
            form_selectable_cell($snmp_group['totals'], $snmp_group['id'], '', 'text-align:right;');
            form_selectable_cell($snmp_group['v1entries'], $snmp_group['id'], '', 'text-align:right;');
            form_selectable_cell($snmp_group['v2entries'], $snmp_group['id'], '', 'text-align:right;');
            form_selectable_cell($snmp_group['v3entries'], $snmp_group['id'], '', 'text-align:right;');
            form_checkbox_cell($snmp_group['name'], $snmp_group['id']);

            form_end_row();
        }
    } else {
        print "<tr class='tableRow'><td colspan='" . (cacti_sizeof($display_text) + 1) . "'><em>" . __('No SNMP Option Sets Found') . "</em></td></tr>";
    }

    html_end_box(false);

    if (cacti_sizeof($snmp_groups)) {
        print $nav;
    }

    /* draw the dropdown containing a list of available actions for this form */
    draw_actions_dropdown($automation_snmp_actions);

    form_end();

    ?>
	<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>
	function applyFilter() {
		strURL  = 'automation_snmp.php?header=false&rows=' + $('#rows').val();
		strURL += strURL + '&filter=' + $('#filter').val();
		loadPageNoHeader(strURL);
	}
	</script>
	<?php
}
