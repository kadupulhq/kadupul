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

test('known defect: a second rrdtool_tune call in one process redeclares print_leaves and ends it', function () {
    $calls = rrd_tune_output_calls(true);
    // An empty diff prints nothing, so only the declaration can fail.
    $calls[1]['args'][1] = array();
    $calls[] = $calls[1];
    $output = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => $calls), true);

    expect($output['status'])->toBe(255)
        ->and($output['stdout'])->toBe('')
        ->and($output['stderr'])->toContain('Cannot redeclare function print_leaves()');
});
