<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\LegacyConnectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * lib/database.php takes its connection state from LegacyConnectionRegistry
 * instead of declaring it global, and the registry reads the globals when it
 * is called, so code that assigns them keeps working. DatabaseConnectionStateTest
 * and tests/integration/DatabaseConnectionStateDatabaseTest hold the helpers'
 * behaviour to what it was before.
 */
final class LegacyConnectionRegistryTest extends TestCase
{
    private const array STATE = ['database_sessions', 'database_details', 'database_hostname', 'database_port', 'database_default', 'database_username', 'database_password', 'database_persist', 'local_db_cnn_id', 'remote_db_cnn_id'];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::STATE as $name) {
            if (\array_key_exists($name, $GLOBALS)) {
                $this->saved[$name] = $GLOBALS[$name];
            }
            unset($GLOBALS[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::STATE as $name) {
            unset($GLOBALS[$name]);
        }
        foreach ($this->saved as $name => $value) {
            $GLOBALS[$name] = $value;
        }
    }

    public function testLibDatabaseNoLongerDeclaresTheConnectionState(): void
    {
        $declared = [];
        $named = [];
        $tokens = token_get_all((string) file_get_contents(\dirname(__DIR__, 2) . '/lib/database.php'), TOKEN_PARSE);
        $function = '';
        foreach ($tokens as $index => $token) {
            if (!\is_array($token)) {
                continue;
            }
            if ($token[0] === T_FUNCTION) {
                $next = $tokens[$index + 2] ?? null;
                $function = \is_array($next) ? $next[1] : $function;
            } elseif ($token[0] === T_GLOBAL) {
                for ($i = $index + 1; ($tokens[$i] ?? ';') !== ';'; $i++) {
                    if (\is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE && \in_array(substr($tokens[$i][1], 1), self::STATE, true)) {
                        $declared[$function][] = substr($tokens[$i][1], 1);
                    }
                }
            } elseif ($token[0] === T_VARIABLE) {
                $named[$token[1]] = true;
            }
        }

        // db_check_reconnect() still writes these globals when config.php is
        // missing, so that fallback keeps its declarations, and db_dump_data()
        // keeps reading the account it hands to the client unchanged.
        self::assertSame([
            'db_check_reconnect' => ['database_hostname', 'database_username', 'database_password', 'database_default', 'database_port'],
            'db_dump_data' => ['database_default', 'database_username', 'database_password'],
        ], $declared);
        foreach (['$database_sessions', '$database_details', '$local_db_cnn_id', '$remote_db_cnn_id'] as $variable) {
            self::assertArrayNotHasKey($variable, $named, $variable . ' is reached only through LegacyConnectionRegistry');
        }
    }

    public function testTheDefaultSessionIsReadWhenTheRegistryIsCalled(): void
    {
        $first = new \PDO('sqlite::memory:');
        $second = new \PDO('sqlite::memory:');
        $GLOBALS['database_hostname'] = 'probe';
        $GLOBALS['database_port'] = 0;
        $GLOBALS['database_default'] = 'state';
        $GLOBALS['database_sessions'] = ['probe:0:state' => $first];
        self::assertSame('probe:0:state', LegacyConnectionRegistry::defaultKey());
        self::assertSame(['probe', 0, 'state'], LegacyConnectionRegistry::defaultTarget());
        self::assertSame($first, LegacyConnectionRegistry::select(false));
        self::assertSame($second, LegacyConnectionRegistry::select($second));

        // A plain assignment after the first read is seen by the next one.
        $GLOBALS['database_sessions']['probe:0:state'] = $second;
        self::assertSame($second, LegacyConnectionRegistry::select(null));
        $GLOBALS['database_default'] = 'moved';
        $GLOBALS['database_sessions']['probe:0:moved'] = $first;
        self::assertSame($first, LegacyConnectionRegistry::select(false));

        LegacyConnectionRegistry::setDefaultSession(null);
        self::assertTrue(\array_key_exists('probe:0:moved', $GLOBALS['database_sessions']));
        self::assertFalse(LegacyConnectionRegistry::hasSession('probe:0:moved'));
    }

    public function testRegisteringASessionWritesTheLegacyTables(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        LegacyConnectionRegistry::remember('h:1:d', $pdo, ['database_conn' => $pdo, 'database_password' => 'kept-for-reconnect']);
        self::assertSame(['h:1:d' => $pdo], $GLOBALS['database_sessions']);
        self::assertSame([spl_object_hash($pdo) => ['database_conn' => $pdo, 'database_password' => 'kept-for-reconnect']], $GLOBALS['database_details']);
        self::assertSame($GLOBALS['database_details'], LegacyConnectionRegistry::settings());
    }

    public function testReadingAMissingGlobalCreatesItAsNullAsGlobalDid(): void
    {
        self::assertNull(LegacyConnectionRegistry::persist());
        self::assertSame(['local' => null, 'remote' => null], LegacyConnectionRegistry::handles());
        self::assertFalse(LegacyConnectionRegistry::hasSession('none'));
        foreach (['database_persist', 'local_db_cnn_id', 'remote_db_cnn_id', 'database_sessions', 'database_details'] as $name) {
            self::assertTrue(\array_key_exists($name, $GLOBALS), $name);
            self::assertNull($GLOBALS[$name], $name);
        }

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];

            return true;
        });
        try {
            self::assertNull(LegacyConnectionRegistry::select(false));
            $GLOBALS['database_sessions'] = [];
            self::assertNull(LegacyConnectionRegistry::select(false));
        } finally {
            restore_error_handler();
        }
        self::assertSame([[E_WARNING, 'Trying to access array offset on null'], [E_WARNING, 'Undefined array key "::"']], $warnings);
    }

    public function testTheRegistryHoldsNothingThatCanLeakCredentials(): void
    {
        $class = new \ReflectionClass(LegacyConnectionRegistry::class);
        self::assertSame([], $class->getProperties());
        self::assertFalse($class->isInstantiable());
        self::assertSame([], $class->getConstants());
        $details = (new \ReflectionMethod(LegacyConnectionRegistry::class, 'remember'))->getParameters()[2];
        self::assertNotSame([], $details->getAttributes(\SensitiveParameter::class));

        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            [$secret, $error] = $this->rememberThroughAFailingCall();
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
        $trace = $error->getTraceAsString() . print_r($error->getTrace(), true) . var_export($error->getTrace(), true) . $error;
        self::assertStringNotContainsString($secret, $trace);
        self::assertStringContainsString('SensitiveParameterValue', $trace);
        self::assertArrayNotHasKey('database_details', array_filter($GLOBALS, static fn(mixed $value): bool => $value !== null));
    }

    /**
     * The secret is made here and travels only as remember()'s argument, so
     * no other frame, and no property of the test, can show it.
     *
     * @return array{string, \TypeError}
     */
    private function rememberThroughAFailingCall(): array
    {
        $secret = 'registry-' . bin2hex(random_bytes(8));
        $remember = [LegacyConnectionRegistry::class, 'remember'];
        try {
            $remember('h:1:d', 'not a connection', ['database_password' => $secret]);
        } catch (\TypeError $error) {
            return [$secret, $error];
        }
        self::fail('remember() accepts only a PDO');
    }
}
