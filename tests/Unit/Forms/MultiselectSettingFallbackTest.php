<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Symfony\Component\Process\Process;

test('native multiselect preserves setting fallback and explicit selections', function (array $input, array $selected): void {
    $directory = sys_get_temp_dir() . '/multiselect-test-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $command = [PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', dirname(__DIR__, 2) . '/Fixtures/form-renderer-native.php', json_encode(['multiselect' => $input], JSON_THROW_ON_ERROR)];
        if ($coverage !== null) {
            $command[] = $directory;
        }
        $process = new Process($command);
        $process->mustRun();
        expect($process->getErrorOutput())->toBe('');
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $document = Dom\HTMLDocument::createFromString($result['html'], LIBXML_NOERROR);
        $select = $document->getElementById('choices');
        expect($select)->not->toBeNull()
            ->and($select->getAttribute('name'))->toBe('choices[]')
            ->and($select->hasAttribute('multiple'))->toBeTrue()
            ->and($select->getElementsByTagName('option'))->toHaveCount(3);
        $actual = [];
        foreach ($select->getElementsByTagName('option') as $option) {
            if ($option->hasAttribute('selected')) {
                $actual[] = $option->getAttribute('value');
            }
        }
        expect($actual)->toBe($selected);
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([
    'stored single selection' => [['value' => '', 'setting' => 'b'], ['b']],
    'stored multiple selections' => [['value' => '', 'setting' => 'a,b'], ['a', 'b']],
    'stored zero selection' => [['value' => '', 'setting' => '0'], ['0']],
    'empty setting' => [['value' => '', 'setting' => ''], []],
    'missing setting' => [['value' => ''], []],
    'explicit string overrides setting' => [['value' => 'a', 'setting' => 'b'], ['a']],
    'explicit rows override setting' => [['value' => [['id' => 'a']], 'setting' => 'b'], ['a']],
    'explicit empty rows skip fallback' => [['value' => [], 'setting' => 'b'], []],
    'explicit zero skips fallback' => [['value' => '0', 'setting' => 'b'], ['0']],
    'null uses fallback' => [['value' => null, 'setting' => 'b'], ['b']],
    'unknown stored option stays unselected' => [['value' => '', 'setting' => 'unknown'], []],
]);
