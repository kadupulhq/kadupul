<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('flag icons retain every configured locale and all CSS asset references', function (): void {
    $root = dirname(__DIR__, 2);
    $base = $root . '/include/vendor/flag-icons';
    $package = json_decode(file_get_contents($base . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($package['version'])->toBe('7.5.0');
    $css = file_get_contents($base . '/css/flag-icons.css');
    $program = <<<'PHP'
function read_config_option($key) { return '0'; }
require $argv[1] . '/include/global_constants.php';
// Disable translation setup only; read the real runtime locale registry.
require $argv[1] . '/include/global_languages.php';
echo json_encode(array_column(get_list_of_locales(), 'country'), JSON_THROW_ON_ERROR);
PHP;
    $process = proc_open(array(PHP_BINARY, '-r', $program, $root), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    expect(is_resource($process))->toBeTrue();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
    $countries = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    expect(count($countries))->toBeGreaterThan(20);
    foreach (array_unique($countries) as $country) {
        expect($css)->toContain('.fi-' . $country . ' {');
        foreach (array('1x1', '4x3') as $ratio) {
            expect(is_file($base . '/flags/' . $ratio . '/' . $country . '.svg'))->toBeTrue();
        }
    }
    foreach (array('flag-icons.css', 'flag-icons.min.css') as $file) {
        preg_match_all('/url\(([^)]+)\)/', file_get_contents($base . '/css/' . $file), $urls);
        expect(count($urls[1]))->toBeGreaterThan(500);
        foreach ($urls[1] as $url) {
            expect($url)->toStartWith('../flags/')
                ->and(is_file($base . '/css/' . $url))->toBeTrue();
        }
    }
});

test('bundled flag SVGs are valid passive images with internal references only', function (): void {
    $files = glob(dirname(__DIR__, 2) . '/include/vendor/flag-icons/flags/*/*.svg');
    expect(count($files))->toBeGreaterThan(500);
    $previous = libxml_use_internal_errors(true);
    try {
        foreach ($files as $file) {
            $xml = new DOMDocument();
            expect($xml->loadXML(file_get_contents($file), LIBXML_NONET))->toBeTrue();
            $xpath = new DOMXPath($xml);
            expect($xml->documentElement->localName)->toBe('svg')
                ->and($xpath->query('//*[local-name()="script" or local-name()="foreignObject"]')->length)->toBe(0);
            foreach ($xpath->query('//@*') as $attribute) {
                expect(str_starts_with(strtolower($attribute->localName), 'on'))->toBeFalse();
                if (in_array($attribute->localName, array('href', 'src'), true)) {
                    expect($attribute->value)->toStartWith('#');
                }
            }
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
});
