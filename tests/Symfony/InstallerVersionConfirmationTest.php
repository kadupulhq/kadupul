<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstallerVersionConfirmationTest extends TestCase
{
    private PDO $database;
    private array $previousGlobals;

    protected function setUp(): void
    {
        // Exercise the actual shared public writer, isolating its legacy class
        // and release constant from other kernel/legacy bootstrap test cases.
        define('CACTI_VERSION', trim(file_get_contents(dirname(__DIR__, 2) . '/include/cacti_version')));
        require_once dirname(__DIR__, 2) . '/lib/installer.php';
        $this->previousGlobals = [];
        foreach (['database_sessions', 'database_hostname', 'database_port', 'database_default'] as $key) {
            $this->previousGlobals[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
        }
        $this->database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $this->database->exec('CREATE TABLE version (cacti TEXT PRIMARY KEY)');
        $GLOBALS['database_hostname'] = 'version-fixture';
        $GLOBALS['database_port'] = 0;
        $GLOBALS['database_default'] = 'version-fixture';
        $GLOBALS['database_sessions'] = ['version-fixture:0:version-fixture' => $this->database];
    }

    protected function tearDown(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
        foreach ($this->previousGlobals as $key => [$present, $value]) {
            if ($present) {
                $GLOBALS[$key] = $value;
            } else {
                unset($GLOBALS[$key]);
            }
        }
    }

    private function confirm(): bool
    {
        return Installer::recordInstalledVersion();
    }

    private function versions(): array
    {
        return $this->database->query('SELECT cacti FROM version ORDER BY cacti')->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function initialVersions(): iterable
    {
        yield [null];
        yield ['1.2.33'];
        yield ['current'];
    }

    #[DataProvider('initialVersions')]
    public function testEmptyFreshUpgradeAndIdempotentMarkersAreConfirmed(?string $previous): void
    {
        if ($previous !== null) {
            $this->database->prepare('INSERT INTO version VALUES(?)')->execute([$previous === 'current' ? CACTI_VERSION : $previous]);
        }
        self::assertTrue($this->confirm());
        self::assertSame([CACTI_VERSION], $this->versions());
        self::assertFalse($this->database->inTransaction());
    }

    public static function writeCases(): iterable
    {
        yield ['INSERT', null];
        yield ['UPDATE', '1.2.33'];
    }

    #[DataProvider('writeCases')]
    public function testRealSilentWriteRefusalRetainsPriorMarker(string $operation, ?string $previous): void
    {
        if ($previous !== null) {
            $this->database->prepare('INSERT INTO version VALUES(?)')->execute([$previous]);
        }
        $this->database->exec("CREATE TRIGGER refuse_version BEFORE $operation ON version BEGIN SELECT RAISE(ABORT,'version write refused'); END");
        self::assertFalse($this->confirm());
        self::assertSame($previous === null ? [] : [$previous], $this->versions());
        self::assertFalse($this->database->inTransaction());
        $this->database->exec('DROP TRIGGER refuse_version');
        self::assertTrue($this->confirm());
        self::assertSame([CACTI_VERSION], $this->versions());
    }

    #[DataProvider('writeCases')]
    public function testRealReadbackMismatchRollsBackBeforePublishingMarker(string $operation, ?string $previous): void
    {
        if ($previous !== null) {
            $this->database->prepare('INSERT INTO version VALUES(?)')->execute([$previous]);
        }
        $this->database->exec("CREATE TRIGGER alter_version AFTER $operation ON version BEGIN UPDATE version SET cacti='wrong-marker'; END");
        self::assertFalse($this->confirm());
        self::assertSame($previous === null ? [] : [$previous], $this->versions());
        self::assertFalse($this->database->inTransaction());
    }

    public function testCallerTransactionAndEarlierWritesArePreserved(): void
    {
        $this->database->exec("INSERT INTO version VALUES('1.2.33')");
        $this->database->exec('CREATE TABLE caller_work (value TEXT)');
        $this->database->beginTransaction();
        $this->database->exec("INSERT INTO caller_work VALUES('preserve')");
        self::assertFalse($this->confirm());
        self::assertTrue($this->database->inTransaction());
        self::assertSame(['1.2.33'], $this->versions());
        self::assertSame('preserve', $this->database->query('SELECT value FROM caller_work')->fetchColumn());
    }

    public function testMalformedMultipleMarkersAreRetainedOnRefusal(): void
    {
        $this->database->exec("INSERT INTO version VALUES('1.2.32'),('1.2.33')");
        self::assertFalse($this->confirm());
        self::assertSame(['1.2.32', '1.2.33'], $this->versions());
        self::assertFalse($this->database->inTransaction());
    }
}
