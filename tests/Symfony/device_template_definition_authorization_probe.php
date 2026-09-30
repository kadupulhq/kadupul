<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require 'include/vendor/autoload.php';
$configuration = new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(getcwd());
$db = (new \Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase($configuration))->get();
$rival = (new \Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase($configuration))->get();
$rival->exec('SET SESSION innodb_lock_wait_timeout = 1');
$db->beginTransaction();
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
echo json_encode($results, JSON_THROW_ON_ERROR);
