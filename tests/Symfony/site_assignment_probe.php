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
$configuration = new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(getcwd());
$db = (new \Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase($configuration))->get();
$name = 'site-assignment-race-' . bin2hex(random_bytes(6));
$db->prepare('INSERT INTO sites (name) VALUES (?)')->execute([$name]);
$siteId = (int) $db->lastInsertId();
$process = null;
try {
    $valid = proc_open([PHP_BINARY, 'cli/add_device.php', '--description=' . $name . '-valid', '--ip=' . $name . '-valid.invalid', '--template=0', '--version=0', '--avail=none', '--site=' . $siteId], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $validPipes);
    fclose($validPipes[0]);
    stream_get_contents($validPipes[1]);
    stream_get_contents($validPipes[2]);
    fclose($validPipes[1]);
    fclose($validPipes[2]);
    $validExit = proc_close($valid);
    $validSite = $db->prepare('SELECT site_id FROM host WHERE description = ?');
    $validSite->execute([$name . '-valid']);
    if ($validExit !== 0 || (int) $validSite->fetchColumn() !== $siteId) {
        throw new RuntimeException('Valid device assignment failed');
    }
    $db->beginTransaction();
    $db->query('SELECT id FROM sites WHERE id = ' . $siteId . ' FOR UPDATE')->fetchColumn();
    $process = proc_open([PHP_BINARY, 'cli/add_device.php', '--description=' . $name, '--ip=' . $name . '.invalid', '--template=0', '--version=0', '--avail=none', '--site=' . $siteId], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start concurrent device save');
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 15;
    do {
        $waiting = (int) $db->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID != CONNECTION_ID() AND INFO LIKE 'SELECT id FROM sites WHERE id = % FOR UPDATE'")->fetchColumn();
        if ($waiting > 0) {
            break;
        }
        if (!proc_get_status($process)['running']) {
            throw new RuntimeException('Device save did not wait for the site lock');
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    if (!$waiting) {
        throw new RuntimeException('Concurrent device save did not acquire the site protocol');
    }
    // Match the deletion transaction after its site-lock/revision checks.
    $db->prepare("UPDATE host SET site_id = 0 WHERE deleted = '' AND site_id = ?")->execute([$siteId]);
    $db->prepare('DELETE FROM sites WHERE id = ?')->execute([$siteId]);
    $db->commit();
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $process = null;
    $query = $db->prepare('SELECT COUNT(*) FROM host WHERE description = ? OR site_id = ?');
    $query->execute([$name, $siteId]);
    $remaining = (int) $query->fetchColumn();
    if ($exit === 0 || $remaining !== 0) {
        throw new RuntimeException('Concurrent assignment failure: exit=' . $exit . ', remaining devices=' . $remaining);
    }
    $validSite->execute([$name . '-valid']);
    if ((int) $validSite->fetchColumn() !== 0) {
        throw new RuntimeException('Deletion did not unassign the committed device');
    }
    echo 'concurrent assignment rejected';
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    $db->prepare('DELETE FROM host WHERE description IN (?, ?)')->execute([$name, $name . '-valid']);
    $db->prepare('DELETE FROM sites WHERE id = ?')->execute([$siteId]);
}
