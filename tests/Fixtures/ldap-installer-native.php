<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$scenarioJson = $argv[1];
$scenario = json_decode($scenarioJson, true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
$mode = $scenario['mode'];
$coverage = null;
$markers = [];
if (($argv[3] ?? '') === 'coverage') {
    require $root . '/tests/vendor/autoload.php';
    require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/ldap-installer-native.php', $scenarioJson, [
        'lib/auth.php', 'lib/ldap.php', 'install/functions.php', 'cacti.sql', 'composer.lock',
        'tests/composer.lock', 'tests/Helpers/NativeChildCoverageEvidence.php',
        'include/global_constants.php', 'tests/Unit/Security/Auth/LdapInstallerNativeContractTest.php',
    ]);
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (['lib/auth.php', 'lib/ldap.php', 'install/functions.php'] as $source) {
        $filter->includeFile($root . '/' . $source);
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
        (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter),
        $filter
    );
    $coverage->start('ldap-installer-' . $mode);
}

function __($message, ...$args)
{
    return $args ? vsprintf($message, $args) : $message;
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}

if ($mode === 'domain') {
    // The network LDAP adapter records the configuration at each actual call.
    class Ldap
    {
        public $username = 'default-user';
        public $dn = 'default-dn';
        public $host = 'default-host';
        public $port = 389;
        public $port_ssl = 636;
        public $version = 3;
        public $encryption = 0;
        public $referrals = 0;
        public $mode = 0;
        public $search_base = 'default-base';
        public $search_filter = 'default-filter';
        public $specific_dn = 'default-specific-dn';
        public $specific_password = 'default-specific-password';
        public $group_require = true;
        public $group_dn = 'default-group-dn';
        public $group_attrib = 'default-group-attrib';
        public $group_member_type = 1;
        public $cn = [];

        public function Search(): array
        {
            return $this->respond('Search');
        }
        public function Getcn(): array
        {
            return $this->respond('Getcn');
        }
        private function respond(string $operation): array
        {
            $GLOBALS['calls'][] = ['operation' => $operation, 'properties' => get_object_vars($this)];
            return array_shift($GLOBALS['responses']);
        }
    }
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Columns and required/default behavior follow cacti.sql user_domains_ldap.
    $db->exec('CREATE TABLE user_domains_ldap (domain_id INTEGER PRIMARY KEY, server TEXT NOT NULL, port INTEGER NOT NULL, port_ssl INTEGER NOT NULL, proto_version INTEGER NOT NULL, encryption INTEGER NOT NULL, referrals INTEGER NOT NULL, mode INTEGER NOT NULL, dn TEXT NOT NULL, group_require TEXT NOT NULL, group_dn TEXT NOT NULL, group_attrib TEXT NOT NULL, group_member_type INTEGER NOT NULL, search_base TEXT NOT NULL, search_filter TEXT NOT NULL, specific_dn TEXT NOT NULL, specific_password TEXT NOT NULL, cn_full_name TEXT DEFAULT \'\', cn_email TEXT DEFAULT \'\')');
    $row = array_merge([
        'domain_id' => 7, 'server' => 'first second', 'port' => 1389, 'port_ssl' => 1636,
        'proto_version' => 2, 'encryption' => 2, 'referrals' => 1, 'mode' => 2,
        'dn' => 'uid=<username>', 'group_require' => 'on', 'group_dn' => 'cn=operators',
        'group_attrib' => 'uniqueMember', 'group_member_type' => 2, 'search_base' => 'dc=example',
        'search_filter' => '(uid=<username>)', 'specific_dn' => 'cn=bind',
        'specific_password' => 'fixture-password',
    ], $scenario['row'] ?? []);
    if (!($scenario['missing'] ?? false)) {
        $db->prepare('INSERT INTO user_domains_ldap (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }
    $queries = [];
    function db_fetch_row_prepared($sql, $parameters = [])
    {
        $GLOBALS['queries'][] = [$sql, $parameters];
        $query = $GLOBALS['db']->prepare($sql);
        $query->execute($parameters);
        return $query->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    $calls = [];
    $responses = $scenario['responses'] ?? [['error_num' => 81], ['error_num' => 0, 'result' => 'found']];
    require $root . '/lib/auth.php';
    $result = $scenario['operation'] === 'dn'
        ? domains_ldap_search_dn($scenario['username'] ?? 'alice', $scenario['realm'] ?? 1007)
        : domains_ldap_search_cn($scenario['username'] ?? 'alice', $scenario['cn'] ?? ['cn', 'mail'], $scenario['realm'] ?? 1007);
    $output = ['result' => $result, 'calls' => $calls, 'queries' => $queries, 'rows' => $db->query('SELECT * FROM user_domains_ldap')->fetchAll(PDO::FETCH_ASSOC)];
    $markers[] = 'domain-production-returned';
} elseif ($mode === 'installer') {
    $config = ['base_path' => $directory, 'cacti_server_os' => $scenario['os']];
    $settings = [
        'path' => [
            'path_spine_config' => ['default' => '', 'method' => 'filepath', 'friendly_name' => 'Spine config'],
            'path_cactilog' => ['default' => '', 'method' => 'filepath', 'friendly_name' => 'Log'],
            'path_stderrlog' => ['default' => '', 'method' => 'filepath', 'friendly_name' => 'Error log'],
            'path_snmpget' => ['default' => 'metadata-default', 'method' => 'filepath', 'friendly_name' => 'SNMP get', 'install_optional' => true],
        ],
        'mail' => ['settings_sendmail_path' => ['default' => 'mail-metadata', 'method' => 'filepath', 'friendly_name' => 'Mail', 'install_optional' => true]],
        'general' => ['rrdtool_version' => ['default' => 'metadata-version']],
    ];
    $options = $scenario['options'] ?? [];
    $discovered = $scenario['discovered'] ?? [];
    $events = [];
    $locations = [];
    function cacti_log($message, ...$args)
    {
        $message = preg_replace('/^(debug|notice): /', '', $message);
        if (str_starts_with($message, 'Searching best path with location: ')) {
            $GLOBALS['events'][] = ['discover', basename(substr($message, strlen('Searching best path with location: ')))];
        } elseif (str_contains($message, ': Locations (')) {
            $GLOBALS['locations'][] = $message;
        }
    }
    function clean_up_lines($value)
    {
        return $value;
    }
    function config_value_exists($key)
    {
        return array_key_exists($key, $GLOBALS['options']);
    }
    function read_config_option($key, $force = false)
    {
        if (str_starts_with($key, 'path_')) $GLOBALS['events'][] = ['config', $key, $force];
        return $GLOBALS['options'][$key] ?? '';
    }
    function get_installed_rrdtool_version()
    {
        return 'fixture-version';
    }
    require $root . '/include/global_constants.php';
    require $root . '/install/functions.php';
    $ownedBinaries = [];
    if ($discovered !== []) {
        mkdir($directory . '/bin', 0700);
        foreach (array_keys($discovered) as $basename) {
            $ownedBinaries[] = $directory . '/bin/' . $basename;
            file_put_contents(end($ownedBinaries), 'owned discovery fixture');
        }
        putenv('PATH=' . $directory . '/bin');
    }
    $actualDiscovery = [];
    foreach ($scenario['basenames'] as $basename) {
        $actualDiscovery[$basename] = find_best_path($basename);
    }
    $output = ['input' => install_file_paths(), 'events' => $events, 'locations' => $locations, 'discovery' => $actualDiscovery];
    foreach ($ownedBinaries as $file) {
        unlink($file);
    }
    if ($ownedBinaries !== []) {
        rmdir($directory . '/bin');
    }
    $markers[] = 'installer-production-returned';
} elseif ($mode === 'legacy') {
    function cacti_debug_backtrace(...$args)
    {
        return 'trace';
    }
    function cacti_session_close()
    {
        $GLOBALS['sessions'][] = 'close';
    }
    function cacti_session_start()
    {
        $GLOBALS['sessions'][] = 'start';
    }
    function CactiErrorHandler(...$args)
    {
        $GLOBALS['handled'][] = $args[1];
        return true;
    }
    require $root . '/lib/ldap.php';
    $ldap = (new ReflectionClass(Ldap::class))->newInstanceWithoutConstructor();
    foreach (['ErrorHandler', 'SetLdapHandler', 'RestoreCactiHandler', 'RecordError', 'Connect', 'Authenticate', 'GetMask', 'Search', 'Getcn'] as $name) {
        if (!is_callable([$ldap, $name])) {
            throw new RuntimeException('Legacy method unavailable: ' . $name);
        }
    }
    $success = call_user_func([LdapError::class, 'GetErrorDetails'], LdapError::Success);
    $sessions = [];
    $handled = [];
    set_error_handler('CactiErrorHandler');
    $ldap->SetLdapHandler();
    trigger_error('LDAP callback', E_USER_WARNING);
    $ldap->RestoreCactiHandler();
    trigger_error('Cacti callback', E_USER_WARNING);
    restore_error_handler();
    $output = [$success['error_num'], $success['error_text'], $ldap->GetMask(), $sessions, $handled];
    $markers[] = 'legacy-callbacks-returned';
} else {
    throw new InvalidArgumentException('Unknown native scenario.');
}

$json = json_encode($output, JSON_THROW_ON_ERROR);
$markers[] = 'result-json-encoded';
if ($coverage !== null) {
    $coverage->stop();
    $report = $directory . '/native.coverage';
    $serialized = serialize($coverage);
    if (file_put_contents($report, $serialized) !== strlen($serialized)) {
        throw new RuntimeException('Incomplete native coverage report.');
    }
    NativeChildCoverageEvidence::write($report, $root, $snapshot, $markers);
}
print $json;
