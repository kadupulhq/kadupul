<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

/** One rrdtool_tune() report, as data_sources.php prints it for a file that differs from its data source. */
function rrd_tune_output_calls(bool $cli): array
{
    $file = 'rra/<b>router</b>_11.rrd';
    $diff = array(
        'step' => 'Step <i>300</i> expected',
        'ds' => array('<b>in</b>' => array('type' => 'Type <script>x</script> differs', 'minimal_heartbeat' => 'Heartbeat & 600')),
        'rra' => array('1' => array('rows' => 'Rows "700"')),
        'tune' => array($file . ' --heartbeat <b>in</b>:600'),
        'resize' => array($file . ' 1 GROW 100'),
    );

    return array(
        array('fn' => 'define', 'args' => array('CACTI_CLI', $cli)),
        array('fn' => 'rrdtool_tune', 'args' => array($file, $diff, true)),
    );
}

test('known defect: rrdtool_tune prints file paths and messages into HTML unescaped', function () {
    $observed = array();
    foreach (array('html' => false, 'cli' => true) as $mode => $cli) {
        $output = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => rrd_tune_output_calls($cli)));
        $result = $output['results'][1];
        expect($result['sent'])->toBe(array());
        $observed[$mode] = array('returned' => $result['returned'], 'printed' => $result['printed'], 'diagnostics' => $result['diagnostics']);
    }

    expect($observed['html']['printed'])->toContain('<tr><td>Type <script>x</script> differs</td></tr>')
        ->and($observed['html']['printed'])->toContain('<b>router</b>_11.rrd');
    rrd_characterization_golden('tune-report', $observed);
});

test('rrdtool_tune can be called repeatedly in one process', function () {
    $calls = rrd_tune_output_calls(true);
    $calls[] = $calls[1];
    $output = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => $calls));

    expect($output['results'])->toHaveCount(3)
        ->and($output['results'][1]['printed'])->not->toBe('')
        ->and($output['results'][2]['printed'])->toBe($output['results'][1]['printed'])
        ->and($output['results'][2]['printed'])->toContain('Step <i>300</i> expected');
});
