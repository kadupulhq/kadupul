<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Operational verification runs only in the native runner's exclusively owned copied candidate.
$root = dirname(__DIR__, 2);
$configFile = $root . '/include/config.php';
$originalConfig = file_get_contents($configFile);
if (!is_file($root . '/.cdef-reference-task-owned-candidate') || !is_string($originalConfig)
    || hash('sha256', $originalConfig) !== hash_file('sha256', $root . '/tests/Fixtures/cdef-reference-runtime-config.php')) {
    throw new RuntimeException('An exact owned native fixture configuration is required.');
}
require_once $root . '/include/vendor/autoload.php';
require_once $root . '/tests/Helpers/PhpSource.php';
$source = file_get_contents($root . '/tests/security/cdef_reference_normal_installer_native_probe.php');
if (!is_string($source)) {
    throw new RuntimeException('The native schema seed helper is required.');
}
foreach (['installerAssert', 'installerSeed', 'installerProvisionAdministrator'] as $function) {
    eval(test_php_function_source($source, $function));
}
$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('An explicitly owned MySQL/MariaDB fixture is required.');
}
$database = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'kadupul_cdef_install_' . bin2hex(random_bytes(8));
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($listener)) {
    throw new RuntimeException('The controlled primary listener is unavailable.');
}
stream_set_blocking($listener, false);
$address = stream_socket_get_name($listener, false);
if (!is_string($address) || preg_match('/:([0-9]+)\z/', $address, $port) !== 1) {
    throw new RuntimeException('The controlled listener address is unavailable.');
}
$environment = getenv();
$environment['KADUPUL_REFERENCE_RUNTIME_SCHEMA'] = $schema;
$environment['APP_SECRET'] = bin2hex(random_bytes(32));
$environment['APP_ENV'] = 'prod';
$environment['APP_DEBUG'] = '0';
$contacts = 0;

/** Drain the controlled listener while the complete canonical CLI process runs. */
function operationalRun(array $arguments, array $environment, $listener, int &$contacts, string $root): array
{
    $process = new Symfony\Component\Process\Process([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'zend.exception_ignore_args=1', ...$arguments], $root, $environment, null, 30.0);
    $process->start();
    try {
        while ($process->isRunning()) {
            while (($connection = @stream_socket_accept($listener, 0)) !== false) {
                $contacts++;
                fclose($connection);
            }
            $process->checkTimeout();
            usleep(10000);
        }
        while (($connection = @stream_socket_accept($listener, 0)) !== false) {
            $contacts++;
            fclose($connection);
        }
        return [$process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    } finally {
        if ($process->isRunning()) {
            $process->stop(0.1);
        }
    }
}

function operationalSnapshot(PDO $database): string
{
    $state = [];
    foreach (['settings', 'poller', 'user_auth', 'graph_local', 'graph_templates_graph', 'graph_templates_item', 'data_template_rrd', 'data_template_data'] as $table) {
        $state[$table] = $database->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', serialize($state));
}

$created = false;
$changedConfig = false;
$ownedFile = null;
$failure = null;
try {
    $database->exec('CREATE DATABASE `' . $schema . '`');
    $created = true;
    $database->exec('USE `' . $schema . '`');
    $database->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");
    installerSeed($database, $root);
    installerProvisionAdministrator($database);
    require_once $root . '/include/global_constants.php';
    $now = time();
    $id = 700001;
    $name = 'operational_' . bin2hex(random_bytes(8)) . '.rrd';
    $binary = getenv('KADUPUL_TEST_RRDTOOL_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    installerAssert(is_file($binary) && is_executable($binary), 'a real existing RRDtool binary is available');
    $rrd = $root . '/rra/' . $name;
    (new Symfony\Component\Process\Process([$binary, 'create', $rrd, '--start', (string) ($now - 300), '--step', '60', 'DS:value:GAUGE:120:U:U', 'RRA:AVERAGE:0.5:1:10']))->mustRun();
    $ownedFile = $rrd;
    (new Symfony\Component\Process\Process([$binary, 'update', $rrd, $now . ':1']))->mustRun();
    $database->exec('INSERT INTO graph_local(id,host_id) VALUES (' . $id . ',0)');
    $database->exec("INSERT INTO graph_templates_graph(local_graph_id,title_cache) VALUES ($id,'Owned operational fixture')");
    $database->exec("INSERT INTO data_template_rrd(id,local_data_id,rrd_heartbeat) VALUES ($id,$id,120)");
    $database->exec("INSERT INTO graph_templates_item(local_graph_id,task_item_id) VALUES ($id,$id)");
    $statement = $database->prepare('INSERT INTO data_template_data(local_data_id,data_source_path) VALUES (?,?)');
    $statement->execute([$id, '<path_rra>/' . $name]);
    $statement = $database->prepare('REPLACE INTO settings(name,value) VALUES (?,?)');
    foreach (['cron_interval' => '300', 'poller_interval' => '300', 'poller_lastrun_2' => (string) $now, 'path_rrdtool' => $binary, 'auth_method' => '1'] as $key => $value) {
        $statement->execute([$key, $value]);
    }
    $statement = $database->prepare('INSERT INTO poller(id,status,total_polls,last_update) VALUES (2,?,1,FROM_UNIXTIME(?))');
    $statement->execute([POLLER_STATUS_IDLE, $now]);
    // Explicit online preference must not override this command's local-only boundary.
    $collector = $originalConfig . "\n\$poller_id=2; \$conn_mode='online'; \$rdatabase_type='mysql';"
        . "\$rdatabase_hostname='127.0.0.1'; \$rdatabase_port=" . $port[1] . ';'
        . "\$rdatabase_default='owned_primary'; \$rdatabase_username='owned'; \$rdatabase_password='owned'; \$rdatabase_retries=1;";
    $changedConfig = true;
    installerAssert(file_put_contents($configFile, $collector) === strlen($collector), 'the owned collector configuration is complete');
    $snapshot = operationalSnapshot($database);
    $rrdHash = hash_file('sha256', $rrd);
    $command = [$root . '/bin/console', 'kadupul:install:verify'];
    [$exit] = operationalRun([...$command, '--output=json'], $environment, $listener, $contacts, $root);
    installerAssert($exit === 2 && $contacts === 0, 'missing consent performs no primary contact through the outer console');
    [$exit] = operationalRun([...$command, '--acknowledge-read-only', '--as=missing-owned-operator', '--output=json'], $environment, $listener, $contacts, $root);
    installerAssert($exit === 1 && $contacts === 0, 'denied local operator performs no primary contact before authorization');
    [$exit, $output] = operationalRun([...$command, '--acknowledge-read-only', '--graph-id=' . $id, '--output=json'], $environment, $listener, $contacts, $root);
    $report = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    installerAssert($exit === 0 && $contacts === 0 && ($report['poller_id'] ?? null) === 2, 'the complete canonical command assesses the local collector without primary contact');
    installerAssert(($report['graphs'][0]['sources'][0]['last_update'] ?? null) === $now
        && ($report['graphs'][0]['rendering'] ?? null) === 'not_assessed'
        && ($report['operational_health_confirmed'] ?? null) === false, 'real local RRD timestamp evidence does not claim device or rendering health');
    installerAssert(operationalSnapshot($database) === $snapshot && hash_file('sha256', $rrd) === $rrdHash, 'operational assessment preserves actual persisted rows and RRD bytes');
    $database->exec('UPDATE user_auth SET policy_graphs=2,policy_hosts=2,policy_graph_templates=2 WHERE id=1');
    $snapshot = operationalSnapshot($database);
    [$exit] = operationalRun([...$command, '--acknowledge-read-only', '--graph-id=' . $id, '--output=json'], $environment, $listener, $contacts, $root);
    installerAssert($exit === 1 && $contacts === 0 && operationalSnapshot($database) === $snapshot, 'native graph policy denial is local and preserves state');
    // Positive control: ordinary trusted bootstrap still honors online mode.
    operationalRun(['-r', 'define("IN_CACTI_INSTALL",true); require $argv[1];', $root . '/include/cli_check.php'], $environment, $listener, $contacts, $root);
    installerAssert($contacts > 0, 'ordinary non-verifier bootstrap still attempts its configured primary connection');
} catch (Throwable $exception) {
    $failure = $exception;
    throw $exception;
} finally {
    $cleanupFailed = false;
    if ($changedConfig && file_put_contents($configFile, $originalConfig) !== strlen($originalConfig)) {
        $cleanupFailed = true;
    }
    if (is_string($ownedFile) && !unlink($ownedFile)) {
        $cleanupFailed = true;
    }
    if ($created) {
        try {
            $database->exec('DROP DATABASE `' . $schema . '`');
        } catch (Throwable) {
            $cleanupFailed = true;
        }
    }
    fclose($listener);
    if ($cleanupFailed && $failure === null) {
        throw new RuntimeException('Owned operational fixture cleanup failed.');
    }
}
echo "PASS genuine local operational command and native authorization proof complete\n";
