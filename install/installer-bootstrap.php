<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Included only at global scope by the authenticated web entry or CLI worker.
if (!defined('IN_CACTI_INSTALL')) {
    http_response_code(404);
    exit;
}
foreach (['auth', 'api_automation_tools', 'api_automation', 'api_data_source', 'api_graph', 'api_device',
    'api_tree', 'data_query', 'import', 'poller', 'snmp', 'sort', 'template', 'utility', 'installer'] as $library) {
    require_once dirname(__DIR__) . '/lib/' . $library . '.php';
}
require_once __DIR__ . '/functions.php';
