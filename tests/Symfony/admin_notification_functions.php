<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

function read_config_option($name)
{
    global $fixture;

    return $fixture['settings'][$name] ?? '';
}

function db_fetch_row_prepared($sql, $parameters)
{
    global $fixture, $evidence;
    $evidence['recipients'][] = $parameters;
    if ($fixture['lookup_error'] ?? false) {
        throw new Error('private-database-secret');
    }

    return $fixture['recipient'] ?? [];
}

function send_mail(...$arguments)
{
    global $fixture, $evidence;
    $evidence['legacy'][] = $arguments;

    return $fixture['legacy_error'] ?? '';
}

function cacti_log(...$arguments)
{
    global $evidence;
    $evidence['logs'][] = $arguments;
}
