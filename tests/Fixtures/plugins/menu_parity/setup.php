<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Synthetic plugin that extends the console menu the way installed plugins do. */
function plugin_menu_parity_version()
{
    return ['name' => 'menu_parity', 'longname' => 'Console Menu Parity Fixture',
        'version' => '1.0.0', 'author' => 'Kadupul', 'homepage' => 'https://example.invalid'];
}

function plugin_menu_parity_install()
{
    api_plugin_register_hook('menu_parity', 'config_arrays', 'menu_parity_config_arrays', 'setup.php');
    api_plugin_register_realm('menu_parity', 'parity.php,parity_tools.php', 'Menu Parity', 1);
}

function plugin_menu_parity_check_config()
{
    return true;
}

function plugin_menu_parity_uninstall()
{
}

function menu_parity_config_arrays()
{
    global $menu;

    $menu[__('Management')]['plugins/menu_parity/parity.php'] = __('Parity <Checks>');
    $menu[__('Parity Tools')]['plugins/menu_parity/parity_tools.php?action=list'] = __('Parity Tools');
    $menu[__('Parity Tools')]['https://parity.example.invalid/parity_tools.php'] = __('Remote Parity');
    $menu[__('Parity Tools')]['EXTERNAL::https://parity.example.invalid/'] = __('External Parity');
    $menu[__('Parity Tools')]['plugins/menu_parity/unregistered.php'] = __('Unregistered Parity');
}
