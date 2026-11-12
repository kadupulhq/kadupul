<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\DataInput\Domain\DataInputNotFound;
use Kadupul\DataInput\Domain\DataQueryRejected;
use Kadupul\DataInput\Domain\DataQuerySettings;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Reads and saves the settings of a data_queries.php data query on the primary
// database. A save, the Data Input Method push-out to data templates, data
// sources and the poller cache, and the replication checksum share one
// transaction, so a failure anywhere leaves the primary as it was.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
define('KADUPUL_THROW_DATABASE_ERRORS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
require_once __DIR__ . '/../lib/poller.php';
require_once __DIR__ . '/../lib/utility.php';
require_once __DIR__ . '/../lib/data_query.php';
require_once __DIR__ . '/../lib/data_input_worker.php';
require_once __DIR__ . '/worker-io.php';

const DATA_QUERY_MARKER = 'KADUPUL_DATA_QUERY_RESULT';

/** @return array{id: int, name: string, description: string, xml_path: string, data_input_id: int}|null */
function dataQueryWorkerRow(PDO $db, int $id, bool $lock): ?array
{
    $query = $db->prepare('SELECT id, name, description, xml_path, data_input_id FROM snmp_query WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([$id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'description' => (string) ($row['description'] ?? ''),
        'xml_path' => (string) $row['xml_path'], 'data_input_id' => (int) $row['data_input_id']];
}

/** The file get_data_query_array() would read for this xml_path, or null when it would refuse it. */
function dataQueryWorkerXmlFile(string $basePath, string $xmlPath): ?string
{
    // The same substitution and resource-tree containment as get_data_query_array().
    $file = str_replace(['<path_cacti>', '<path_snmpget>', '<path_php_binary>'], [$basePath, read_config_option('path_snmpget'), read_config_option('path_php_binary')], $xmlPath);

    return cacti_path_is_within($file, $basePath . '/resource') && is_file($file) && is_readable($file) ? $file : null;
}

$status = 'failed';
$result = [];
$command = [];
$db = null;
try {
    $command = \Kadupul\LegacyWorker\read_json_command(STDIN, 16384, 4, InvalidArgumentException::class, 'Invalid command.');
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'action', 'id', 'nonce', 'payload']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] < 1
        || !is_int($command['id'] ?? null) || $command['id'] < 0 || $command['id'] > DataQuerySettings::ID_MAX
        || !is_string($command['nonce'] ?? null) || preg_match('/\A[a-f0-9]{32}\z/', $command['nonce']) !== 1
        || !in_array($command['action'] ?? null, ['find', 'save'], true) || !is_array($command['payload'] ?? null)) {
        throw new InvalidArgumentException('Invalid command.');
    }
    $db = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    if (!$db instanceof PDO || (int) ($config['poller_id'] ?? 0) !== 1) {
        throw new RuntimeException('Primary database required.');
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    if (!$db->beginTransaction()) {
        throw new RuntimeException('Transaction unavailable.');
    }
    // The grant is read with shared locks inside the transaction that writes.
    dataInputWorkerAuthorize($db, $command['actor'], [8, 13]);
    $_SESSION = ['sess_user_id' => $command['actor']];
    $id = $command['id'];
    $payload = $command['payload'];

    if ($command['action'] === 'find') {
        $row = $id === 0 ? null : dataQueryWorkerRow($db, $id, false);
        if ($id > 0 && $row === null) {
            throw new DataInputNotFound('Data query not found.');
        }
        $inputs = [];
        foreach ($db->query('SELECT id, name FROM data_input WHERE type_id IN (' . implode(',', DataQuerySettings::INPUT_TYPES) . ') ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC) as $input) {
            $inputs[(int) $input['id']] = (string) $input['name'];
        }
        $result = ['query' => $row, 'revision' => $row === null ? '' : DataQuerySettings::revision($row),
            'xml' => $row === null ? 'none' : (dataQueryWorkerXmlFile($config['base_path'], $row['xml_path']) === null ? 'missing' : 'found'), 'inputs' => $inputs];
        // Nothing was written; end the snapshot without a commit.
        $db->rollBack();
        $status = 'ok';
    } else {
        $settings = DataQuerySettings::fromArray(is_array($payload['settings'] ?? null) ? $payload['settings'] : []);
        $previous = null;
        if ($id > 0) {
            $previous = dataQueryWorkerRow($db, $id, true);
            if ($previous === null) {
                throw new DataInputNotFound('Data query not found.');
            }
            if (!is_string($payload['revision'] ?? null) || !hash_equals(DataQuerySettings::revision($previous), $payload['revision'])) {
                throw new DataInputWorkerConflict();
            }
        }
        $input = $db->prepare('SELECT id FROM data_input WHERE id = ? AND type_id IN (' . implode(',', DataQuerySettings::INPUT_TYPES) . ') LOCK IN SHARE MODE');
        $input->execute([$settings->dataInputId]);
        if ($input->fetchColumn() === false) {
            throw new DataQueryRejected('Choose an existing Data Input Method.', [], 'data_input_id');
        }
        if (dataQueryWorkerXmlFile($config['base_path'], $settings->xmlPath) === null) {
            throw new DataQueryRejected('Enter the path of a readable XML file under the resource directory.', [], 'xml_path');
        }
        $saved = (int) sql_save(['id' => $id, 'hash' => get_hash_data_query($id)] + $settings->columns(), 'snmp_query');
        if ($saved < 1 || ($id > 0 && $saved !== $id)) {
            throw new RuntimeException('Save was not confirmed.');
        }
        $stored = dataQueryWorkerRow($db, $saved, true);
        if ($stored === null || array_diff_assoc(array_map('strval', $settings->columns()), array_map('strval', array_diff_key($stored, ['id' => true]))) !== []) {
            throw new RuntimeException('Save was not confirmed.');
        }
        // data_queries.php ran the push-out whenever the query already had a
        // method, because it also repairs templates whose method drifted.
        // Its poller cache write commits to a remote collector's database on
        // that collector's own connection; that part is outside this
        // transaction, as it was outside any transaction before.
        if ($previous !== null && $previous['data_input_id'] > 0) {
            data_query_update_input_method($saved, $previous['data_input_id'], $settings->dataInputId);
        }
        update_replication_crc(0, 'poller_replicate_snmp_query_crc');
        if (!$db->commit()) {
            throw new RuntimeException('Commit was not confirmed.');
        }
        // A collector that was down only raised a message; report it.
        $down = array_values(array_filter(array_keys($_SESSION['sess_messages'] ?? []), static fn($key): bool => is_string($key) && str_starts_with($key, 'poller_down_')));
        $result = ['id' => $saved, 'partial' => $down !== []];
        $status = 'ok';
    }
} catch (DataInputWorkerDenied) {
    $status = 'denied';
} catch (DataInputWorkerConflict) {
    $status = 'conflict';
} catch (DataInputNotFound $error) {
    $status = 'not_found';
    $result = ['message' => $error->getMessage()];
} catch (DataQueryRejected $error) {
    $status = 'invalid';
    $result = ['message' => $error->getMessage(), 'parameters' => $error->parameters, 'field' => $error->field];
} catch (InvalidArgumentException|JsonException) {
    $status = 'invalid';
    $result = ['message' => 'Invalid command.', 'parameters' => [], 'field' => null];
} catch (Throwable $error) {
    $status = 'failed';
    cacti_log('Data query operation failed (' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . ').', false, 'DATAQUERY');
} finally {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo DATA_QUERY_MARKER . '=' . json_encode(['actor' => $command['actor'] ?? 0, 'action' => $command['action'] ?? '', 'request_id' => $command['id'] ?? 0,
    'nonce' => $command['nonce'] ?? '', 'status' => $status, 'result' => $result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
exit($status === 'ok' ? 0 : 1);
