<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use InvalidArgumentException;

/** Preserves each legacy permission page's ordered filter and session contract. */
final class PermissionRequests
{
    public static function process(bool $group, string $kind): void
    {
        $choices = $group
            ? array('graph' => array('sess_ugg', 'graph_template_id'), 'device' => array('sess_ugd', 'host_template_id'), 'template' => array('sess_ugte', 'host_template_id'), 'tree' => array('sess_ugtr', ''), 'member' => array('sess_ugm', ''))
            : array('graph' => array('sess_uag', 'graph_template_id'), 'group' => array('sess_uagr', ''), 'device' => array('sess_uad', 'host_template_id'), 'template' => array('sess_uate', 'graph_template_id'), 'tree' => array('sess_uatr', 'graph_template_id'));
        if (!isset($choices[$kind])) {
            throw new InvalidArgumentException('Unknown permission filter context.');
        }
        [$prefix, $selector] = $choices[$kind];
        $filters = array(
            'rows' => array('filter' => FILTER_VALIDATE_INT, 'pageset' => true, 'default' => read_config_option('num_rows_table')),
            'page' => array('filter' => FILTER_VALIDATE_INT, 'default' => '1'),
            'filter' => array('filter' => FILTER_DEFAULT, 'pageset' => true, 'default' => ''),
        );
        $selectorOptions = array('filter' => FILTER_VALIDATE_INT, 'pageset' => true, 'default' => '-1');
        // User selectors precede associated; group selectors follow it. The group
        // template page intentionally retains its existing host-template selector.
        if (!$group && $selector !== '') {
            $filters[$selector] = $selectorOptions;
        }
        $filters['associated'] = array(
            'filter' => FILTER_VALIDATE_REGEXP,
            'options' => array('options' => array('regexp' => '(true|false)')),
            'pageset' => true,
            'default' => 'true'
        );
        if ($group && $selector !== '') {
            $filters[$selector] = $selectorOptions;
        }
        validate_store_request_vars($filters, $prefix);
    }
}
