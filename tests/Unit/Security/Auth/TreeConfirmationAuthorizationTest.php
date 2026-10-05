<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../../../Helpers/PhpSource.php';
require_once __DIR__ . '/../../../Helpers/NativeChildCoverageEvidence.php';
require_once __DIR__ . '/../../../Helpers/TreeConfirmationCoverageRegistration.php';

function runTreeConfirmationScenario(array $scenario, $coverage = null): array
{
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/tree-confirm-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create tree fixture directory.');
    try {
        $encoded = json_encode($scenario, JSON_THROW_ON_ERROR);
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/tree-confirmation-native.php', $encoded, $directory);
        if ($coverage !== null) $command[] = 'coverage';
        $result = ($scenario['http'] ?? false)
            ? runTreeConfirmationHttpRequest($root, $encoded, $directory, $coverage !== null)
            : test_php_run($command);
        \PHPUnit\Framework\Assert::assertSame(0, $result['status'], $result['err'] . $result['out']);
        expect($result['err'])->toBe('');
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $markers = TreeConfirmationCoverageRegistration::MARKERS;
            if ($scenario['http'] ?? false) $markers[] = 'tree-controller-response-headers-readback';
            $arguments = array($reports[0], $root, 'tests/Fixtures/tree-confirmation-native.php', $encoded, TreeConfirmationCoverageRegistration::SOURCES, $markers, TreeConfirmationCoverageRegistration::HITS);
            $child = NativeChildCoverageEvidence::load(...$arguments);
            static $checked = [];
            $mode = ($scenario['http'] ?? false) ? 'http' : 'cli';
            if (!isset($checked[$mode])) {
                expect(NativeChildCoverageEvidence::verifyRejections(...array_merge($arguments, array('lib/rrd.php'))))->toBe(count(TreeConfirmationCoverageRegistration::SOURCES) + 12 + (($scenario['http'] ?? false) ? 1 : 0));
                $checked[$mode] = true;
            }
            $coverage->merge($child);
        }
        $state = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
        if ($scenario['http'] ?? false) $state['transport_headers'] = $result['headers'];
        return $state;
    } finally {
        foreach (glob($directory . '/*.coverage*') as $file) unlink($file);
        if (is_link($directory . '/lib')) unlink($directory . '/lib');
        if (is_file($directory . '/include/auth.php')) unlink($directory . '/include/auth.php');
        if (is_dir($directory . '/include')) rmdir($directory . '/include');
        if (is_dir($directory . '/http')) {
            foreach (glob($directory . '/http/*') as $file) unlink($file);
            rmdir($directory . '/http');
        }
        rmdir($directory);
    }
}

test('tree confirmation renders persisted ownership and submits exactly the admitted ids', function (int $action, bool $admin) {
    $state = runTreeConfirmationScenario(array('action' => $action, 'ids' => [7,8], 'admin' => $admin), $this->getTestResultObject()->getCodeCoverage());
    expect($state['lookups'])->toBe($admin ? [7,8] : [7])->and($state['writes'])->toBe([])->and($state['messages'])->toBe([]);
    $document = new DOMDocument();
    $document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $fields = $xpath->query('//form[@method="post" and @action="tree.php"]//input[@name="selected_items"]');
    expect($fields->length)->toBe(1);
    $ids = unserialize($fields->item(0)->getAttribute('value'), array('allowed_classes' => false));
    expect($ids)->toBe($admin ? ['7','8'] : ['7']);
    expect($xpath->query('//input[@type="submit"]')->length)->toBe(1);
    if (!$admin) expect($state['html'])->not->toContain('Foreign secret tree');
    $submitted = runTreeConfirmationScenario(array('action' => $action, 'ids' => $ids, 'admin' => $admin, 'submit' => true), $this->getTestResultObject()->getCodeCoverage());
    $trees = array_column($submitted['trees'], null, 'id');
    if ($action === 1) {
        expect(array_keys($trees))->toBe($admin ? [] : [8])->and($submitted['items'])->toBe($admin ? [] : [80]);
    } else {
        foreach ($admin ? [7,8] : [7] as $id) {
            expect($trees[$id][$action === 4 ? 'locked' : 'enabled'])->toBe($action === 4 ? 0 : ($action === 2 ? 'on' : 'off'));
            expect($trees[$id]['modified_by'])->toBe(42);
        }
        if (!$admin) expect($trees[8]['modified_by'])->toBeNull()->and($trees[8]['locked'])->toBe(1);
    }
})->with(['delete' => 1, 'publish' => 2, 'unpublish' => 3, 'unlock' => 4])->with(['owner' => false, 'administrator' => true]);

test('all denied tree confirmations disclose no names and offer no hidden selection', function (int $action) {
    $state = runTreeConfirmationScenario(array('action' => $action, 'ids' => [8,999]), $this->getTestResultObject()->getCodeCoverage());
    expect($state['lookups'])->toBe([])->and($state['writes'])->toBe([])->and($state['messages'])->toBe([40]);
    expect($state['html'])->not->toContain('Foreign secret tree')->not->toContain('selected_items')->not->toContain("type='submit'");
    expect(array_column($state['trees'], 'id'))->toBe([7,8])->and($state['items'])->toBe([70,80]);
})->with(['delete' => 1, 'publish' => 2, 'unpublish' => 3, 'unlock' => 4]);

test('forged tree submissions recheck persisted ownership before every bulk action', function (int $action) {
    $state = runTreeConfirmationScenario(array('action' => $action, 'ids' => [8,999], 'submit' => true), $this->getTestResultObject()->getCodeCoverage());
    expect($state['lookups'])->toBe([])->and($state['writes'])->toBe([])->and($state['settings'])->toBe([]);
    expect(array_column($state['trees'], 'id'))->toBe([7,8])->and($state['items'])->toBe([70,80]);
})->with(['delete' => 1, 'publish' => 2, 'unpublish' => 3, 'unlock' => 4]);


test('trees without an owner remain denied during actual bulk confirmation', function (int $action) {
    $state = runTreeConfirmationScenario(array('action' => $action, 'ids' => [9], 'ownerless' => true), $this->getTestResultObject()->getCodeCoverage());
    expect($state['lookups'])->toBe([])->and($state['writes'])->toBe([])->and($state['messages'])->toBe([40]);
    expect($state['html'])->not->toContain('Unowned secret tree')->not->toContain('selected_items');
    expect(array_column($state['trees'], 'id'))->toBe([7,8,9]);
    expect(array_column($state['trees'], 'user_id'))->toBe([42,43,0]);
    expect($state['items'])->toBe([70,80]);
})->with(['delete' => 1, 'publish' => 2, 'unpublish' => 3, 'unlock' => 4]);


test('all denied tree confirmations return the actual listing redirect before protected lookups', function (int $action) {
    $state = runTreeConfirmationScenario(['action' => $action, 'ids' => [8,999], 'http' => true], $this->getTestResultObject()->getCodeCoverage());
    expect($state['transport_headers'][0])->toContain(' 302 ');
    $locations = array_values(array_filter($state['transport_headers'], static fn(string $header): bool => str_starts_with($header, 'Location:')));
    expect($locations)->toBe(['Location: tree.php?header=false']);
    expect($state['response_headers'])->toContain('Location: tree.php?header=false');
    expect($state['lookups'])->toBe([])->and($state['writes'])->toBe([])->and($state['messages'])->toBe([40]);
    expect($state['html'])->not->toContain('Foreign secret tree')->not->toContain('selected_items');
    expect(array_column($state['trees'], 'id'))->toBe([7,8])->and($state['items'])->toBe([70,80]);
})->with(['delete' => 1, 'publish' => 2, 'unpublish' => 3, 'unlock' => 4]);

function runTreeConfirmationHttpRequest(string $root, string $encoded, string $directory, bool $coverage): array
{
    if (!mkdir($directory . '/http', 0700) || file_put_contents($directory . '/http/scenario.json', $encoded) !== strlen($encoded)) throw new RuntimeException('Cannot prepare owned tree HTTP scenario.');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
    if ($socket === false) throw new RuntimeException('Cannot allocate owned tree HTTP port: ' . $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if ($address === false) throw new RuntimeException('Cannot identify owned tree HTTP port.');
    $process = proc_open(
        [PHP_BINARY, '-d','auto_prepend_file=', '-d','pcov.directory=' . $root, '-d','pcov.exclude=~/(include/vendor|tests)/~', '-d','session.save_path=' . $directory . '/http', '-S',$address, $root . '/tests/Fixtures/tree-confirmation-native-router.php'],
        [0 => ['pipe','r'],1 => ['file',$directory . '/http/stdout.log','w'],2 => ['file',$directory . '/http/stderr.log','w']],
        $pipes,
        $root,
        array_merge(getenv(), ['TREE_CONFIRMATION_ROOT' => $root,'TREE_CONFIRMATION_DIRECTORY' => $directory,'TREE_CONFIRMATION_COVERAGE' => $coverage ? '1' : '0'])
    );
    if (!is_resource($process)) throw new RuntimeException('Cannot start owned tree HTTP transport.');
    fclose($pipes[0]);
    try {
        $previous = null;
        $previous = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$previous): bool {
            if ($severity === E_WARNING && str_starts_with($message, 'fsockopen(): Unable to connect') && str_contains($message, '(Connection refused)')) return true;
            return $previous !== null ? (bool) $previous($severity, $message, $file, $line) : false;
        });
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $probe = fsockopen('tcp://' . $address, -1, $code, $error, 0.05);
                if ($probe !== false) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            if (!$ready) throw new RuntimeException('Owned tree HTTP transport was not ready.');
        } finally {
            restore_error_handler();
        }
        $context = stream_context_create(['http' => ['method' => 'POST','content' => '', 'follow_location' => 0,'ignore_errors' => true,'timeout' => 5]]);
        $body = file_get_contents('http://' . $address . '/tree.php', false, $context);
        if ($body === false) throw new RuntimeException('Cannot read owned tree HTTP response.');
        return ['status' => 0,'err' => '', 'out' => $body,'headers' => $http_response_header];
    } finally {
        proc_terminate($process);
        proc_close($process);
        $log = file_get_contents($directory . '/http/stderr.log');
        if ($log === false) throw new RuntimeException('Cannot read owned tree HTTP diagnostics.');
        expect($log)->not->toMatch('/PHP (?:Warning|Fatal error|Parse error)/');
    }
}
