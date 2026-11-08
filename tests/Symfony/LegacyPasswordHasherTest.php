<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyPasswordHasher;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class LegacyPasswordHasherTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testNativeFacadesRetainCoercionAndExplicitOptions(): void
    {
        require dirname(__DIR__, 2) . '/lib/functions.php';
        require dirname(__DIR__, 2) . '/lib/auth.php';
        self::assertFalse(\compat_password_verify(null, null));
        self::assertTrue(\compat_password_needs_rehash(null, PASSWORD_DEFAULT));
        $hash = \compat_password_hash(null, PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertTrue(password_verify('', $hash));
        self::assertTrue(\compat_password_verify(null, $hash));
        self::assertFalse(\compat_password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 4]));
        $scalar = \compat_password_hash(1234, PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertTrue(\compat_password_verify(1234, $scalar));
        self::assertTrue(password_verify('1234', $scalar));
        self::assertTrue(password_verify('existing', \compat_password_hash('existing', PASSWORD_DEFAULT, false)));
    }

    #[DataProvider('legacyRecords')]
    public function testExistingLegacyRecords(string $hash, string $password, bool $expected): void
    {
        $hasher = new LegacyPasswordHasher();
        self::assertSame($expected, $hasher->verify($hash, $password));
        self::assertTrue($hasher->needsRehash($hash));
    }

    public static function legacyRecords(): iterable
    {
        // Recorded legacy values, never newly generated MD5 credentials.
        yield 'matching' => ['7c4f29407893c334a6cb7a87bf045c0d', 'right', true];
        yield 'wrong' => ['7c4f29407893c334a6cb7a87bf045c0d', 'wrong', false];
        yield 'case remains significant' => ['7C4F29407893C334A6CB7A87BF045C0D', 'right', false];
        yield 'empty record' => ['', 'right', false];
        yield 'malformed record' => ['not-a-hash', 'right', false];
        yield 'empty password primitive' => ['d41d8cd98f00b204e9800998ecf8427e', '', true];
        yield 'NUL input is refused without padding failure' => ['', "\0", false];
    }

    #[DataProvider('nativePasswords')]
    public function testBcryptUsesExistingNativeBytes(string $password): void
    {
        $options = ['cost' => 4];
        $native = password_hash($password, PASSWORD_BCRYPT, $options);
        $hasher = new LegacyPasswordHasher(PASSWORD_BCRYPT, $options);
        self::assertTrue($hasher->verify($native, $password));
        $migrated = $hasher->hash($password);
        self::assertTrue(password_verify($password, $migrated));
        self::assertSame(password_get_info($native), password_get_info($migrated));
        self::assertFalse($hasher->needsRehash($native));
        self::assertFalse($hasher->verify($native, 'different'));
        self::assertSame(password_verify($password . "\0", $native), $hasher->verify($native, $password . "\0"));
    }

    public static function nativePasswords(): iterable
    {
        yield 'ordinary' => ['existing-passphrase'];
        yield 'empty primitive' => [''];
        yield '72 bytes' => [str_repeat('a', 72)];
        yield 'over 72 bytes' => [str_repeat('a', 73)];
        yield 'over Symfony default limit' => [str_repeat('a', 4097)];
        yield 'multibyte' => [str_repeat('é', 40)];
    }

    public function testExplicitAlgorithmOptionsAndRehashPolicyArePreserved(): void
    {
        $hasher = new LegacyPasswordHasher(PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 2, 'threads' => 1]);
        $hash = $hasher->hash("native\0passphrase");
        self::assertTrue(password_verify("native\0passphrase", $hash));
        self::assertTrue($hasher->verify($hash, "native\0passphrase"));
        self::assertFalse($hasher->needsRehash($hash));
        self::assertTrue((new LegacyPasswordHasher())->needsRehash($hash));
        self::assertTrue((new LegacyPasswordHasher(PASSWORD_BCRYPT, ['cost' => 5]))->needsRehash(password_hash('existing', PASSWORD_BCRYPT, ['cost' => 4])));
    }

    public function testBcryptHashingStillRejectsNulInsteadOfChangingPasswordBytes(): void
    {
        $this->expectException(\ValueError::class);
        (new LegacyPasswordHasher())->hash("native\0passphrase");
    }

    public function testInvalidAlgorithmStillFails(): void
    {
        $this->expectException(\ValueError::class);
        (new LegacyPasswordHasher('invalid'))->hash('passphrase');
    }

    public function testSymfonyFactoryUsesTheCompatibilityHasherWithoutNativeBootstrap(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $factory = $kernel->getContainer()->get('test.service_container')->get(PasswordHasherFactoryInterface::class);
            $hasher = $factory->getPasswordHasher('legacy_native');
            self::assertInstanceOf(LegacyPasswordHasher::class, $hasher);
            $password = str_repeat('a', 73);
            $hash = $hasher->hash($password);
            self::assertTrue(password_verify($password, $hash));
            self::assertTrue($hasher->verify($hash, $password));
            self::assertFalse($hasher->needsRehash($hash));
            self::assertSame(password_get_info(password_hash('native-policy', PASSWORD_DEFAULT)), password_get_info($hash));
            self::assertFalse(defined('CACTI_VERSION'));
        } finally {
            $kernel->shutdown();
        }
    }
}
