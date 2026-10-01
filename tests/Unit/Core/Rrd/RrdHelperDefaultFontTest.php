<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Data Source statistics and RRD checks start their own RRDtool, and their
// pollers may load the library without lib/rrd.php.
test('RRDtool helper processes get the Default Font only when it names a font', function (string $library, string $init, string $font, $environment) {
    $root = dirname(__DIR__, 4);
    $script = '$root = ' . var_export($root, true) . ';' . <<<'PHP'
        $config = array('base_path' => $root, 'cacti_server_os' => 'unix');
        require $root . '/include/global_constants.php';
        function read_config_option($name) { return $name == 'path_rrdtool' ? '/bin/cat' : $GLOBALS['argv'][3]; }
        require $root . '/lib/' . $argv[1];
        putenv('RRD_DEFAULT_FONT');
        [$process, $pipes] = $argv[2]();
        fclose($pipes[0]);
        fclose($pipes[1]);
        proc_close($process);
        echo json_encode(getenv('RRD_DEFAULT_FONT'));
        PHP;

    $pipes = array();
    $process = proc_open(array(PHP_BINARY, '-r', $script, '--', $library, $init, $font), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $error . $output)
        ->and($error)->toBe('')
        ->and(json_decode($output, true))->toBe($environment);
})->with(array(
    'Data Source statistics' => array('dsstats.php', 'dsstats_rrdtool_init'),
    'RRD checks' => array('rrdcheck.php', 'rrdcheck_rrdtool_init'),
))->with(array(
    'a family' => array('DejaVu Sans', 'DejaVu Sans'),
    'a description with a style and size' => array('DejaVu Sans Bold 9', 'DejaVu Sans Bold 9'),
    'none' => array('', false),
    'a font file path' => array('/usr/share/fonts/DejaVuSans.ttf', false),
    'a line break' => array("Sans\nBold", false),
));
