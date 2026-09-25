<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What a console command test reads before it reaches its port: the operator
 * tables CliConsoleAccess queries and the files InstallationVersion and the
 * operator log expect under the installation root. Both are at version 1.3.0.
 */
trait ConsoleOperatorDatabase
{
    /** @var list<string> */
    private array $installationRoots = [];

    /** User 1, admin, is admin_user and holds each realm named. */
    private function operatorDatabase(int ...$realms): Connection
    {
        return $this->operatorSchema(true, $realms);
    }

    /**
     * The same schema without the group tables, as an installation that
     * predates them has it: CliConsoleAccess must then check direct realms only.
     */
    private function operatorDatabaseWithoutGroups(int ...$realms): Connection
    {
        return $this->operatorSchema(false, $realms);
    }

    /** @param list<int> $realms */
    private function operatorSchema(bool $groups, array $realms): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE version (cacti TEXT)',
            "INSERT INTO version VALUES ('1.3.0')",
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            "INSERT INTO settings VALUES ('admin_user', '1')",
            'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)',
            "INSERT INTO user_auth VALUES (1, 'admin', 'on', '', '')",
            'CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)',
        ] as $statement) {
            $db->executeStatement($statement);
        }
        foreach ($realms as $realm) {
            $db->insert('user_auth_realm', ['user_id' => 1, 'realm_id' => $realm]);
        }
        if ($groups) {
            $db->executeStatement('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)');
            $db->executeStatement('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
            $db->executeStatement('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
        }

        return $db;
    }

    /** A throwaway installation root with include/cacti_version and an empty log/. */
    private function installationRoot(string $prefix): string
    {
        $root = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        (new Filesystem())->dumpFile($root . '/include/cacti_version', "1.3.0\n");
        mkdir($root . '/log', 0700);
        $this->installationRoots[] = $root;

        return $root;
    }

    #[After]
    protected function removeInstallationRoots(): void
    {
        (new Filesystem())->remove($this->installationRoots);
        $this->installationRoots = [];
    }
}
