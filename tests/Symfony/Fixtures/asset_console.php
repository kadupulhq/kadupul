<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// A subprocess harness for the production command, persistence and operator
// checks. Only its installation root/database come from the test fixture.
require getenv('KADUPUL_ASSET_PROJECT') . '/include/vendor/autoload.php';

$params = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse(getenv('KADUPUL_ASSET_TEST_DSN'));
if (!str_ends_with($params['dbname'] ?? '', '_assets_test')) {
    throw new RuntimeException('Disposable asset database required.');
}
$params['password'] = getenv('KADUPUL_ASSET_TEST_PASSWORD') ?: ($params['password'] ?? '');
$db = Doctrine\DBAL\DriverManager::getConnection($params);
$operator = new Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess($db, $db);
$topology = new Kadupul\Platform\Infrastructure\Legacy\CollectorIdentity(new Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(getcwd()));
$access = new Kadupul\AssetManagement\Infrastructure\Console\CliAssetAccess(true, $operator, $topology);
$store = new Kadupul\AssetManagement\Infrastructure\Persistence\DoctrineAssetStore($db);
$registry = new Kadupul\AssetManagement\Application\AssetRegistry($access, $store, new Kadupul\AssetManagement\Infrastructure\Persistence\MonitoringSnapshot($db), new Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuditTrail(getcwd()));
$application = new Symfony\Component\Console\Application();
$application->add(new Kadupul\AssetManagement\Infrastructure\Console\AssetCommand($registry, new Kadupul\AssetManagement\Application\ViewAssets($access, $store), $operator, $access));
$application->run();
