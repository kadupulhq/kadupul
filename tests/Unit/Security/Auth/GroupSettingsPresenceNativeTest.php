<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('group settings presence uses its own stored row', function (string $scenario): void {
    if (!getenv('KADUPUL_TEST_MYSQL_DSN')) $this->markTestSkipped('Disposable database required.');
    $root = dirname(__DIR__, 4);
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $root . '/tests/fixtures/group-settings-presence-native.php', $scenario], [1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    expect($errors)->toBe('')->and($exit)->toBe(0);
    $fields = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    foreach (['hide_disabled' => '', 'min_tree_width' => '0', 'thumbnail_section_preview' => '', 'thumbnail_section_tree_2' => 'on'] as $name => $value) {
        $field = str_starts_with($name, 'thumbnail_') ? $fields['thumbnail_sections']['items'][$name] : $fields[$name];
        if ($scenario === 'stored') expect($field['value'])->toBe($value)->and($field['form_id'] ?? null)->toBe(1);
        else expect($field)->not->toHaveKey('form_id');
    }
})->with(['stored','unrelated']);
