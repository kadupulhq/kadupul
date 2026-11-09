<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Fixture for check_marker_before_exit.php; never executed.

function early_exit_in_branch($ok)
{
    set_config_option('job_lastrun', time());
    if (!$ok) {
        exit(1);
    }
    run_job();
}

function terminal_exit_ends_the_run()
{
    run_job();
    set_config_option('job_lastrun', time());
    exit(0);
}

function exit_in_the_other_branch($master)
{
    if ($master) {
        set_config_option('job_last_end_time', time());
    } else {
        exit(1);
    }
}

function nothing_runs_after_a_terminal_exit($save)
{
    if ($save) {
        set_config_option('time_last_change_tree', time());
        header('Location: tree.php');
        exit;
    }
    if (bad()) {
        exit;
    }
}

function die_in_an_expression()
{
    set_config_option('job_stamp', time());
    connect() or die('no database');
}

function exit_in_a_loop($items)
{
    set_config_option('job_last_run_time', time());
    foreach ($items as $item) {
        if (!$item) {
            exit(2);
        }
    }
}

function ordinary_setting_is_ignored()
{
    set_config_option('path_rrdtool', '/usr/bin/rrdtool');
    exit(1);
}

function nested_scope_is_separate()
{
    set_config_option('job_lastrun', time());
    $handler = function () {
        exit(1);
    };
    return $handler;
}

function exit_before_the_marker()
{
    if (bad()) {
        exit(1);
    }
    set_config_option('job_lastrun', time());
}

if (empty($last) || (time() - $last > 14400)) {
    set_config_option('poller_mibsrun_' . $poller_id, time());
}

if (overlapping()) {
    exit;
}

function exit_earlier_in_the_loop($items)
{
    foreach ($items as $item) {
        if (!$item) {
            exit(1);
        }
        set_config_option('job_lastrun_' . $item, time());
    }
}

function exit_in_the_other_branch_of_a_loop()
{
    while (next_item()) {
        if (ready()) {
            set_config_option('job_last_poll', time());
        } else {
            exit(1);
        }
    }
}

function exit_before_a_loop_that_holds_no_marker($items)
{
    foreach ($items as $item) {
        if (!$item) {
            exit(1);
        }
    }
    set_config_option('job_lastrun', time());
}

function bail_out()
{
    cleanup();
    exit(1);
}

function fail_validation(): never
{
    throw new RuntimeException('invalid');
}

function exit_through_a_helper()
{
    set_config_option('job_stamp', time());
    if (bad()) {
        bail_out();
    }
    run_job();
}

function exit_through_a_never_function()
{
    set_config_option('job_lastrun', time());
    valid() || fail_validation();
}

function a_helper_that_ends_the_run()
{
    run_job();
    set_config_option('job_lastrun', time());
    bail_out();
}
