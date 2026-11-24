<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/lib/auth.php';

function read_config_option($name, $force = false)
{
    $GLOBALS['reads'][] = $name;
    return $GLOBALS['scenario']['settings'][$name] ?? '';
}

function __($text, ...$arguments)
{
    $GLOBALS['messages'][] = array($text, $arguments);
    return ($GLOBALS['scenario']['translation_prefix'] ?? '') . vsprintf($text, $arguments);
}

$results = array();
foreach (json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) as $scenario) {
    $GLOBALS['scenario'] = $scenario;
    $GLOBALS['reads'] = array();
    $GLOBALS['messages'] = array();
    $result = secpass_check_pass($scenario['password']);
    $results[] = array('result' => $result, 'reads' => $GLOBALS['reads'], 'messages' => $GLOBALS['messages']);
}
print json_encode($results, JSON_THROW_ON_ERROR);
