<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Test-only instrumentation in the disposable HTTP container. Observe actual
// sessions at entry to each legacy writer, without changing connection settings.
if (PHP_SAPI !== 'cli' || getcwd() !== '/var/www/html' || !is_dir('/artifacts')) {
    exit(1);
}
$file = getcwd() . '/lib/api_device.php';
$backup = '/artifacts/api_device.state-probe.original';
$instrumented = '/artifacts/api_device.state-probe.php';
if (($argv[1] ?? '') === 'restore') {
    if (!is_file($backup) || is_link($backup) || !is_link($file)
        || readlink($file) !== $instrumented || !rename($backup, $file)) {
        throw new RuntimeException('Cannot restore state writer fixture');
    }
    if (!unlink($instrumented)) {
        throw new RuntimeException('Cannot remove state writer fixture');
    }
    exit;
}
if (($argv[1] ?? '') !== 'install' || file_exists($backup) || is_link($backup)
    || file_exists($instrumented) || is_link($instrumented) || is_link($file)) {
    throw new RuntimeException('Invalid state probe setup');
}
$source = file_get_contents($file);
if ($source === false) {
    throw new RuntimeException('Cannot read state writer source');
}
$observer = <<<'PHP'

    global $database_sessions;
    $observed = [];
    foreach ($database_sessions as $session) {
        $observed[] = $session->query('SELECT DATABASE() AS db, @@SESSION.sql_mode AS mode, @@SESSION.character_set_client AS client, @@SESSION.character_set_connection AS connection, @@SESSION.character_set_results AS results')->fetch(PDO::FETCH_ASSOC);
    }
    file_put_contents('/artifacts/state-session-modes.jsonl', json_encode(['writer' => __FUNCTION__, 'sessions' => $observed]) . "\n", FILE_APPEND | LOCK_EX);
PHP;
// Keep original source line numbers for integration coverage attribution.
$observer = str_replace(["\r", "\n"], ' ', $observer);
foreach (['api_device_disable_devices', 'api_device_enable_devices'] as $function) {
    $pattern = '/function ' . $function . '\(\$device_ids\)(?:: bool)?\s*\{/';
    if (preg_match_all($pattern, $source) !== 1) {
        throw new RuntimeException('State writer fixture no longer matches');
    }
    // Preserve the matched brace style and every original newline: both legacy
    // and PER-CS formatting must retain exact coverage source attribution.
    $source = preg_replace_callback($pattern, static fn(array $match): string => $match[0] . $observer, $source);
}
// Give instrumented execution its own source identity outside the production
// coverage root. Other scenarios still measure the unchanged production helper;
// the merger must never accept a different hash under its canonical path.
if (!copy($file, $backup)) {
    throw new RuntimeException('Cannot install state writer fixture');
}
try {
    if (file_put_contents($instrumented, $source) !== strlen($source)
        || !unlink($file) || !symlink($instrumented, $file)) {
        throw new RuntimeException('Cannot install state writer fixture');
    }
} catch (Throwable $failure) {
    if (!rename($backup, $file)) {
        throw new RuntimeException('Cannot restore failed state writer fixture', 0, $failure);
    }
    if (is_file($instrumented)) {
        unlink($instrumented);
    }
    throw $failure;
}
