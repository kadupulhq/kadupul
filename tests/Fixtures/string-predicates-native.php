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
// A real PCRE engine failure need not emit a PHP diagnostic at all.
// Disable PCRE JIT for this pattern so Linux/macOS reach the same engine error.
// Probe that boundary independently; no error-array stub is involved.
error_clear_last();
$result['runtime_regex_probe'] = [@preg_match("'(*NO_JIT)(?R)'", ''), preg_last_error_msg(), error_get_last()];
define('IN_CACTI_INSTALL', true);
error_clear_last();
$result['runtime_regex'] = validate_is_regex('(*NO_JIT)(?R)');
$result['bounded_regex'] = validate_is_regex('(*NO_JIT)(*NO_START_OPT)(?:a?|b?){14}c');
$result['regex_limits_unchanged'] = [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')];
// Compilation warnings belong to this probe even after a real PCRE runtime failure.
$result['regex_compile_after_runtime'] = validate_is_regex("abc'z");
@trigger_error('unrelated previous warning', E_USER_WARNING);
$result['regex_runtime_after_warning'] = validate_is_regex('(*NO_JIT)(?R)');
$priorHandler = static fn() => false;
set_error_handler($priorHandler);
$result['regex_valid_with_handler'] = validate_is_regex('valid');
$result['regex_invalid_with_handler'] = validate_is_regex("abc'z");
$afterHandler = set_error_handler(static fn() => false);
$result['regex_handler_restored'] = $afterHandler === $priorHandler;
restore_error_handler();
restore_error_handler();

// Native complete networking modules; these admission/packet paths need no server.
require $root . '/lib/ping.php';
require $root . '/lib/api_automation.php';
$ping = new Net_Ping();
$ping->build_udp_packet();
$result['native_udp'] = [$ping->port, bin2hex($ping->request), $ping->request_len];
$ping->build_icmp_packet();
$result['native_icmp'] = [substr(bin2hex($ping->request), 0, 4), bin2hex($ping->get_checksum($ping->request)), $ping->request_len];
$result['native_checksum'] = bin2hex($ping->get_checksum("abc"));
$result['native_addresses'] = array_map([$ping, 'is_ipaddress'], ['127.0.0.1', '::1', 'fe80::1%eth0', 'invalid']);
$result['native_transports'] = array_map([$ping, 'strip_ip_address'], ['tcp:127.0.0.1', 'udp6:[::1]', '[::1]']);
$ping->start_time();
$result['native_timer'] = is_numeric($ping->get_time());
$result['native_no_ping'] = $ping->ping(AVAIL_NONE);
$ping->host = ['hostname' => ''];
$result['native_missing_target'] = [$ping->ping_icmp(), $ping->ping_udp(), $ping->ping_tcp()];
$result['native_ping_error'] = $ping->ping_error_handler(E_USER_WARNING, 'owned fixture warning', __FILE__, __LINE__);
$ping->set_ping_error_handler();
$installedHandler = set_error_handler(static fn() => false);
$result['native_ping_handler'] = $installedHandler instanceof Closure
    && (new ReflectionFunction($installedHandler))->getClosureThis() === $ping
    && $installedHandler(E_USER_WARNING, 'owned fixture warning', __FILE__, __LINE__) === true;
restore_error_handler();
$ping->restore_cacti_error_handler();
$result['native_dns_rejections'] = array_map(static fn($ip) => automation_get_dns_from_ip($ip, 'unused'), ['1.2.3', '1.2.3.', '1234.2.3.4']);

// Exercise the actual TCP socket path against an exclusively owned loopback listener.
$listener = stream_socket_server('tcp://127.0.0.1:0', $listenerError, $listenerMessage);
if ($listener === false) {
    throw new RuntimeException('Could not create owned TCP listener');
}
try {
    $ping->host = ['hostname' => 'tcp:127.0.0.1'];
    $ping->port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    $ping->timeout = 1000;
    $result['native_tcp_loopback'] = [$ping->ping_tcp(), $ping->host['hostname'], str_starts_with($ping->ping_response, 'TCP Ping Success')];
} finally {
    fclose($listener);
}

// Non-Unix configured branches must leave the actual process identity untouched.
$identity = function_exists('posix_geteuid') ? posix_geteuid() : null;
$config['cacti_server_os'] = 'win32';
$result['native_portable_uid'] = [$ping->seteuid(), $ping->setuid(0), function_exists('posix_geteuid') ? posix_geteuid() === $identity : true];
$config['cacti_server_os'] = 'unix';

// Complete configured SNMP adapter rejects missing v2 credentials before I/O.
$config['php_snmp_support'] = false;
$config['include_path'] = $root . '/include';
$config['config_options_array']['max_get_size'] = 10;
$config['config_options_array']['oid_increasing_check_disable'] = '';
require $root . '/lib/snmp.php';
$ping->host = ['hostname' => '127.0.0.1', 'snmp_community' => '', 'snmp_version' => '2',
    'snmp_username' => '', 'snmp_password' => '', 'snmp_auth_protocol' => '', 'snmp_priv_passphrase' => '',
    'snmp_priv_protocol' => '', 'snmp_context' => '', 'snmp_engine_id' => '', 'snmp_port' => 161, 'snmp_timeout' => 1];
$ping->retries = 0;
$ping->avail_method = AVAIL_SNMP;
$result['native_snmp_missing_credentials'] = [$ping->ping_snmp(), $ping->snmp_status, $ping->snmp_response];

// Complete LDAP module: real handler/session restoration and pre-network rejection.
require $root . '/lib/ldap.php';
$config['cacti_session_name'] = 'predicate-native';
$config['cookie_options'] = ['save_path' => $directory, 'use_cookies' => 0, 'use_only_cookies' => 0];
foreach (['dn', 'server', 'port', 'port_ssl', 'version', 'encryption', 'referrals', 'debug', 'group_require', 'group_dn', 'group_attrib', 'group_member_type', 'mode', 'search_base', 'search_filter', 'specific_dn', 'specific_password'] as $option) {
    $config['config_options_array']['ldap_' . $option] = '';
}
$ldap = new Ldap();
$result['native_ldap_defaults'] = [$ldap->debug, $ldap->group_require, $ldap->GetMask()];
$config['config_options_array']['ldap_debug'] = 'on';
$config['config_options_array']['ldap_group_require'] = 'on';
$ldapEnabled = new Ldap();
$result['native_ldap_enabled_options'] = [$ldapEnabled->debug, $ldapEnabled->group_require];
$ldap->SetLdapHandler();
$ldapHandler = set_error_handler(static fn() => false);
$result['native_ldap_handler'] = $ldapHandler instanceof Closure
    && (new ReflectionFunction($ldapHandler))->getClosureThis() === $ldap
    && $ldapHandler(E_USER_WARNING, 'owned fixture warning', __FILE__, __LINE__) === true;
restore_error_handler();
$ldap->RestoreCactiHandler();
$restoredHandler = set_error_handler(static fn() => false);
$result['native_ldap_restore'] = [$restoredHandler, session_status() === PHP_SESSION_ACTIVE];
restore_error_handler();
$result['native_ldap_rejections'] = [];
foreach (['Authenticate', 'Search', 'Getcn'] as $operation) {
    $error = $ldap->$operation();
    $result['native_ldap_rejections'][] = [$error['error_num'], $error['dn'], session_status() === PHP_SESSION_ACTIVE];
}
$result['native_ldap_expected_rejection'] = function_exists('ldap_connect') ? LdapError::UndefinedUsername : LdapError::Disabled;
$result['native_ldap_errors'] = [];
foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 99, 100] as $code) {
    $error = LdapError::GetErrorDetails($code, null, 'owned.test', 7);
    $result['native_ldap_errors'][] = [$error['error_num'], $error['error_ldap'], $error['dn'], $error['error_text']];
}
cacti_session_close();


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
require_once $root . '/tests/Helpers/PredicateNativeEvidence.php';
$resultJson = json_encode($result, JSON_THROW_ON_ERROR);
if (file_put_contents($directory . '/result.json', $resultJson) !== strlen($resultJson)) {
    throw new RuntimeException('Could not preserve predicate assertions');
}
$receiptJson = json_encode(PredicateNativeEvidence::capture($root, $resultJson), JSON_THROW_ON_ERROR);
if (file_put_contents($directory . '/evidence.json', $receiptJson) !== strlen($receiptJson)) {
    throw new RuntimeException('Could not preserve predicate source evidence');
}
