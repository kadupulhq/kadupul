<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../Helpers/PhpSource.php';
require_once __DIR__ . '/../../../Helpers/PestCodeCoverageCompatibility.php';
require_once __DIR__ . '/../../../Helpers/SpikeControllerCoverageRegistration.php';

/** Original #512: validated-id SQL handoff and complete successful JSON. */
final class SpikeControllerNativeOutcomeTest extends TestCase
{
    use PestCodeCoverageCompatibility;
    private static bool $evidenceChecked = false;

    private function runController(array $scenario): array
    {
        $scenario += ['operation' => 'spike-controller', 'config' => ['graph_auth_method' => 1]];
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/spike-controller-evidence-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create owned spike evidence directory.');
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $json = json_encode($scenario, JSON_THROW_ON_ERROR);
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . $root,
            '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/auth-policy-native.php', $json, $directory];
        if ($coverage !== null) $command[] = 'coverage';
        try {
            ['out' => $output, 'err' => $error, 'status' => $status] = test_php_run($command);
            self::assertSame(0, $status, $error . $output);
            self::assertSame('', $error);
            if ($coverage !== null) {
                require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $child = NativeChildCoverageEvidence::load(
                    $reports[0],
                    $root,
                    'tests/Fixtures/auth-policy-native.php',
                    $json,
                    SpikeControllerCoverageRegistration::SOURCES,
                    SpikeControllerCoverageRegistration::MARKERS,
                    SpikeControllerCoverageRegistration::HITS
                );
                if (!self::$evidenceChecked) {
                    self::assertSame(37, NativeChildCoverageEvidence::verifyRejections(
                        $reports[0],
                        $root,
                        'tests/Fixtures/auth-policy-native.php',
                        $json,
                        SpikeControllerCoverageRegistration::SOURCES,
                        SpikeControllerCoverageRegistration::MARKERS,
                        SpikeControllerCoverageRegistration::HITS,
                        'lib/rrd.php'
                    ));
                    $this->assertStaleEvidenceRejected($reports[0], $root, $json);
                    self::$evidenceChecked = true;
                }
                $coverage->merge($child);
            }
            $state = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([['user_id' => 42, 'type' => 1, 'item_id' => 101], ['user_id' => 42, 'type' => 3, 'item_id' => 111], ['user_id' => 42, 'type' => 4, 'item_id' => 121]], $state['policy_rows']);
            return $state;
        } finally {
            foreach (glob($directory . '/*') as $file) unlink($file);
            rmdir($directory);
        }
    }

    private function assertStaleEvidenceRejected(string $report, string $root, string $scenario): void
    {
        $original = file_get_contents($report . '.json');
        self::assertIsString($original);
        $evidence = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
        try {
            foreach (['producer', 'scenario', 'report', 'source'] as $kind) {
                $changed = $evidence;
                if ($kind === 'source') {
                    $changed['sources']['lib/html_utility.php'] = str_repeat('0', 64);
                } else {
                    $changed[$kind] = str_repeat('0', 64);
                }
                if (file_put_contents($report . '.json', json_encode($changed, JSON_THROW_ON_ERROR)) === false) {
                    throw new RuntimeException('Cannot write owned stale evidence control.');
                }
                try {
                    NativeChildCoverageEvidence::load(
                        $report,
                        $root,
                        'tests/Fixtures/auth-policy-native.php',
                        $scenario,
                        SpikeControllerCoverageRegistration::SOURCES,
                        SpikeControllerCoverageRegistration::MARKERS,
                        SpikeControllerCoverageRegistration::HITS
                    );
                    self::fail('Stale spike ' . $kind . ' evidence was admitted.');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('stale', $error->getMessage(), $kind);
                }
            }
        } finally {
            if (file_put_contents($report . '.json', $original) !== strlen($original)) {
                throw new RuntimeException('Cannot restore owned native evidence.');
            }
        }
    }

    #[DataProvider('admittedCases')]
    public function testAdmittedControllerUsesValidatedIdentityAndCompletesJsonAfterProcessorHandoff(array $scenario, string $results, array $arguments, int $dryrun): void
    {
        $state = $this->runController($scenario);
        self::assertSame(200, $state['status']);
        self::assertSame(['local_graph_id' => 100, 'results' => $results], $state['response']);
        self::assertSame([[100]], $state['lookup_parameters']);
        self::assertSame([[300, true]], $state['path_calls']);
        self::assertSame([['arguments' => array_merge(['/owned/spike-source.rrd'], $arguments), 'dryrun' => $dryrun, 'html' => 1]], $state['processor_calls']);
    }

    public static function admittedCases(): iterable
    {
        yield 'default method preserves omitted processor options' => [[], 'native processor output', ['', '', '', '', ''], 0];
        yield 'validated scalar id and options reach one distinct source' => [['graph_id' => '100', 'method' => 'stddev', 'avgnan' => 'on', 'outlier-start' => '10', 'outlier-end' => '20'], 'native processor output', ['stddev', 'on', '', '10', '20'], 0];
        yield 'present false dryrun retains historical presence semantics' => [['dryrun' => false, 'method' => 'fill'], 'native processor output', ['fill', '', '', '', ''], 1];
        yield 'processor refusal preserves historical successful envelope' => [['processor_failure' => true, 'method' => 'variance'], 'native processor refusal', ['variance', '', '', '', ''], 0];
    }

    public function testAdmittedGraphWithoutPositiveDataSourcesReturnsIdentityAndEmptyResults(): void
    {
        $state = $this->runController(['graph_id' => '102']);
        self::assertSame(200, $state['status']);
        self::assertSame(['local_graph_id' => 102, 'results' => ''], $state['response']);
        self::assertSame([[102]], $state['lookup_parameters']);
        self::assertSame([], $state['path_calls']);
        self::assertSame([], $state['processor_calls']);
    }

    #[DataProvider('deniedCases')]
    public function testPersistedPolicyAndMissingGraphDenialsStopBeforeSourceReadsAndProcessorCalls(string $id, bool $dryrun): void
    {
        $state = $this->runController(['graph_id' => $id] + ($dryrun ? ['dryrun' => true] : []));
        self::assertSame(403, $state['status']);
        self::assertSame(['local_graph_id' => (int) $id, 'results' => 'Graph access denied'], $state['response']);
        self::assertSame([], $state['lookup_parameters']);
        self::assertSame([], $state['path_calls']);
        self::assertSame([], $state['processor_calls']);
    }

    public static function deniedCases(): iterable
    {
        yield 'persisted denied graph normal' => ['101', false];
        yield 'persisted denied graph dryrun' => ['101', true];
        yield 'nonexistent graph normal' => ['999', false];
        yield 'nonexistent graph dryrun' => ['999', true];
    }
}
