<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('legacy RRDtool execution wires LC_TIME from active locale while keeping LC_NUMERIC=C and clearing LC_ALL', function ($locale, $command, $expectedTimeLocale) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-lc-time-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700, true);

    file_put_contents($dir . '/rrdtool', '#!' . PHP_BINARY . "\n<?php\n" . <<<'FAKE'
        $observed = array(
            'LC_ALL' => getenv('LC_ALL'),
            'LC_NUMERIC' => getenv('LC_NUMERIC'),
            'LC_TIME' => getenv('LC_TIME'),
            'LANG' => getenv('LANG'),
        );
        while (($line = fgets(STDIN)) !== false) {
            if (trim($line) === 'quit') {
                break;
            }
            echo json_encode($observed) . "\n";
            fflush(STDOUT);
        }
        FAKE);
    chmod($dir . '/rrdtool', 0700);

    $defineLocale = $locale === null ? '' : 'define("CACTI_LOCALE", ' . var_export($locale, true) . ');';
    $program = '<?php '
        . '$root = ' . var_export($root, true) . ';'
        . '$dir = ' . var_export($dir, true) . ';'
        . '$command = ' . var_export($command, true) . ';'
        . '$config = array("cacti_server_os" => "unix", "rra_path" => $dir, "include_path" => $dir, "is_web" => false);'
        . 'require $root . "/include/global_constants.php";'
        . $defineLocale
        . 'function read_config_option($key) { return $key === "path_rrdtool" ? $GLOBALS["dir"] . "/rrdtool" : ""; }'
        . 'function cacti_log($message, ...$args) {}'
        . 'function cacti_session_close() {}'
        . 'require $root . "/lib/rrd.php";'
        . 'putenv("LC_ALL=fr_FR.UTF-8");'
        . 'putenv("LC_NUMERIC=de_DE.UTF-8");'
        . 'putenv("LC_TIME");'
        . 'putenv("LANG=en_US.UTF-8");'
        . '$out = rrdtool_execute($command, false, RRDTOOL_OUTPUT_STDOUT);'
        . '$after = array("LC_ALL" => getenv("LC_ALL"), "LC_NUMERIC" => getenv("LC_NUMERIC"), "LC_TIME" => getenv("LC_TIME"), "LANG" => getenv("LANG"));'
        . 'echo json_encode(array("child" => json_decode(trim((string) $out), true, 512, JSON_THROW_ON_ERROR), "after" => $after, "non_string" => rrdtool_time_locale(array("bad"))));';

    file_put_contents($dir . '/run.php', $program);

    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', $dir . '/run.php'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0, $stdout . $stderr)
            ->and($stderr)->toBe('');

        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['child'])->toBe(array(
            'LC_ALL' => false,
            'LC_NUMERIC' => 'C',
            'LC_TIME' => $expectedTimeLocale,
            'LANG' => $expectedTimeLocale,
        ))
            ->and($payload['after'])->toBe(array(
                'LC_ALL' => 'fr_FR.UTF-8',
                'LC_NUMERIC' => 'de_DE.UTF-8',
                'LC_TIME' => false,
                'LANG' => 'en_US.UTF-8',
            ))
            ->and($payload['non_string'])->toBe('C');
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array(
    'graph with German locale' => array('de-DE', 'graph - DEF:a=x.rrd:v:AVERAGE', 'de_DE.UTF-8'),
    'graph with Japanese locale' => array('ja-JP', 'graph - DEF:a=x.rrd:v:AVERAGE', 'ja_JP.UTF-8'),
    'graph with Moroccan Arabic locale' => array('ar-MA', 'graph - DEF:a=x.rrd:v:AVERAGE', 'ar_MA.UTF-8'),
    'graph with Canadian French locale' => array('fr-CA', 'graph - DEF:a=x.rrd:v:AVERAGE', 'fr_CA.UTF-8'),
    'graph with Simplified Chinese locale' => array('zh-CN', 'graph - DEF:a=x.rrd:v:AVERAGE', 'zh_CN.UTF-8'),
    'graph with US English locale' => array('en-US', 'graph - DEF:a=x.rrd:v:AVERAGE', 'en_US.UTF-8'),
    'fetch forces neutral C even under German locale' => array('de-DE', 'fetch x.rrd AVERAGE', 'C'),
    'info forces neutral C even under Japanese locale' => array('ja-JP', 'info x.rrd', 'C'),
    'graph with undefined CACTI_LOCALE falls back to C' => array(null, 'graph - DEF:a=x.rrd:v:AVERAGE', 'C'),
    'graph with untrusted CACTI_LOCALE falls back to C' => array("de-DE\nLC_NUMERIC=de_DE", 'graph - DEF:a=x.rrd:v:AVERAGE', 'C'),
    'graph with unlisted locale falls back to C' => array('xx-ZZ', 'graph - DEF:a=x.rrd:v:AVERAGE', 'C'),
));
