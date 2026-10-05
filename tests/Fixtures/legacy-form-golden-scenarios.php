<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Scenarios for tests/Unit/Forms/LegacyFormGoldenTest.php.
//
// 'methods' draws field arrays through draw_edit_form(), one or more per
// field method used on main plus the generated and fallback ones. 'pages'
// runs each track A edit form from the forms plan inventory and each settings
// tab as the browser requests it, with header=false. Page scenarios start
// from an empty database except for the signed-in administrator, so most
// render the "create" form; the rows below are the least a page needs to
// draw its form at all.

$description = 'Plain text, <b>bold</b> & <script>alert(1)</script>a <a href="https://example.com/">link</a>.';
$special = "a'b\"c<d>&e";

$field = static function (string $method, array $extra = array()) use ($description): array {
    return $extra + array('method' => $method, 'friendly_name' => 'Field ' . $method, 'description' => $description);
};

$form = static function (array $fields, array $config = array('no_form_tag' => true)): array {
    return array('config' => $config, 'fields' => $fields);
};

$administrator = array(
    'id' => '1', 'username' => 'admin', 'password' => 'stored-password-hash', 'realm' => '0', 'full_name' => 'Administrator',
    'email_address' => 'admin@example.com', 'must_change_password' => '', 'password_change' => 'on', 'show_tree' => 'on',
    'show_list' => 'on', 'show_preview' => 'on', 'graph_settings' => 'on', 'login_opts' => '1', 'policy_graphs' => '1',
    'policy_trees' => '1', 'policy_hosts' => '1', 'policy_graph_templates' => '1', 'enabled' => 'on', 'lastchange' => '-1',
    'lastlogin' => '-1', 'password_history' => '-1', 'locked' => '', 'failed_attempts' => '0', 'lastfail' => '0',
    'reset_perms' => '0', 'tfa_enabled' => '', 'tfa_secret' => '',
);

// The current user, read by include/auth.php and by is_realm_allowed().
$user_rows = array(
    array('sql' => 'FROM user_auth WHERE id = ?', 'params' => array(1), 'rows' => array($administrator)),
);

// global_item_edit() lists these columns as rule fields.
$columns = array(
    array('sql' => 'SHOW COLUMNS FROM host_template', 'rows' => array(
        array('Field' => 'id', 'Type' => 'mediumint(8) unsigned'), array('Field' => 'hash', 'Type' => 'varchar(32)'),
        array('Field' => 'name', 'Type' => 'varchar(100)'), array('Field' => 'class', 'Type' => 'varchar(40)'),
    )),
    array('sql' => 'SHOW COLUMNS FROM host', 'rows' => array(
        array('Field' => 'id', 'Type' => 'mediumint(8) unsigned'), array('Field' => 'host_template_id', 'Type' => 'mediumint(8) unsigned'),
        array('Field' => 'description', 'Type' => 'varchar(150)'), array('Field' => 'hostname', 'Type' => 'varchar(100)'),
    )),
);

$page = static function (string $page, array $request, array $db = array(), array $extra = array()) use ($user_rows): array {
    return $extra + array('page' => $page, 'request' => $request + array('header' => 'false'), 'db' => array_merge($db, $user_rows));
};

$methods = array(
    'textbox-default' => array('form' => $form(array(
        'name' => $field('textbox', array('value' => '', 'default' => 'Default value', 'max_length' => '50')),
        'no_limit' => $field('textbox', array('value' => '', 'max_length' => '', 'size' => '20')),
    ))),
    'textbox-stored' => array('form' => $form(array(
        'name' => $field('textbox', array('value' => $special, 'default' => 'Default value', 'max_length' => '50', 'size' => '30', 'form_id' => '1', 'placeholder' => 'Enter <name>')),
        'empty_saved' => $field('textbox', array('value' => '', 'default' => 'Default value', 'max_length' => '10', 'form_id' => '1')),
    ))),
    'textbox-error' => array(
        'form' => $form(array('name' => $field('textbox', array('value' => 'saved', 'max_length' => '50', 'form_id' => '1')))),
        'session' => array('sess_error_fields' => array('name' => 'name'), 'sess_field_values' => array('name' => "submitted'<")),
    ),
    'textbox-sub-checkbox' => array('form' => $form(array(
        'name' => $field('textbox', array('value' => 'saved', 'max_length' => '50', 'form_id' => '1', 'sub_checkbox' => array('name' => 'name_enabled', 'value' => 'on', 'friendly_name' => 'Enabled'))),
    ))),
    'textbox_password-stored' => array('form' => $form(array(
        'secret' => $field('textbox_password', array('value' => 'stored-secret', 'default' => '', 'max_length' => '64', 'size' => '20', 'form_id' => '1')),
    ))),
    'textbox_password-error' => array(
        'form' => $form(array('secret' => $field('textbox_password', array('value' => 'stored-secret', 'max_length' => '64', 'form_id' => '1')))),
        'session' => array('sess_error_fields' => array('secret' => 'secret', 'secret_confirm' => 'secret_confirm'), 'sess_field_values' => array('secret' => 'typed', 'secret_confirm' => 'mistyped')),
    ),
    'textarea-default' => array('form' => $form(array(
        'notes' => $field('textarea', array('value' => '', 'default' => "line 1\nline 2", 'textarea_rows' => '4', 'textarea_cols' => '50')),
    ))),
    'textarea-stored' => array('form' => $form(array(
        'notes' => $field('textarea', array('value' => $special . "\n</textarea>", 'textarea_rows' => '3', 'textarea_cols' => '40', 'class' => 'monoSpace', 'on_change' => 'notesChanged()', 'placeholder' => 'Notes')),
    ))),
    'textarea-error' => array(
        'form' => $form(array('notes' => $field('textarea', array('value' => 'saved', 'textarea_rows' => '3', 'textarea_cols' => '40')))),
        'session' => array('sess_error_fields' => array('notes' => 'notes'), 'sess_field_values' => array('notes' => 'submitted')),
    ),
    'drop_array-selected' => array('form' => $form(array(
        'choice' => $field('drop_array', array('value' => '2', 'array' => array('1' => 'One', '2' => 'Two & <2>', 'x' => $special))),
    ))),
    'drop_array-default' => array('form' => $form(array(
        'choice' => $field('drop_array', array('value' => '', 'default' => 'x', 'array' => array('1' => 'One', 'x' => 'Ex'), 'class' => 'wide', 'on_change' => 'choiceChanged()')),
        'with_none' => $field('drop_array', array('value' => '0', 'none_value' => 'None', 'array' => array('1' => 'One'))),
    ))),
    'drop_array-error' => array(
        'form' => $form(array('choice' => $field('drop_array', array('value' => '1', 'array' => array('1' => 'One', '2' => 'Two'))))),
        'session' => array('sess_error_fields' => array('choice' => 'choice'), 'sess_field_values' => array('choice' => '2')),
    ),
    'drop_sql-selected' => array(
        'form' => $form(array(
            'host_id' => $field('drop_sql', array('value' => '7', 'none_value' => 'None', 'sql' => 'SELECT id, description AS name FROM host ORDER BY name')),
            'empty_list' => $field('drop_sql', array('value' => '', 'default' => '0', 'sql' => 'SELECT id, name FROM site ORDER BY name')),
        )),
        'db' => array(array('sql' => 'FROM host ORDER BY name', 'rows' => array(array('id' => '3', 'name' => 'Router <3>'), array('id' => '7', 'name' => $special)))),
    ),
    'drop_sql-error' => array(
        'form' => $form(array('host_id' => $field('drop_sql', array('value' => '3', 'class' => 'hosts', 'on_change' => 'hostChanged()', 'sql' => 'SELECT id, description AS name FROM host ORDER BY name')))),
        'db' => array(array('sql' => 'FROM host ORDER BY name', 'rows' => array(array('id' => '3', 'name' => 'Three'), array('id' => '7', 'name' => 'Seven')))),
        'session' => array('sess_error_fields' => array('host_id' => 'host_id'), 'sess_field_values' => array('host_id' => '7')),
    ),
    'drop_callback-autocomplete' => array('form' => $form(array(
        'host_id' => $field('drop_callback', array('value' => $special, 'id' => '7', 'none_value' => 'None', 'action' => 'ajax_hosts', 'on_change' => 'hostChanged()', 'sql' => 'SELECT id, description AS name FROM host ORDER BY name')),
        'site_id' => $field('drop_callback', array('value' => '', 'id' => '0', 'none_value' => 'Any', 'action' => 'ajax_sites', 'sql' => 'SELECT id, name FROM sites ORDER BY name')),
    ))),
    'drop_callback-classic' => array(
        'form' => $form(array('host_id' => $field('drop_callback', array('value' => 'Seven', 'id' => '7', 'none_value' => 'None', 'action' => 'ajax_hosts', 'sql' => 'SELECT id, description AS name FROM host ORDER BY name')))),
        'settings' => array('selected_theme' => 'classic'),
        'db' => array(array('sql' => 'FROM host ORDER BY name', 'rows' => array(array('id' => '3', 'name' => 'Three'), array('id' => '7', 'name' => 'Seven')))),
    ),
    'drop_multi-values' => array(
        'form' => $form(array(
            'listed' => $field('drop_multi', array('value' => '1,3', 'array' => array('1' => 'One', '2' => 'Two', '3' => 'Three & <3>'))),
            'from_sql' => $field('drop_multi', array('array' => array('1' => 'One', '2' => 'Two'), 'sql' => 'SELECT id FROM user_auth_group_members WHERE user_id = 1')),
            'from_settings' => $field('drop_multi', array('value' => '', 'array' => array('a' => 'A', 'b' => 'B'), 'class' => 'ignored', 'on_change' => 'multiChanged()')),
        )),
        'db' => array(array('sql' => 'FROM user_auth_group_members', 'rows' => array(array('id' => '2')))),
    ),
    // A populated same-name setting reaches the PHP 8 string append error in #701.
    'drop_multi-settings-failure' => array(
        'form' => $form(array('from_settings' => $field('drop_multi', array('value' => '', 'array' => array('a' => 'A', 'b' => 'B'))))),
        'settings' => array('from_settings' => 'b'),
        'expected_exception' => array('class' => 'Error', 'message' => '[] operator not supported for strings'),
    ),
    'drop_tree-selected' => array(
        'form' => $form(array('parent_item_id' => $field('drop_tree', array('value' => '12', 'tree_id' => '4')))),
        'require' => array('lib/html_tree.php'),
        'db' => array(
            array('sql' => 'FROM graph_tree_items AS gti WHERE gti.graph_tree_id = ?', 'params' => array(4, 0), 'rows' => array(array('id' => '11', 'title' => 'Branch <A>', 'parent' => '0'))),
            array('sql' => 'FROM graph_tree_items AS gti WHERE gti.graph_tree_id = ?', 'params' => array(4, 11), 'rows' => array(array('id' => '12', 'title' => 'Leaf', 'parent' => '11'))),
        ),
    ),
    'drop_color-selected' => array(
        'form' => $form(array(
            'color_id' => $field('drop_color', array('value' => '9', 'on_change' => 'colorChanged()')),
            'other_color' => $field('drop_color', array('value' => '', 'default' => '0', 'class' => 'small')),
        )),
        'db' => array(
            array('sql' => 'SELECT hex FROM colors WHERE id = ?', 'params' => array(9), 'rows' => array(array('hex' => 'FF0000'))),
            array('sql' => 'FROM colors ORDER BY', 'rows' => array(array('id' => '5', 'hex' => 'FFFFFF', 'name' => 'White'), array('id' => '9', 'hex' => 'FF0000', 'name' => ''))),
        ),
    ),
    'drop_language-selected' => array('form' => $form(array(
        'user_language' => $field('drop_language', array('value' => 'de-DE', 'default' => 'en-US', 'on_change' => 'languageChanged()')),
    ))),
    'drop_files-listed' => array(
        'form' => $form(array('contentfile' => $field('drop_files', array('value' => 'b.html', 'none_value' => 'None', 'directory' => '<DIR>/content', 'exclusions' => array('skip.html'))))),
        'files' => array('content/a.html', 'content/b.html', 'content/skip.html'),
    ),
    'checkbox-states' => array('form' => $form(array(
        'stored_on' => $field('checkbox', array('value' => 'on', 'default' => '', 'form_id' => '1')),
        'stored_off' => $field('checkbox', array('value' => '', 'default' => 'on', 'form_id' => '1')),
        'default_on' => $field('checkbox', array('value' => '', 'default' => 'on', 'class' => 'extra', 'on_change' => 'toggled()')),
    ))),
    'checkbox-error' => array(
        'form' => $form(array('stored_off' => $field('checkbox', array('value' => '', 'form_id' => '1')))),
        'session' => array('sess_error_fields' => array('stored_off' => 'stored_off'), 'sess_field_values' => array('stored_off' => 'on')),
    ),
    'checkbox_group-plain' => array('form' => $form(array(
        'options' => $field('checkbox_group', array('items' => array(
            'option_a' => array('value' => 'on', 'friendly_name' => 'Option <A>', 'form_id' => '1'),
            'option_b' => array('value' => '', 'friendly_name' => 'Option B', 'default' => 'on', 'on_change' => 'optionB()'),
        ))),
    ))),
    'checkbox_group-flex' => array('form' => $form(array(
        'options' => $field('checkbox_group', array('type' => 'flex', 'class' => 'grouped', 'on_change' => 'groupChanged()', 'items' => array(
            'option_a' => array('value' => '', 'friendly_name' => 'Option A', 'form_id' => '1'),
            'option_b' => array('value' => 'on', 'friendly_name' => 'Option B', 'form_id' => '1'),
        ))),
    ))),
    'radio-selected' => array('form' => $form(array(
        'mode' => $field('radio', array('value' => '2', 'default' => '1', 'on_change' => 'modeChanged()', 'items' => array(
            array('radio_value' => '1', 'radio_caption' => 'First <1>'),
            array('radio_value' => '2', 'radio_caption' => 'Second'),
        ))),
        'fallback' => $field('radio', array('value' => '', 'default' => 'b', 'class' => 'radios', 'items' => array(
            array('radio_value' => 'a', 'radio_caption' => 'A'),
            array('radio_value' => 'b', 'radio_caption' => 'B'),
        ))),
    ))),
    'spacer-headers' => array('form' => $form(array(
        'plain_header' => array('method' => 'spacer', 'friendly_name' => 'Header <one>'),
        'collapsible_header' => array('method' => 'spacer', 'friendly_name' => 'Header two', 'collapsible' => 'true', 'description' => $description),
        'after' => $field('textbox', array('value' => 'x', 'max_length' => '5')),
    ))),
    'hidden-values' => array('form' => $form(array(
        'id' => array('method' => 'hidden', 'value' => $special),
        'from_default' => array('method' => 'hidden', 'default' => 'fallback'),
        'missing' => array('method' => 'hidden'),
        'empty_with_default' => array('method' => 'hidden', 'value' => '', 'default' => 'used'),
    ))),
    'hidden_zero-values' => array('form' => $form(array(
        'local_graph_id' => array('method' => 'hidden_zero', 'value' => '42'),
        'missing' => array('method' => 'hidden_zero'),
        'empty' => array('method' => 'hidden_zero', 'value' => ''),
    ))),
    'filepath-states' => array(
        'form' => $form(array(
            'found' => $field('filepath', array('value' => '<DIR>/bin/tool', 'max_length' => '255', 'form_id' => '1')),
            'directory' => $field('filepath', array('value' => '<DIR>/bin', 'max_length' => '255', 'form_id' => '1')),
            'missing' => $field('filepath', array('value' => '<DIR>/bin/none', 'max_length' => '', 'size' => '60', 'form_id' => '1')),
            'empty' => $field('filepath', array('value' => '', 'default' => '', 'max_length' => '255')),
        )),
        'files' => array('bin/tool'),
    ),
    'filepath-error' => array(
        'form' => $form(array('found' => $field('filepath', array('value' => 'saved', 'max_length' => '255', 'form_id' => '1')))),
        'session' => array('sess_error_fields' => array('found' => 'found'), 'sess_field_values' => array('found' => '<DIR>/bin/tool')),
        'files' => array('bin/tool'),
    ),
    'dirpath-states' => array(
        'form' => $form(array(
            'found' => $field('dirpath', array('value' => '<DIR>/rra', 'max_length' => '255', 'form_id' => '1')),
            'file' => $field('dirpath', array('value' => '<DIR>/rra/file', 'max_length' => '255', 'form_id' => '1')),
            'missing' => $field('dirpath', array('value' => '<DIR>/none', 'max_length' => '255', 'form_id' => '1')),
            'empty' => $field('dirpath', array('value' => '', 'max_length' => '255')),
        )),
        'files' => array('rra/file'),
    ),
    'dirpath-error' => array(
        'form' => $form(array('found' => $field('dirpath', array('value' => 'saved', 'max_length' => '255', 'form_id' => '1')))),
        'session' => array('sess_error_fields' => array('found' => 'found'), 'sess_field_values' => array('found' => '<DIR>')),
    ),
    'font-states' => array('form' => $form(array(
        'title_font' => $field('font', array('value' => 'DejaVu Sans Bold 10', 'max_length' => '100', 'form_id' => '1', 'placeholder' => 'Font')),
        'legend_font' => $field('font', array('value' => '', 'default' => '', 'max_length' => '100', 'size' => '25')),
    ))),
    'font-error' => array(
        'form' => $form(array('title_font' => $field('font', array('value' => 'saved', 'max_length' => '100', 'form_id' => '1')))),
        'session' => array('sess_error_fields' => array('title_font' => 'title_font'), 'sess_field_values' => array('title_font' => 'Submitted 12')),
    ),
    'file-upload' => array('form' => $form(array(
        'import_file' => $field('file', array('accept' => '.xml,.gz', 'size' => '50')),
        'plain_file' => $field('file'),
    ))),
    'file-error' => array(
        'form' => $form(array('import_file' => $field('file', array('accept' => '.xml')))),
        'session' => array('sess_error_fields' => array('import_file' => 'import_file')),
    ),
    // A button's click handler is the only thing that makes draw_edit_form()
    // print the queued change handlers; see the form_change_action check.
    'button-click-and-change' => array('form' => $form(array(
        'choice' => $field('drop_array', array('value' => '1', 'array' => array('1' => 'One'), 'on_change' => 'choiceChanged()')),
        'refresh' => $field('button', array('value' => 'Refresh <now>', 'title' => 'Reload "list"', 'on_click' => 'refreshList()')),
        'plain' => $field('button', array('value' => 'Plain')),
    ))),
    'submit-button' => array('form' => $form(array(
        'go' => $field('submit', array('value' => 'Go & see', 'title' => 'Apply')),
        'save' => $field('submit', array('value' => 'Save', 'on_click' => 'saveClicked()')),
    ))),
    'display-only-methods' => array('form' => $form(array(
        'other_field' => $field('other', array('value' => $special)),
        'view_field' => $field('view', array('value' => 'Viewed')),
        'value_field' => $field('value', array('value' => '0')),
        'template_textbox' => $field('template_textbox', array('value' => 'From template')),
        'plugin_widget' => $field('plugin_widget', array('value' => 'Plugin value')),
        'no_value' => $field('other'),
    ))),
    'template-methods' => array('form' => $form(array(
        'template_checkbox' => $field('template_checkbox', array('value' => 'on')),
        'template_checkbox_off' => $field('template_checkbox', array('value' => '')),
        'template_drop_array' => $field('template_drop_array', array('value' => '2', 'array' => array('1' => 'One', '2' => 'Two <2>'))),
    ))),
    'custom-passthrough' => array('form' => $form(array(
        'custom_field' => $field('custom', array('value' => "<span class='custom'>Raw <b>markup</b></span>")),
    ))),
    'config-force-row-color' => array('form' => $form(array(
        'first' => $field('textbox', array('value' => 'a', 'max_length' => '5')),
        'second' => $field('textbox', array('value' => 'b', 'max_length' => '5')),
        'third' => $field('checkbox', array('value' => 'on', 'form_id' => '1')),
    ), array('no_form_tag' => true, 'force_row_color' => true))),
    'config-hide-descriptions' => array(
        'form' => $form(array(
            'first' => $field('textbox', array('value' => 'a', 'max_length' => '5')),
            'no_description' => array('method' => 'textbox', 'friendly_name' => 'No description', 'value' => 'b', 'max_length' => '5'),
        )),
        'settings' => array('hide_form_description' => 'on'),
    ),
    // Plugins may still let draw_edit_form() open its own form.
    'config-own-form-tag' => array('form' => $form(array(
        'name' => $field('textbox', array('value' => 'x', 'max_length' => '5')),
    ), array('post_to' => "plugin.php?a=1&b='2'", 'form_name' => 'plugin_form', 'enctype' => 'multipart/form-data'))),
    'config-own-form-tag-default' => array(
        'form' => $form(array('name' => $field('textbox', array('value' => 'x', 'max_length' => '5'))), array()),
        'page' => 'plugin_page.php',
    ),
);

$pages = array(
    'aggregate_graphs-edit' => $page('aggregate_graphs.php', array('action' => 'edit', 'id' => '42'), array(
        array('sql' => 'FROM graph_templates_graph WHERE local_graph_id = ?', 'params' => array(42), 'rows' => array(array(
            'id' => '5', 'local_graph_template_graph_id' => '0', 'local_graph_id' => '42', 'graph_template_id' => '0',
            't_image_format_id' => '', 'image_format_id' => '1', 't_title' => '', 'title' => 'Aggregate <one>', 'title_cache' => 'Aggregate <one>',
            't_height' => '', 'height' => '200', 't_width' => '', 'width' => '700', 't_upper_limit' => '', 'upper_limit' => '100',
            't_lower_limit' => '', 'lower_limit' => '0', 't_vertical_label' => '', 'vertical_label' => 'bits', 't_slope_mode' => '',
            'slope_mode' => 'on', 't_auto_scale' => '', 'auto_scale' => 'on', 't_auto_scale_opts' => '', 'auto_scale_opts' => '2',
            't_auto_scale_log' => '', 'auto_scale_log' => '', 't_scale_log_units' => '', 'scale_log_units' => '', 't_auto_scale_rigid' => '',
            'auto_scale_rigid' => '', 't_auto_padding' => '', 'auto_padding' => 'on', 't_base_value' => '', 'base_value' => '1000',
            't_grouping' => '', 'grouping' => '', 't_unit_value' => '', 'unit_value' => '', 't_unit_exponent_value' => '',
            'unit_exponent_value' => '', 't_alt_y_grid' => '', 'alt_y_grid' => '', 't_right_axis' => '', 'right_axis' => '',
            't_right_axis_label' => '', 'right_axis_label' => '', 't_right_axis_format' => '', 'right_axis_format' => '0',
            't_right_axis_formatter' => '', 'right_axis_formatter' => '0', 't_left_axis_formatter' => '', 'left_axis_formatter' => '0',
            't_no_gridfit' => '', 'no_gridfit' => '', 't_unit_length' => '', 'unit_length' => '', 't_tab_width' => '', 'tab_width' => '30',
            't_dynamic_labels' => '', 'dynamic_labels' => '', 't_force_rules_legend' => '', 'force_rules_legend' => '',
            't_legend_position' => '', 'legend_position' => '', 't_legend_direction' => '', 'legend_direction' => '',
        ))),
        array('sql' => 'FROM aggregate_graphs WHERE local_graph_id = ?', 'params' => array(42), 'rows' => array(array(
            'id' => '9', 'aggregate_template_id' => '0', 'template_propogation' => '', 'local_graph_id' => '42', 'title_format' => 'Aggregate <one>',
            'graph_template_id' => '3', 'gprint_prefix' => '', 'gprint_format' => '', 'graph_type' => '0', 'total' => '0', 'total_type' => '1',
            'total_prefix' => '', 'order_type' => '1', 'created' => '2026-01-01 00:00:00', 'user_id' => '1',
        ))),
    )),
    'auth_profile' => $page('auth_profile.php', array()),
    // managers.php queues these and nothing prints them; the next form the
    // user opens inherits them.
    'auth_profile-stale-change-handlers' => $page('auth_profile.php', array(), array(), array('session' => array('form_change_actions' => array(
        'snmp_version' => 'setSNMP()', 'snmp_security_level' => 'setSNMP()', 'snmp_auth_protocol' => 'setSNMP()', 'snmp_priv_protocol' => 'setSNMP()',
    )))),
    'automation_graph_rules-edit' => $page('automation_graph_rules.php', array('action' => 'edit')),
    'automation_graph_rules-item_edit' => $page('automation_graph_rules.php', array('action' => 'item_edit', 'id' => '1', 'rule_type' => '1'), array_merge($columns, array(
        array('sql' => 'FROM automation_graph_rules WHERE id = ?', 'params' => array(1), 'rows' => array(array(
            'id' => '1', 'name' => 'Traffic <rule>', 'snmp_query_id' => '1', 'graph_type_id' => '2', 'enabled' => 'on',
        ))),
    ))),
    'automation_networks-edit' => $page('automation_networks.php', array('action' => 'edit')),
    'automation_snmp-edit' => $page('automation_snmp.php', array('action' => 'edit')),
    'automation_snmp-item_edit' => $page('automation_snmp.php', array('action' => 'item_edit', 'id' => '1'), array(
        array('sql' => 'FROM automation_snmp WHERE id = ?', 'params' => array(1), 'rows' => array(array('id' => '1', 'name' => 'Default <snmp>'))),
    )),
    'automation_templates-edit' => $page('automation_templates.php', array('action' => 'edit')),
    'automation_tree_rules-edit' => $page('automation_tree_rules.php', array('action' => 'edit')),
    'automation_tree_rules-item_edit' => $page('automation_tree_rules.php', array('action' => 'item_edit', 'id' => '1', 'rule_type' => '3'), array_merge($columns, array(
        array('sql' => 'FROM automation_tree_rules WHERE id = ?', 'params' => array(1), 'rows' => array(array(
            'id' => '1', 'name' => 'Devices <rule>', 'tree_id' => '1', 'tree_item_id' => '0', 'leaf_type' => '3', 'host_grouping_type' => '1', 'enabled' => 'on',
        ))),
    ))),
    'data_queries-edit' => $page('data_queries.php', array('action' => 'edit')),
    'data_queries-item_edit' => $page('data_queries.php', array('action' => 'item_edit', 'snmp_query_id' => '1')),
    'data_source_profiles-edit' => $page('data_source_profiles.php', array('action' => 'edit')),
    'data_source_profiles-item_edit' => $page('data_source_profiles.php', array('action' => 'item_edit', 'profile_id' => '1')),
    // Match the rendered Add link: Any listing scope opens a new None/device-less source.
    'data_sources-ds_edit' => $page('data_sources.php', array('action' => 'ds_edit', 'host_id' => '0'), array(
        array('sql' => 'SELECT id, name FROM data_template ORDER BY name', 'rows' => array(array('id' => '4', 'name' => 'Interface - Traffic <in/out>'), array('id' => '9', 'name' => 'Unix - Load Average'))),
    )),
    'data_templates-template_edit' => $page('data_templates.php', array('action' => 'template_edit')),
    'graph_templates-template_edit' => $page('graph_templates.php', array('action' => 'template_edit')),
    'graph_templates_inputs-input_edit' => $page('graph_templates_inputs.php', array('action' => 'input_edit', 'graph_template_id' => '1')),
    'graph_templates_items-item_edit' => $page('graph_templates_items.php', array('action' => 'item_edit', 'graph_template_id' => '1')),
    'graphs-graph_edit' => $page('graphs.php', array('action' => 'graph_edit'), array(
        array('sql' => 'FROM graph_templates AS gt WHERE id NOT IN', 'rows' => array(array('id' => '3', 'name' => 'Interface - Traffic <bits>'), array('id' => '8', 'name' => 'Unix - Load Average'))),
    )),
    'graphs_items-item_edit' => $page('graphs_items.php', array('action' => 'item_edit', 'local_graph_id' => '1'), array(
        // The actual authorization and ownership lookups precede this new-item renderer.
        array('sql' => 'SELECT COUNT(*) FROM graph_templates_graph AS gtg', 'rows' => array(array('COUNT(*)' => '1'))),
        array('sql' => 'SELECT id, host_id, graph_template_id FROM graph_local WHERE id = ?', 'params' => array(1), 'rows' => array(array('id' => '1', 'host_id' => '0', 'graph_template_id' => '0'))),
    )),
    'reports_admin-edit' => $page('reports_admin.php', array('action' => 'edit')),
    'reports_admin-item_edit' => $page('reports_admin.php', array('action' => 'item_edit', 'id' => '4'), array(
        array('sql' => 'SELECT user_id FROM reports WHERE id = ?', 'params' => array(4), 'rows' => array(array('user_id' => '1'))),
        array('sql' => 'SELECT id FROM reports WHERE id = ?', 'params' => array(4), 'rows' => array(array('id' => '4'))),
        array('sql' => 'FROM reports WHERE id = ?', 'params' => array(4), 'rows' => array(array(
            'id' => '4', 'user_id' => '1', 'name' => 'Daily <report>', 'cformat' => 'on', 'format_file' => 'default.format', 'font_size' => '16',
            'alignment' => '0', 'graph_linked' => 'on', 'intrvl' => '2', 'count' => '1', 'offset' => '0', 'mailtime' => '1767225600',
            'subject' => 'Report', 'from_name' => 'Kadupul', 'from_email' => 'reports@example.com', 'email' => 'ops@example.com', 'bcc' => '',
            'attachment_type' => '1', 'graph_height' => '150', 'graph_width' => '500', 'graph_columns' => '1', 'thumbnails' => '',
            'lastsent' => '0', 'enabled' => 'on',
        ))),
    )),
    'managers-edit' => $page('managers.php', array('action' => 'edit')),
    'package_import' => $page('package_import.php', array()),
    'templates_export' => $page('templates_export.php', array()),
    'templates_import' => $page('templates_import.php', array()),
    'user_admin-user_edit' => $page('user_admin.php', array('action' => 'user_edit')),
    'user_admin-user_edit-existing' => $page('user_admin.php', array('action' => 'user_edit', 'id' => '1')),
    // The save failed: the fields it flagged come back highlighted, holding
    // what was typed.
    'user_admin-user_edit-failed-submit' => $page('user_admin.php', array('action' => 'user_edit', 'id' => '1'), array(), array('session' => array(
        'sess_error_fields' => array('username' => 'username', 'email_address' => 'email_address'),
        'sess_field_values' => array('username' => "taken'<name>", 'full_name' => 'Typed name', 'email_address' => 'not-an-address'),
    ))),
    'user_admin-settings' => $page('user_admin.php', array('action' => 'user_edit', 'tab' => 'settings', 'id' => '1'), array(
        array('sql' => 'FROM data_source_profiles_rra ORDER BY steps', 'rows' => array(array('id' => '1', 'name' => 'Daily (5 Minute Average)'), array('id' => '2', 'name' => 'Weekly (30 Minute Average)'))),
        array('sql' => 'FROM graph_tree ORDER BY name', 'rows' => array(array('id' => '1', 'name' => 'Default <tree>'), array('id' => '2', 'name' => 'Servers'))),
    )),
    'user_domains-edit' => $page('user_domains.php', array('action' => 'edit')),
    'user_group_admin-edit' => $page('user_group_admin.php', array('action' => 'edit')),
    'user_group_admin-settings' => $page('user_group_admin.php', array('action' => 'edit', 'tab' => 'settings', 'id' => '1'), array(
        array('sql' => 'FROM user_auth_group WHERE id = ?', 'params' => array(1), 'rows' => array(array(
            'id' => '1', 'name' => 'Operators <ops>', 'description' => 'On call', 'graph_settings' => 'on', 'login_opts' => '1', 'show_tree' => 'on',
            'show_list' => 'on', 'show_preview' => 'on', 'policy_graphs' => '1', 'policy_trees' => '1', 'policy_hosts' => '1',
            'policy_graph_templates' => '1', 'enabled' => 'on',
        ))),
    )),
);

// Two path defaults point at files a working copy may or may not have. The
// stored passwords show what the tabs print for them today.
$stored = array(
    'settings' => array(
        'path_stderrlog' => '<DIR>/cacti_stderr.log',
        'rrd_archive' => '<DIR>/archive/',
        'settings_smtp_password' => 'stored-smtp-secret',
        'snmp_password' => 'stored-snmp-secret',
        'snmp_priv_passphrase' => 'stored-snmp-passphrase',
        'ldap_specific_password' => 'stored-ldap-secret',
    ),
    'files' => array('cacti_stderr.log'),
);
foreach (array('general', 'path', 'snmp', 'poller', 'data', 'visual', 'authentication', 'boost', 'spikes', 'mail') as $tab) {
    $pages['settings-' . $tab] = $page('settings.php', array('tab' => $tab), array(), $stored);
}

return array('methods' => $methods, 'pages' => $pages);
