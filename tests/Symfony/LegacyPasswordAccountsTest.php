<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Application\Command\UserCommandAccess;
use Kadupul\IdentityAccess\Application\Query\ListLegacyPasswordAccounts;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Domain\LegacyPasswordHash;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalLegacyPasswordAccounts;
use Kadupul\IdentityAccess\Infrastructure\Symfony\ListLegacyPasswordsCommand;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

final class LegacyPasswordAccountsTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private Connection $db;

    /** @var list<string> every stored password value in the fixture */
    private array $stored = [];

    protected function setUp(): void
    {
        // User 1, admin, holds Console Access (8) and User Management (1).
        $this->db = $this->operatorDatabase(8, 1);
        $this->db->executeStatement("ALTER TABLE user_auth ADD COLUMN password TEXT NOT NULL DEFAULT ''");
        $this->db->executeStatement('ALTER TABLE user_auth ADD COLUMN realm INTEGER NOT NULL DEFAULT 0');
        $this->db->executeStatement("CREATE TABLE user_log (username TEXT, user_id INTEGER, time TEXT NOT NULL DEFAULT '0000-00-00 00:00:00', result INTEGER, ip TEXT)");
        $this->db->update('user_auth', ['password' => password_hash('admin-secret', PASSWORD_BCRYPT)], ['id' => 1]);

        $this->account(2, 'ops', md5('ops-secret'));
        $this->account(3, 'dormant', md5('dormant-secret'), enabled: '');
        $this->account(4, 'modern', password_hash('modern-secret', PASSWORD_BCRYPT));
        if (defined('PASSWORD_ARGON2ID')) {
            $this->account(5, 'argon', password_hash('argon-secret', PASSWORD_ARGON2ID));
        }
        $this->account(6, 'blank', '');
        // The same digest in the other realms: their login never checks it.
        $this->account(7, 'web', md5('web-secret'), realm: 2);
        $this->account(8, 'directory', md5('directory-secret'), realm: 3);
        $this->account(9, 'shouting', strtoupper(md5('shouting-secret')));
        // install/cacti.sql ships guest with a 16-digit value that never verifies.
        $this->account(10, 'guest', '43e9a4ab75570f5b');
        $this->account(11, 'padded', md5('padded-secret') . ' ');
        $this->account(12, 'short', substr(md5('short-secret'), 1));
        $this->account(13, 'mixed', 'g' . substr(md5('mixed-secret'), 1));

        // Failures and later rows for other users must not change the latest success.
        $this->db->insert('user_log', ['username' => 'ops', 'user_id' => 2, 'time' => '2026-10-01 08:00:00', 'result' => 1, 'ip' => '192.0.2.1']);
        $this->db->insert('user_log', ['username' => 'ops', 'user_id' => 2, 'time' => '2026-10-03 08:00:00', 'result' => 2, 'ip' => '192.0.2.1']);
        $this->db->insert('user_log', ['username' => 'ops', 'user_id' => 2, 'time' => '2026-10-05 08:00:00', 'result' => 0, 'ip' => '192.0.2.1']);
        // auth_changepassword.php logs a password change as result 3 with no user_id.
        $this->db->insert('user_log', ['username' => 'ops', 'user_id' => 0, 'time' => '2026-10-06 08:00:00', 'result' => 3, 'ip' => '192.0.2.1']);
        $this->db->insert('user_log', ['username' => 'modern', 'user_id' => 4, 'time' => '2026-10-07 08:00:00', 'result' => 1, 'ip' => '192.0.2.1']);
        $this->db->insert('user_log', ['username' => 'dormant', 'user_id' => 3, 'time' => '2026-10-07 08:00:00', 'result' => 0, 'ip' => '192.0.2.1']);
    }

    private function account(int $id, string $username, string $password, string $enabled = 'on', int $realm = 0): void
    {
        $this->db->insert('user_auth', ['id' => $id, 'username' => $username, 'enabled' => $enabled, 'locked' => '', 'must_change_password' => '', 'password' => $password, 'realm' => $realm]);
        $this->stored[] = $password;
    }

    private function command(): CommandTester
    {
        $access = new UserCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $list = new ListLegacyPasswordAccounts($access, new DbalLegacyPasswordAccounts($this->db, $this->db));

        return new CommandTester(new Command(null, new ListLegacyPasswordsCommand($list, new ResultRenderer())));
    }

    /** Fails if the output holds any eight-character run of a stored password. */
    private function assertNoStoredPassword(string $output): void
    {
        foreach ($this->stored as $password) {
            for ($start = 0; $start + 8 <= strlen($password); $start++) {
                self::assertStringNotContainsString(substr($password, $start, 8), $output);
            }
        }
    }

    public function testJsonListsOnlyLocalAccountsWhoseStoredValueIsALowercaseMd5Digest(): void
    {
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        self::assertSame([
            'status' => 'ok',
            'database' => 'local',
            'count' => 2,
            'accounts' => [
                ['id' => 2, 'username' => 'ops', 'enabled' => true, 'last_login' => '2026-10-03 08:00:00'],
                ['id' => 3, 'username' => 'dormant', 'enabled' => false, 'last_login' => null],
            ],
        ], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertNoStoredPassword($tester->getDisplay());
    }

    public function testTheTableNamesTheAccountsAndNeverTheirStoredValue(): void
    {
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/\b2\s+ops\s+yes\s+2026-10-03 08:00:00\b/', $display);
        self::assertMatchesRegularExpression('/\b3\s+dormant\s+no\s+none recorded\b/', $display);
        self::assertStringContainsString('2 local account(s)', $display);
        foreach (['admin', 'modern', 'argon', 'blank', 'web', 'directory', 'shouting', 'guest', 'padded', 'short', 'mixed'] as $absent) {
            self::assertDoesNotMatchRegularExpression('/\b' . $absent . '\b/', $display);
        }
        $this->assertNoStoredPassword($display);
    }

    public function testNoLegacyAccountsIsASuccessWithAnEmptyList(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth WHERE id IN (2, 3)');
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No local account stores an unsalted MD5 password.', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'local', 'count' => 0, 'accounts' => []], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAnOperatorWithoutUserManagementIsDeniedBeforeAnyAccountIsRead(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 1');
        $tester = $this->command();

        self::assertSame(Command::FAILURE, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'denied'], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringNotContainsString('ops', $tester->getDisplay());
    }

    public function testAnUnknownOrEmptyOperatorIsRefused(): void
    {
        $tester = $this->command();

        self::assertSame(Command::FAILURE, $tester->execute(['--as' => 'nobody', '--json' => true]));
        self::assertSame('denied', json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['status']);
        self::assertSame(Command::INVALID, $tester->execute(['--as' => '', '--json' => true]));
        self::assertSame('invalid', json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['status']);
    }

    public function testADatabaseFailureIsReportedWithoutItsDetail(): void
    {
        $this->db->executeStatement('DROP TABLE user_log');
        $tester = $this->command();

        self::assertSame(Command::FAILURE, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'failed', 'error' => 'Listing failed'], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string, string}> */
    public static function storedValues(): iterable
    {
        yield 'lowercase md5' => [md5('right'), 'right'];
        yield 'uppercase md5' => [strtoupper(md5('right')), 'right'];
        yield 'md5 with a trailing space' => [md5('right') . ' ', 'right'];
        yield 'bcrypt' => [password_hash('right', PASSWORD_BCRYPT), 'right'];
        yield 'empty' => ['', ''];
        yield 'shipped guest value' => ['43e9a4ab75570f5b', 'guest'];
    }

    /**
     * The report must flag exactly the stored values that the shipped
     * compat_password_verify() accepts through its MD5 fallback, so the
     * fallback is run from lib/auth.php itself in a child process.
     */
    #[DataProvider('storedValues')]
    public function testTheDetectionMatchesTheLoginMd5Fallback(string $stored, string $password): void
    {
        $auth = file_get_contents(dirname(__DIR__, 2) . '/lib/auth.php');
        self::assertIsString($auth);
        $program = 'function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }' . "\n"
            . test_php_function_source($auth, 'compat_password_hash') . "\n"
            . test_php_function_source($auth, 'compat_hash_equals') . "\n"
            // The extracted function runs as -r code, where __DIR__ is the working
            // directory; give it back the lib/ directory it has in lib/auth.php.
            . str_replace('__DIR__', var_export(dirname(__DIR__, 2) . '/lib', true), test_php_function_source($auth, 'compat_password_verify')) . "\n"
            . '[$stored, $password] = json_decode($argv[1], true);'
            . 'print json_encode(["verified" => compat_password_verify($password, $stored), "modern" => password_get_info($stored)["algo"] !== null]);';
        $run = test_php_run([PHP_BINARY, '-r', $program, json_encode([$stored, $password], JSON_THROW_ON_ERROR)]);
        self::assertSame(['', 0], [$run['err'], $run['status']]);
        $login = json_decode($run['out'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($login['verified'] && !$login['modern'], LegacyPasswordHash::isUnsaltedMd5($stored));
    }
}
