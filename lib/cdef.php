<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* get_cdef_item_name - resolves a single CDEF item into its text-based representation
   @arg $cdef_item_id - the id of the individual cdef item
   @returns - a text-based representation of the cdef item */
function get_cdef_item_name($cdef_item_id)
{
    global $cdef_functions, $cdef_operators;

    $cdef_item = db_fetch_row_prepared('SELECT type, value FROM cdef_items WHERE id = ?', array($cdef_item_id));
    $current_cdef_value = $cdef_item['value'];

    switch ($cdef_item['type']) {
        case '1': return $cdef_functions[$current_cdef_value];
            break;
        case '2': return $cdef_operators[$current_cdef_value];
            break;
        case '4': return $current_cdef_value;
            break;
        case '5': return db_fetch_cell_prepared('SELECT name FROM cdef WHERE id = ?', array($current_cdef_value));
            break;
        case '6': return $current_cdef_value;
            break;
    }
}

/* get_cdef - resolves an entire CDEF into its text-based representation for use in the RRDtool 'graph'
     string. this name will be resolved recursively if necessary
   @arg $cdef_id - the id of the cdef to resolve
   @returns - a text-based representation of the cdef */
function get_cdef($cdef_id)
{
    $cdef_items = db_fetch_assoc_prepared('SELECT id, type, value FROM cdef_items WHERE cdef_id = ? ORDER BY sequence', array($cdef_id));

    $i = 0;
    $cdef_string = '';

    if (cacti_sizeof($cdef_items) > 0) {
        foreach ($cdef_items as $cdef_item) {
            if ($i > 0) {
                $cdef_string .= ',';
            }
            if ($cdef_item['type'] == 5) {
                $current_cdef_id = $cdef_item['value'];
                $cdef_string .= get_cdef($current_cdef_id);
            } else {
                $cdef_string .= get_cdef_item_name($cdef_item['id']);
            }
            $i++;
        }
    }
    return $cdef_string;
}


function duplicate_cdef($_cdef_id, $cdef_title)
{
    global $fields_cdef_edit;

    $cdef       = db_fetch_row_prepared('SELECT * FROM cdef WHERE id = ?', array($_cdef_id));
    $cdef_items = db_fetch_assoc_prepared('SELECT * FROM cdef_items WHERE cdef_id = ?', array($_cdef_id));
    if (!$cdef) {
        return false;
    }

    /* substitute the title variable */
    $cdef['name'] = str_replace('<cdef_title>', $cdef['name'], $cdef_title);

    /* create new entry: host_template */
    $save['id']   = 0;
    $save['hash'] = get_hash_cdef(0);

    foreach ($fields_cdef_edit as $field => $array) {
        if (!preg_match('/^hidden/', $array['method'])) {
            $save[$field] = $cdef[$field];
        }
    }

    $cdef_id = sql_save($save, 'cdef');
    if ($cdef_id === false || !is_numeric($cdef_id) || (int) $cdef_id < 1) {
        return false;
    }

    /* create new entry(s): cdef_items */
    if (cacti_sizeof($cdef_items) > 0) {
        foreach ($cdef_items as $cdef_item) {
            unset($save);

            $save['id']       = 0;
            $save['hash']     = get_hash_cdef(0, 'cdef_item');
            $save['cdef_id']  = $cdef_id;
            $save['sequence'] = $cdef_item['sequence'];
            $save['type']     = $cdef_item['type'];
            $save['value']    = $cdef_item['value'];

            $cdef_item_id = sql_save($save, 'cdef_items');
            if ($cdef_item_id === false || !is_numeric($cdef_item_id) || (int) $cdef_item_id < 1) {
                return false;
            }
        }
    }
    return $cdef_id;
}
