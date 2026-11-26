<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\IdentityAccess;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\DirectoryType;
use Kadupul\IdentityAccess\Domain\LdapEncryption;
use Kadupul\IdentityAccess\Domain\LdapTlsCertRequirement;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalAuthDomainRepository;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalPasswordCredentialStore;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalRealmAccessChecker;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalSessionCredentialGeneration;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalSessionRevocation;
use PHPUnit\Framework\TestCase;

final class DbalAdaptersTest extends TestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->database->executeStatement('CREATE TABLE settings_user (user_id INTEGER NOT NULL, name TEXT NOT NULL, value TEXT NOT NULL, PRIMARY KEY (user_id, name))');
        $this->database->executeStatement('CREATE TABLE user_auth (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            realm INTEGER NOT NULL DEFAULT 0,
            password TEXT NOT NULL,
            enabled TEXT NOT NULL DEFAULT "on",
            locked TEXT NOT NULL DEFAULT "",
            must_change_password TEXT NOT NULL DEFAULT "",
            password_change TEXT NOT NULL DEFAULT "on",
            failed_attempts INTEGER NOT NULL DEFAULT 0,
            lastfail INTEGER NOT NULL DEFAULT 0,
            lastlogin INTEGER NOT NULL DEFAULT -1,
            full_name TEXT NOT NULL DEFAULT "",
            email_address TEXT NOT NULL DEFAULT ""
        )');
        $this->database->executeStatement('CREATE TABLE user_auth_cache (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, hostname TEXT NOT NULL, last_update TEXT NOT NULL, token TEXT NOT NULL)');
        $this->database->executeStatement('CREATE TABLE sessions (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, access INTEGER NOT NULL, data TEXT NOT NULL)');
        $this->database->executeStatement('CREATE TABLE user_auth_realm (user_id INTEGER NOT NULL, realm_id INTEGER NOT NULL, PRIMARY KEY (user_id, realm_id))');
        $this->database->executeStatement('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, name TEXT NOT NULL, enabled TEXT NOT NULL DEFAULT "on")');
        $this->database->executeStatement('CREATE TABLE user_auth_group_members (group_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (group_id, user_id))');
        $this->database->executeStatement('CREATE TABLE user_auth_group_realm (group_id INTEGER NOT NULL, realm_id INTEGER NOT NULL, PRIMARY KEY (group_id, realm_id))');
        $this->database->executeStatement('CREATE TABLE user_domains (domain_id INTEGER PRIMARY KEY, domain_name TEXT NOT NULL, type INTEGER NOT NULL, enabled TEXT NOT NULL, defdomain INTEGER NOT NULL, user_id INTEGER NOT NULL)');
        $this->database->executeStatement('CREATE TABLE user_domains_ldap (
            domain_id INTEGER PRIMARY KEY, server TEXT NOT NULL, port INTEGER NOT NULL, port_ssl INTEGER NOT NULL,
            proto_version INTEGER NOT NULL, encryption INTEGER NOT NULL, referrals INTEGER NOT NULL, mode INTEGER NOT NULL,
            dn TEXT NOT NULL, group_require TEXT NOT NULL, group_dn TEXT NOT NULL, group_attrib TEXT NOT NULL,
            group_member_type INTEGER NOT NULL, search_base TEXT NOT NULL, search_filter TEXT NOT NULL,
            specific_dn TEXT NOT NULL, specific_password TEXT NOT NULL, cn_full_name TEXT NOT NULL, cn_email TEXT NOT NULL
        )');
    }

    public function testDbalSessionRevocationRotatesGenerationAndPurgesSessions(): void
    {
        $this->database->insert('user_auth', ['id' => 7, 'username' => 'alice', 'realm' => 0, 'password' => 'hash-v1']);
        $this->database->insert('user_auth_cache', ['user_id' => 7, 'hostname' => '127.0.0.1', 'last_update' => '2026-11-26 00:00:00', 'token' => 'tok']);
        $this->database->insert('sessions', ['id' => 'sess1', 'user_id' => 7, 'access' => 100, 'data' => 'payload']);

        $current = CredentialGeneration::fromStoredPassword('hash-v1');
        $next = CredentialGeneration::generate();
        $revoker = new DbalSessionRevocation($this->database);

        // Stale generation is rejected and preserves rows.
        $this->assertFalse($revoker->revokeAll(7, CredentialGeneration::generate(), $next));
        $this->assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM user_auth_cache WHERE user_id = 7'));

        // Current generation succeeds, bumps auth_credential_generation, and clears tokens + sessions.
        $this->assertTrue($revoker->revokeAll(7, $current, $next));
        $this->assertSame($next->toHex(), DbalSessionCredentialGeneration::forAccount($this->database, 7, 'hash-v1'));
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM user_auth_cache WHERE user_id = 7'));
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM sessions WHERE user_id = 7'));
    }

    public function testDbalPasswordCredentialStoreRehashPreservesGenerationAndChangeRotatesIt(): void
    {
        $this->database->insert('user_auth', ['id' => 9, 'username' => 'bob', 'realm' => 0, 'password' => 'old-hash', 'must_change_password' => 'on']);
        $this->database->insert('user_auth_cache', ['user_id' => 9, 'hostname' => '127.0.0.1', 'last_update' => '2026-11-26 00:00:00', 'token' => 'tok']);
        $this->database->insert('settings', ['name' => 'secpass_lockfailed', 'value' => '4']);
        $this->database->insert('settings', ['name' => 'secpass_unlocktime', 'value' => '30']);
        $this->database->insert('settings', ['name' => 'secpass_forcecomplex', 'value' => 'on']);

        $store = new DbalPasswordCredentialStore($this->database);
        $account = $store->findLocalAccountByUsername('bob');
        $this->assertNotNull($account);
        $initialGen = $account->generation;

        // Rehash preserves existing credential generation.
        $this->assertTrue($store->updatePasswordPreservingSessions(9, 'old-hash', 'rehashed-hash'));
        $this->assertSame($initialGen->toHex(), DbalSessionCredentialGeneration::forAccount($this->database, 9, 'rehashed-hash'));

        // Password change rotates generation, clears must_change_password, and purges remembered cache.
        $rotated = CredentialGeneration::generate();
        $this->assertTrue($store->changePasswordAndRotateGeneration(9, 'rehashed-hash', 'brand-new-hash', $rotated));
        $this->assertSame($rotated->toHex(), DbalSessionCredentialGeneration::forAccount($this->database, 9, 'brand-new-hash'));
        $this->assertSame('', $this->database->fetchOne('SELECT must_change_password FROM user_auth WHERE id = 9'));
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM user_auth_cache WHERE user_id = 9'));

        $this->assertSame(4, $store->lockoutPolicy()->failureLimit);
        $this->assertTrue($store->complexityPolicy()->enforced);
    }

    public function testDbalAuthDomainRepositoryAndRealmAccessChecker(): void
    {
        $this->database->insert('user_domains', ['domain_id' => 2, 'domain_name' => 'AD', 'type' => 2, 'enabled' => 'on', 'defdomain' => 1, 'user_id' => 1]);
        $this->database->insert('user_domains_ldap', [
            'domain_id' => 2, 'server' => 'ad.example.internal', 'port' => 389, 'port_ssl' => 636,
            'proto_version' => 3, 'encryption' => 2, 'referrals' => 0, 'mode' => 2,
            'dn' => '', 'group_require' => '', 'group_dn' => '', 'group_attrib' => 'member',
            'group_member_type' => 1, 'search_base' => 'dc=example,dc=internal', 'search_filter' => '(sAMAccountName=<username>)',
            'specific_dn' => 'cn=bind', 'specific_password' => 'bind-pass', 'cn_full_name' => 'displayName', 'cn_email' => 'mail',
        ]);

        $repo = new DbalAuthDomainRepository($this->database);
        $domain = $repo->findDefaultEnabled();
        $this->assertNotNull($domain);
        $this->assertSame(2, $domain->id);
        $this->assertSame(DirectoryType::ActiveDirectory, $domain->type);
        $this->assertSame(LdapEncryption::StartTls, $domain->ldapConfig->encryption);
        $this->assertSame(LdapTlsCertRequirement::Demand, $domain->ldapConfig->tlsCertRequirement);
        $this->assertSame(1002, $domain->accountRealm());

        $this->database->insert('user_auth_realm', ['user_id' => 5, 'realm_id' => 8]);
        $this->database->insert('user_auth_group', ['id' => 3, 'name' => 'Admins', 'enabled' => 'on']);
        $this->database->insert('user_auth_group_members', ['group_id' => 3, 'user_id' => 5]);
        $this->database->insert('user_auth_group_realm', ['group_id' => 3, 'realm_id' => 15]);

        $realms = new DbalRealmAccessChecker($this->database);
        $this->assertTrue($realms->hasRealm(5, 8));
        $this->assertTrue($realms->hasRealm(5, 15, true));
        $this->assertFalse($realms->hasRealm(5, 15, false));
        $this->assertFalse($realms->hasRealm(5, 29));
    }
}
