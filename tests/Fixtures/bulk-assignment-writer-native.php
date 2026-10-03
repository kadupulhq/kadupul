<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require dirname(__DIR__, 2) . '/include/vendor/autoload.php';

use Kadupul\Inventory\Domain\DeviceBulkAssignment;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceBulkAssignmentWriter;

final class AssignmentFixtureDatabase extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->exec('CREATE TABLE host (id INTEGER, poller_id INTEGER, host_template_id INTEGER, site_id INTEGER, disabled TEXT, deleted TEXT);
            CREATE TABLE poller_item (host_id INTEGER, poller_id INTEGER);
            CREATE TABLE host_graph (host_id INTEGER, graph_template_id INTEGER);
            CREATE TABLE host_template_graph (host_template_id INTEGER, graph_template_id INTEGER);
            CREATE TABLE host_snmp_query (host_id INTEGER, snmp_query_id INTEGER, reindex_method INTEGER);
            CREATE TABLE host_template_snmp_query (host_template_id INTEGER, snmp_query_id INTEGER)');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' LOCK IN SHARE MODE', '', $query), $options);
    }
}

$events = [];
function api_plugin_hook_function(string $name, array $arguments): void
{
    global $events;
    $events[] = [$name, $arguments];
}
function api_device_update_host_template(int $id, int $template): void
{
    global $primary, $events;
    $events[] = ['template_api', [$id, $template]];
    $primary->prepare('UPDATE host SET host_template_id=? WHERE id=?')->execute([$template, $id]);
    $primary->prepare('INSERT INTO host_graph SELECT ?,graph_template_id FROM host_template_graph WHERE host_template_id=?')->execute([$id, $template]);
}

$primary = new AssignmentFixtureDatabase();
$remote = new AssignmentFixtureDatabase();
$primary->exec("INSERT INTO host VALUES (7,2,9,0,'','')");
$remote->exec("INSERT INTO host VALUES (7,2,9,0,'on','')");
$primary->beginTransaction();
$device = new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 2, 9);
$writer = new DeviceBulkAssignmentWriter();
$mode = $argv[1];
$error = null;
try {
    if ($mode === 'template-existing') {
        $primary->exec('INSERT INTO host_template_graph VALUES (9,5)');
        $writer->apply($primary, [], $device, new DeviceBulkAssignment('template', 9));
        $writer->verify($primary, [], $device, new DeviceBulkAssignment('template', 9));
    } elseif ($mode === 'template-remove') {
        $writer->apply($primary, [2 => $remote], $device, new DeviceBulkAssignment('template', 0));
    } elseif ($mode === 'site-noop') {
        $writer->apply($primary, [2 => $remote], $device, new DeviceBulkAssignment('site', 0));
    } elseif ($mode === 'site-missing') {
        $primary->exec('DELETE FROM host');
        $writer->apply($primary, [], $device, new DeviceBulkAssignment('site', 5));
    } elseif ($mode === 'collector-wrong-polling-owner') {
        $primary->exec('INSERT INTO poller_item VALUES (7,3)');
        $writer->verify($primary, [2 => $remote], $device, new DeviceBulkAssignment('collector', 2), false);
    } elseif ($mode === 'collector-noop') {
        $writer->apply($primary, [2 => $remote], $device, new DeviceBulkAssignment('collector', 2));
    } else {
        $writer->apply($primary, [2 => $remote], $device, new DeviceBulkAssignment('site', 5));
        if ($mode === 'site-drift-changed') {
            $remote->exec("UPDATE host SET disabled=''");
        } elseif ($mode === 'site-wrong-owner') {
            $remote->exec('UPDATE host SET poller_id=3');
        }
        if ($mode === 'site-template-mismatch') {
            $primary->exec('UPDATE host SET host_template_id=3');
        } elseif ($mode === 'site-primary-owner-mismatch') {
            $primary->exec('UPDATE host SET poller_id=3');
        } elseif ($mode === 'site-primary-site-mismatch') {
            $primary->exec('UPDATE host SET site_id=0');
        }
        $writer->verify($primary, [2 => $remote], $device, new DeviceBulkAssignment('site', 5), false);
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
echo json_encode(['error' => $error, 'events' => $events, 'primary' => $primary->query('SELECT * FROM host')->fetchAll(PDO::FETCH_ASSOC), 'remote' => $remote->query('SELECT * FROM host')->fetchAll(PDO::FETCH_ASSOC), 'graphs' => $primary->query('SELECT * FROM host_graph')->fetchAll(PDO::FETCH_ASSOC), 'active' => $primary->inTransaction()], JSON_THROW_ON_ERROR);
