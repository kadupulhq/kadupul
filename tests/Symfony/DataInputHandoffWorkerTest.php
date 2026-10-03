<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Kadupul\DataInput\Domain\DataInputState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DataInputHandoffWorkerTest extends TestCase
{
    private string $directory;
    private \PDO $database;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->directory = sys_get_temp_dir() . '/data-input-handoff-worker-' . bin2hex(random_bytes(8));
        foreach (['', '/bin', '/include', '/lib', '/cli'] as $folder) {
            mkdir($this->directory . $folder, 0700);
        }
        foreach (['legacy-data-input', 'legacy-data-input-handoff'] as $script) {
            $source = $script === 'legacy-data-input' && getenv('DATA_INPUT_FAIL_BEFORE_WORKER') ? getenv('DATA_INPUT_FAIL_BEFORE_WORKER') : $root . '/bin/' . $script . '.php';
            copy($source, $this->directory . '/bin/' . $script . '.php');
        }
        copy($root . '/tests/Fixtures/data-input-handoff-bootstrap.php', $this->directory . '/include/cli_check.php');
        file_put_contents($this->directory . '/source.json', json_encode($root, JSON_THROW_ON_ERROR));
        file_put_contents($this->directory . '/mode', 'ok');
        foreach (['api_data_source', 'poller', 'template', 'utility'] as $library) {
            file_put_contents($this->directory . '/lib/' . $library . '.php', '<?php');
        }
        file_put_contents($this->directory . '/lib/data_input_worker.php', '<?php require ' . var_export($root . '/lib/data_input_worker.php', true) . ';');
        $this->database = new \PDO('sqlite:' . $this->directory . '/worker.sqlite');
        $this->database->exec("CREATE TABLE settings(name TEXT PRIMARY KEY,value TEXT); INSERT INTO settings VALUES('auth_method','1');
            CREATE TABLE user_auth(id INTEGER,username TEXT,enabled TEXT,locked TEXT,must_change_password TEXT,password_change TEXT);
            INSERT INTO user_auth VALUES(9,'operator','on','','','');
            CREATE TABLE user_auth_realm(user_id INTEGER,realm_id INTEGER); INSERT INTO user_auth_realm VALUES(9,8),(9,2);
            CREATE TABLE data_input(id INTEGER,hash TEXT,name TEXT,type_id INTEGER,input_string TEXT);
            INSERT INTO data_input VALUES(3,'fixture-hash','Fixture',1,'fixture command'),(4,'3eb92bb845b9660a7445cf9740726522','Protected',1,'fixture command');
            CREATE TABLE data_input_fields(id INTEGER,data_input_id INTEGER);
            CREATE TABLE data_template_data(local_data_id INTEGER,data_input_id INTEGER);");
    }

    protected function tearDown(): void
    {
        unset($this->database);
        foreach (glob($this->directory . '/*/*') as $file) {
            unlink($file);
        }
        foreach (glob($this->directory . '/*') as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->directory);
    }

    #[DataProvider('collectorCases')]
    public function testLeafCollectorReauthorizesAndVerifiesTheTargetThenReportsActualWarnings(string $mode, int $id, string $status, bool $started): void
    {
        file_put_contents($this->directory . '/mode', $mode);
        if ($mode === 'revoked') {
            $this->database->exec('DELETE FROM user_auth_realm WHERE realm_id=2');
        }
        $process = new Process([PHP_BINARY, $this->directory . '/bin/legacy-data-input-handoff.php'], $this->directory);
        $nonce = str_repeat('a', 32);
        $process->setInput(json_encode(['actor' => 9, 'id' => $id, 'nonce' => $nonce], JSON_THROW_ON_ERROR));
        $process->run();
        self::assertSame('', $process->getErrorOutput());
        self::assertMatchesRegularExpression('/^KADUPUL_DATA_INPUT_HANDOFF_RESULT=/', $process->getOutput());
        $result = json_decode(substr(trim($process->getOutput()), strlen('KADUPUL_DATA_INPUT_HANDOFF_RESULT=')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['actor' => 9, 'id' => $id, 'nonce' => $nonce, 'phase' => 'propagate', 'status' => $status], $result);
        self::assertSame($started, is_file($this->directory . '/collector-' . $id));
        self::assertSame(0, (int) $this->database->query("SELECT COUNT(*) FROM settings WHERE name LIKE 'poller_replicate%'")->fetchColumn());
        self::assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM data_input')->fetchColumn());
    }

    public static function collectorCases(): iterable
    {
        yield 'confirmed' => ['ok', 3, 'ok', true];
        yield 'revoked grant' => ['revoked', 3, 'partial', false];
        yield 'missing target' => ['ok', 999, 'partial', false];
        yield 'protected target' => ['ok', 4, 'partial', false];
        yield 'remote collector execution' => ['collector', 3, 'partial', false];
        yield 'session warning' => ['warning', 3, 'partial', true];
        yield 'database error' => ['db_error', 3, 'partial', true];
        yield 'error message' => ['message_error', 3, 'partial', true];
    }

    #[DataProvider('whitelistPresentationCases')]
    public function testReadStatePreservesDisabledMissingAndStrictVerifiedDistinctions(string $mode, ?string $contents, string $expected): void
    {
        file_put_contents($this->directory . '/mode', $mode);
        if ($contents !== null) {
            file_put_contents($this->directory . '/whitelist', $contents);
        }
        if ($mode === 'empty command') {
            $this->database->exec("UPDATE data_input SET input_string='' WHERE id=3");
        }
        $process = new Process([PHP_BINARY, $this->directory . '/bin/legacy-data-input.php'], $this->directory);
        $process->setInput(json_encode(['actor' => 9, 'id' => 3, 'nonce' => str_repeat('a', 32), 'action' => 'find', 'payload' => []], JSON_THROW_ON_ERROR));
        $process->run();
        self::assertSame('', $process->getErrorOutput());
        self::assertTrue($process->isSuccessful());
        $result = json_decode(substr(trim($process->getOutput()), strlen('KADUPUL_DATA_INPUT_RESULT=')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $result['status']);
        self::assertSame($expected, $result['result']['whitelist']);
    }

    public static function whitelistPresentationCases(): iterable
    {
        yield 'disabled' => ['disabled', null, 'disabled'];
        yield 'empty command' => ['empty command', null, 'disabled'];
        yield 'configured missing file' => ['ok', null, 'requires_update'];
        yield 'exact string' => ['ok', '{"fixture-hash":"fixture command"}', 'verified'];
        yield 'wrong type' => ['ok', '{"fixture-hash":true}', 'requires_update'];
        yield 'missing entry' => ['ok', '{}', 'requires_update'];
        yield 'corrupt JSON' => ['ok', '{broken', 'requires_update'];
    }

    #[DataProvider('whitelistValues')]
    public function testWhitelistConfirmationRequiresTheExactSavedCommand(mixed $value, string $expected, bool $changed = false): void
    {
        $json = json_encode(['fixture-hash' => $value], JSON_THROW_ON_ERROR);
        $program = '<?php file_put_contents("whitelist", ' . var_export($json, true) . ');';
        if ($changed) {
            // A distinct connection commits a concurrent edit after the whitelist
            // file snapshot is written but before the post-commit leaf returns.
            $program .= ' $db = new PDO("sqlite:worker.sqlite"); $db->exec("UPDATE data_input SET input_string=\"changed command\" WHERE id=3");';
        }
        file_put_contents($this->directory . '/cli/input_whitelist.php', $program);
        $method = $this->database->query('SELECT * FROM data_input WHERE id=3')->fetch(\PDO::FETCH_ASSOC);
        $command = ['actor' => 9, 'id' => 3, 'nonce' => str_repeat('a', 32), 'action' => 'whitelist', 'payload' => ['revision' => DataInputState::revision($method, [])]];
        $process = new Process([PHP_BINARY, $this->directory . '/bin/legacy-data-input.php'], $this->directory);
        $process->setInput(json_encode($command, JSON_THROW_ON_ERROR));
        $process->run();
        self::assertSame('', $process->getErrorOutput());
        self::assertTrue($process->isSuccessful());
        $result = json_decode(substr(trim($process->getOutput()), strlen('KADUPUL_DATA_INPUT_RESULT=')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, $result['status']);
        self::assertSame($expected === 'ok', is_file($this->directory . '/collector-3'));
        self::assertSame(2, (int) $this->database->query("SELECT COUNT(*) FROM settings WHERE name LIKE 'poller_replicate%' AND value='1'")->fetchColumn());
        self::assertSame($changed ? 'changed command' : 'fixture command', $this->database->query('SELECT input_string FROM data_input WHERE id=3')->fetchColumn());
    }

    public static function whitelistValues(): iterable
    {
        yield 'exact saved command' => ['fixture command', 'ok'];
        yield 'different command' => ['another command', 'partial'];
        yield 'boolean cannot verify text' => [true, 'partial'];
        yield 'command changed after whitelist snapshot' => ['fixture command', 'partial', true];
    }

    #[DataProvider('boundLeafCases')]
    public function testWhitelistLeafReauthorizesTheBoundRevisionAndExactFileEntry(string $mode, string $expected): void
    {
        $method = $this->database->query('SELECT * FROM data_input WHERE id=3')->fetch(\PDO::FETCH_ASSOC);
        $revision = DataInputState::revision($method, []);
        file_put_contents($this->directory . '/whitelist', json_encode(['fixture-hash' => $mode === 'boolean' ? true : 'fixture command'], JSON_THROW_ON_ERROR));
        if ($mode === 'changed') {
            $this->database->exec("UPDATE data_input SET input_string='changed command' WHERE id=3");
        } elseif ($mode === 'malformed revision') {
            $revision = 'not-a-revision';
        } elseif ($mode === 'revoked') {
            $this->database->exec('DELETE FROM user_auth_realm WHERE realm_id=2');
        }
        if ($mode === 'missing') {
            unlink($this->directory . '/whitelist');
        }
        $process = new Process([PHP_BINARY, $this->directory . '/bin/legacy-data-input-handoff.php'], $this->directory);
        $process->setInput(json_encode(['actor' => 9, 'id' => 3, 'nonce' => str_repeat('a', 32), 'revision' => $revision], JSON_THROW_ON_ERROR));
        $process->run();
        self::assertSame('', $process->getErrorOutput());
        $result = json_decode(substr(trim($process->getOutput()), strlen('KADUPUL_DATA_INPUT_HANDOFF_RESULT=')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, $result['status']);
        self::assertSame($expected === 'ok', is_file($this->directory . '/collector-3'));
        self::assertSame(0, (int) $this->database->query("SELECT COUNT(*) FROM settings WHERE name LIKE 'poller_replicate%'")->fetchColumn());
    }

    public static function boundLeafCases(): iterable
    {
        yield 'matching snapshot' => ['ok', 'ok'];
        foreach (['changed', 'boolean', 'missing', 'malformed revision', 'revoked'] as $mode) {
            yield $mode => [$mode, 'partial'];
        }
    }

    public function testDefaultWhitelistPhaseTimesOutWithoutLateWritesAndPreservesTheCommittedResult(): void
    {
        file_put_contents($this->directory . '/cli/input_whitelist.php', <<<'PROGRAM'
<?php
file_put_contents('whitelist-arguments', json_encode(array_slice($argv, 1)));
usleep(33000000);
file_put_contents('whitelist', 'late write');
PROGRAM);
        $method = $this->database->query('SELECT * FROM data_input WHERE id=3')->fetch(\PDO::FETCH_ASSOC);
        $command = ['actor' => 9, 'id' => 3, 'nonce' => str_repeat('a', 32), 'action' => 'whitelist', 'payload' => ['revision' => DataInputState::revision($method, [])]];
        $process = new Process([PHP_BINARY, $this->directory . '/bin/legacy-data-input.php'], $this->directory);
        $process->setTimeout(40);
        $process->setInput(json_encode($command, JSON_THROW_ON_ERROR));
        $start = hrtime(true);
        $process->run();
        $duration = (hrtime(true) - $start) / 1e9;
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode(substr(trim($process->getOutput()), strlen('KADUPUL_DATA_INPUT_RESULT=')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('partial', $result['status']);
        self::assertSame(['id' => 3], $result['result']);
        self::assertSame($command['actor'], $result['actor']);
        self::assertSame($command['action'], $result['action']);
        self::assertSame($command['id'], $result['request_id']);
        self::assertSame($command['nonce'], $result['nonce']);
        self::assertLessThan(32, $duration);
        self::assertSame(2, (int) $this->database->query("SELECT COUNT(*) FROM settings WHERE name LIKE 'poller_replicate%' AND value='1'")->fetchColumn());
        self::assertSame(['--update', '--id=3'], json_decode(file_get_contents($this->directory . '/whitelist-arguments'), true));
        usleep(3500000);
        self::assertFileDoesNotExist($this->directory . '/whitelist');
        self::assertFileDoesNotExist($this->directory . '/collector-3');
    }
}
