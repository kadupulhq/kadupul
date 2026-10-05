<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/auth.php';

final class CredentialGenerationFaultStatement extends PDOStatement
{
    public string $fault = '';
    private bool $fetched = false;

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        return $this->fault === 'execute' ? false : $result;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $value = parent::fetchColumn($column);
        $this->fetched = true;
        return $value;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $value = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        $this->fetched = true;
        return $value;
    }

    public function errorInfo(): array
    {
        return $this->errorCode() === '00000' ? parent::errorInfo() : ['HY000', 999, 'Owned read confirmation fault'];
    }

    public function errorCode(): ?string
    {
        if ($this->fault === 'state' || ($this->fault === 'fetch' && $this->fetched)) {
            return 'HY000';
        }
        if ($this->fault === 'unknown' && $this->fetched) {
            return null;
        }
        return parent::errorCode();
    }
}

final class CredentialGenerationFaultConnection extends PDO
{
    public function __construct(private string $fault, private bool $coherent)
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CredentialGenerationFaultStatement::class]);
        $this->exec('CREATE TABLE settings_user(user_id INTEGER, name TEXT, value TEXT); CREATE TABLE user_auth(id INTEGER, password TEXT)');
        $this->exec("INSERT INTO user_auth VALUES(9,'fixture-password')");
        if ($coherent) {
            $this->exec("INSERT INTO settings_user VALUES(9,'auth_credential_generation','" . str_repeat('a', 64) . ':' . str_repeat('b', 64) . "')");
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->fault === 'prepare' && str_starts_with($query, $this->coherent ? 'SELECT ua.password' : 'SELECT value')) {
            return parent::prepare('SELECT missing_column FROM missing_table');
        }
        $statement = parent::prepare($query, $options);
        if ($statement instanceof CredentialGenerationFaultStatement && str_starts_with($query, $this->coherent ? 'SELECT ua.password' : 'SELECT value')) {
            $statement->fault = $this->fault;
        }
        return $statement;
    }
}

final class CredentialGenerationReadTest extends TestCase
{
    public static function faults(): iterable
    {
        foreach ([false, true] as $coherent) {
            foreach (['prepare', 'execute', 'state', 'fetch', 'unknown'] as $fault) {
                yield ($coherent ? 'coherent reread' : 'mapping read') . '-' . $fault => [$fault, $coherent];
            }
        }
    }

    #[DataProvider('faults')]
    public function testUnconfirmedNativeReadNeverFallsBackToPasswordFingerprint(string $fault, bool $coherent): void
    {
        $database = new CredentialGenerationFaultConnection($fault, $coherent);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Credential generation read could not be confirmed.');
        auth_session_credential_generation(9, 'fixture-password', $database);
    }

    private function configureDefaultConnection(PDO $database): void
    {
        $GLOBALS['database_hostname'] = 'owned';
        $GLOBALS['database_port'] = '0';
        $GLOBALS['database_default'] = 'credential-fixture';
        $GLOBALS['database_sessions'] = ['owned:0:credential-fixture' => $database];
        $GLOBALS['config'] = [];
        $_SESSION = ['sess_user_id' => 9, 'sess_user_credential' => auth_session_credential_key('fixture-password')];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDefaultConfiguredPrimaryDistinguishesMissingMappingFromActualLegacyReadFailure(): void
    {
        require dirname(__DIR__, 2) . '/lib/database.php';
        require dirname(__DIR__) . '/Helpers/PhpSource.php';
        eval(test_php_function_source(file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php'), 'cacti_count'));
        eval(test_php_function_source(file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php'), 'clean_up_lines'));
        $database = new CredentialGenerationFaultConnection('', false);
        $this->configureDefaultConnection($database);
        self::assertFalse(db_fetch_cell_prepared("SELECT value FROM settings_user WHERE user_id = ? AND name = 'auth_credential_generation'", [9], '', false));
        self::assertTrue(auth_session_credentials_valid('fixture-password'));
        $failed = new CredentialGenerationFaultConnection('state', false);
        $this->configureDefaultConnection($failed);
        self::assertFalse(db_fetch_cell_prepared("SELECT value FROM settings_user WHERE user_id = ? AND name = 'auth_credential_generation'", [9], '', false));
        self::assertStringContainsString('DB Cell Failed!', $GLOBALS['database_last_error']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Credential generation read could not be confirmed.');
        auth_session_credentials_valid('fixture-password');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConfiguredMissingPrimaryIsRefusedBeforeLegacyFallback(): void
    {
        $this->configureDefaultConnection(new CredentialGenerationFaultConnection('', false));
        $GLOBALS['database_sessions'] = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Credential generation read could not be confirmed.');
        auth_session_credentials_valid('fixture-password');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConfiguredInvalidPrimaryIsRefusedBeforeLegacyFallback(): void
    {
        $this->configureDefaultConnection(new CredentialGenerationFaultConnection('', false));
        $GLOBALS['database_sessions']['owned:0:credential-fixture'] = new stdClass();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Credential generation read could not be confirmed.');
        auth_session_credentials_valid('fixture-password');
    }

    public function testSuccessfulMissingMappingRetainsThePasswordFingerprint(): void
    {
        $database = new CredentialGenerationFaultConnection('', false);
        self::assertSame(hash('sha256', 'fixture-password'), auth_session_credential_generation(9, 'fixture-password', $database));
    }

    public function testSuccessfulCoherentRereadWithMissingAccountHasNoGeneration(): void
    {
        $database = new CredentialGenerationFaultConnection('', true);
        $database->exec('DELETE FROM user_auth WHERE id=9');
        self::assertSame('', auth_session_credential_generation(9, 'fixture-password', $database));
    }
}
