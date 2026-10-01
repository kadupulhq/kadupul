<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use CactiSecureHeaders;

final class PermissionFilter
{
    /** Render the shared filter controls; callers retain their route and translations. */
    public static function render(string $page, string $action, string $tab, string $title, string $row_label, string $associated_label, string $template_field, string $go_label, string $clear_label, bool $apply_form_argument = false, bool $clear_form_argument = false): void
    {
        global $item_rows;

        $id = (int) get_request_var('id');
        $url = $page . '?action=' . $action . '&tab=' . $tab . '&id=' . $id;
        $json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $change_selector = '#rows' . ($template_field === '' ? '' : ', #' . $template_field);
        ?>
    <script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute(); ?>>
    function applyFilter(<?php print $apply_form_argument ? 'objForm' : ''; ?>) {
        strURL = <?php print json_encode($url, $json_flags); ?>;
        strURL += '&rows=' + $('#rows').val();
        <?php if ($template_field !== '') { ?>
        strURL += <?php print json_encode('&' . $template_field . '=', $json_flags); ?> + $(<?php print json_encode('#' . $template_field, $json_flags); ?>).val();
        <?php } ?>
        strURL += '&associated=' + $('#associated').is(':checked');
        strURL += '&filter=' + encodeURIComponent($('#filter').val());
        strURL += '&header=false';
        loadPageNoHeader(strURL);
    }
    function clearFilter(<?php print $clear_form_argument ? 'objForm' : ''; ?>) {
        strURL = <?php print json_encode($url . '&clear=true&header=false', $json_flags); ?>;
        loadPageNoHeader(strURL);
    }
    $(function() {
        $('#associated').on('click', function() { applyFilter(); });
        $('#clear').on('click', function() { clearFilter(); });
        $(<?php print json_encode($change_selector, $json_flags); ?>).on('change', function() { applyFilter(); });
        $('#forms').on('submit', function(event) {
            event.preventDefault();
            applyFilter();
        });
    });
    </script>
    <?php
        html_start_box($title, '100%', '', '3', 'center', '');
        ?>
    <tr class='even'><td>
    <form id='forms' action='<?php print html_escape($page); ?>'>
    <table class='filterTable' role='presentation'><tr>
        <td><label for='filter'><?php print __('Search'); ?></label></td>
        <td><input type='text' class='ui-state-default ui-corner-all' id='filter' size='25' value='<?php print htmlspecialchars((string) html_escape_request_var('filter'), ENT_QUOTES | ENT_HTML5, ini_get('default_charset') ?: 'UTF-8', false); ?>'></td>
        <?php if ($template_field !== '') { ?>
        <td><label for='<?php print html_escape($template_field); ?>'><?php print __('Template'); ?></label></td>
        <td><select id='<?php print html_escape($template_field); ?>'>
            <option value='-1'<?php print get_request_var($template_field) == '-1' ? ' selected' : ''; ?>><?php print __('Any'); ?></option>
            <option value='0'<?php print get_request_var($template_field) == '0' ? ' selected' : ''; ?>><?php print __('None'); ?></option>
            <?php
                $templates = $template_field === 'graph_template_id'
                    ? db_fetch_assoc('SELECT DISTINCT gt.id, gt.name
                    FROM graph_templates AS gt
                    INNER JOIN graph_local AS gl
                    ON gl.graph_template_id = gt.id
                    ORDER BY name')
                    : db_fetch_assoc('SELECT id, name FROM host_template ORDER BY name');
            foreach ($templates ?: array() as $template) {
                print "<option value='" . html_escape($template['id']) . "'";
                print get_request_var($template_field) == $template['id'] ? ' selected' : '';
                print '>' . html_escape($template['name']) . '</option>';
            }
            ?>
        </select></td>
        <?php } ?>
        <td><label for='rows'><?php print $row_label; ?></label></td>
        <td><select id='rows'>
            <option value='-1'<?php print get_request_var('rows') == '-1' ? ' selected' : ''; ?>><?php print __('Default'); ?></option>
            <?php
            foreach (cacti_sizeof($item_rows) ? $item_rows : array() as $key => $value) {
                print "<option value='" . html_escape($key) . "'";
                print get_request_var('rows') == $key ? ' selected' : '';
                print '>' . html_escape($value) . '</option>';
            }
        ?>
        </select></td>
        <td><span>
            <input type='checkbox' id='associated' <?php print get_request_var('associated') == 'true' || get_request_var('associated') == 'on' ? 'checked' : ''; ?>>
            <label for='associated'><?php print $associated_label; ?></label>
        </span></td>
        <td><span>
            <input type='submit' class='ui-button ui-corner-all ui-widget' id='go' value='<?php print html_escape($go_label); ?>' title='<?php print __esc('Set/Refresh Filters'); ?>'>
            <input type='button' class='ui-button ui-corner-all ui-widget' id='clear' value='<?php print html_escape($clear_label); ?>' title='<?php print __esc('Clear Filters'); ?>'>
        </span></td>
    </tr></table>
    <input type='hidden' name='action' value='<?php print html_escape($action); ?>'>
    <input type='hidden' name='tab' value='<?php print html_escape($tab); ?>'>
    <input type='hidden' name='id' value='<?php print html_escape($id); ?>'>
    </form></td></tr>
    <?php
        html_end_box();
    }
}
