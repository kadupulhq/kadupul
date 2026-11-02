<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Infrastructure\Persistence\DbalPollerCacheSettings;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

/** The commands_processes read the --threads error quotes. */
final class DbalPollerCacheSettingsTest extends TestCase
{
    private function database(?string $value, bool $row = true): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NULL)');
        $db->executeStatement("INSERT INTO settings VALUES ('commands_process', '9')");
        if ($row) {
            $db->executeStatement("INSERT INTO settings VALUES ('commands_processes', ?)", [$value]);
        }

        return $db;
    }

    public function testTheStoredTextIsReturnedAsTyped(): void
    {
        foreach (['5', ' 7', '', 'x'] as $value) {
            self::assertSame($value, (new DbalPollerCacheSettings($this->database($value), $this->database('0')))->concurrentProcesses(OperatorDatabase::Local));
        }
    }

    public function testAMissingRowFallsBackToTheSettingsDefaultAndANullOneToTheScriptsFive(): void
    {
        self::assertSame('1', (new DbalPollerCacheSettings($this->database(null, false), $this->database('0')))->concurrentProcesses(OperatorDatabase::Local));
        self::assertSame('5', (new DbalPollerCacheSettings($this->database(null), $this->database('0')))->concurrentProcesses(OperatorDatabase::Local));
    }

    public function testTheMainConnectionIsUsedForMain(): void
    {
        $settings = new DbalPollerCacheSettings($this->database('2'), $this->database('6'));

        self::assertSame('6', $settings->concurrentProcesses(OperatorDatabase::Main));
        self::assertSame('2', $settings->concurrentProcesses(OperatorDatabase::Local));
    }
}
