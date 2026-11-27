<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\IdentityAccess;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalSessionCredentialGeneration;
use PHPUnit\Framework\TestCase;

final class DbalSessionCredentialGenerationTest extends TestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, password TEXT NOT NULL)');
        $this->database->executeStatement('CREATE TABLE settings_user (user_id INTEGER NOT NULL, name TEXT NOT NULL, value TEXT NOT NULL)');
        $this->database->executeStatement("INSERT INTO user_auth VALUES (7, 'initial-hash')");
    }

    public function testFallsBackToPasswordFingerprintWithoutMapping(): void
    {
        $expected = DbalSessionCredentialGeneration::key('initial-hash');
        $this->assertSame($expected, DbalSessionCredentialGeneration::forAccount($this->database, 7, 'initial-hash'));
    }

    public function testReturnsMappedGenerationWhenFingerprintMatches(): void
    {
        $fingerprint = DbalSessionCredentialGeneration::key('initial-hash');
        $generation = str_repeat('a', 64);
        $this->database->insert('settings_user', [
            'user_id' => 7,
            'name' => 'auth_credential_generation',
            'value' => $fingerprint . ':' . $generation,
        ]);

        $this->assertSame($generation, DbalSessionCredentialGeneration::forAccount($this->database, 7, 'initial-hash'));
    }

    public function testRereadsLivePasswordAndMappingAfterConcurrentRehash(): void
    {
        $rehashed = 'upgraded-argon2-hash';
        $generation = str_repeat('b', 64);
        $this->database->update('user_auth', ['password' => $rehashed], ['id' => 7]);
        $this->database->insert('settings_user', [
            'user_id' => 7,
            'name' => 'auth_credential_generation',
            'value' => DbalSessionCredentialGeneration::key($rehashed) . ':' . $generation,
        ]);

        $this->assertSame($generation, DbalSessionCredentialGeneration::forAccount($this->database, 7, 'initial-hash'));
    }

    public function testReturnsEmptyStringWhenRehashCheckFindsMissingAccount(): void
    {
        $generation = str_repeat('c', 64);
        $this->database->insert('settings_user', [
            'user_id' => 99,
            'name' => 'auth_credential_generation',
            'value' => str_repeat('d', 64) . ':' . $generation,
        ]);

        $this->assertSame('', DbalSessionCredentialGeneration::forAccount($this->database, 99, 'stale-hash'));
    }

    public function testIgnoresMalformedMappingRow(): void
    {
        $this->database->insert('settings_user', [
            'user_id' => 7,
            'name' => 'auth_credential_generation',
            'value' => 'not-a-valid-mapping',
        ]);

        $this->assertSame(
            DbalSessionCredentialGeneration::key('initial-hash'),
            DbalSessionCredentialGeneration::forAccount($this->database, 7, 'initial-hash'),
        );
    }
}
