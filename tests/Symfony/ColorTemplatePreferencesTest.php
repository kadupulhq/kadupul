<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Infrastructure\Legacy\LegacyColorTemplatePreferences;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class ColorTemplatePreferencesTest extends TestCase
{
    private \PDO $db;
    private ColorTemplateAccess $access;
    private LegacyColorTemplatePreferences $preferences;
    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE settings_user(user_id INTEGER,name TEXT,value TEXT,PRIMARY KEY(user_id,name))');
        $this->access = $this->createMock(ColorTemplateAccess::class);
        $this->access->method('authorize')->willReturn(new Actor(42, 'operator'));
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($this->db);
        $this->preferences = new LegacyColorTemplatePreferences($this->access, $database);
    }
    /** @dataProvider malformedValues */
    public function testIgnoresMalformedOrUnexpectedStoredValues(?string $json): void
    {
        if ($json !== null) {
            $q = $this->db->prepare("INSERT INTO settings_user VALUES (42,'color_templates_filters',?)");
            $q->execute([$json]);
        }
        self::assertNull($this->preferences->load());
    }
    public static function malformedValues(): array
    {
        return [[null], [''], ['{malformed'], ['true'], ['{"unknown":"x"}'], ['{"filter":[]}'], ['{"rows":false}']];
    }
    public function testRoundTripsValidatedUserScopedPreferences(): void
    {
        $this->access->expects(self::once())->method('assertCurrent')->with(42);
        $this->preferences->save(['filter' => '<color>', 'rows' => 25, 'page' => 2]);
        self::assertSame(['filter' => '<color>', 'rows' => '25', 'page' => '2'], $this->preferences->load());
        self::assertFalse($this->db->inTransaction());
    }
    public function testRejectsInvalidSubmissionBeforeAuthorization(): void
    {
        $this->access->expects(self::never())->method('authorize');
        $this->expectException(\InvalidArgumentException::class);
        $this->preferences->save(['filter' => ['nested']]);
    }
    public function testPreservesCallerTransaction(): void
    {
        $this->db->beginTransaction();
        try {
            $this->preferences->save(['filter' => 'x']);
            self::fail('nested preference write accepted');
        } catch (\RuntimeException) {
            self::assertTrue($this->db->inTransaction());
        } finally {
            $this->db->rollBack();
        }
    }
    public function testRollsBackDatabaseFailure(): void
    {
        $this->db->exec("CREATE TRIGGER deny_preference BEFORE INSERT ON settings_user BEGIN SELECT RAISE(ABORT,'injected'); END");
        try {
            $this->preferences->save(['filter' => 'x']);
            self::fail('trigger accepted write');
        } catch (\PDOException) {
            self::assertFalse($this->db->inTransaction());
            self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM settings_user')->fetchColumn());
        }
    }
}
