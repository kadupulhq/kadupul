<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Stock data input methods that ran Perl or Python scripts, keyed by hash:
 * the input string Kadupul shipped and the PHP port that replaces it.
 *
 * @return array<string, array{string, string}>
 */
function upgrade_package_script_inputs(): array
{
    return [
        '95ed0993eb3095f9920d431ac80f4231' => ['perl <path_cacti>/scripts/loadavg_multi.pl', '<path_php_binary> -q <path_cacti>/scripts/loadavg_multi.php'],
        '79a284e136bb6b061c6f96ec219ac448' => ['perl <path_cacti>/scripts/unix_users.pl <username>', '<path_php_binary> -q <path_cacti>/scripts/unix_users.php <username>'],
        '362e6d4768937c4f899dd21b91ef0ff8' => ['perl <path_cacti>/scripts/linux_memory.pl <grepstr>', '<path_php_binary> -q <path_cacti>/scripts/linux_memory.php <grepstr>'],
        'a637359e0a4287ba43048a5fdf202066' => ['perl <path_cacti>/scripts/unix_processes.pl', '<path_php_binary> -q <path_cacti>/scripts/unix_processes.php'],
        '8bd153aeb06e3ff89efc73f35849a7a0' => ['perl <path_cacti>/scripts/ping.pl <ip>', '<path_php_binary> -q <path_cacti>/scripts/ping.php <ip>'],
        '1da6c132fde30587acb733f5de7d218c' => ['<path_cacti>/scripts/motorola_surfboard_SB6141.py Downstream <hostname>', '<path_php_binary> -q <path_cacti>/scripts/motorola_surfboard_SB6141.php Downstream <hostname>'],
        '439b48461e81a54fabb2d60c0f9bd764' => ['<path_cacti>/scripts/motorola_surfboard_SB6141.py Upstream <hostname>', '<path_php_binary> -q <path_cacti>/scripts/motorola_surfboard_SB6141.php Upstream <hostname>'],
        '664283d1f86ebfb17cc7d51eae12c52a' => ['<path_cacti>/scripts/motorola_surfboard_SB6141.py Stats <hostname>', '<path_php_binary> -q <path_cacti>/scripts/motorola_surfboard_SB6141.php Stats <hostname>'],
    ];
}

/**
 * Points the stock methods at the PHP ports. A row whose input string an
 * operator changed in any way, even only in case or trailing spaces, is left
 * alone, so the comparison is on bytes rather than the column's collation.
 * The Perl scripts stay installed for those rows and for poller items cached
 * before the upgrade; Rebuild Poller Cache switches the cached items.
 */
function upgrade_to_1_2_36(): void
{
    foreach (upgrade_package_script_inputs() as $hash => [$shipped, $port]) {
        db_install_execute(
            'UPDATE data_input
			SET input_string = ?
			WHERE hash = ?
			AND CAST(input_string AS BINARY) = CAST(? AS BINARY)',
            [$port, $hash, $shipped]
        );
    }

    // Remote collectors copy data_input when this value changes. A fixed value
    // for this step changes it once and keeps the upgrade's result repeatable.
    // Nothing compares the value or its length, so the algorithm is arbitrary.
    db_install_execute(
        'REPLACE INTO settings (name, value) VALUES (?, ?)',
        ['poller_replicate_data_input_crc', hash('sha256', 'kadupul 1.2.36 package script inputs')]
    );
}
