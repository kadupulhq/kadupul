<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require getcwd() . '/include/vendor/autoload.php';
$config = new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(getcwd());
$db = (new \Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase($config))->get();
$name = 'site-disable-' . bin2hex(random_bytes(6));
$db->prepare('INSERT INTO sites (name) VALUES (?)')->execute([$name]);
$siteId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO host (description,hostname,site_id,poller_id,disabled,status,snmp_version,availability_method) VALUES (?,?,?,1,'',3,0,0)")->execute([$name, $name . '.invalid', $siteId]);
$hostId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO host_template (hash,name) VALUES (?,?)')->execute([bin2hex(random_bytes(16)), $name]);
$templateId = (int) $db->lastInsertId();
$graphId = (int) $db->query('SELECT MIN(id) FROM graph_templates')->fetchColumn();
$db->prepare('INSERT INTO host_template_graph (host_template_id,graph_template_id) VALUES (?,?)')->execute([$templateId, $graphId]);
$graphsBefore = $db->query('SELECT * FROM host_graph WHERE host_id=0 ORDER BY graph_template_id')->fetchAll();
$worker = null;
try {
    $invalid = new \Symfony\Component\Process\Process([PHP_BINARY, 'bin/legacy-device-edit.php'], getcwd());
    $invalid->setInput('{}');
    $invalid->run();
    if ($invalid->isSuccessful() || !str_contains($invalid->getOutput(), 'KADUPUL_EDIT_RESULT={"status":"failed"}')) {
        throw new RuntimeException('Malformed worker commands must not masquerade as invalid stored credentials');
    }
    $row = $db->query('SELECT * FROM host WHERE id=' . $hostId)->fetch();
    $device = new \Kadupul\Inventory\Domain\Device($hostId, $row['description'], $row['hostname'], (string) $row['notes'], true, (string) $row['location'], (string) $row['external_id'], (int) $row['site_id'], array_intersect_key($row, \Kadupul\Inventory\Domain\DevicePolling::DEFAULTS), array_intersect_key($row, \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::PUBLIC_DEFAULTS));
    $command = ['actor' => (int) $db->query("SELECT id FROM user_auth WHERE username='admin'")->fetchColumn(), 'id' => $hostId, 'description' => $device->description(), 'hostname' => $device->hostname(), 'notes' => $device->notes(), 'enabled' => false, 'location' => $device->location(), 'external_id' => $device->externalId(), 'revision' => $device->revision(), 'site_id' => $siteId, 'polling' => $device->polling(), 'snmp' => $device->snmpChange()->fields];
    $db->beginTransaction();
    $db->query('SELECT id FROM sites WHERE id=' . $siteId . ' FOR UPDATE')->fetchColumn();
    $worker = proc_open([PHP_BINARY, 'bin/legacy-device-edit.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($worker)) {
        throw new RuntimeException('Could not start the edit worker');
    }
    fwrite($pipes[0], json_encode($command, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $deadline = microtime(true) + 15;
    do {
        $waiting = (int) $db->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID != CONNECTION_ID() AND INFO LIKE 'SELECT id FROM sites WHERE id = % FOR UPDATE'")->fetchColumn();
        if ($waiting > 0) {
            break;
        }
        if (!proc_get_status($worker)['running']) {
            throw new RuntimeException('Edit worker did not wait for the site lock');
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    if (!$waiting) {
        throw new RuntimeException('Edit worker never requested the site lock');
    }
    // This fails immediately with the previous host-before-site lock order.
    $db->query('SELECT id FROM host WHERE id=' . $hostId . ' FOR UPDATE NOWAIT')->fetchColumn();
    $db->prepare("UPDATE host SET site_id=0 WHERE id=? AND deleted=''")->execute([$hostId]);
    $db->prepare('DELETE FROM sites WHERE id=?')->execute([$siteId]);
    $db->commit();
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($worker);
    $worker = null;
    if ($exit === 0 || !str_contains($output, 'KADUPUL_EDIT_RESULT={"status":"failed"}')) {
        throw new RuntimeException('Editor accepted a deleted site');
    }

    // Exercise a normal legacy API call without a caller-owned transaction.
    $script = <<<'CODE'
ob_start();
require getcwd() . '/include/cli_check.php';
require_once getcwd() . '/lib/api_device.php';
$row = db_fetch_row_prepared('SELECT * FROM host WHERE id=?', [(int) $argv[1]]);
$row['site_id'] = (int) $argv[2];
$row['disabled'] = 'on';
$row['device_template_id'] = (int) $argv[3];
$arguments = [];
foreach ((new ReflectionFunction('api_device_save'))->getParameters() as $parameter) {
    $arguments[] = $row[$parameter->getName()] ?? ($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : '');
}
$result = api_device_save(...$arguments);
ob_end_clean();
echo $result === false ? 'rejected' : 'accepted';
CODE;
    $worker = proc_open([PHP_BINARY, '-r', $script, (string) $hostId, (string) $siteId, (string) $templateId], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($worker);
    $worker = null;
    $state = $db->query('SELECT disabled,status,site_id FROM host WHERE id=' . $hostId)->fetch();
    if ($exit !== 0 || $output !== 'rejected' || $state['disabled'] !== '' || (int) $state['status'] !== 3 || (int) $state['site_id'] !== 0) {
        throw new RuntimeException('Rejected site assignment changed device polling state');
    }
    if ($db->query('SELECT * FROM host_graph WHERE host_id=0 ORDER BY graph_template_id')->fetchAll() !== $graphsBefore) {
        throw new RuntimeException('Rejected device save created template associations for host zero');
    }
    echo 'site locked before device effects';
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if (is_resource($worker)) {
        proc_terminate($worker);
        proc_close($worker);
    }
    $db->prepare('DELETE FROM host_template_graph WHERE host_template_id=?')->execute([$templateId]);
    $db->prepare('DELETE FROM host_template WHERE id=?')->execute([$templateId]);
    $db->prepare('DELETE FROM host WHERE id=?')->execute([$hostId]);
    $db->prepare('DELETE FROM sites WHERE id=?')->execute([$siteId]);
}
