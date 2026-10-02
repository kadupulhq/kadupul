<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class InputWhitelistNativeTest extends TestCase
{
    private array $running = [];
    private string $directory;
    private string $root;
    private string $first = '11111111111111111111111111111111';
    private string $second = '22222222222222222222222222222222';
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->directory = sys_get_temp_dir() . '/whitelist-native-' . bin2hex(random_bytes(8));
        foreach (['', '/cli', '/include', '/lib'] as $folder) {
            mkdir($this->directory . $folder, 0700);
        }
        copy($this->root . '/cli/input_whitelist.php', $this->directory . '/cli/input_whitelist.php');
        copy($this->root . '/tests/Fixtures/input-whitelist-native.php', $this->directory . '/include/cli_check.php');
        foreach (['utility.php', 'poller.php', 'template.php'] as $file) {
            file_put_contents($this->directory . '/lib/' . $file, '<?php');
        }
        file_put_contents($this->directory . '/lib/input_whitelist.php', '<?php require ' . var_export($this->root . '/lib/input_whitelist.php', true) . ';');
        file_put_contents($this->directory . '/scenario.json', '{}');
        file_put_contents($this->directory . '/whitelist.json', json_encode([$this->first => 'old first', $this->second => 'old second'], JSON_THROW_ON_ERROR));
        $db = new PDO('sqlite:' . $this->directory . '/database.sqlite');
        $db->exec('CREATE TABLE data_input(id INTEGER PRIMARY KEY, name VARCHAR(200), hash VARCHAR(32), input_string VARCHAR(512))');
        $insert = $db->prepare('INSERT INTO data_input VALUES (?, ?, ?, ?)');
        $insert->execute([1, 'First', $this->first, 'new first']);
        $insert->execute([2, 'Second', $this->second, 'new second']);
    }
    protected function tearDown(): void
    {
        foreach ($this->running as $running) {
            if (is_resource($running[0])) {
                proc_terminate($running[0]);
                $this->finish($running);
            }
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }
    private function start(array $options, array $scenario = []): array
    {
        file_put_contents($this->directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, '-d', 'auto_prepend_file='];
        if ($scenario['disableRename'] ?? false) {
            array_push($command, '-d', 'disable_functions=rename');
        }
        array_push($command, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~');
        array_push($command, $this->directory . '/cli/input_whitelist.php', ...$options);
        // RLIMIT_FSIZE deliberately prevents report files too; that real OS fault
        // has no coverage claim. Other completed leaves require full evidence.
        $coverage = ($scenario['limit'] ?? false) ? null : $this->getTestResultObject()->getCodeCoverage();
        $environment = getenv();
        $environment['KADUPUL_NATIVE_WHITELIST_ROOT'] = $this->root;
        if ($coverage !== null) {
            $reportDirectory = $this->directory . '/coverage-' . bin2hex(random_bytes(8));
            mkdir($reportDirectory, 0700);
            if (isset($scenario['runAs'])) {
                chown($reportDirectory, $scenario['runAs']);
                chgrp($reportDirectory, $scenario['runAs']);
            }
            $environment['KADUPUL_NATIVE_WHITELIST_COVERAGE'] = $reportDirectory;
            $environment['KADUPUL_NATIVE_WHITELIST_ROOT'] = $this->root;
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        $this->running[] = [$process, $pipes, $coverage === null ? null : $reportDirectory, json_encode([$options, $scenario], JSON_THROW_ON_ERROR)];
        return [$process, $pipes, $coverage === null ? null : $reportDirectory, json_encode([$options, $scenario], JSON_THROW_ON_ERROR)];
    }
    private function finish(array $running): array
    {
        [$process, $pipes] = $running;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        // Deliberately terminated leaves have no completed report and make no
        // coverage claim. Normal success/failure leaves must prove real execution.
        if (($running[2] ?? null) !== null && in_array($status, [0, 1], true)) {
            $reports = glob($running[2] . '/*.coverage');
            self::assertCount(1, $reports, $out . $err);
            $sources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'tests/Unit/InputWhitelistNativeTest.php', 'lib/input_whitelist.php', 'cli/input_whitelist.php');
            $markers = ['actual-cli-terminated'];
            $hits = ['cli/input_whitelist.php', 'lib/input_whitelist.php'];
            $arguments = json_decode($running[3], true, flags: JSON_THROW_ON_ERROR)[0];
            if (in_array('--audit', $arguments, true)) {
                array_push($sources, 'lib/template.php', 'lib/graph_template_input.php');
                $hits = ['cli/input_whitelist.php', 'lib/template.php'];
            }
            if (in_array('--id=0', $arguments, true)) {
                $hits = ['cli/input_whitelist.php'];
            }
            $child = NativeChildCoverageEvidence::load($reports[0], $this->root, 'tests/Fixtures/input-whitelist-native.php', $running[3], $sources, $markers, $hits);
            static $omissionsVerified = false;
            if (!$omissionsVerified && $status === 0) {
                self::assertSame(27, NativeChildCoverageEvidence::verifyRejections($reports[0], $this->root, 'tests/Fixtures/input-whitelist-native.php', $running[3], $sources, $markers, $hits, 'lib/boost.php'));
                $omissionsVerified = true;
            }
            $this->getTestResultObject()->getCodeCoverage()->merge($child);
        }
        return [$status, $out, $err];
    }
    private function state(): array
    {
        return json_decode(file_get_contents($this->directory . '/whitelist.json'), true, flags: JSON_THROW_ON_ERROR);
    }
    public function testConcurrentActualCliUpdatesRetainBothSuccessfulCommands(): void
    {
        $first = $this->start(['--update', '--id=1'], ['hold' => true]);
        $deadline = hrtime(true) / 1e9 + 5;
        while (!file_exists($this->directory . '/held')) {
            self::assertLessThan($deadline, hrtime(true) / 1e9);
            usleep(10000);
        }
        $second = $this->start(['--update', '--id=2']);
        $deadline = hrtime(true) / 1e9 + 1;
        while (proc_get_status($second[0])['running'] && hrtime(true) / 1e9 < $deadline) {
            usleep(10000);
        }
        touch($this->directory . '/release');
        self::assertSame(0, $this->finish($first)[0]);
        self::assertSame(0, $this->finish($second)[0]);
        self::assertSame([$this->first => 'new first', $this->second => 'new second'], $this->state());
    }
    public function testFullUpdatePublishesActualCommands(): void
    {
        [$status, $output, $errors] = $this->finish($this->start(['--update']));
        self::assertSame(0, $status, $errors);
        self::assertSame('', $errors);
        self::assertStringContainsString('SUCCESS:', $output);
        self::assertSame([$this->first => 'new first', $this->second => 'new second'], $this->state());
    }
    public function testScopedUpdatePreservesOtherEntriesAndPushesAfterUnlock(): void
    {
        [$status, $output, $errors] = $this->finish($this->start(['--update', '--id=1', '--push']));
        self::assertSame(0, $status, $errors);
        self::assertSame('', $errors);
        self::assertSame([$this->first => 'new first', $this->second => 'old second'], $this->state());
        self::assertSame("1\n", file_get_contents($this->directory . '/pushes'));
    }
    public function testActualShortWritePreservesPriorFileAndReportsFailure(): void
    {
        $db = new PDO('sqlite:' . $this->directory . '/database.sqlite');
        $db->prepare('UPDATE data_input SET input_string=? WHERE id=1')->execute([str_repeat('x', 512)]);
        $before = file_get_contents($this->directory . '/whitelist.json');
        [$status, $output] = $this->finish($this->start(['--update'], ['limit' => true]));
        self::assertSame(1, $status);
        self::assertStringNotContainsString('SUCCESS:', $output);
        self::assertSame($before, file_get_contents($this->directory . '/whitelist.json'));
    }

    /** @dataProvider rejectedPaths */
    public function testInvalidExistingFileStatesPreserveBytesAndRejectSuccess(string $case): void
    {
        $path = $this->directory . '/whitelist.json';
        $before = file_get_contents($path);
        if ($case === 'malformed') {
            $before = '{broken';
            file_put_contents($path, $before);
        } elseif ($case === 'readonly') {
            chmod($path, 0444);
        } elseif ($case === 'hardlink') {
            link($path, $this->directory . '/other.json');
        } elseif ($case === 'lock-symlink') {
            symlink($path, $path . '.lock');
        } elseif ($case === 'dangling-symlink') {
            unlink($path);
            symlink($this->directory . '/missing.json', $path);
        }
        [$status, $output] = $this->finish($this->start(['--update']));
        self::assertSame(1, $status);
        self::assertStringNotContainsString('SUCCESS:', $output);
        if ($case === 'dangling-symlink') {
            self::assertTrue(is_link($path));
            self::assertFileDoesNotExist($this->directory . '/missing.json');
        } else {
            self::assertSame($before, file_get_contents($path));
        }
        self::assertSame([], glob($this->directory . '/.whitelist-*'));
    }
    public static function rejectedPaths(): array
    {
        return array_map(static fn($case) => [$case], ['malformed', 'readonly', 'hardlink', 'lock-symlink', 'dangling-symlink']);
    }
    public function testExistingSymlinkKeepsItsDestinationAndMetadata(): void
    {
        $target = $this->directory . '/real.json';
        rename($this->directory . '/whitelist.json', $target);
        chmod($target, 0640);
        symlink($target, $this->directory . '/whitelist.json');
        [$status, $output, $errors] = $this->finish($this->start(['--update']));
        self::assertSame(0, $status, $errors);
        self::assertSame('', $errors);
        self::assertTrue(is_link($this->directory . '/whitelist.json'));
        self::assertSame(0640, fileperms($target) & 0777);
        self::assertSame([$this->first => 'new first', $this->second => 'new second'], $this->state());
    }
    public function testAbsentFileBackfillsAllRecordsAndScopedUpdatePrunesUnknownHashes(): void
    {
        unlink($this->directory . '/whitelist.json');
        self::assertSame(0, $this->finish($this->start(['--update', '--id=1']))[0]);
        self::assertSame([$this->first => 'new first', $this->second => 'new second'], $this->state());
        file_put_contents($this->directory . '/whitelist.json', json_encode([$this->first => 'old first', $this->second => 'kept second', 'unknown' => 'removed']));
        self::assertSame(0, $this->finish($this->start(['--update', '--id=1']))[0]);
        self::assertSame([$this->first => 'new first', $this->second => 'kept second'], $this->state());
    }
    public function testCommandBytesAndChangedScientificLookingValuesRemainExact(): void
    {
        $db = new PDO('sqlite:' . $this->directory . '/database.sqlite');
        $command = "  <path_php_binary> script.php 'α & \"quoted\"'  ";
        $db->prepare('UPDATE data_input SET input_string=? WHERE id=1')->execute([$command]);
        $db->exec("UPDATE data_input SET input_string='0e2' WHERE id=2");
        file_put_contents($this->directory . '/whitelist.json', json_encode([$this->first => 'old first', $this->second => '0e1']));
        self::assertSame(0, $this->finish($this->start(['--update', '--push']))[0]);
        self::assertSame([$this->first => $command, $this->second => '0e2'], $this->state());
        self::assertSame("1\n2\n", file_get_contents($this->directory . '/pushes'));
    }
    public function testKilledOwningCliReleasesItsLockWithoutPublishingPartialFile(): void
    {
        $first = $this->start(['--update', '--id=1'], ['hold' => true]);
        $deadline = hrtime(true) / 1e9 + 5;
        while (!file_exists($this->directory . '/held')) {
            self::assertLessThan($deadline, hrtime(true) / 1e9);
            usleep(10000);
        }
        proc_terminate($first[0]);
        $this->finish($first);
        self::assertSame(0, $this->finish($this->start(['--update', '--id=2']))[0]);
        self::assertSame([$this->first => 'old first', $this->second => 'new second'], $this->state());
        self::assertSame([], glob($this->directory . '/.whitelist-*'));
    }
    public function testActualWorkerDeadlineKillsWaitingLeafWithoutChangingWhitelist(): void
    {
        require_once $this->root . '/src/Platform/Infrastructure/Legacy/LegacyComponentAutoloader.php';
        \Kadupul\Platform\Infrastructure\Legacy\LegacyComponentAutoloader::register($this->root);
        require_once $this->root . '/src/DataInput/Infrastructure/Legacy/DataInputHandoff.php';
        $first = $this->start(['--update', '--id=1'], ['hold' => true]);
        $deadline = hrtime(true) / 1e9 + 5;
        while (!file_exists($this->directory . '/held')) {
            self::assertLessThan($deadline, hrtime(true) / 1e9);
            usleep(10000);
        }
        file_put_contents($this->directory . '/scenario.json', '{}');
        $before = file_get_contents($this->directory . '/whitelist.json');
        $start = hrtime(true) / 1e9;
        $handoff = new \Kadupul\DataInput\Infrastructure\Legacy\DataInputHandoff(PHP_BINARY, $this->directory, 1, $start + 1, 0.25);
        self::assertFalse($handoff->whitelist(2));
        self::assertLessThan(0.8, hrtime(true) / 1e9 - $start);
        self::assertSame($before, file_get_contents($this->directory . '/whitelist.json'));
        touch($this->directory . '/release');
        self::assertSame(0, $this->finish($first)[0]);
        self::assertSame(0, $this->finish($this->start(['--update', '--id=2']))[0]);
        self::assertSame([$this->first => 'new first', $this->second => 'new second'], $this->state());
    }

    public function testDisabledNativeRenamePreservesExistingFileAndCleansOwnedTemporary(): void
    {
        $before = file_get_contents($this->directory . '/whitelist.json');
        [$status, $output] = $this->finish($this->start(['--update'], ['disableRename' => true]));
        self::assertSame(1, $status);
        self::assertStringNotContainsString('SUCCESS:', $output);
        self::assertSame($before, file_get_contents($this->directory . '/whitelist.json'));
        self::assertSame([], glob($this->directory . '/.whitelist-*'));
    }
    public function testNumericLookingHashIdentityUpdatesOnlyTheRequestedId(): void
    {
        $first = '0e' . str_repeat('1', 30);
        $second = '0e' . str_repeat('2', 30);
        $db = new PDO('sqlite:' . $this->directory . '/database.sqlite');
        $db->prepare('UPDATE data_input SET hash=? WHERE id=1')->execute([$first]);
        $db->prepare('UPDATE data_input SET hash=? WHERE id=2')->execute([$second]);
        file_put_contents($this->directory . '/whitelist.json', json_encode([$first => 'old first', $second => 'kept second']));
        self::assertSame(0, $this->finish($this->start(['--update', '--id=1']))[0]);
        self::assertSame([$first => 'new first', $second => 'kept second'], $this->state());
    }
    public function testSymlinkRetargetDuringLocalSnapshotDoesNotPublishToTheOldTarget(): void
    {
        $link = $this->directory . '/configured.json';
        symlink($this->directory . '/whitelist.json', $link);
        $before = file_get_contents($this->directory . '/whitelist.json');
        $running = $this->start(['--update'], ['path' => $link, 'hold' => true]);
        $deadline = hrtime(true) / 1e9 + 5;
        while (!file_exists($this->directory . '/held')) {
            self::assertLessThan($deadline, hrtime(true) / 1e9);
            usleep(10000);
        }
        file_put_contents($this->directory . '/other.json', $before);
        unlink($link);
        symlink($this->directory . '/other.json', $link);
        touch($this->directory . '/release');
        [$status, $output] = $this->finish($running);
        self::assertSame(1, $status);
        self::assertStringNotContainsString('SUCCESS:', $output);
        self::assertSame($before, file_get_contents($this->directory . '/whitelist.json'));
        self::assertSame($before, file_get_contents($this->directory . '/other.json'));
    }
    public function testRootCreatedLockPreservesExistingWebWorkerOwnership(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
            self::markTestSkipped('Native root-to-worker ownership requires the Linux container.');
        }
        foreach (['', '/include', '/lib', '/cli'] as $folder) {
            chmod($this->directory . $folder, 0755);
        }
        chown($this->directory, 33);
        chgrp($this->directory, 33);
        $path = $this->directory . '/whitelist.json';
        chown($path, 33);
        chgrp($path, 33);
        chmod($path, 0600);
        self::assertSame(0, $this->finish($this->start(['--update', '--id=1']))[0]);
        clearstatcache(true, $path . '.lock');
        self::assertSame(33, fileowner($path . '.lock'));
        self::assertSame(33, filegroup($path . '.lock'));
        [$status, $output, $errors] = $this->finish($this->start(['--update', '--id=2'], ['runAs' => 33]));
        self::assertSame(0, $status, $errors . $output);
        self::assertSame('', $errors);
        self::assertSame([$this->first => 'new first', $this->second => 'new second'], $this->state());
    }

    public function testWaitingCliQueriesTheLatestDatabaseSnapshotAfterLockOwnership(): void
    {
        $lock = fopen($this->directory . '/whitelist.json.lock', 'c+b');
        self::assertTrue(flock($lock, LOCK_EX));
        $running = $this->start(['--update', '--id=1']);
        usleep(150000);
        self::assertTrue(proc_get_status($running[0])['running']);
        self::assertFileDoesNotExist($this->directory . '/queried');
        $db = new PDO('sqlite:' . $this->directory . '/database.sqlite');
        $db->exec("UPDATE data_input SET input_string='latest first' WHERE id=1");
        flock($lock, LOCK_UN);
        fclose($lock);
        [$status, $output, $errors] = $this->finish($running);
        self::assertSame(0, $status, $errors . $output);
        self::assertSame([$this->first => 'latest first', $this->second => 'old second'], $this->state());
    }
    /** @dataProvider failedDatabaseSnapshots */
    public function testUnavailableAndEmptyDatabaseSnapshotsPreserveExistingWhitelist(string $case): void
    {
        $db = new PDO('sqlite:' . $this->directory . '/database.sqlite');
        if ($case === 'query-failed') {
            $db->exec('DROP TABLE data_input');
        } elseif ($case === 'no-rows') {
            $db->exec('DELETE FROM data_input');
        } else {
            $db->exec("UPDATE data_input SET input_string=''");
        }
        $before = file_get_contents($this->directory . '/whitelist.json');
        [$status, $output] = $this->finish($this->start($case === 'scoped-empty' ? ['--update', '--id=1'] : ['--update']));
        self::assertSame(1, $status);
        self::assertStringNotContainsString('SUCCESS:', $output);
        if ($case !== 'query-failed') {
            self::assertSame("ERROR: No Data Input records found.\n", $output);
        }
        self::assertSame($before, file_get_contents($this->directory . '/whitelist.json'));
        self::assertSame([], glob($this->directory . '/.whitelist-*'));
    }
    public static function failedDatabaseSnapshots(): array
    {
        return [['query-failed'], ['no-rows'], ['scoped-empty']];
    }

    public function testActualAuditPreservesTheLegacyReadOnlyOutcomeAndExitContract(): void
    {
        $before = file_get_contents($this->directory . '/whitelist.json');
        [$status, $output, $errors] = $this->finish($this->start(['--audit']));
        self::assertSame(0, $status, $errors);
        self::assertSame('', $errors);
        self::assertStringContainsString('ERROR: 2 audits failed', $output);
        self::assertSame($before, file_get_contents($this->directory . '/whitelist.json'));
        file_put_contents($this->directory . '/whitelist.json', json_encode([$this->first => 'new first', $this->second => 'new second']));
        [$status, $output, $errors] = $this->finish($this->start(['--audit', '--id=1']));
        self::assertSame(0, $status, $errors);
        self::assertStringContainsString('SUCCESS: Audits successful for total of 2', $output);
    }
    public function testInvalidAndMissingIdsReturnTheOriginalFailureOutcome(): void
    {
        $before = file_get_contents($this->directory . '/whitelist.json');
        foreach (['--id=0', '--id=999'] as $option) {
            [$status, $output] = $this->finish($this->start(['--update', $option]));
            self::assertSame(1, $status);
            if ($option === '--id=999') {
                self::assertSame("ERROR: Data Input id '999' was not found or has an empty hash.\n", $output);
            }
            self::assertStringNotContainsString('SUCCESS:', $output);
            self::assertSame($before, file_get_contents($this->directory . '/whitelist.json'));
        }
    }
}
