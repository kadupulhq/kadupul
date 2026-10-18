<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Symfony\Component\Process\Process;

test('edit forms bind and consume each action queue before the next form', function (string $mode): void {
    $directory = sys_get_temp_dir() . '/form-actions-test-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $command = [PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', dirname(__DIR__, 2) . '/Fixtures/form-renderer-native.php', json_encode(['actions' => $mode], JSON_THROW_ON_ERROR)];
        if ($coverage !== null) {
            $command[] = $directory;
        }
        $process = new Process($command);
        $process->mustRun();
        expect($process->getErrorOutput())->toBe('');
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $document = Dom\HTMLDocument::createFromString($result['html'], LIBXML_NOERROR);
        $scripts = $document->getElementsByTagName('script');
        expect($scripts)->toHaveCount($mode === 'none' ? 0 : 1);
        if ($mode !== 'none') {
            $script = $scripts->item(0);
            expect($script->getAttribute('nonce'))->not->toBe('')
                ->and(str_contains($script->textContent, "$('#choice').on('change'"))->toBe(in_array($mode, ['change', 'both'], true))
                ->and(str_contains($script->textContent, "$('#refresh').on('click'"))->toBe(in_array($mode, ['click', 'both'], true));
        }
        foreach (['first_session', 'session'] as $session) {
            expect($result[$session])->not->toHaveKey('form_change_actions')
                ->not->toHaveKey('form_click_actions');
        }
        expect($result['second_html'])->not->toContain('<script')
            ->not->toContain('window.changeCalls')
            ->not->toContain('window.clickCalls');
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
})->with(['change-only' => 'change', 'click-only' => 'click', 'combined' => 'both', 'no handlers' => 'none']);
