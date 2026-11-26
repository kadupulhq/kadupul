<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Platform\Infrastructure\Persistence\DbalUserPreferenceStore;
use PHPUnit\Framework\TestCase;

final class DbalUserPreferenceStoreTest extends TestCase
{
    private Connection $database;
    private DbalUserPreferenceStore $store;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement('CREATE TABLE settings_user (user_id INTEGER NOT NULL, name TEXT NOT NULL, value TEXT NOT NULL, PRIMARY KEY (user_id, name))');
        $this->store = new DbalUserPreferenceStore($this->database);
    }

    public function testReadsWritesUpdatesAndRemovesUserPreference(): void
    {
        $this->assertNull($this->store->get(7, 'selected_theme'));

        $this->store->set(7, 'selected_theme', 'dark');
        $this->assertSame('dark', $this->store->get(7, 'selected_theme'));

        $this->store->set(7, 'selected_theme', 'modern');
        $this->assertSame('modern', $this->store->get(7, 'selected_theme'));

        $this->store->remove(7, 'selected_theme');
        $this->assertNull($this->store->get(7, 'selected_theme'));
    }

    public function testRejectsOutOfRangeUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->set(-1, 'selected_theme', 'modern');
    }
}
