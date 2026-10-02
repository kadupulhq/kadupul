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
require getcwd() . '/include/config.php';

use Doctrine\DBAL\DriverManager;
use Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyVdefEditor;
use Kadupul\GraphDefinition\Infrastructure\Persistence\DoctrineVdefCatalog;
use Kadupul\Platform\Contract\LegacyConfiguration;

$actorId = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$vdefId = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($actorId === false || $vdefId === false) {
    throw new RuntimeException('Invalid transaction fixture identity.');
}
$database = DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $database_hostname, 'port' => $database_port,
    'dbname' => $database_default, 'user' => $database_username, 'password' => $database_password,
]);
$configuration = new class implements LegacyConfiguration {
    public int $collectorId = 1;
    public function values(): array
    {
        return ['collector_id' => $this->collectorId];
    }
};
$editor = new LegacyVdefEditor($database, $configuration);
$catalog = new DoctrineVdefCatalog($database);
$before = $catalog->find($vdefId);
if ($before === null) {
    throw new RuntimeException('Missing transaction fixture.');
}
$database->executeStatement('CREATE TEMPORARY TABLE vdef_items (id INT UNSIGNED PRIMARY KEY, hash VARCHAR(64), vdef_id INT UNSIGNED, sequence INT, type INT, value VARCHAR(150)) ENGINE=MyISAM');
try {
    try {
        $editor->save($actorId, $vdefId, 'must-not-save', $before['revision']);
        throw new LogicException('Nontransactional temporary shadow accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'VDEF writes require transactional tables.') {
            throw $error;
        }
    }
    if ($database->isTransactionActive() || $catalog->find($vdefId)['name'] !== $before['name']) {
        throw new RuntimeException('Temporary shadow refusal changed data or transaction state.');
    }
} finally {
    $database->executeStatement('DROP TEMPORARY TABLE vdef_items');
}
$observer = DriverManager::getConnection($database->getParams());
$observerCatalog = new DoctrineVdefCatalog($observer);
$persistentBefore = $observerCatalog->find($vdefId);
foreach (['vdef', 'vdef_items', 'graph_templates_item', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
    $database->executeStatement('CREATE TEMPORARY TABLE `' . $table . '` (guard_fixture INT) ENGINE=InnoDB');
    try {
        $denied = false;
        try {
            $editor->save($actorId, $vdefId, 'must-not-save', $before['revision']);
        } catch (RuntimeException $error) {
            $denied = $error->getMessage() === 'VDEF writes require transactional tables.';
        }
        if (!$denied || $database->isTransactionActive() || $database->getNativeConnection()->inTransaction()) {
            throw new RuntimeException('Persistent VDEF storage guard did not reject temporary ' . $table);
        }
        if ($observerCatalog->find($vdefId) !== $persistentBefore) {
            throw new RuntimeException('Temporary VDEF shadow changed persistent observer values.');
        }
    } finally {
        $database->executeStatement('DROP TEMPORARY TABLE `' . $table . '`');
    }
}
$observer->close();
$database->beginTransaction();
$database->update('vdef', ['name' => 'caller-private'], ['id' => $vdefId]);
try {
    $editor->save($actorId, $vdefId, 'must-not-save', $before['revision']);
    throw new LogicException('Caller-owned transaction accepted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'VDEF writes require their own transaction.') {
        throw $error;
    }
}
if (!$database->isTransactionActive() || $database->fetchOne('SELECT name FROM vdef WHERE id = ?', [$vdefId]) !== 'caller-private') {
    throw new RuntimeException('Caller transaction or prior write was lost.');
}
$database->rollBack();
if ($catalog->find($vdefId)['revision'] !== $before['revision']) {
    throw new RuntimeException('Caller rollback did not restore the fixture.');
}
$native = $database->getNativeConnection();
if (!$native instanceof PDO) {
    throw new RuntimeException('Expected native PDO fixture.');
}
$native->beginTransaction();
$database->update('vdef', ['name' => 'caller-native'], ['id' => $vdefId]);
$nesting = $database->getTransactionNestingLevel();
try {
    $editor->save($actorId, $vdefId, 'must-not-save', $before['revision']);
    throw new LogicException('Native caller transaction accepted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'VDEF writes require their own transaction.') {
        throw $error;
    }
}
if (!$native->inTransaction() || $database->getTransactionNestingLevel() !== $nesting || $database->fetchOne('SELECT name FROM vdef WHERE id = ?', [$vdefId]) !== 'caller-native') {
    throw new RuntimeException('Native transaction, nesting, or prior write was lost.');
}
$native->rollBack();
if ($catalog->find($vdefId)['revision'] !== $before['revision']) {
    throw new RuntimeException('Native rollback did not restore the fixture.');
}
$configuration->collectorId = 2;
try {
    $editor->save($actorId, $vdefId, 'must-not-save', $before['revision']);
    throw new LogicException('Remote collector mutation accepted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'VDEF writes require the primary collector.') {
        throw $error;
    }
}
if ($database->isTransactionActive() || $catalog->find($vdefId)['revision'] !== $before['revision']) {
    throw new RuntimeException('Remote collector refusal changed data or transaction state.');
}
$configuration->collectorId = 1;
$database->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$editor->save($actorId, $vdefId, $before['name'], $before['revision']);
if ($database->isTransactionActive() || $catalog->find($vdefId)['revision'] !== $before['revision']) {
    throw new RuntimeException('Primary mutation failed under alternate session isolation.');
}
echo json_encode(['caller_preserved' => true, 'native_preserved' => true, 'remote_refused' => true, 'primary_confirmed' => true, 'temporary_shadow_refused' => true, 'persistent_shadows_refused' => true], JSON_THROW_ON_ERROR), PHP_EOL;
