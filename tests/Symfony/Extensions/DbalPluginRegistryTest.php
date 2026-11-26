<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Extensions;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Extensions\Application\UseCase\ListPlugins;
use Kadupul\Extensions\Domain\PluginSlug;
use Kadupul\Extensions\Domain\PluginStatus;
use Kadupul\Extensions\Infrastructure\Persistence\DbalPluginRegistry;
use PHPUnit\Framework\TestCase;

final class DbalPluginRegistryTest extends TestCase
{
    private Connection $database;
    private DbalPluginRegistry $registry;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement(
            "CREATE TABLE plugin_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                directory TEXT NOT NULL DEFAULT '',
                name TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 0,
                author TEXT NOT NULL DEFAULT '',
                webpage TEXT NOT NULL DEFAULT '',
                version TEXT NOT NULL DEFAULT ''
            )",
        );
        $this->database->executeStatement(
            "INSERT INTO plugin_config (id, directory, name, status, author, webpage, version) VALUES
                (1, 'thold', 'Thresholds', 1, 'Kadupul', 'https://example.org/thold', '1.8.2'),
                (2, 'monitor', 'Device Monitor', 4, 'Kadupul', 'javascript:alert(1)', '2.7'),
                (3, '../bad', 'Unsafe Row', 1, 'Bad', '', '1.0')",
        );

        $this->registry = new DbalPluginRegistry($this->database);
    }

    public function testReadsValidPluginRowsAndSanitizesUnsafeWebpage(): void
    {
        $plugins = $this->registry->findAll();
        $this->assertCount(2, $plugins);

        $this->assertSame('monitor', $plugins[0]->metadata->slug->value());
        $this->assertSame(PluginStatus::Disabled, $plugins[0]->status);
        $this->assertNull($plugins[0]->metadata->webpage);

        $this->assertSame('thold', $plugins[1]->metadata->slug->value());
        $this->assertSame(PluginStatus::Enabled, $plugins[1]->status);
        $this->assertSame('https://example.org/thold', $plugins[1]->metadata->webpage);
    }

    public function testFindBySlugAndUseCaseIntegration(): void
    {
        $found = $this->registry->findBySlug(PluginSlug::fromString('thold'));
        $this->assertNotNull($found);
        $this->assertSame('Thresholds', $found->metadata->name);
        $this->assertNull($this->registry->findBySlug(PluginSlug::fromString('absent')));

        $listPlugins = new ListPlugins($this->registry);
        $this->assertTrue($listPlugins->isEnabled('thold'));
        $this->assertFalse($listPlugins->isEnabled('monitor'));
        $this->assertCount(1, $listPlugins->list(true));
    }
}
