<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

function rrd_legend_text_scenario(string $type, string $text, bool $debug = false): array
{
    $graph = rrd_characterization_graph(['title_cache' => 'Traffic', 'vertical_label' => 'bits']);
    $items = [rrd_characterization_item(1, 'AREA', rrd_characterization_ds('traffic_in') + ['hex' => '00CF00'])];
    if (in_array($type, ['title', 'vertical_label'], true)) {
        $graph[$type === 'title' ? 'title_cache' : 'vertical_label'] = $text;
    } else {
        $items[] = rrd_characterization_item(2, $type, array_replace(rrd_characterization_ds('traffic_in'), [
            'hex' => 'FF0000', 'text_format' => $text, 'gprint_text' => '%8.2lf %s',
            'value' => $type === 'VRULE' ? '1700001800' : ($type === 'TIC' ? '0.5' : '1'),
        ]));
    }

    return [
        'files' => ['router_traffic_11.rrd'],
        'rrd_cfs' => ['AVERAGE', 'MIN', 'MAX', 'LAST'],
        'options' => rrd_characterization_options(),
        'db' => rrd_characterization_graph_db($graph, $items),
        'calls' => [['fn' => 'rrdtool_function_graph', 'args' => [7, 0, [
            'graph_start' => 1700000000, 'graph_end' => 1700003600,
            'output_filename' => '/dev/null',
        ] + ($debug ? ['print_source' => true] : []), false, [], 0]]],
    ];
}

function rrd_legend_text_command(array $output): string
{
    $commands = array_values(array_filter($output['results'][0]['sent'], static fn(array $sent): bool => str_starts_with($sent['stdin'], 'graph ')));
    expect($commands)->toHaveCount(1);

    return $commands[0]['stdin'];
}

test('graph text is encoded for the selected Pango markup mode', function (string $type) {
    $output = rrd_characterization_run($this, rrd_legend_text_scenario($type, 'in & out <b>'));
    expect(rrd_legend_text_command($output))->toContain('--pango-markup', 'in &amp; out &lt;b&gt;')->not->toContain('in & out <b>');
})->with(['COMMENT', 'GPRINT', 'GPRINT_LAST', 'GPRINT_AVERAGE', 'GPRINT_MAX', 'GPRINT_MIN', 'AREA', 'STACK', 'LINE1', 'LINE2', 'LINE3', 'LINESTACK', 'TIC', 'HRULE', 'VRULE', 'title', 'vertical_label']);

test('graph debug source displays untrusted text without creating HTML elements', function () {
    $text = '<img src=x onerror=alert(1)> &';
    $output = rrd_characterization_run($this, rrd_legend_text_scenario('COMMENT', $text, true));
    $dom = new DOMDocument();
    expect($dom->loadHTML($output['results'][0]['printed'], LIBXML_NONET))->toBeTrue();
    expect($dom->getElementsByTagName('pre')->length)->toBe(1)
        ->and($dom->getElementsByTagName('img')->length)->toBe(0)
        ->and($dom->getElementsByTagName('pre')->item(0)->textContent)->toContain($text);
});

test('native graph pixels match literal RRDtool text', function (string $type) {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) {
        $this->markTestSkipped('Real RRDtool is required.');
    }
    $text = 'in & out <b>café</b>';
    $actual = rrd_legend_text_command(rrd_characterization_run($this, rrd_legend_text_scenario($type, $text)));
    // Build unchanged options with an ASCII placeholder, then supply the known
    // literal text directly to RRDtool with markup disabled. This independently
    // checks the pixels, rather than treating encoded command text as a defect.
    $reference = str_replace(['TEXTMARKER', '--pango-markup '], [$text, ''], rrd_legend_text_command(rrd_characterization_run($this, rrd_legend_text_scenario($type, 'TEXTMARKER'))));
    $directory = sys_get_temp_dir() . '/rrd-legend-text-' . bin2hex(random_bytes(8));
    mkdir($directory . '/rra', 0700, true);
    try {
        $images = [];
        foreach ([$actual, $reference] as $command) {
            if (is_file($directory . '/graph.png')) {
                unlink($directory . '/graph.png');
            }
            $command = str_replace('/dev/null', 'graph.png', $command);
            $create = 'create rra/router_traffic_11.rrd --start 1699990000 --step 300 DS:traffic_in:GAUGE:600:U:U RRA:AVERAGE:0.5:1:100 RRA:MIN:0.5:1:100 RRA:MAX:0.5:1:100 RRA:LAST:0.5:1:100';
            $environment = ['PATH' => getenv('PATH'), 'LANG' => 'C', 'LC_ALL' => 'C', 'HOME' => $directory, 'XDG_CACHE_HOME' => $directory . '/cache'];
            $process = proc_open([$binary, '-'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/stdout', 'w'], 2 => ['file', $directory . '/stderr', 'w']], $pipes, $directory, $environment);
            expect(is_resource($process))->toBeTrue();
            $input = $create . "\n" . $command;
            expect(fwrite($pipes[0], $input))->toBe(strlen($input));
            fclose($pipes[0]);
            expect(proc_close($process))->toBe(0)
                ->and(file_get_contents($directory . '/stderr'))->toBe('')
                ->and(file_get_contents($directory . '/stdout'))->not->toContain('ERROR:');
            expect(preg_match_all('/^OK u:/m', file_get_contents($directory . '/stdout')))->toBe(2);
            expect(is_file($directory . '/graph.png'))->toBeTrue();
            $image = file_get_contents($directory . '/graph.png');
            expect($image)->toStartWith("\x89PNG\r\n\x1a\n");
            $images[] = hash('sha256', $image);
        }
        expect($images[0])->toBe($images[1]);
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
})->with(['COMMENT', 'GPRINT', 'GPRINT_LAST', 'GPRINT_AVERAGE', 'GPRINT_MAX', 'GPRINT_MIN', 'LINE1', 'HRULE', 'VRULE', 'title', 'vertical_label']);
