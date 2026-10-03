<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';
require_once dirname(__DIR__, 3) . '/Fixtures/csrf-rotation-sqlite.php';
require_once dirname(__DIR__, 4) . '/lib/csrf_rotation.php';

function csrf_rotation_wait(string $file, float $seconds = 5): void
{
    $deadline = microtime(true) + $seconds;
    while (!is_file($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Rotation fixture did not reach its handoff');
        }
        usleep(10000);
    }
}

/** @return array{string,string,array<string,PDO>} */
function csrf_rotation_fixture(): array
{
    $root = dirname(__DIR__, 4);
    $fixture = sys_get_temp_dir() . '/csrf-rotation-concurrent-' . bin2hex(random_bytes(8));
    foreach (array('cli', 'include', 'lib') as $part) {
        mkdir($fixture . '/' . $part, 0700, true);
    }
    copy($root . '/cli/refresh_csrf.php', $fixture . '/cli/refresh_csrf.php');
    copy($root . '/lib/poller.php', $fixture . '/lib/poller.php');
    file_put_contents($fixture . '/lib/utility.php', '<?php');
    file_put_contents($fixture . '/lib/csrf_rotation.php', '<?php require ' . var_export($root . '/lib/csrf_rotation.php', true) . ';');
    file_put_contents($fixture . '/include/cli_check.php', '<?php require ' . var_export($root . '/tests/Fixtures/csrf-rotation-process.php', true) . ';');
    $connections = array();
    $profile = getenv('CSRF_ROTATION_MYSQL');
    $profile = $profile !== false && $profile !== '' ? json_decode($profile, true, 512, JSON_THROW_ON_ERROR) : null;
    foreach (array('primary', 'collector2', 'collector3') as $name) {
        $connections[$name] = $profile === null
            ? new CsrfRotationSqlite($fixture . '/' . $name . '.sqlite', $fixture, $fixture . '/' . $name . '.lock')
            : new PDO($profile[$name], $profile['user'], $profile['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        if ($profile !== null) {
            $connections[$name]->exec('CREATE TABLE settings(name VARCHAR(50) PRIMARY KEY,value TEXT NOT NULL) ENGINE=InnoDB');
            $connections[$name]->exec('CREATE TABLE poller(id INT,last_status TIMESTAMP,disabled VARCHAR(3)) ENGINE=InnoDB');
        }
        $connections[$name]->exec("INSERT INTO settings VALUES('csrf_secret','old-key')");
    }
    $connections['primary']->exec("INSERT INTO poller VALUES(2,CURRENT_TIMESTAMP,''),(3,CURRENT_TIMESTAMP,'')");
    return array($root, $fixture, $connections);
}

test('overlapping actual CLI rotations preserve the final primary key on every collector', function () {
    [$root, $fixture, $connections] = csrf_rotation_fixture();
    $workers = array();
    $start = function (string $actor) use ($root, $fixture, &$workers): void {
        $registration = child_coverage_registration(
            __FILE__,
            'overlapping-csrf-rotation:' . $actor,
            array($actor, hash_file('sha256', $fixture . '/cli/refresh_csrf.php'), hash('sha256', (string) getenv('CSRF_ROTATION_MYSQL'))),
            array('rotation-state-readback'),
            array('cli/refresh_csrf.php', 'lib/csrf_rotation.php'),
            array('tests/Fixtures/csrf-rotation-process.php', 'tests/Fixtures/csrf-rotation-sqlite.php', 'include/global_constants.php', 'composer.lock', 'include/vendor/symfony/process/Process.php', 'include/vendor/symfony/process/ProcessUtils.php', 'include/vendor/symfony/process/Pipes/AbstractPipes.php', 'include/vendor/symfony/process/Pipes/UnixPipes.php', 'include/vendor/symfony/process/Pipes/WindowsPipes.php', 'include/vendor/symfony/process/Exception/ProcessTimedOutException.php')
        );
        $registration['collectorPrelude'] = 'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($fixture . '/cli/refresh_csrf.php', true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/cli/refresh_csrf.php', true) . ');';
        // The byte-identical CLI copy is outside the source root. PCOV must
        // measure it before the collector verifies and maps its filename.
        $command = child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-r',
            '$GLOBALS["cacti_csrf_rotation_worker"] = true; $_SERVER["argv"] = array($argv[1]); require $argv[1];',
            $fixture . '/cli/refresh_csrf.php'), $coverage, $registration);
        $environment = array('CSRF_ROTATION_SOURCE' => $root, 'CSRF_ROTATION_FIXTURE' => $fixture,
            'CSRF_ROTATION_ACTOR' => $actor, 'CSRF_ROTATION_PAUSE' => '1') + getenv();
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $fixture, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start rotation CLI');
        }
        $workers[$actor] = array($process, $pipes, $coverage);
    };
    try {
        $start('A');
        csrf_rotation_wait($fixture . '/A-paused');
        $first = $connections['primary']->query('SELECT value FROM settings')->fetchColumn();
        $start('B');
        csrf_rotation_wait($fixture . '/B-started');
        // B is a real competing process. It cannot write before A releases.
        usleep(200000);
        expect($connections['primary']->query('SELECT value FROM settings')->fetchColumn())->toBe($first);
        touch($fixture . '/resume-A');
        foreach ($workers as [$process, $pipes, $coverage]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0)->and($error)->toBe('')
                ->and($output)->toContain('New CSRF secret stored in the database.');
            child_coverage_collect($coverage);
        }
        $final = $connections['primary']->query('SELECT value FROM settings')->fetchColumn();
        expect($final)->not->toBe($first)->and(strlen($final))->toBe(64);
        foreach (array('collector2', 'collector3') as $collector) {
            expect($connections[$collector]->query('SELECT value FROM settings')->fetchColumn())->toBe($final);
        }
        $database = $connections['primary']->query('SELECT DATABASE()')->fetchColumn();
        $name = 'kadupul.csrf.' . substr(hash('sha256', $database), 0, 64 - strlen('kadupul.csrf.'));
        $lock = $connections['primary']->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute(array($name));
        expect((string) $lock->fetchColumn())->toBe('1');
        $lock = $connections['primary']->prepare('SELECT RELEASE_LOCK(?)');
        $lock->execute(array($name));
        expect((string) $lock->fetchColumn())->toBe('1');
    } finally {
        touch($fixture . '/resume-A');
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $connections = array();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($fixture);
    }
});

test('the actual supervisor reports success only after persisted collector readbacks', function () {
    [$root, $fixture, $connections] = csrf_rotation_fixture();
    try {
        $environment = array('CSRF_ROTATION_SOURCE' => $root, 'CSRF_ROTATION_FIXTURE' => $fixture,
            'CSRF_ROTATION_ACTOR' => 'normal', 'CSRF_ROTATION_PAUSE' => '0', 'CSRF_ROTATION_DELAY' => '0') + getenv();
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', $fixture . '/cli/refresh_csrf.php'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $fixture,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the actual rotation supervisor');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($error)->toBe('')
            ->and(substr_count($output, 'New CSRF secret stored in the database.'))->toBe(1);
        $secret = $connections['primary']->query('SELECT value FROM settings')->fetchColumn();
        expect(strlen($secret))->toBe(64);
        foreach (array('collector2', 'collector3') as $collector) {
            expect($connections[$collector]->query('SELECT value FROM settings')->fetchColumn())->toBe($secret);
        }
    } finally {
        if (isset($process) && is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        $connections = array();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($fixture);
    }
});

test('rotation fails closed at storage and lock boundaries without claiming propagation', function (string $failure) {
    $fixture = sys_get_temp_dir() . '/csrf-rotation-control-' . bin2hex(random_bytes(8));
    mkdir($fixture, 0700);
    $primary = new CsrfRotationSqlite(':memory:', $fixture, $fixture . '/primary.lock');
    $collector = new CsrfRotationSqlite(':memory:', $fixture . '-collector', $fixture . '/collector.lock');
    foreach (array($primary, $collector) as $database) {
        $database->exec("INSERT INTO settings VALUES('csrf_secret','old-key')");
    }
    $primary->exec("INSERT INTO poller VALUES(2,CURRENT_TIMESTAMP,'')");
    $connected = 0;
    $warnings = array();
    $inventoryReads = 0;
    if ($failure === 'identity') {
        $primary->sqliteCreateFunction('DATABASE', static fn() => '');
    } elseif ($failure === 'transaction') {
        $primary->beginTransaction();
    } elseif ($failure === 'preowned lock') {
        $name = 'kadupul.csrf.' . substr(hash('sha256', $fixture), 0, 64 - strlen('kadupul.csrf.'));
        $lock = $primary->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute(array($name));
    } elseif ($failure === 'lock timeout' || $failure === 'lock error') {
        $primary->sqliteCreateFunction('GET_LOCK', static fn(...$args) => $failure === 'lock timeout' ? 0 : null);
    } elseif ($failure === 'ownership lost') {
        $primary->sqliteCreateFunction('IS_USED_LOCK', static fn($name) => 0);
    } elseif ($failure === 'primary write') {
        $primary->exec("CREATE TRIGGER reject_primary BEFORE INSERT ON settings BEGIN SELECT RAISE(FAIL,'fixture rejection'); END");
    } elseif ($failure === 'primary readback') {
        $primary->exec("CREATE TRIGGER change_primary AFTER UPDATE ON settings BEGIN UPDATE settings SET value='different-primary'; END");
        $primary->sqliteCreateFunction('UNIX_TIMESTAMP', static function (...$args) use (&$inventoryReads) {
            $inventoryReads++;
            return $args ? strtotime($args[0]) : time();
        });
    } elseif ($failure === 'inventory') {
        $primary->exec('DROP TABLE poller');
    } elseif ($failure === 'heartbeat') {
        $primary->exec("UPDATE poller SET last_status='2000-01-01 00:00:00'");
    } elseif ($failure === 'collector write') {
        $collector->exec("CREATE TRIGGER reject_collector BEFORE INSERT ON settings BEGIN SELECT RAISE(FAIL,'fixture rejection'); END");
    } elseif ($failure === 'collector readback') {
        $collector->exec("CREATE TRIGGER change_collector AFTER UPDATE ON settings BEGIN UPDATE settings SET value='different-key'; END");
    } elseif ($failure === 'release') {
        $primary->sqliteCreateFunction('RELEASE_LOCK', static fn($name) => 0);
    }
    try {
        $result = cacti_rotate_database_csrf_secret(
            $primary,
            str_repeat('a', 64),
            600,
            static function ($id) use (&$connected, $failure, $collector) {
                $connected++;
                return $failure === 'connection' ? false : $collector;
            },
            static function ($id, $stale) use (&$warnings): void {
                $warnings[] = array($id, $stale);
            }
        );
        expect($result)->toBeFalse();
        $beforeWrite = in_array($failure, array('identity', 'transaction', 'preowned lock', 'lock timeout', 'lock error', 'ownership lost', 'primary write'), true);
        expect($primary->query('SELECT value FROM settings')->fetchColumn())->toBe($beforeWrite ? 'old-key' : ($failure === 'primary readback' ? 'different-primary' : str_repeat('a', 64)));
        $collectorValue = $failure === 'release' ? str_repeat('a', 64) : ($failure === 'collector readback' ? 'different-key' : 'old-key');
        expect($collector->query('SELECT value FROM settings')->fetchColumn())->toBe($collectorValue);
        if ($beforeWrite || $failure === 'primary readback' || $failure === 'inventory' || $failure === 'heartbeat') {
            expect($connected)->toBe(0);
        }
        if ($failure === 'primary readback') {
            expect($inventoryReads)->toBe(0)->and($warnings)->toBe(array());
        }
        if ($failure === 'transaction') {
            expect($primary->inTransaction())->toBeTrue();
        }
        if ($failure === 'preowned lock') {
            $lock = $primary->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            $lock->execute(array($name));
            expect((string) $lock->fetchColumn())->toBe('1');
            $lock = $primary->prepare('SELECT RELEASE_LOCK(?)');
            $lock->execute(array($name));
        }
        if ($failure === 'heartbeat') {
            expect($warnings)->toBe(array(array(2, true)));
        }
    } finally {
        if ($primary->inTransaction()) {
            $primary->rollBack();
        }
        unset($primary, $collector);
        gc_collect_cycles();
        foreach (glob($fixture . '/*') as $file) {
            unlink($file);
        }
        rmdir($fixture);
    }
})->with(array('identity', 'transaction', 'preowned lock', 'lock timeout', 'lock error', 'ownership lost', 'primary write', 'primary readback', 'inventory', 'heartbeat', 'connection', 'collector write', 'collector readback', 'release'));

test('supervisor timeout prevents a late remote SQL write from overtaking the next rotation', function () {
    [$root, $fixture, $connections] = csrf_rotation_fixture();
    $sqlite = $connections['collector3'] instanceof CsrfRotationSqlite;
    if ($sqlite) {
        $connections['collector3']->exec('CREATE TRIGGER delayed_rotation BEFORE UPDATE ON settings BEGIN SELECT rotation_delay(); END');
    } else {
        $connections['collector3']->exec('CREATE TRIGGER delayed_rotation BEFORE INSERT ON settings FOR EACH ROW BEGIN IF @csrf_rotation_review_delay > 0 THEN DO SLEEP(@csrf_rotation_review_delay); END IF; END');
    }
    $processes = array();
    $start = function (string $actor, bool $supervise) use ($root, $fixture, &$processes): void {
        $program = ($supervise ? '' : '$GLOBALS["cacti_csrf_rotation_worker"] = true;')
            . '$_SERVER["argv"] = array($argv[1]); require $argv[1];';
        $registration = child_coverage_registration(
            __FILE__,
            'supervised-csrf-timeout:' . $actor,
            array($actor, $supervise, hash('sha256', (string) getenv('CSRF_ROTATION_MYSQL'))),
            array('rotation-state-readback'),
            $supervise ? array('cli/refresh_csrf.php') : array('cli/refresh_csrf.php', 'lib/csrf_rotation.php'),
            array('tests/Fixtures/csrf-rotation-process.php', 'tests/Fixtures/csrf-rotation-sqlite.php', 'include/global_constants.php', 'composer.lock', 'include/vendor/symfony/process/Process.php', 'include/vendor/symfony/process/ProcessUtils.php', 'include/vendor/symfony/process/Pipes/AbstractPipes.php', 'include/vendor/symfony/process/Pipes/UnixPipes.php', 'include/vendor/symfony/process/Pipes/WindowsPipes.php', 'include/vendor/symfony/process/Exception/ProcessTimedOutException.php')
        );
        $registration['collectorPrelude'] = 'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($fixture . '/cli/refresh_csrf.php', true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/cli/refresh_csrf.php', true) . ');';
        $command = child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-r', $program, $fixture . '/cli/refresh_csrf.php'), $coverage, $registration);
        $environment = array('CSRF_ROTATION_SOURCE' => $root, 'CSRF_ROTATION_FIXTURE' => $fixture,
            'CSRF_ROTATION_ACTOR' => $actor, 'CSRF_ROTATION_DELAY' => '1', 'CSRF_ROTATION_PAUSE' => '0') + getenv();
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $fixture, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start supervised rotation');
        }
        $processes[$actor] = array($process, $pipes, $coverage);
    };
    try {
        $started = microtime(true);
        $start('A', true);
        if ($sqlite) {
            csrf_rotation_wait($fixture . '/remote-statement-running');
        } else {
            csrf_rotation_wait($fixture . '/remote-connection-id');
            $id = (int) file_get_contents($fixture . '/remote-connection-id');
            $state = $connections['collector3']->prepare('SELECT STATE FROM INFORMATION_SCHEMA.PROCESSLIST WHERE ID = ?');
            $deadline = microtime(true) + 5;
            do {
                $state->execute(array($id));
                $sleeping = stripos((string) $state->fetchColumn(), 'sleep') !== false;
                if (!$sleeping) {
                    usleep(10000);
                }
            } while (!$sleeping && microtime(true) < $deadline);
            expect($sleeping)->toBeTrue();
        }
        [$process, $pipes, $coverage] = $processes['A'];
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(1)->and($error)->toBe('')
            ->and($output)->toContain('worker failed or exceeded its deadline')
            ->and($output)->not->toContain('New CSRF secret stored in the database.');
        expect(microtime(true) - $started)->toBeGreaterThan(29)->toBeLessThan(33);
        child_coverage_collect($coverage);
        $first = $connections['primary']->query('SELECT value FROM settings')->fetchColumn();
        expect(strlen($first))->toBe(64);
        $start('B', false);
        [$process, $pipes, $coverage] = $processes['B'];
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($error)->toBe('')
            ->and($output)->toContain('New CSRF secret stored in the database.');
        child_coverage_collect($coverage);
        $final = $connections['primary']->query('SELECT value FROM settings')->fetchColumn();
        expect($final)->not->toBe($first);
        foreach (array('collector2', 'collector3') as $collector) {
            expect($connections[$collector]->query('SELECT value FROM settings')->fetchColumn())->toBe($final);
        }
    } finally {
        foreach ($processes as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $connections = array();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($fixture);
    }
});
