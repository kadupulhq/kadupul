<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace ChangeDeviceDisableFlagTest;

$root = dirname(__DIR__, 4);

/**
 * Run the real --disable branch of cli/change_device.php against one value,
 * without the CLI bootstrap or a database. Taking the fragment from the file
 * means a regression in that branch fails here rather than in a copy of it.
 *
 * @param string $value The raw option value, as argv would supply it.
 *
 * @return array{status: int, out: string, disabled: string|null}
 */
function change_device_disable($value)
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/change_device.php');
    expect($source)->not->toBeFalse();

    $start = strpos($source, "case '--disable':");
    $end   = strpos($source, "case '--external-id':", $start);
    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    $code = '$overrides = array(); $value = ' . var_export($value, true) . ';'
        . 'switch ("--disable") {' . substr($source, $start, $end - $start) . '}'
        . 'echo json_encode($overrides);';

    $pipes   = array();
    $process = proc_open(
        array(PHP_BINARY, '-r', $code),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    expect($process)->not->toBeFalse();

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    expect($err)->toBe('');
    $overrides = json_decode($out, true);

    return array(
        'status'   => $status,
        'out'      => $out,
        'disabled' => is_array($overrides) && array_key_exists('disabled', $overrides) ? $overrides['disabled'] : null,
    );
}

/* 'on' in host.disabled means polling is off, so 1 must store 'on'. */
test('numeric --disable follows the documented contract', function () {
    $disable = change_device_disable('1');
    $enable  = change_device_disable('0');

    expect($disable['status'])->toBe(0)
        ->and($disable['disabled'])->toBe('on')
        ->and($enable['status'])->toBe(0)
        ->and($enable['disabled'])->toBe('');
});

test('string --disable keeps its existing meaning', function () {
    expect(change_device_disable('on')['disabled'])->toBe('on')
        ->and(change_device_disable('off')['disabled'])->toBe('');
});

test('a numeric --disable outside 0 and 1 is refused rather than read as enable', function () {
    foreach (array('2', '-1', '0.5', '99') as $value) {
        $result = change_device_disable($value);

        expect($result['status'])->toBe(1, $value);
        expect($result['out'])->toContain('ERROR: Invalid disable flag');
    }
});

test('change_device and add_device agree on what a numeric flag means', function () use ($root) {
    $add = file_get_contents($root . '/cli/add_device.php');

    expect($add)->not->toBeFalse()
        // add_device is the contract this branch was corrected against.
        ->and($add)->toContain('ERROR: Invalid disable flag')
        ->and(change_device_disable('1')['disabled'])->toBe('on')
        ->and(change_device_disable('0')['disabled'])->toBe('');
});

/*
 * --bulk_walk once ran off the end of its case into display_version(), so a
 * correct value printed the banner and exited 0 without changing the device.
 * Check the whole loop, not just that option, so the next omission is caught.
 */
test('every option in the argument loop ends its own case', function () use ($root) {
    $source = file_get_contents($root . '/cli/change_device.php');
    $start  = strpos($source, 'switch ($arg)');

    expect($start)->not->toBeFalse();

    // Balance braces rather than match the closing line, so the check does not
    // depend on whether the file is tab- or space-indented.
    $open  = strpos($source, '{', $start);
    $depth = 0;
    $end   = $open;

    for ($i = $open; $i < strlen($source); $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                $end = $i;

                break;
            }
        }
    }

    $lines  = explode("\n", substr($source, $open, $end - $open));
    $widths = array();

    foreach ($lines as $index => $line) {
        if (preg_match('/^(\s+)(case .+|default):\s*$/', rtrim($line, "\r"), $matches)) {
            $widths[$index] = strlen($matches[1]);
        }
    }

    expect($widths)->not->toBe(array());

    /* The shallowest labels are this switch's own; the deeper ones belong to
       the nested --avail and --ping_method switches inside a case body. */
    $outer  = min($widths);
    $labels = array();

    foreach ($widths as $index => $width) {
        if ($width === $outer) {
            $labels[] = $index;
        }
    }

    expect(count($labels))->toBeGreaterThan(20);

    $fallen = array();
    $total  = count($labels);

    foreach ($labels as $position => $index) {
        $next = $position + 1 < $total ? $labels[$position + 1] : count($lines);
        $body = array_slice($lines, $index + 1, $next - $index - 1);
        $body = array_values(array_filter(array_map('trim', $body), 'strlen'));

        // An empty body means stacked labels sharing the next one's body.
        if ($body === array()) {
            continue;
        }

        if (!preg_match('/^(break;|continue\b.*;|exit\(.*\);|return\b.*;)$/', end($body))) {
            $fallen[] = trim($lines[$index]);
        }
    }

    expect($fallen)->toBe(array());
});

test('change_device states which numeric value disables', function () use ($root) {
    $wrong = array();

    foreach (array('change_device.php') as $script) {
        $source = file_get_contents($root . '/cli/' . $script);
        $line   = '';

        foreach (preg_grep('/--disable\s/', explode("\n", $source)) as $candidate) {
            if (strpos($candidate, 'print') !== false && strpos($candidate, 'usage:') === false) {
                $line = $candidate;
            }
        }

        /* The old wording, "0, 1 to ... and 0 to enable it", gave 0 both
           meanings. Naming 1 before 0 is what makes it unambiguous. Collect
           the offenders rather than pass a message to toContain(), whose
           second argument is another needle. */
        if (strpos($line, '0 to enable') === false
            || strpos($line, '0, 1 to') !== false
            || strpos($line, '1 to') > strpos($line, '0 to enable')) {
            $wrong[$script] = trim($line);
        }
    }

    expect($wrong)->toBe(array());
});
