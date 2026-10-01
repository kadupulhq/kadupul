<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require 'include/vendor/autoload.php';
$configuration = new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(getcwd());
$db = (new \Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase($configuration))->get();
$rival = (new \Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase($configuration))->get();
$rival->exec('SET SESSION innodb_lock_wait_timeout = 1');
\Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction::begin($db, $configuration->values(), ['settings_user']);
$actor = (int) $argv[1];
$results = [];
try {
    \Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateAuthorization::authorize($db, $actor, true);
    foreach ([
        'account' => ["UPDATE user_auth SET enabled='' WHERE id=?", [$actor]],
        'password policy' => ["UPDATE user_auth SET must_change_password='on' WHERE id=?", [$actor]],
        'console grant' => ['DELETE FROM user_auth_realm WHERE user_id=? AND realm_id=8', [$actor]],
        'template grant' => ['DELETE FROM user_auth_realm WHERE user_id=? AND realm_id=12', [$actor]],
        'auth policy' => ["UPDATE settings SET value='0' WHERE name='auth_method'", []],
    ] as $key => [$sql, $parameters]) {
        $rival->beginTransaction();
        try {
            $rival->prepare($sql)->execute($parameters);
            $results[$key] = false;
        } catch (PDOException $error) {
            $results[$key] = ($error->errorInfo[1] ?? null) === 1205;
        } finally {
            $rival->rollBack();
        }
    }
} finally {
    $db->rollBack();
}
$username = 'device-template-share-' . bin2hex(random_bytes(8));
$query = $db->prepare("INSERT INTO user_auth (username,enabled) VALUES (?, 'on')");
$query->execute([$username]);
$secondActor = (int) $db->lastInsertId();
try {
    $query = $db->prepare('INSERT INTO user_auth_realm (user_id,realm_id) VALUES (?,8),(?,12)');
    $query->execute([$secondActor, $secondActor]);
    \Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction::begin($db, $configuration->values(), ['settings_user']);
    \Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateAuthorization::authorize($db, $actor, true);
    \Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction::begin($rival, $configuration->values(), ['settings_user']);
    \Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateAuthorization::authorize($rival, $secondActor, true);
    $results['shared authorization across actors'] = $db->inTransaction() && $rival->inTransaction();
} finally {
    if ($rival->inTransaction()) $rival->rollBack();
    if ($db->inTransaction()) $db->rollBack();
    $db->prepare('DELETE FROM user_auth_realm WHERE user_id=?')->execute([$secondActor]);
    $db->prepare('DELETE FROM user_auth WHERE id=? AND username=?')->execute([$secondActor, $username]);
}
$db->beginTransaction();
try {
    $db->prepare("INSERT INTO settings (name,value) VALUES ('device_template_caller_probe','owned')")->execute();
    try {
        \Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction::begin($db, $configuration->values(), ['settings_user']);
        $results['caller ownership'] = false;
    } catch (RuntimeException $error) {
        $results['caller ownership'] = $db->inTransaction() && $db->query("SELECT value FROM settings WHERE name='device_template_caller_probe'")->fetchColumn() === 'owned';
    }
} finally {
    $db->rollBack();
}
foreach ([...\Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction::AUTHORIZATION_TABLES, 'settings_user'] as $table) {
    $db->exec('CREATE TEMPORARY TABLE `' . $table . '` (id INT) ENGINE=MyISAM');
    try {
        try {
            \Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction::begin($db, $configuration->values(), ['settings_user']);
            $results['shadow ' . $table] = false;
        } catch (RuntimeException $error) {
            $results['shadow ' . $table] = !$db->inTransaction() && $error->getMessage() === 'Nontransactional storage.';
        }
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        $db->exec('DROP TEMPORARY TABLE `' . $table . '`');
    }
}
try {
    \Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction::begin($db, ['poller_id' => 2], ['settings_user']);
    $results['primary collector'] = false;
} catch (RuntimeException $error) {
    $results['primary collector'] = !$db->inTransaction();
}
$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
try {
    \Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction::begin($db, $configuration->values(), ['settings_user']);
    $query = $db->prepare("SELECT value FROM settings_user WHERE user_id=? AND name='device_template_gap_probe' FOR UPDATE");
    $query->execute([$actor]);
    $rival->beginTransaction();
    try {
        $rival->prepare("INSERT INTO settings_user (user_id,name,value) VALUES (?, 'device_template_gap_probe', 'rival')")->execute([$actor]);
        $results['repeatable read gap lock'] = false;
    } catch (PDOException $error) {
        $results['repeatable read gap lock'] = ($error->errorInfo[1] ?? null) === 1205;
    } finally {
        $rival->rollBack();
    }
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
}
echo json_encode($results, JSON_THROW_ON_ERROR);
