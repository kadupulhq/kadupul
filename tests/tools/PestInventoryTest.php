<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Tests\Tools\PestInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/tools/verify_pest_inventory.php';

final class PestInventoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pest-inventory-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/Unit', 0700, true);
        file_put_contents($this->directory . '/Unit/ExampleTest.php', '<?php');
        file_put_contents($this->directory . '/config.xml', '<phpunit><testsuites><testsuite><file>./Unit/ExampleTest.php</file></testsuite></testsuites></phpunit>');
        file_put_contents($this->directory . '/discovery.xml', '<testSuite><tests><testClass name="P\Unit\ExampleTest"><testMethod id="case"/></testClass></tests></testSuite>');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $path) {
            if (is_dir($path)) {
                foreach (glob($path . '/*') as $file) {
                    unlink($file);
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    public function testAnAdmittedCaseCanBeSkippedInOneModeButMustExecuteInAnother(): void
    {
        file_put_contents($this->directory . '/ordinary.xml', '<testsuites><testcase file="Unit/ExampleTest.php::case"><skipped/></testcase></testsuites>');
        file_put_contents($this->directory . '/privileged.xml', '<testsuites><testcase file="Unit/ExampleTest.php::case"/></testsuites>');
        $counts = PestInventory::verify(PestInventory::files($this->directory . '/config.xml'), $this->directory . '/discovery.xml', [$this->directory . '/ordinary.xml', $this->directory . '/privileged.xml']);

        self::assertSame(['Unit/ExampleTest.php' => ['discovered' => 1, 'recorded' => 2, 'skipped' => 1]], $counts);
    }

    #[DataProvider('invalidReports')]
    public function testMissingIncompleteFailedAndUnexecutedReportsAreRejected(string $report): void
    {
        file_put_contents($this->directory . '/report.xml', $report);
        $this->expectException(RuntimeException::class);
        PestInventory::verify(['Unit/ExampleTest.php'], $this->directory . '/discovery.xml', [$this->directory . '/report.xml']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReports(): iterable
    {
        yield 'empty file' => [''];
        yield 'missing cases despite aggregate count' => ['<testsuites tests="99"/>'];
        yield 'malformed' => ['<testsuites>'];
        yield 'unexpected file' => ['<testsuites><testcase file="Unit/OtherTest.php::case"/></testsuites>'];
        yield 'duplicate case count' => ['<testsuites><testcase file="Unit/ExampleTest.php::case"/><testcase file="Unit/ExampleTest.php::case"/></testsuites>'];
        yield 'failed case' => ['<testsuites><testcase file="Unit/ExampleTest.php::case"><failure/></testcase></testsuites>'];
        yield 'errored case' => ['<testsuites><testcase file="Unit/ExampleTest.php::case"><error/></testcase></testsuites>'];
        yield 'only skipped' => ['<testsuites><testcase file="Unit/ExampleTest.php::case"><skipped/></testcase></testsuites>'];
        yield 'DTD' => ['<!DOCTYPE testsuites [<!ENTITY case "Unit/ExampleTest.php::case">]><testsuites><testcase file="&case;"/></testsuites>'];
    }

    public function testMissingReportsAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        PestInventory::verify(['Unit/ExampleTest.php'], $this->directory . '/discovery.xml', [$this->directory . '/missing.xml']);
    }

    public function testEmptyDiscoveryIsRejected(): void
    {
        file_put_contents($this->directory . '/discovery.xml', '<testSuite/>');
        $this->expectException(RuntimeException::class);
        PestInventory::verify(['Unit/ExampleTest.php'], $this->directory . '/discovery.xml', []);
    }

    #[DataProvider('selections')]
    public function testTheInstalledRunnerCannotHideAnOmittedSuiteBehindAPassingAggregate(bool $partial): void
    {
        $root = dirname(__DIR__, 2);
        $configuration = tempnam($root . '/tests', 'pest-inventory-');
        self::assertNotFalse($configuration);
        try {
            file_put_contents($configuration, '<phpunit bootstrap="bootstrap-unit.php"><testsuites><testsuite name="probe"><file>Unit/Security/Auth/CsrfTokenFormatTest.php</file><file>Unit/Security/Auth/CsrfSecretSourceTest.php</file></testsuite></testsuites></phpunit>');
            $report = $this->directory . '/native.xml';
            $command = [PHP_BINARY, 'vendor/bin/pest', '--test-directory=.', '--configuration=' . $configuration, '--fail-on-empty-test-suite', '--colors=never', '--log-junit=' . $report];
            if ($partial) {
                $command[] = 'Unit/Security/Auth/CsrfTokenFormatTest.php';
            }
            $run = new Process($command, $root . '/tests');
            $run->setTimeout(30);
            $run->run();
            self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
            $check = new Process([PHP_BINARY, 'tools/verify_pest_inventory.php', $configuration, $report], $root . '/tests');
            $check->setTimeout(60);
            $check->run();
            self::assertSame($partial ? 1 : 0, $check->getExitCode(), $check->getOutput() . $check->getErrorOutput());
            self::assertStringContainsString($partial ? 'recorded 0' : 'PASS: 2 registered files', $check->getOutput() . $check->getErrorOutput());
        } finally {
            unlink($configuration);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function selections(): iterable
    {
        yield 'passing partial selection' => [true];
        yield 'canonical registration' => [false];
    }
}
