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
        $result = test_php_run($command);
        \PHPUnit\Framework\Assert::assertSame(0, $result['status'], $result['err'] . $result['out']);
        expect($result['err'])->toBe('');
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $arguments = array($reports[0], $root, 'tests/Fixtures/tree-confirmation-native.php', $encoded, TreeConfirmationCoverageRegistration::SOURCES, TreeConfirmationCoverageRegistration::MARKERS, TreeConfirmationCoverageRegistration::HITS);
            $child = NativeChildCoverageEvidence::load(...$arguments);
            static $checked = false;
            if (!$checked) {
                expect(NativeChildCoverageEvidence::verifyRejections(...array_merge($arguments, array('lib/rrd.php'))))->toBe(count(TreeConfirmationCoverageRegistration::SOURCES) + 12);
                $checked = true;
            }
            $coverage->merge($child);
        }
        return json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (glob($directory . '/*.coverage*') as $file) unlink($file);
        if (is_link($directory . '/lib')) unlink($directory . '/lib');
        if (is_file($directory . '/include/auth.php')) unlink($directory . '/include/auth.php');
        if (is_dir($directory . '/include')) rmdir($directory . '/include');
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
