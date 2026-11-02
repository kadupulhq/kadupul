<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExplicitMembershipConnectionTest extends TestCase
{
    private \PDO $default;
    private \PDO $selected;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/database.php';
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        $this->default = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->selected = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach ([$this->default, $this->selected] as $database) {
            $database->exec('CREATE TABLE caller_marker (value TEXT NOT NULL)');
            $database->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT)');
        }
        $GLOBALS['database_hostname'] = 'fixture';
        $GLOBALS['database_port'] = 0;
        $GLOBALS['database_default'] = 'default';
        $GLOBALS['database_sessions'] = ['fixture:0:default' => $this->default];
    }

    public function testExplicitConnectionDoesNotUseTheLegacyDefault(): void
    {
        $unit = \auth_membership_begin([], $this->selected);
        self::assertSame($this->selected, $unit['db']);
        self::assertFalse($this->default->inTransaction());
        self::assertTrue($this->selected->inTransaction());
        $this->selected->exec("INSERT INTO caller_marker VALUES ('selected')");
        \auth_membership_finish($unit, true);
        self::assertFalse($this->selected->inTransaction());
        self::assertSame('selected', $this->selected->query('SELECT value FROM caller_marker')->fetchColumn());
        self::assertSame(0, (int) $this->default->query('SELECT COUNT(*) FROM caller_marker')->fetchColumn());
    }

    public function testExplicitConnectionDoesNotCreateOrReadRegistryGlobals(): void
    {
        $keys = ['database_hostname', 'database_port', 'database_default', 'database_sessions'];
        foreach ($keys as $key) {
            unset($GLOBALS[$key]);
        }
        $unit = \auth_membership_begin([], $this->selected);
        self::assertSame($this->selected, $unit['db']);
        \auth_membership_finish($unit, false);
        foreach ($keys as $key) {
            self::assertArrayNotHasKey($key, $GLOBALS);
        }
    }

    public function testBorrowedExplicitTransactionPreservesCallerWorkOnRollback(): void
    {
        $this->selected->beginTransaction();
        $this->selected->exec("INSERT INTO caller_marker VALUES ('caller')");
        $unit = \auth_membership_begin([], $this->selected);
        self::assertFalse($unit['owned']);
        $this->selected->exec("INSERT INTO caller_marker VALUES ('unit')");
        \auth_membership_finish($unit, false);
        self::assertTrue($this->selected->inTransaction());
        self::assertSame(['caller'], $this->selected->query('SELECT value FROM caller_marker')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertFalse($this->default->inTransaction());
        $this->selected->rollBack();
    }

    public function testExplicitCredentialGenerationDoesNotTouchRegistryGlobals(): void
    {
        $password = str_repeat('a', 32);
        $fingerprint = \auth_session_credential_key($password);
        $generation = str_repeat('b', 64);
        $this->selected->prepare('INSERT INTO settings_user VALUES (42,?,?)')->execute(['auth_credential_generation', $fingerprint . ':' . $generation]);
        $keys = ['database_hostname', 'database_port', 'database_default', 'database_sessions'];
        foreach ($keys as $key) {
            unset($GLOBALS[$key]);
        }
        self::assertSame($generation, \auth_session_credential_generation(42, $password, $this->selected));
        foreach ($keys as $key) {
            self::assertArrayNotHasKey($key, $GLOBALS);
        }
    }

    public function testCredentialGenerationRetainsTheLegacyDefaultWhenNoPdoIsSupplied(): void
    {
        $password = str_repeat('a', 32);
        $fingerprint = \auth_session_credential_key($password);
        $generation = str_repeat('b', 64);
        $this->selected->prepare('INSERT INTO settings_user VALUES (42,?,?)')->execute(['auth_credential_generation', $fingerprint . ':' . $generation]);
        self::assertSame($fingerprint, \auth_session_credential_generation(42, $password));
        self::assertSame($generation, \auth_session_credential_generation(42, $password, $this->selected));
    }

    public function testMissingConfiguredDefaultCredentialConnectionFailsClosed(): void
    {
        $GLOBALS['database_sessions'] = [];
        $this->selected->beginTransaction();
        $this->selected->exec("INSERT INTO caller_marker VALUES ('caller')");
        try {
            \auth_session_credential_generation(42, str_repeat('a', 32));
            self::fail('An absent configured connection must not read another database.');
        } catch (\RuntimeException $error) {
            self::assertSame('Credential generation read could not be confirmed.', $error->getMessage());
        }
        self::assertTrue($this->selected->inTransaction());
        self::assertSame('caller', $this->selected->query('SELECT value FROM caller_marker')->fetchColumn());
        $this->selected->rollBack();
    }

    public function testExistingCallersRetainDefaultConnectionSelection(): void
    {
        $unit = \auth_membership_begin();
        self::assertSame($this->default, $unit['db']);
        self::assertTrue($this->default->inTransaction());
        self::assertFalse($this->selected->inTransaction());
        \auth_membership_finish($unit, true);
        self::assertFalse($this->default->inTransaction());
    }
}
