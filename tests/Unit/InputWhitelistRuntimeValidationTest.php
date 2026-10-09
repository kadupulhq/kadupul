<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

test('configured runtime whitelist requires a validated method', function ($case, $expected) {
    $root = dirname(__DIR__, 2);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $environment = getenv();
    $reportDirectory = null;
    if ($coverage !== null) {
        $reportDirectory = sys_get_temp_dir() . '/whitelist-runtime-coverage-' . bin2hex(random_bytes(8));
        mkdir($reportDirectory, 0700);
        $environment['KADUPUL_RUNTIME_WHITELIST_COVERAGE'] = $reportDirectory;
    }
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/input-whitelist-runtime.php', $root, $case),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            null,
            $environment
        );
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0);
        expect($stderr)->toBe('');
        expect(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
        if ($coverage !== null) {
            $reports = glob($reportDirectory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $sources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php',
                'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Unit/InputWhitelistRuntimeValidationTest.php', 'lib/utility.php');
            $markers = array('runtime-predicate-outcomes-readback');
            $hits = array('lib/utility.php');
            $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/input-whitelist-runtime.php', $case, $sources, $markers, $hits);
            static $rejectionsVerified = false;
            if (!$rejectionsVerified) {
                expect(NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/input-whitelist-runtime.php', $case, $sources, $markers, $hits, 'lib/utility.php'))->toBe(17);
                $rejectionsVerified = true;
            }
            $coverage->merge($child);
        }
    } finally {
        if ($reportDirectory !== null) {
            foreach (glob($reportDirectory . '/*') as $report) unlink($report);
            rmdir($reportDirectory);
        }
    }
})->with(array(
    'unset' => array('unset', array('first' => true, 'repeat' => true, 'empty-command' => true, 'unknown' => true)),
    'valid' => array('valid', array('first' => true, 'repeat' => true, 'empty-command' => true, 'unknown' => false)),
    'empty' => array('empty', array('first' => false, 'repeat' => false, 'empty-command' => true, 'unknown' => false)),
    'missing entry' => array('missing-entry', array('first' => false, 'repeat' => false, 'empty-command' => true, 'unknown' => false)),
    'changed command' => array('changed-command', array('first' => false, 'repeat' => false, 'empty-command' => true, 'unknown' => false)),
    'wrong type' => array('wrong-type', array('first' => false, 'repeat' => false, 'empty-command' => true, 'unknown' => false)),
    'malformed' => array('malformed', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'null' => array('null', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'boolean' => array('boolean', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'scalar' => array('scalar', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'missing file' => array('missing-file', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'invalid path' => array('invalid-path', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'directory' => array('directory', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false))
));
