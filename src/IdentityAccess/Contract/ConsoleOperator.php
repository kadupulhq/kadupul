<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/**
 * The account a command-line run acts as. Command-line use only: it names an
 * account and applies the same account and realm checks as the web path, but
 * it does not authenticate anyone. Web code resolves actors through
 * ConsoleAccess, which this contract deliberately does not extend, so no
 * route can reach an operator chosen by a command-line flag.
 */
interface ConsoleOperator
{
    /**
     * Null or '' selects the admin_user setting. The account is checked on
     * the database the command works on, so a collector run against its local
     * database does not depend on reaching the main one.
     */
    public function select(?string $username, OperatorDatabase $database): void;

    /** Null when the selected account may not use the console at all. */
    public function actor(): ?Actor;

    public function canAdministerInstallation(Actor $actor): bool;

    /**
     * Realm 26, Installation/Upgrades. Core schema changes on the web run only
     * in the install wizard (lib/installer.php, install/upgrades/), which that
     * realm guards, so command-line schema changes need it too.
     */
    public function canUpgradeInstallation(Actor $actor): bool;

    /**
     * Realm 1, User Management: user_admin.php (include/global_arrays.php:1310),
     * where the web copies users and edits their item permissions.
     */
    public function canManageUsers(Actor $actor): bool;

    /**
     * Realm 5, Graphs: graphs.php and graphs_new.php (include/global_arrays.php:1288),
     * where the web edits, repairs and removes graphs and renames them from a template.
     */
    public function canManageGraphs(Actor $actor): bool;

    /**
     * Realm 3, Devices: host.php (include/global_arrays.php:1293), where the web
     * associates graph templates and data queries with a device.
     */
    public function canManageDevices(Actor $actor): bool;

    /**
     * Realm 4, Trees: tree.php (include/global_arrays.php:1289), where the web
     * creates graph trees and adds header, graph, device and site nodes to them.
     */
    public function canManageTrees(Actor $actor): bool;
}
