<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * alerting_poller_bottom - starts native alert evaluation after a polling
 *   cycle (ADR 0011 decision 2). Only the main poller drains poller_output
 *   and feeds dsstats, so only it evaluates; nothing starts while
 *   alerting_enable is off. The command runs as its own background process
 *   and the poller never waits for it. Its named lock makes a start that
 *   overlaps a running evaluation exit at once.
 *
 * @param int    $poller_id the running poller's id
 * @param string $base_path the installation root
 *
 * @return bool whether the evaluator was started
 */
function alerting_poller_bottom(int $poller_id, string $base_path): bool
{
    if ($poller_id !== 1 || read_config_option('alerting_enable') !== 'on') {
        return false;
    }

    // exec_background() starts nothing for a path it cannot find, without a
    // word; an absolute path also keeps PATH from choosing the interpreter.
    $php = (string) read_config_option('path_php_binary');
    if (!preg_match('~^(/|[A-Za-z]:[\\\\/])~', $php) || !is_file($php)) {
        cacti_log('WARNING: Alert evaluation was not started: the PHP Binary Path setting is not an absolute path to a file.', false, 'ALERTING');

        return false;
    }

    // An argument array: exec_background() quotes each element.
    exec_background($php, array($base_path . '/bin/console', 'kadupul:alerting:evaluate', '--no-interaction'));

    return true;
}
