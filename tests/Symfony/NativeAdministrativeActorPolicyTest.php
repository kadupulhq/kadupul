<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Application\Command\UserManagementDenied;
use Kadupul\IdentityAccess\Infrastructure\Legacy\NativeAdministrativeActorPolicy;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PDO;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Kadupul\Tests\Fixtures\AdministrativePolicyFaultPdo;

require_once __DIR__ . '/Fixtures/RealMariaDb.php';
require_once __DIR__ . '/Fixtures/AdministrativePolicyFaultPdo.php';

final class NativeAdministrativeActorPolicyTest extends TestCase
{
    use RealMariaDb;

    private ?Connection $database = null;
    private string $schema = '';
    private bool $created = false;
    private string $credential;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        $this->database = $this->realMariaDb(true);
        $this->schema = 'actor_policy_' . bin2hex(random_bytes(8));
        $this->database->executeStatement('CREATE DATABASE `' . $this->schema . '` CHARACTER SET utf8mb4');
        $this->created = true;
        $this->database->executeStatement('USE `' . $this->schema . '`');
        $this->database->executeStatement("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($source);
        foreach (['user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'settings', 'settings_user'] as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`?\s*\([^;]*;/s', $source, $match), $table);
            $this->database->executeStatement($match[0]);
        }
        $this->database->executeStatement("INSERT INTO user_auth (id,username,password,enabled) VALUES (42,'operator',?,'on')", [str_repeat('a', 32)]);
        $this->database->executeStatement('INSERT INTO user_auth_realm (user_id,realm_id) VALUES (42,8),(42,15)');
        $this->database->executeStatement("INSERT INTO settings (name,value) VALUES ('auth_method','1'),('guest_user','0')");
        $this->credential = \auth_session_credential_generation(42, str_repeat('a', 32), $this->pdo());
    }

    protected function tearDown(): void
    {
        try {
            if ($this->database !== null && $this->created) {
                $connection = $this->pdo();
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                $this->database->executeStatement('DROP DATABASE `' . $this->schema . '`');
            }
        } finally {
            $this->database?->close();
        }
    }

    public function testSuccessfulPolicyDoesNotOwnTheCallerTransaction(): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO settings (name,value) VALUES ('caller_work','kept')");
        $actor = (new NativeAdministrativeActorPolicy($pdo, 15))->authorize(42, $this->credential);
        self::assertSame(42, $actor->id);
        self::assertSame('operator', $actor->username);
        self::assertTrue($pdo->inTransaction());
        self::assertSame('kept', $this->database->fetchOne("SELECT value FROM settings WHERE name='caller_work'"));
        $pdo->rollBack();
        self::assertFalse($this->database->fetchOne("SELECT value FROM settings WHERE name='caller_work'"));
    }

    public function testDirectAndEnabledGroupGrantsAreRequiredWithoutCaching(): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $policy = new NativeAdministrativeActorPolicy($pdo, 15);
        self::assertTrue($this->admitted($policy));
        $pdo->exec('DELETE FROM user_auth_realm WHERE user_id=42 AND realm_id=15');
        self::assertFalse($this->admitted($policy));
        $pdo->exec("INSERT INTO user_auth_group (id,name,enabled) VALUES (5,'Operators','on')");
        $pdo->exec('INSERT INTO user_auth_group_members (group_id,user_id) VALUES (5,42)');
        $pdo->exec('INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES (5,15)');
        self::assertTrue($this->admitted($policy));
        $pdo->exec("UPDATE user_auth_group SET enabled='' WHERE id=5");
        self::assertFalse($this->admitted($policy));
        $pdo->exec('INSERT INTO user_auth_realm (user_id,realm_id) VALUES (42,15)');
        $pdo->exec('DELETE FROM user_auth_realm WHERE user_id=42 AND realm_id=8');
        self::assertFalse($this->admitted($policy));
        self::assertTrue($pdo->inTransaction());
    }

    public function testFeatureRealmCannotBeSubstitutedWithAnotherAdministrativeRealm(): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $policy = new NativeAdministrativeActorPolicy($pdo, 1);
        self::assertFalse($this->admitted($policy));
        $pdo->exec('INSERT INTO user_auth_realm (user_id,realm_id) VALUES (42,1)');
        self::assertTrue($this->admitted($policy));
    }

    public function testCredentialRevocationIsDeniedAndTransparentRehashBindingSurvives(): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $policy = new NativeAdministrativeActorPolicy($pdo, 15);
        self::assertTrue($this->admitted($policy));
        $newPassword = str_repeat('c', 32);
        $this->database->executeStatement('UPDATE user_auth SET password=? WHERE id=42', [$newPassword]);
        self::assertFalse($this->admitted($policy));
        $this->database->executeStatement(
            'INSERT INTO settings_user (user_id,name,value) VALUES (42,?,?)',
            ['auth_credential_generation', \auth_session_credential_key($newPassword) . ':' . $this->credential]
        );
        self::assertTrue($this->admitted($policy));
        $this->database->executeStatement(
            'UPDATE settings_user SET value=? WHERE user_id=42 AND name=?',
            [\auth_session_credential_key($newPassword) . ':' . str_repeat('d', 64), 'auth_credential_generation']
        );
        self::assertFalse($this->admitted($policy));
    }

    public function testMutableAccountAndGuestPoliciesAreRechecked(): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $policy = new NativeAdministrativeActorPolicy($pdo, 15);
        foreach (["enabled=''", "locked='on'", "must_change_password='on',password_change='on'"] as $change) {
            $pdo->exec('UPDATE user_auth SET ' . $change . ' WHERE id=42');
            self::assertFalse($this->admitted($policy), $change);
            $pdo->exec("UPDATE user_auth SET enabled='on',locked='',must_change_password='',password_change='' WHERE id=42");
            self::assertTrue($this->admitted($policy));
        }
        foreach (['42', 'operator'] as $guest) {
            $this->database->executeStatement("UPDATE settings SET value=? WHERE name='guest_user'", [$guest]);
            self::assertFalse($this->admitted($policy));
        }
        $pdo->exec("UPDATE settings SET value='0' WHERE name='guest_user'");
        foreach (['0', '1.0', 'invalid'] as $method) {
            $this->database->executeStatement("UPDATE settings SET value=? WHERE name='auth_method'", [$method]);
            self::assertFalse($this->admitted($policy));
        }
        $pdo->exec("DELETE FROM settings WHERE name IN ('guest_user','auth_method')");
        self::assertTrue($this->admitted($policy));
        $pdo->exec('DELETE FROM user_auth WHERE id=42');
        self::assertFalse($this->admitted($policy));
    }

    public function testTemporaryRoleShadowIsRejectedBeforeAnyPolicyDecision(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TEMPORARY TABLE user_auth_realm (user_id int unsigned NOT NULL, realm_id int unsigned NOT NULL) ENGINE=InnoDB');
        $pdo->beginTransaction();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Administrative policy requires persistent InnoDB participants.');
        (new NativeAdministrativeActorPolicy($pdo, 15))->authorize(42, $this->credential);
    }

    public function testNonTransactionalCredentialMappingIsRejected(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('ALTER TABLE user_auth_realm ENGINE=MyISAM');
        $pdo->beginTransaction();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Administrative policy requires persistent InnoDB participants.');
        (new NativeAdministrativeActorPolicy($pdo, 15))->authorize(42, $this->credential);
    }

    public function testActiveTransactionIsMandatory(): void
    {
        $this->expectException(\LogicException::class);
        (new NativeAdministrativeActorPolicy($this->pdo(), 15))->authorize(42, $this->credential);
    }

    public function testCredentialReadFailureDoesNotExposeStoredPasswordInExceptionArguments(): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $connection = new AdministrativePolicyFaultPdo($pdo, 'credential_prepare');
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        try {
            try {
                (new NativeAdministrativeActorPolicy($connection, 15))->authorize(42, $this->credential);
                self::fail('A failed credential read was admitted.');
            } catch (\RuntimeException $error) {
                self::assertTrue($connection->injected);
                self::assertTrue($pdo->inTransaction());
                $frames = array_values(array_filter($error->getTrace(), static fn(array $frame): bool => $frame['function'] === 'auth_session_credential_generation'));
                self::assertCount(1, $frames);
                self::assertInstanceOf(\SensitiveParameterValue::class, $frames[0]['args'][1] ?? null);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function testMalformedCredentialsAreDeniedBeforeInspectingAnInactiveConnection(): void
    {
        $policy = new NativeAdministrativeActorPolicy($this->pdo(), 15);
        foreach ([[0, $this->credential], [-1, $this->credential], [42, ''], [42, str_repeat('a', 63)],
            [42, str_repeat('A', 64)], [42, str_repeat('z', 64)], [42, str_repeat('a', 65)]] as [$actor, $credential]) {
            try {
                $policy->authorize($actor, $credential);
                self::fail('Malformed actor or credential was admitted.');
            } catch (UserManagementDenied) {
                self::assertFalse($this->pdo()->inTransaction());
            }
        }
    }

    public function testAnInvalidConfiguredRealmIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NativeAdministrativeActorPolicy($this->pdo(), 0);
    }

    public function testUnsupportedDatabaseCannotBeMistakenForAnAdmittedMysqlSession(): void
    {
        $connection = new PDO('sqlite::memory:');
        $connection->beginTransaction();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Administrative mutation authorization requires MySQL or MariaDB.');
        (new NativeAdministrativeActorPolicy($connection, 15))->authorize(42, $this->credential);
    }

    #[DataProvider('unconfirmedReads')]
    public function testUnconfirmedReadDoesNotAdmitOrRollBackCallerWork(string $fault, string $message): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO settings (name,value) VALUES ('caller_work','kept')");
        self::assertSame(42, (new NativeAdministrativeActorPolicy($pdo, 15))->authorize(42, $this->credential)->id);
        $connection = new AdministrativePolicyFaultPdo($pdo, $fault);
        try {
            (new NativeAdministrativeActorPolicy($connection, 15))->authorize(42, $this->credential);
            self::fail('An unconfirmed read must not authorize a mutation.');
        } catch (\RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
        }
        self::assertTrue($connection->injected, 'The selected driver stage must actually execute.');
        self::assertTrue($pdo->inTransaction());
        self::assertSame('kept', $this->database->fetchOne("SELECT value FROM settings WHERE name='caller_work'"));
        $pdo->rollBack();
        self::assertFalse($this->database->fetchOne("SELECT value FROM settings WHERE name='caller_work'"));
    }

    public static function unconfirmedReads(): iterable
    {
        yield 'late fetch SQLSTATE' => ['late_fetch', 'Administrative policy read could not be confirmed.'];
        yield 'invalid row shape' => ['scalar_row', 'Administrative policy row is invalid.'];
        yield 'invalid column shape' => ['numeric_column', 'Administrative policy column is invalid.'];
        yield 'unconfirmed read close' => ['late_close', 'Administrative policy read could not be confirmed.'];
        yield 'invalid table metadata' => ['metadata_shape', 'Administrative policy table metadata is unavailable.'];
        yield 'unavailable table definition' => ['definition_false', 'Administrative policy table definition is unavailable.'];
        yield 'unconfirmed metadata close' => ['definition_close', 'Administrative policy metadata read could not be confirmed.'];
    }

    private function admitted(NativeAdministrativeActorPolicy $policy): bool
    {
        try {
            $policy->authorize(42, $this->credential);
            return true;
        } catch (UserManagementDenied) {
            return false;
        }
    }

    private function pdo(): PDO
    {
        self::assertInstanceOf(Connection::class, $this->database);
        $connection = $this->database->getNativeConnection();
        self::assertInstanceOf(PDO::class, $connection);
        return $connection;
    }
}
