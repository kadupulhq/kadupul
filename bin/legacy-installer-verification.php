<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('IN_CACTI_INSTALL', true);
define('KADUPUL_REDACT_DATABASE_LOGS', true);
define('KADUPUL_THROW_DATABASE_ERRORS', true);
define('KADUPUL_OPERATIONAL_LOCAL_ONLY', true);
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
ob_start(static fn(string $buffer): string => '', 4096);
try {
    $raw = stream_get_contents(STDIN, 16385);
    if (!is_string($raw) || strlen($raw) > 16384) {
        throw new InvalidArgumentException('Invalid verification request.');
    }
    $command = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($command) || ($command['confirmed'] ?? null) !== true || !is_array($command['graph_ids'] ?? null)
        || !is_string($command['operator'] ?? '') && ($command['operator'] ?? null) !== null) {
        throw new InvalidArgumentException('Invalid verification request.');
    }
    require __DIR__ . '/../include/cli_check.php';
    require __DIR__ . '/../install/installer-bootstrap.php';
    require_once __DIR__ . '/../lib/rrd.php';
    $kernel = require __DIR__ . '/../config/bootstrap.php';
    $kernel->boot();
    $data = $kernel->getContainer()->get(\Kadupul\Platform\Application\Installer\VerifyInstallationOperation::class)
        ->run($command['operator'] ?? null, $command['graph_ids'], true);
    $ok = true;
} catch (Throwable) {
    $ok = false;
    $data = ['error' => 'Operational evidence was not confirmed. Check consent, operator permissions and local configuration.'];
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
$receipt = json_encode(['status' => $ok ? 'ok' : 'failed', 'data' => $data], JSON_THROW_ON_ERROR);
if (strlen($receipt) > 131000) {
    $ok = false;
    $receipt = '{"status":"failed","data":{"error":"Operational evidence exceeded the response limit."}}';
}
$line = 'KADUPUL_OPERATIONAL_RESULT=' . $receipt . PHP_EOL;
$offset = 0;
while ($offset < strlen($line)) {
    $written = @fwrite(STDOUT, substr($line, $offset));
    if (!is_int($written) || $written < 1) {
        exit(1);
    }
    $offset += $written;
}
exit($ok ? 0 : 1);
