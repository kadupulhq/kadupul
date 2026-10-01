<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyCdefEditor;
use Kadupul\Tests\Fixtures\RealMariaDb;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class CdefMariaDbTest extends TestCase
{
    use RealMariaDb;

    public function testDuplicateFailureAfterNewDefinitionInsertRollsBackEntireMutation(): void
    {
        $database = $this->realMariaDb();
        $this->createTemporaryTables($database);
        try {
            $database->executeStatement("INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', 'guest')");
            $database->executeStatement("INSERT INTO user_auth VALUES (42, 'operator', 'on', '', '')");
            $database->executeStatement('INSERT INTO user_auth_realm VALUES (42, 8), (42, 14)');
            $database->executeStatement("INSERT INTO cdef VALUES (1, 'source-hash', 0, 'Source')");
            $database->executeStatement("INSERT INTO cdef_items VALUES (1, 'source-item-hash', 1, 1, 6, '7')");
            $database->executeStatement('ALTER TABLE cdef AUTO_INCREMENT = 99');

            $failure = null;
            try {
                (new LegacyCdefEditor($database, $this->primaryConfiguration()))->act(42, 'duplicate', [1], '<cdef_title> (1)', [1 => (new \Kadupul\GraphDefinition\Infrastructure\Persistence\DoctrineCdefCatalog($database))->find(1)['revision']]);
            } catch (\Throwable $error) {
                $failure = $error;
            }
            self::assertNotNull($failure, 'The forced item constraint must fail after inserting the duplicate CDEF row.');
            self::assertStringContainsString('cdef_item_test_check', $failure->getMessage());
            self::assertSame(1, (int) $database->fetchOne('SELECT COUNT(*) FROM cdef'));
            self::assertSame(1, (int) $database->fetchOne('SELECT COUNT(*) FROM cdef_items'));
            self::assertSame('Source', $database->fetchOne('SELECT name FROM cdef WHERE id = 1'));
        } finally {
            $this->dropTemporaryTables($database);
            $database->close();
        }
    }

    private function primaryConfiguration(): LegacyConfiguration
    {
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        return $configuration;
    }

    private function createTemporaryTables(Connection $database): void
    {
        foreach ([
            'CREATE TEMPORARY TABLE settings (name VARCHAR(64) PRIMARY KEY, value VARCHAR(255)) ENGINE=InnoDB',
            'CREATE TEMPORARY TABLE user_auth (id INT PRIMARY KEY, username VARCHAR(64), enabled VARCHAR(8), locked VARCHAR(8), must_change_password VARCHAR(8)) ENGINE=InnoDB',
            'CREATE TEMPORARY TABLE user_auth_realm (user_id INT, realm_id INT, PRIMARY KEY (user_id, realm_id)) ENGINE=InnoDB',
            'CREATE TEMPORARY TABLE user_auth_group_realm (group_id INT, realm_id INT, PRIMARY KEY (group_id, realm_id)) ENGINE=InnoDB',
            'CREATE TEMPORARY TABLE user_auth_group_members (group_id INT, user_id INT, PRIMARY KEY (group_id, user_id)) ENGINE=InnoDB',
            'CREATE TEMPORARY TABLE user_auth_group (id INT PRIMARY KEY, enabled VARCHAR(8)) ENGINE=InnoDB',
            'CREATE TEMPORARY TABLE cdef (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, hash VARCHAR(64) NOT NULL UNIQUE, `system` TINYINT NOT NULL DEFAULT 0, name VARCHAR(255) NOT NULL) ENGINE=InnoDB',
            'CREATE TEMPORARY TABLE cdef_items (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, hash VARCHAR(64) NOT NULL UNIQUE, cdef_id INT UNSIGNED NOT NULL, sequence INT NOT NULL, type INT NOT NULL, value VARCHAR(150), CONSTRAINT cdef_item_test_check CHECK (cdef_id <> 99)) ENGINE=InnoDB',
            'CREATE TEMPORARY TABLE graph_templates_item (id INT UNSIGNED PRIMARY KEY, cdef_id INT UNSIGNED, local_graph_id INT UNSIGNED, graph_template_id INT UNSIGNED) ENGINE=InnoDB',
        ] as $sql) {
            $database->executeStatement($sql);
        }
    }

    private function dropTemporaryTables(Connection $database): void
    {
        foreach (['graph_templates_item', 'cdef_items', 'cdef', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'user_auth_realm', 'user_auth', 'settings'] as $table) {
            $database->executeStatement('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
        }
    }
}
