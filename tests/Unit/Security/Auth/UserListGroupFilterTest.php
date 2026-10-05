<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The User Management list filters by group. The group value passed only a
 * search-string sanitizer, which keeps spaces and words, and was then pasted
 * into the WHERE clause of both list queries.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AdminActionProbe.php';

/*
 * user() draws the whole page. Every helper it calls that the probe does not
 * provide becomes a no-op, and validate_store_request_vars() refuses a value
 * its declared integer filter rejects, as the real one does.
 */
function user_list_stubs(): string
{
    $source = test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/user_admin.php'), 'user');
    $names = array();

    foreach (token_get_all('<?php ' . $source) as $token) {
        if (is_array($token) && $token[0] === T_STRING) {
            $names[strtolower($token[1])] = true;
        }
    }

    $stubs = <<<'PHP'
function validate_store_request_vars($filters, $prefix = '') {
    foreach ($filters as $name => $options) {
        if (!isset($GLOBALS['request'][$name])) {
            $GLOBALS['request'][$name] = $options['default'] ?? '';
        } elseif (($options['filter'] ?? null) === FILTER_VALIDATE_INT && filter_var($GLOBALS['request'][$name], FILTER_VALIDATE_INT) === false) {
            $GLOBALS['messages'][] = 'die_html_input_error:' . $name;
            exit;
        }
    }
}
function get_order_string() { return 'ORDER BY username ASC'; }
class CactiSecureHeaders { public static function getNonceAttribute() { return ''; } }
define('MAX_DISPLAY_PAGES', 21);
PHP;

    foreach (array_keys($names) as $name) {
        if (!in_array($name, array('user', 'validate_store_request_vars', 'get_order_string', 'cactisecureheaders', 'getnonceattribute'), true)) {
            $stubs .= "\nif (!function_exists('$name')) { function $name(...\$args) { return ''; } }";
        }
    }

    return $stubs;
}

function user_list_run(array $request): array
{
    return admin_action_probe_run(array(
        'page' => 'user_admin.php',
        'functions' => array('user'),
        'request' => $request,
        'session' => array('sess_user_id' => 1),
        'globals' => array('auth_realms' => array(0 => 'Local'), 'user_actions' => array(), 'item_rows' => array(), 'config' => array('url_path' => '/')),
        'config' => array('num_rows_table' => 30),
        'stubs' => user_list_stubs(),
        'call' => 'user()',
    ));
}

function user_list_reads(array $result): array
{
    return array_values(array_filter($result['reads'], function ($read) {
        return str_contains($read['sql'], 'FROM user_auth AS ua');
    }));
}

test('the group filter reaches both list queries as a bound value', function () {
    $reads = user_list_reads(user_list_run(array('group' => '7')));

    expect($reads)->toHaveCount(2);

    foreach ($reads as $read) {
        expect($read['sql'])->toContain('ug.group_id = ?')
            ->and($read['params'])->toBe(array('7'));
    }
});

test('a group value that is not a whole number is refused', function (string $group) {
    $result = user_list_run(array('group' => $group));

    expect($result['messages'])->toBe(array('die_html_input_error:group'))
        ->and(user_list_reads($result))->toBe(array());
})->with(array('words' => '7 OR 1', 'hex' => '0x07', 'decimal' => '7.5'));

test('no group filter leaves the list unfiltered', function () {
    $reads = user_list_reads(user_list_run(array()));

    expect($reads)->toHaveCount(2);

    foreach ($reads as $read) {
        expect($read['sql'])->not->toContain('group_id')
            ->and($read['params'])->toBe(array());
    }
});
