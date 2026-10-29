<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
// The legacy database and settings helpers over a real MariaDB/MySQL schema,
// shared by the master, the queue worker and the child of BoostQueueDatabaseTest.
require dirname(__DIR__, 2) . '/include/vendor/autoload.php';

$pdo = new PDO(getenv('BOOST_QUEUE_DSN'), getenv('BOOST_QUEUE_USER'), getenv('BOOST_QUEUE_PASSWORD') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

function read_config_option($key, $force = false)
{
    $statement = $GLOBALS['pdo']->prepare('SELECT value FROM settings WHERE name = ?');
    $statement->execute(array($key));

    return $statement->fetchColumn();
}

function set_config_option($key, $value)
{
    $GLOBALS['pdo']->prepare('REPLACE INTO settings (name, value) VALUES (?, ?)')->execute(array($key, (string) $value));
}

function db_fetch_cell_prepared($sql, $params = array())
{
    $statement = $GLOBALS['pdo']->prepare($sql);
    $statement->execute($params);
    $value = $statement->fetchColumn();

    return $value === false ? false : (string) $value;
}

function db_execute_prepared($sql, $params = array())
{
    $GLOBALS['pdo']->prepare($sql)->execute($params);

    return true;
}

function db_execute($sql)
{
    $GLOBALS['pdo']->exec($sql);

    return true;
}
