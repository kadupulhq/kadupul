<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('IN_CACTI_INSTALL', true);
define('KADUPUL_REDACT_DATABASE_LOGS', true);
define('KADUPUL_THROW_DATABASE_ERRORS', true);
ob_start(static fn(string $buffer): string => '', 4096);
try {
    $raw = stream_get_contents(STDIN, 65538);
    $command = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (strlen($raw) > 65536 || !is_array($command) || !is_string($command['action'] ?? null)
        || !in_array($command['action'], ['check', 'status', 'configure', 'start', 'recover'], true)
        || !is_array($command['options'] ?? null)
        || !is_bool($command['retry'] ?? null) || !is_bool($command['discovery_confirmed'] ?? null)
        || !is_string($command['operator'] ?? '') && ($command['operator'] ?? null) !== null) {
        throw new InvalidArgumentException('Invalid installer worker request.');
    }
    define('KADUPUL_INSTALLER_OPERATOR', $command['operator'] ?? null);
    require __DIR__ . '/../include/cli_check.php';
    require __DIR__ . '/../install/installer-bootstrap.php';
    $kernel = require __DIR__ . '/../config/bootstrap.php';
    $kernel->boot();
    $workflow = $kernel->getContainer()->get(\Kadupul\Platform\Application\Installer\InstallerWorkflow::class);
    $data = match ($command['action']) {
        'check' => $workflow->inspect(),
        'status' => $workflow->status(),
        'recover' => (static function () use ($workflow, $command): array {
            if (!is_int($command['attempt'] ?? null)) {
                throw new InvalidArgumentException('Recovery requires an exact attempt number.');
            }
            $workflow->recover($command['attempt'], $command['retry']);
            return $workflow->status();
        })(),
        'configure', 'start' => $command['action'] === 'start' && $command['retry'] && $command['options'] === []
            ? $workflow->inspect() : $workflow->configure($command['options']),
    };
    if ($command['action'] === 'start') {
        $workflow->start($data['revision'], true, $command['discovery_confirmed'], $command['retry']);
        $data = $workflow->status();
    }
    // Successful recovery records a stopped attempt as failed; it does not
    // claim that installation succeeded or start another worker.
    $ok = in_array($command['action'], ['recover', 'check', 'configure'], true)
        || (!in_array($data['state'] ?? '', ['failed', 'partial'], true) && !in_array($data['job']['state'] ?? '', ['failed', 'partial'], true));
} catch (Throwable $error) {
    $ok = false;
    $data = ['error' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Installation could not proceed. Check authorization, prerequisites and the installer log.'];
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
print 'KADUPUL_INSTALLER_RESULT=' . json_encode(['status' => $ok ? 'ok' : 'failed', 'data' => $data], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($ok ? 0 : 1);
