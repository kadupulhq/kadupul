<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Infrastructure\Legacy\LegacyLinkPreferences;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class LinkPreferencesTest extends TestCase
{
    public function testUnconfirmedCommitRollsBackPreferenceWrite(): void
    {
        $db = new class ('sqlite::memory:') extends \PDO {
            public function commit(): bool
            {
                return false;
            }
        };
        $db->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT, PRIMARY KEY(user_id,name))');
        $db->exec("INSERT INTO settings_user VALUES (9, 'external_links_filters', '{\"filter\":\"before\"}')");
        $connection = new readonly class ($db) implements DatabaseConnection {
            public function __construct(private \PDO $db) {}
            public function get(): \PDO
            {
                return $this->db;
            }
        };
        $access = $this->createMock(LinkAccess::class);
        $access->method('authorize')->willReturn(new Actor(9, 'operator'));
        $preferences = new LegacyLinkPreferences($access, $connection, $this->createMock(LegacyConfiguration::class));
        $failure = null;
        try {
            $preferences->save(['filter' => 'after']);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('commit', $failure->getMessage());
        self::assertFalse($db->inTransaction());
        self::assertSame(['filter' => 'before'], $preferences->load());
    }

    public function testPreferencesStayWithTheCurrentActorAndRejectCorruptData(): void
    {
        $db = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT, PRIMARY KEY(user_id,name))');
        $connection = new readonly class ($db) implements DatabaseConnection {
            public function __construct(private \PDO $db) {}
            public function get(): \PDO
            {
                return $this->db;
            }
        };
        $access = $this->createMock(LinkAccess::class);
        $access->method('authorize')->willReturn(new Actor(9, 'operator'));
        $configuration = $this->createMock(LegacyConfiguration::class);
        $preferences = new LegacyLinkPreferences($access, $connection, $configuration);
        self::assertNull($preferences->load());
        $preferences->save(['filter' => '東京 & "', 'page' => '2']);
        self::assertSame(['filter' => '東京 & "', 'page' => '2'], $preferences->load());
        self::assertSame(9, (int) $db->query('SELECT user_id FROM settings_user')->fetchColumn());
        $db->beginTransaction();
        $db->exec("INSERT INTO settings_user VALUES (10,'caller','before')");
        try {
            $preferences->save(['filter' => 'change']);
            self::fail('Caller transaction accepted');
        } catch (\RuntimeException) {
        }
        self::assertTrue($db->inTransaction());
        self::assertSame('before', $db->query("SELECT value FROM settings_user WHERE user_id=10")->fetchColumn());
        $db->rollBack();
        $db->exec("UPDATE settings_user SET value='corrupt'");
        self::assertNull($preferences->load());
        $db->exec("UPDATE settings_user SET value='[\"value\"]'");
        self::assertNull($preferences->load());
        $this->expectException(\InvalidArgumentException::class);
        $preferences->save(['return_url' => 'https://attacker.invalid']);
    }
}
