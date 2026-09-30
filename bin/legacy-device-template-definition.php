<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use Kadupul\Inventory\Domain\DeviceTemplateDefinition;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateAuthorization;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceTemplateDefinitions;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('KADUPUL_REDACT_DATABASE_LOGS', true);
define('KADUPUL_THROW_DATABASE_ERRORS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
foreach (['auth', 'api_device', 'api_graph', 'api_data_source', 'api_tree', 'data_query', 'poller', 'template', 'api_automation_tools', 'api_automation', 'snmp', 'utility'] as $library) {
    require_once __DIR__ . '/../lib/' . $library . '.php';
}
$database_last_error = '';
$status = 'failed';
$actor = 0;
$action = '';
$correlation = '';
$ids = [];
$hooks = [];
$started = false;
$syncStarted = false;
$claim = null;
$claimOwned = false;
$db = null;
try {
    $raw = stream_get_contents(STDIN, 131073);
    if (strlen($raw) > 131072) {
        throw new InvalidArgumentException();
    }
    $command = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $actor = $command['actor'] ?? 0;
    $action = $command['action'] ?? '';
    $correlation = $command['correlation'] ?? '';
    if (!is_int($actor) || $actor < 1 || !is_string($action) || !in_array($action, ['save', 'association', 'delete', 'duplicate', 'sync', 'hooks'], true)
        || !is_string($correlation) || !preg_match('/^[a-f0-9]{32}$/D', $correlation)) {
        throw new InvalidArgumentException();
    }
    $db = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    if (!$db instanceof PDO || (int) ($config['poller_id'] ?? 0) !== 1) {
        throw new RuntimeException();
    }
    $db->beginTransaction();
    $started = true;
    DeviceTemplateAuthorization::authorize($db, $actor, true);
    $_SESSION['sess_user_id'] = $actor;
    if ($action === 'hooks') {
        $id = definitionWorkerId($command['id'] ?? null, true);
        if ($id > 0 && LegacyDeviceTemplateDefinitions::read($db, $id) === null) {
            throw new DeviceEditConflict();
        }
        $_REQUEST = $_GET = ['id' => (string) $id, 'action' => $id > 0 ? 'edit' : ''];
        $_POST = [];
        // Only installed plugin hooks enter this explicitly trusted presentation boundary.
        foreach ($id > 0 ? ['device_template_top', 'device_template_edit'] : ['device_template_top'] as $hook) {
            ob_start();
            api_plugin_hook($hook);
            $hooks[$hook] = ob_get_clean();
        }
        $db->rollBack();
        $started = false;
        $status = 'ok';
    } else {
        $tables = ['host_template', 'host_template_graph', 'host_template_snmp_query', 'graph_templates', 'snmp_query', 'snmp_query_graph'];
        if ($action === 'delete') {
            $tables[] = 'host';
        }
        if ($action === 'sync') {
            $tables = [...$tables, 'settings', 'host', 'host_graph', 'host_snmp_query', 'host_snmp_cache', 'poller_item', 'poller_reindex', 'graph_local', 'graph_templates_graph', 'graph_templates_item', 'data_local', 'data_template_data', 'data_template_rrd'];
        }
        definitionWorkerStorage($db, $tables);
        if (in_array($action, ['save', 'association'], true)) {
            $id = definitionWorkerId($command['id'] ?? null, $action === 'save');
            $revision = $command['revision'] ?? null;
            if (!is_string($revision)) {
                throw new InvalidArgumentException();
            }
            if ($id > 0) {
                definitionWorkerLock($db, $id, $revision);
            } elseif ($revision !== 'new') {
                throw new DeviceEditConflict();
            }
            if ($action === 'save') {
                $data = DeviceTemplateDefinition::validate($command['data'] ?? []);
                if ($id > 0) {
                    $query = $db->prepare('UPDATE host_template SET name = ?, class = ? WHERE id = ?');
                    $query->execute([$data['name'], $data['class'], $id]);
                } else {
                    $query = $db->prepare('INSERT INTO host_template (hash, name, class) VALUES (?, ?, ?)');
                    $query->execute([get_hash_host_template(0), $data['name'], $data['class']]);
                    $id = (int) $db->lastInsertId();
                    if ($id < 1) {
                        throw new RuntimeException();
                    }
                }
            } else {
                $kind = $command['kind'] ?? '';
                $operation = $command['operation'] ?? '';
                if (!in_array($kind, ['graph', 'query'], true) || !in_array($operation, ['add', 'remove'], true)) {
                    throw new InvalidArgumentException();
                }
                $child = definitionWorkerId($command['child'] ?? null);
                [$table, $key, $target] = $kind === 'graph' ? ['host_template_graph', 'graph_template_id', 'graph_templates'] : ['host_template_snmp_query', 'snmp_query_id', 'snmp_query'];
                $query = $db->prepare('SELECT id FROM ' . $target . ' WHERE id = ? LOCK IN SHARE MODE');
                $query->execute([$child]);
                if ($query->fetchColumn() === false) {
                    throw new InvalidArgumentException();
                }
                if ($kind === 'graph' && $operation === 'add') {
                    $query = $db->prepare('SELECT graph_template_id FROM snmp_query_graph WHERE graph_template_id = ? LOCK IN SHARE MODE');
                    $query->execute([$child]);
                    if ($query->fetchColumn() !== false) {
                        throw new InvalidArgumentException();
                    }
                }
                $sql = $operation === 'add' ? "INSERT INTO $table (host_template_id, $key) VALUES (?, ?) ON DUPLICATE KEY UPDATE $key = VALUES($key)" : "DELETE FROM $table WHERE host_template_id = ? AND $key = ?";
                $query = $db->prepare($sql);
                $query->execute([$id, $child]);
            }
            $ids = [$id];
            $db->commit();
            $started = false;
            $status = 'ok';
        } else {
            $revisions = $command['revisions'] ?? null;
            if (!is_array($revisions) || !$revisions || count($revisions) > 100) {
                throw new InvalidArgumentException();
            }
            foreach ($revisions as $id => $revision) {
                $ids[] = definitionWorkerId($id);
                if (!is_string($revision)) {
                    throw new InvalidArgumentException();
                }
            }
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                definitionWorkerLock($db, $id, $revisions[$id]);
            }
            if ($action === 'delete') {
                foreach ($ids as $id) {
                    foreach (['host_template_graph', 'host_template_snmp_query'] as $table) {
                        $query = $db->prepare('DELETE FROM ' . $table . ' WHERE host_template_id = ?');
                        $query->execute([$id]);
                    }
                    $query = $db->prepare("UPDATE host SET host_template_id = 0 WHERE host_template_id = ? AND deleted = ''");
                    $query->execute([$id]);
                    $query = $db->prepare('DELETE FROM host_template WHERE id = ?');
                    $query->execute([$id]);
                }
                $db->commit();
                $started = false;
                $status = 'ok';
            } elseif ($action === 'duplicate') {
                $format = $command['title_format'] ?? null;
                if (!is_string($format) || trim($format) === '' || mb_strlen($format) > 255 || str_contains($format, "\0")) {
                    throw new InvalidArgumentException();
                }
                $new = [];
                foreach ($ids as $id) {
                    $row = LegacyDeviceTemplateDefinitions::read($db, $id);
                    if (mb_strlen(str_replace('<template_title>', $row->name, $format)) > 255) {
                        throw new InvalidArgumentException();
                    }
                    $newId = api_duplicate_device_template($id, $format);
                    if (!$newId) {
                        throw new RuntimeException();
                    } $new[] = (int) $newId;
                }
                $ids = $new;
                $db->commit();
                $started = false;
                $status = 'ok';
            } else {
                $token = $command['operation_id'] ?? null;
                if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) {
                    throw new InvalidArgumentException();
                }
                $claim = 'device_template_sync_' . $token;
                $query = $db->prepare('SELECT value FROM settings WHERE name = ? FOR UPDATE');
                $query->execute([$claim]);
                if ($query->fetchColumn() !== false) {
                    throw new DeviceEditConflict('Sync outcome exists; do not replay.');
                }
                $query = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
                $query->execute([$claim, json_encode(['actor' => $actor, 'ids' => $ids, 'status' => 'pending'], JSON_THROW_ON_ERROR)]);
                // Durable claim survives loss of the response; a retry cannot repeat hooks or remote writes.
                $db->commit();
                $claimOwned = true;
                $started = false;
                $db->beginTransaction();
                $started = true;
                DeviceTemplateAuthorization::authorize($db, $actor, true);
                foreach ($ids as $id) {
                    definitionWorkerLock($db, $id, $revisions[$id]);
                }
                $devices = [];
                foreach ($ids as $id) {
                    $query = $db->prepare('SELECT id, host_template_id, poller_id FROM host WHERE host_template_id = ? AND status IN (2,3) ORDER BY id FOR UPDATE');
                    $query->execute([$id]);
                    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $device) {
                        $remote = null;
                        if ((int) $device['poller_id'] > 1) {
                            $query = $db->prepare('SELECT id, disabled, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(last_status) AS age FROM poller WHERE id = ? FOR UPDATE');
                            $query->execute([(int) $device['poller_id']]);
                            $collector = $query->fetch(PDO::FETCH_ASSOC);
                            if (!$collector || $collector['disabled'] !== '' || $collector['age'] === null || (int) $collector['age'] >= (int) read_config_option('poller_interval') * 2) {
                                throw new RuntimeException('Collector unavailable before synchronization.');
                            }
                            $remote = poller_connect_to_remote((int) $device['poller_id']);
                            if (!$remote instanceof PDO) {
                                throw new RuntimeException('Collector unavailable before synchronization.');
                            }
                            $query = $remote->prepare('SELECT id FROM host WHERE id = ? AND poller_id = ?');
                            $query->execute([(int) $device['id'], (int) $device['poller_id']]);
                            if ($query->fetchColumn() === false) {
                                throw new RuntimeException('Collector device unavailable before synchronization.');
                            }
                        }
                        $devices[] = [$device, $remote];
                    }
                }
                $_SESSION['sess_messages'] = [];
                $syncStarted = true;
                foreach ($ids as $id) {
                    api_device_template_sync_template($id);
                }
                foreach ($devices as [$device, $remote]) {
                    (new \Kadupul\Inventory\Infrastructure\Legacy\DeviceCreationVerifier())->verify($db, $remote, (int) $device['id'], (int) $device['host_template_id'], true);
                    foreach (array_filter([$db, $remote]) as $connection) {
                        $query = $connection->prepare('SELECT host_template_id FROM host WHERE id = ? AND poller_id = ?');
                        $query->execute([(int) $device['id'], (int) $device['poller_id']]);
                        if ((int) $query->fetchColumn() !== (int) $device['host_template_id']) {
                            throw new RuntimeException('Collector template assignment could not be verified.');
                        }
                    }
                }
                foreach ($_SESSION['sess_messages'] ?? [] as $message) {
                    if ((int) ($message['level'] ?? 0) >= MESSAGE_LEVEL_WARN) {
                        throw new RuntimeException('Synchronization reported a warning.');
                    }
                }
                if (is_error_message() || db_error() !== '' || !$db->inTransaction()) {
                    throw new RuntimeException('Sync had external effects.');
                }
                $db->commit();
                $started = false;
                $status = 'ok';
            }
        }
    }
} catch (DeviceEditConflict) {
    $status = $syncStarted ? 'partial' : 'conflict';
} catch (InventoryAccessDenied) {
    $status = $syncStarted ? 'partial' : 'denied';
} catch (InvalidArgumentException) {
    $status = $syncStarted ? 'partial' : 'invalid';
} catch (Throwable $error) {
    cacti_log("DEVICE-TEMPLATE-DEFINITION: " . get_class($error) . " file=" . basename($error->getFile()) . " line=" . $error->getLine(), false, "AUDIT");
    $status = $syncStarted ? 'partial' : 'failed';
} finally {
    if ($started && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($claimOwned && $claim !== null && $db instanceof PDO) {
        try {
            $query = $db->prepare('UPDATE settings SET value = ? WHERE name = ?');
            $query->execute([json_encode(['actor' => $actor, 'ids' => $ids, 'status' => $status], JSON_THROW_ON_ERROR), $claim]);
        } catch (Throwable) {
            $status = 'partial';
        }
    }
}
if ($actor > 0 && $action !== 'hooks' && preg_match('/^[a-f0-9]{32}$/D', $correlation)) {
    foreach ($ids ?: [0] as $id) {
        try {
            (new \Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuditTrail(dirname(__DIR__)))->record(new \Kadupul\IdentityAccess\Contract\AuditEvent(
                $correlation,
                $actor,
                'inventory.device-template.' . $action,
                'device-template',
                $id > 0 ? (string) $id : 'new',
                $status === 'denied' ? 'denied' : 'allowed',
                $status === 'ok' ? 'succeeded' : ($status === 'denied' ? 'denied' : 'failed')
            ));
        } catch (Throwable) { /* Never replace a confirmed operation result with audit sink failure. */
        }
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_DEVICE_DEFINITION_RESULT=' . json_encode(['actor' => $actor, 'action' => $action, 'correlation' => $correlation, 'status' => $status, 'ids' => $ids, 'hooks' => $hooks], JSON_THROW_ON_ERROR) . "\n";
exit(in_array($status, ['ok', 'partial'], true) ? 0 : 1);
function definitionWorkerId(mixed $id, bool $zero = false): int
{
    if ((!is_int($id) && !is_string($id)) || !preg_match('/^[0-9]{1,8}$/D', (string) $id) || (int) $id < ($zero ? 0 : 1) || (int) $id > 16777215) {
        throw new InvalidArgumentException();
    }
    return (int) $id;
}
function definitionWorkerLock(PDO $db, int $id, string $revision): void
{
    $row = LegacyDeviceTemplateDefinitions::read($db, $id, true);
    if (!$row || !hash_equals($row->revision(), $revision)) {
        throw new DeviceEditConflict();
    }
}
function definitionWorkerStorage(PDO $db, array $tables): void
{
    $query = $db->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
    $query->execute($tables);
    $engines = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($tables as $table) {
        if (strtoupper($engines[$table] ?? '') !== 'INNODB') {
            throw new RuntimeException('Nontransactional storage.');
        }
    }
}
