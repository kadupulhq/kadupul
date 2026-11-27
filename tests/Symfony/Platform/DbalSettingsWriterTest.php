<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Platform\Infrastructure\Persistence\DbalSettingsWriter;
use PHPUnit\Framework\TestCase;

final class DbalSettingsWriterTest extends TestCase
{
    private Connection $database;
    private DbalSettingsWriter $writer;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->writer = new DbalSettingsWriter($this->database);
    }

    public function testInsertsUpdatesAndDeletesSetting(): void
    {
        $this->writer->write('poller_interval', 300);
        $this->assertSame('300', $this->database->fetchOne("SELECT value FROM settings WHERE name = 'poller_interval'"));

        $this->writer->write('poller_interval', 60);
        $this->assertSame('60', $this->database->fetchOne("SELECT value FROM settings WHERE name = 'poller_interval'"));

        $this->writer->delete('poller_interval');
        $this->assertFalse($this->database->fetchOne("SELECT value FROM settings WHERE name = 'poller_interval'"));
    }

    public function testRejectsEmptySettingName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->write('', 'value');
    }
}
