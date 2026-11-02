<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\Platform\Application\Installer\OperationalEvidence;
use Kadupul\Platform\Application\Installer\VerifyInstallationOperation;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use Kadupul\Platform\Domain\Installer\OperationalVerificationRequest;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperationalVerificationClient;
use Kadupul\Platform\Infrastructure\Legacy\OperationalVerificationProcess;
use Kadupul\Platform\Infrastructure\Symfony\Console\InstallerVerifyCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallerOperationalVerificationTest extends TestCase
{
    public static function invalidIds(): iterable
    {
        foreach ([['0'], ['-1'], ['1.0'], ['1e2'], [' 1'], ['4294967296'], [[1]], array_fill(0, 21, '1')] as $ids) {
            yield [$ids];
        }
    }

    #[DataProvider('invalidIds')]
    public function testInvalidIdsAreRejectedBeforeAnyWorker(array $ids): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OperationalVerificationRequest::fromGraphIds($ids);
    }

    public function testExplicitConsentAndBothOperatorGatesPrecedeEvidenceReads(): void
    {
        foreach (['consent', 'identity', 'upgrade', 'graph'] as $gate) {
            $operator = $this->createMock(ConsoleOperator::class);
            $actor = new Actor(7, 'fixture-operator');
            $operator->method('actor')->willReturn($gate === 'identity' ? null : $actor);
            $operator->method('canUpgradeInstallation')->willReturn($gate !== 'upgrade');
            $operator->method('canManageGraphs')->willReturn($gate !== 'graph');
            $evidence = $this->createMock(OperationalEvidence::class);
            $evidence->expects($this->never())->method('inspect');
            try {
                (new VerifyInstallationOperation($operator, $evidence))->run(null, [1], $gate !== 'consent');
                $this->fail('Denied operational assessment was admitted.');
            } catch (\InvalidArgumentException|\RuntimeException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testAdmittedUseCaseDeduplicatesRequestedGraphs(): void
    {
        $operator = $this->createMock(ConsoleOperator::class);
        $operator->method('actor')->willReturn(new Actor(7, 'fixture-operator'));
        $operator->method('canUpgradeInstallation')->willReturn(true);
        $operator->method('canManageGraphs')->willReturn(true);
        $evidence = $this->createMock(OperationalEvidence::class);
        $evidence->expects($this->once())->method('inspect')->with(7, [1, 2])->willReturn(['read_only' => true]);
        $this->assertSame(['read_only' => true], (new VerifyInstallationOperation($operator, $evidence))->run(null, ['1', 1, 2], true));
    }

    public function testCommandDoesNotStartAWorkerWithoutConsentAndReturnsAttentionAsFailure(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects($this->once())->method('run')->willReturn($this->receipt('action_required'));
        $tester = new CommandTester(new InstallerVerifyCommand(new LegacyOperationalVerificationClient($worker)));
        $this->assertSame(2, $tester->execute(['--output' => 'json']));
        $this->assertSame(1, $tester->execute(['--acknowledge-read-only' => true, '--output' => 'json']));
        $report = json_decode($tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['operational_health_confirmed']);
        $this->assertSame('action_required', $report['scheduler']['status']);
        $this->assertStringNotContainsString('private-worker-value', $tester->getDisplay());
    }

    public function testClientRejectsAnUnrecognizedStatusWithoutPrintingPrivateWorkerFields(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willReturn($this->receipt('healthy'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed or unconfirmed');
        (new LegacyOperationalVerificationClient($worker))->request(null, []);
    }

    private function receipt(string $scheduler): array
    {
        return ['output' => '', 'errors' => '', 'ok' => true, 'result' => ['status' => 'ok', 'data' => [
            'version' => 1, 'read_only' => true, 'operational_health_confirmed' => false, 'sampled_at' => 1000, 'poller_id' => 1,
            'scheduler' => ['status' => $scheduler, 'reason' => $scheduler === 'verified' ? 'persisted_start_within_native_tolerance' : 'persisted_start_stale_future_or_invalid'],
            'collection' => ['status' => 'absent', 'reason' => 'no_persisted_completed_cycle'], 'graphs' => [], 'private' => 'private-worker-value',
        ]]];
    }

    public function testUnassessedCollectionCanCompleteWithoutClaimingOverallHealth(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willReturn($this->receipt('verified'));
        $tester = new CommandTester(new InstallerVerifyCommand(new LegacyOperationalVerificationClient($worker)));
        $this->assertSame(0, $tester->execute(['--acknowledge-read-only' => true, '--output' => 'json']));
        $this->assertFalse(json_decode($tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR)['operational_health_confirmed']);
    }

    public static function malformedGraphs(): iterable
    {
        yield 'rendering claim' => [['id' => 1, 'metadata' => 'observed', 'rendering' => 'verified', 'sources' => []]];
        yield 'empty metadata success' => [['id' => 1, 'metadata' => 'observed', 'rendering' => 'not_assessed', 'sources' => []]];
        foreach ([['status' => 'observed', 'reason' => 'local_rrd_timestamp_read', 'local_data_id' => 1],
            ['status' => 'failed', 'reason' => 'graph_source_reference_missing', 'local_data_id' => 'private-canary'],
            ['status' => 'verified', 'reason' => 'local_rrd_timestamp_read', 'local_data_id' => 1, 'last_update' => 1000]] as $source) {
            yield [['id' => 1, 'metadata' => 'observed', 'rendering' => 'not_assessed', 'sources' => [$source]]];
        }
    }

    #[DataProvider('malformedGraphs')]
    public function testClientRejectsMalformedGraphEvidence(array $graph): void
    {
        $receipt = $this->receipt('verified');
        $receipt['result']['data']['graphs'] = [$graph];
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willReturn($receipt);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed or unconfirmed');
        (new LegacyOperationalVerificationClient($worker))->request(null, [1]);
    }

    public static function privateWorkerFailures(): iterable
    {
        yield 'stdout overflow' => ['echo str_repeat("private-canary", 12000);', 2.0];
        yield 'stderr overflow' => ['fwrite(STDERR, str_repeat("private-canary", 1000));', 2.0];
        yield 'partial protocol' => ['echo "KADUPUL_OPERATIONAL_RESULT={";', 2.0];
        yield 'timeout' => ['usleep(5000000);', 0.1];
        yield 'private exception' => ['throw new RuntimeException("private-canary");', 2.0];
        yield 'nonzero false acknowledgement' => ['echo "KADUPUL_OPERATIONAL_RESULT={\"status\":\"ok\",\"data\":{}}\\n"; exit(1);', 2.0];
    }

    public function testBoundedWorkerAdmitsACompleteSuccessfulProtocol(): void
    {
        $directory = sys_get_temp_dir() . '/operational-transport-' . bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        mkdir($directory . '/bin', 0700);
        $file = $directory . '/bin/legacy-installer-verification.php';
        $frame = "KADUPUL_OPERATIONAL_RESULT={\"status\":\"ok\",\"data\":{\"read_only\":true}}\n";
        file_put_contents($file, '<?php fwrite(STDOUT, ' . var_export($frame, true) . ');');
        try {
            $result = (new OperationalVerificationProcess($directory))->run('legacy-installer-verification.php', 'KADUPUL_OPERATIONAL_RESULT', [], 2.0);
            $this->assertTrue($result['ok']);
            $this->assertSame(['read_only' => true], $result['result']['data']);
        } finally {
            unlink($file);
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }

    #[DataProvider('privateWorkerFailures')]
    public function testWorkerOutputAndTimeoutAreBoundedAndPrivate(string $body, float $timeout): void
    {
        $directory = sys_get_temp_dir() . '/operational-transport-' . bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        mkdir($directory . '/bin', 0700);
        $file = $directory . '/bin/legacy-installer-verification.php';
        file_put_contents($file, '<?php ' . $body);
        $started = microtime(true);
        try {
            $result = (new OperationalVerificationProcess($directory))->run('legacy-installer-verification.php', 'KADUPUL_OPERATIONAL_RESULT', [], $timeout);
            $this->assertFalse($result['ok']);
            $this->assertSame('', $result['output']);
            $this->assertSame('', $result['errors']);
            $this->assertSame([], $result['result']);
            $this->assertLessThan(3.0, microtime(true) - $started);
        } finally {
            unlink($file);
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }
}
