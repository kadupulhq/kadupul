<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Kadupul\DataInput\Infrastructure\Legacy\DataInputHandoff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataInputHandoffTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/data-input-handoff-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/bin', 0700, true);
        mkdir($this->directory . '/cli', 0700);
        file_put_contents($this->directory . '/bin/legacy-data-input-handoff.php', <<<'PROGRAM'
<?php
$command = json_decode(stream_get_contents(STDIN), true);
$mode = file_get_contents('mode');
file_put_contents('started-' . $command['id'], 'started');
if ($mode === 'slow') { usleep(800000); file_put_contents('late-' . $command['id'], 'late'); }
$response = ['actor' => $command['actor'], 'id' => $command['id'], 'nonce' => $command['nonce'], 'phase' => 'propagate', 'status' => 'ok'];
if (in_array($mode, ['actor', 'id', 'nonce', 'phase'], true)) $response[$mode] = 'foreign';
if ($mode === 'partial') $response['status'] = 'partial';
if ($mode === 'missing') { echo 'UNVERIFIED'; exit; }
if ($mode === 'malformed') { echo 'KADUPUL_DATA_INPUT_HANDOFF_RESULT={broken}', PHP_EOL; exit; }
echo 'KADUPUL_DATA_INPUT_HANDOFF_RESULT=', json_encode($response), PHP_EOL;
PROGRAM);
        file_put_contents($this->directory . '/cli/input_whitelist.php', <<<'PROGRAM'
<?php
file_put_contents('whitelist-arguments', json_encode(array_slice($argv, 1)));
if (file_get_contents('mode') === 'slow') { usleep(800000); file_put_contents('whitelist-late', 'late'); }
PROGRAM);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*/*') as $file) {
            unlink($file);
        }
        foreach (glob($this->directory . '/*') as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->directory);
    }

    #[DataProvider('responses')]
    public function testCollectorConfirmationMustMatchItsActorTargetNonceAndPhase(string $mode, bool $expected): void
    {
        file_put_contents($this->directory . '/mode', $mode);
        $handoff = new DataInputHandoff(PHP_BINARY, $this->directory, 9, hrtime(true) / 1e9 + 2);
        self::assertSame($expected, $handoff->propagate(3));
    }

    public static function responses(): iterable
    {
        yield ['ok', true];
        foreach (['actor', 'id', 'nonce', 'phase', 'partial', 'missing', 'malformed'] as $mode) {
            yield [$mode, false];
        }
    }

    public function testWhitelistUsesOnlyUpdateAndTheTargetWithoutPush(): void
    {
        file_put_contents($this->directory . '/mode', 'ok');
        $handoff = new DataInputHandoff(PHP_BINARY, $this->directory, 9, hrtime(true) / 1e9 + 2);
        self::assertTrue($handoff->whitelist(3));
        self::assertSame(['--update', '--id=3'], json_decode(file_get_contents($this->directory . '/whitelist-arguments'), true));
    }

    #[DataProvider('configuredBinaryNames')]
    public function testConfiguredWhitelistBinaryReceivesArgvWithoutChangingCollectorRuntime(string $name): void
    {
        file_put_contents($this->directory . '/mode', 'ok');
        $wrapper = $this->directory . '/' . $name;
        file_put_contents($wrapper, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> configured-arguments\nexec " . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
        chmod($wrapper, 0700);
        $handoff = new DataInputHandoff(PHP_BINARY, $this->directory, 9, hrtime(true) / 1e9 + 2, whitelistBinary: $wrapper);

        self::assertTrue($handoff->whitelist(3));
        self::assertTrue($handoff->propagate(3));
        self::assertSame([$this->directory . '/cli/input_whitelist.php', '--update', '--id=3'], file($this->directory . '/configured-arguments', FILE_IGNORE_NEW_LINES));
        self::assertSame(['--update', '--id=3'], json_decode(file_get_contents($this->directory . '/whitelist-arguments'), true));
    }

    public static function configuredBinaryNames(): iterable
    {
        yield 'interior space' => ['configured php'];
        yield 'pathname ending with a space' => [' configured php '];
    }

    #[DataProvider('emptyConfiguredBinaries')]
    public function testEmptyConfiguredWhitelistBinaryFallsBackToTheWorkerRuntime(string $binary): void
    {
        file_put_contents($this->directory . '/mode', 'ok');
        $handoff = new DataInputHandoff(PHP_BINARY, $this->directory, 9, hrtime(true) / 1e9 + 2, whitelistBinary: $binary);
        self::assertTrue($handoff->whitelist(3));
        self::assertSame(['--update', '--id=3'], json_decode(file_get_contents($this->directory . '/whitelist-arguments'), true));
    }

    public static function emptyConfiguredBinaries(): iterable
    {
        yield 'empty setting' => [''];
        yield 'blank setting' => ['   '];
    }

    public function testUnavailableConfiguredWhitelistBinaryFailsWithoutChangingCollectorRuntime(): void
    {
        file_put_contents($this->directory . '/mode', 'ok');
        $handoff = new DataInputHandoff(PHP_BINARY, $this->directory, 9, hrtime(true) / 1e9 + 2, whitelistBinary: $this->directory . '/missing php');

        self::assertFalse($handoff->whitelist(3));
        self::assertFileDoesNotExist($this->directory . '/whitelist-arguments');
        self::assertTrue($handoff->propagate(3));
    }

    #[DataProvider('slowPhases')]
    public function testTimedOutLeafCannotWriteAfterItsPhaseReturns(string $phase, string $artifact): void
    {
        file_put_contents($this->directory . '/mode', 'slow');
        $handoff = new DataInputHandoff(PHP_BINARY, $this->directory, 9, hrtime(true) / 1e9 + 2, 0.1);
        $start = hrtime(true);
        self::assertFalse($handoff->$phase(3));
        self::assertLessThan(0.6, (hrtime(true) - $start) / 1e9);
        usleep(850000);
        self::assertFileDoesNotExist($this->directory . '/' . $artifact);
    }

    public static function slowPhases(): iterable
    {
        yield ['whitelist', 'whitelist-late'];
        yield ['propagate', 'late-3'];
    }

    public function testBulkTargetsShareTheDeadlineAndExhaustionNeverSpawnsTheNextChild(): void
    {
        file_put_contents($this->directory . '/mode', 'slow');
        $handoff = new DataInputHandoff(PHP_BINARY, $this->directory, 9, hrtime(true) / 1e9 + 0.5);
        self::assertFalse($handoff->propagate(3));
        self::assertFileExists($this->directory . '/started-3');
        self::assertFalse($handoff->propagate(5));
        self::assertFileDoesNotExist($this->directory . '/started-5');
        usleep(850000);
        self::assertFileDoesNotExist($this->directory . '/late-3');
        self::assertFileDoesNotExist($this->directory . '/late-5');
    }

    public function testAnAlreadyExhaustedDeadlineDoesNotSpawnEitherPhase(): void
    {
        file_put_contents($this->directory . '/mode', 'ok');
        $handoff = new DataInputHandoff(PHP_BINARY, $this->directory, 9, hrtime(true) / 1e9 - 1);
        self::assertFalse($handoff->whitelist(3));
        self::assertFalse($handoff->propagate(3));
        self::assertFileDoesNotExist($this->directory . '/started-3');
        self::assertFileDoesNotExist($this->directory . '/whitelist-arguments');
    }
}
