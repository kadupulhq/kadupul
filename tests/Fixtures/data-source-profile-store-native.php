<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// One profile store write in its own process, so a test can hold the locks
// it waits for. Prints the connection ID, then the outcome as JSON.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/include/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Application\Port\DataSourceProfileAccess;
use Kadupul\Graphing\Infrastructure\Persistence\DbalDataSourceProfileStore;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;

$request = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$database = DriverManager::getConnection($request['connection']);
$database->executeStatement('SET SESSION innodb_lock_wait_timeout=30');
echo $database->fetchOne('SELECT CONNECTION_ID()') . "\n";
flush();

$store = new DbalDataSourceProfileStore(
    $database,
    new class implements DataSourceProfileAccess {
        public function authorize(): Actor
        {
            return new Actor(7, 'fixture-admin');
        }

        public function assertCurrent(int $actorId): void {}
    },
    new class implements AuditTrail {
        public function record(AuditEvent $event): void {}
    },
    new class implements LegacyConfiguration {
        public function values(): array
        {
            return ['collector_id' => 1];
        }
    },
);

try {
    $result = match ($request['operation']) {
        'save' => $store->save(7, 3, 'changed', null, null, null, [], false, $request['revision']),
        'rra' => $store->saveArchive(7, 3, null, 'new', 3600, 100, 86400, null),
        'copy' => $store->duplicate(7, [3], [3 => $request['revision']], '<profile_title> copy'),
        'remove' => $store->deleteArchive(7, 3, 13, $request['archive']),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
