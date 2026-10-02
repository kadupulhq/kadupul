<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = dirname(__DIR__, 2);
$directory = $argv[1];
if (isset($argv[2])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('STRING_PREDICATE_TEST_COVERAGE', 1);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html.php';
require $root . '/lib/database.php';
require $root . '/lib/poller.php';

// Translation is outside these predicate contracts; every helper body is native.
function __($text, ...$values)
{
    return $values ? vsprintf($text, $values) : $text;
}
$config = ['base_path' => $directory, 'url_path' => '/', 'poller_id' => 1,
    'is_web' => false, 'cacti_server_os' => 'unix', 'config_options_array' => [
        'log_validation' => '', 'log_destination' => 0, 'log_verbosity' => 0,
        'client_timezone_support' => '', 'selective_debug' => '',
        'selective_plugin_debug' => '', 'path_cactilog' => $directory . '/log',
        'path_php_binary' => PHP_BINARY, 'md5dirsum_scripts' => '',
    ]];
$_SESSION = [];
$_REQUEST = [];
$_CACTI_REQUEST = [];
$_SERVER['PHP_SELF'] = '/host.php';
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['SERVER_PORT'] = 80;
$result = [];
$result['rows'] = [];
foreach ([12, 'row_12', 'ROW_12', ''] as $id) {
    ob_start();
    form_alternate_row($id);
    form_alternate_row_class($id, 'probe');
    $result['rows'][] = ob_get_clean();
}
$result['cells'] = [];
foreach (['highlight', 'color:red'] as $style) {
    ob_start();
    form_selectable_cell('body', 12, '10px', $style);
    $result['cells'][] = ob_get_clean();
}
$_REQUEST = ['sort_column' => 'description', 'sort_direction' => 'DESC'];
update_order_string();
$result['sort_update'] = $_SESSION['sort_string'];
$result['sort_get'] = get_order_string();
$result['redirects'] = [];
foreach (['//evil.test/path', 'http://example.test//path', 'http://example.test/path', 'relative.php', 'http://evil.test/path'] as $url) {
    $result['redirects'][] = validate_redirect_url($url, 'fallback.php');
}
$result['regex'] = validate_is_regex('value;other');
$result['pages'] = [get_page_list(1, 3, 10, 30, 'host.php'), get_page_list(1, 3, 10, 30, 'host.php?filter=x')];
$result['indexes'] = [db_format_index_create('name'), db_format_index_create('name(10)'), db_format_index_create(['name', 'value(10)'])];
$result['quoted'] = [file_escaped('"plain"'), file_escaped('plain'), file_escaped('"plain')];
$result['paths'] = [cacti_join_dir_child('/base/', 'file'), cacti_join_dir_child('/base', 'file')];

// Real prepared SQL reads through the complete production database module.
$database_hostname = 'sqlite-fixture';
$database_port = 0;
$database_default = 'resource';
$database_total_queries = 0;
$pdo = new PDO('sqlite:' . $directory . '/resource.sqlite');
$database_sessions = ['sqlite-fixture:0:resource' => $pdo];
$remote_db_cnn_id = $pdo;
$pdo->exec('CREATE TABLE poller_resource_cache (id INTEGER PRIMARY KEY, path TEXT, resource_type TEXT, md5sum TEXT, attributes INTEGER, contents TEXT)');
foreach (['plugins/example', 'out', 'lib', 'include'] as $path) {
    mkdir($directory . '/' . $path, 0700, true);
}
file_put_contents($directory . '/plugins/example/config.php', '<?php // plugin fixture');
$statement = $pdo->prepare('INSERT INTO poller_resource_cache VALUES (?, ?, ?, ?, ?, ?)');
$statement->execute([1, 'plugins/example/config.php', 'config', md5_file($directory . '/plugins/example/config.php'), 33188, '']);
cache_in_path($directory . '/plugins/example/config.php', 'config', false);
file_put_contents($directory . '/include/config.php', 'core fixture');
update_db_from_path($directory . '/include', 'config', false);
$result['core_config_cached'] = $pdo->query("SELECT COUNT(*) FROM poller_resource_cache WHERE path = 'include/config.php'")->fetchColumn();
$sources = [
    'out/env.php' => "#!/usr/bin/env php\n<?php // env fixture\n",
    'out/direct.php' => "#!/usr/bin/php\n<?php // direct fixture\n",
    'lib/poller.php' => "#!/usr/bin/env php\n<?php // poller fixture\n",
];
$id = 2;
foreach ($sources as $path => $contents) {
    $statement->execute([$id++, $path, 'scripts', md5($contents), 33188, base64_encode($contents)]);
}
ob_start();
resource_cache_out('scripts', ['path' => $directory . '/out', 'recursive' => false]);
$result['lint_output'] = ob_get_clean();
$result['replicated'] = [];
clearstatcache();
foreach ($sources as $path => $contents) {
    $result['replicated'][$path] = [file_get_contents($directory . '/' . $path) === $contents, fileperms($directory . '/' . $path) & 0777];
}
$encodedResult = json_encode($result, JSON_THROW_ON_ERROR);
if (file_put_contents($directory . '/result.json', $encodedResult) !== strlen($encodedResult)
    || file_get_contents($directory . '/result.json') !== $encodedResult) {
    throw new RuntimeException('Native result persistence was not confirmed');
}
define('NATIVE_COVERAGE_COMPLETED', ['native-result-persisted']);
