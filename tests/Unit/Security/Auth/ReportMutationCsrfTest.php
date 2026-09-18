<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root              = dirname(__DIR__, 4);
$reportsAdmin     = file_get_contents($root . '/reports_admin.php');
$reportsUser      = file_get_contents($root . '/reports_user.php');
$htmlReports      = file_get_contents($root . '/lib/html_reports.php');
$reportsGenerator = file_get_contents($root . '/lib/reports.php');

test('report mutation actions reject non-POST requests in both report controllers', function () use ($reportsAdmin, $reportsUser) {
    foreach (array('send', 'ajax_dnd', 'item_movedown', 'item_moveup', 'item_remove') as $action) {
        expect($reportsAdmin)->toMatch('/case\s+\'' . preg_quote($action, '/') . '\':\s+reports_require_post\(\'' . preg_quote($action, '/') . '\'\);/s');
        expect($reportsUser)->toMatch('/case\s+\'' . preg_quote($action, '/') . '\':\s+reports_require_post\(\'' . preg_quote($action, '/') . '\'\);/s');
    }
});

test('report item controls post mutations with the csrf token', function () use ($htmlReports) {
    expect($htmlReports)->toContain('function reports_require_post($action)')
        ->and($htmlReports)->toContain('loadPageUsingPost(reportsPage')
        ->and($htmlReports)->toContain('__csrf_magic:csrfMagicToken')
        ->and($htmlReports)->not->toContain('?action=item_movedown&item_id=')
        ->and($htmlReports)->not->toContain('?action=item_moveup&item_id=')
        ->and($htmlReports)->not->toContain('?action=item_remove&item_id=');
});

test('csrf middleware rejects empty-body POST mutations without a token', function () use ($root) {
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('defer', true);
    csrf_conf('rewrite', false);
    csrf_conf('auto-session', false);
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array();
require __DIR__ . '/../include/vendor/csrf/csrf-magic.php';
echo csrf_check(false) ? 'accepted' : 'rejected';
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root . '/tests'
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toBe('rejected');
});

test('report controllers redirect mutations through the realm-aware reports page', function () use ($reportsAdmin, $reportsUser) {
    expect($reportsUser)->not->toContain("header('Location: reports_admin.php?action=edit&tab=items&id='")
        ->and($reportsAdmin)->toContain("header('Location: ' . get_reports_page()")
        ->and($reportsUser)->toContain("header('Location: ' . get_reports_page()")
        ->and(substr_count($reportsAdmin, "&header=false'"))->toBe(5)
        ->and(substr_count($reportsUser, "&header=false'"))->toBe(5);
});

test('report data query labels are escaped before generated html output', function () use ($reportsGenerator, $root) {
    expect($reportsGenerator)->toContain('reports_data_query_label($data_query[\'name\'])');

    $program = <<<'PHP'
namespace ReportMutationRuntime;
function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$source = file_get_contents(getcwd() . '/lib/reports.php');
preg_match('/^function reports_data_query_label\(.*?^}\n/ms', $source, $match);
if (empty($match)) { exit(2); }
eval('namespace ReportMutationRuntime; ' . $match[0]);
echo reports_data_query_label('<script>alert(1)</script>');
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toBe('Data Query: &lt;script&gt;alert(1)&lt;/script&gt;');
});

test('report image conversion uses unpredictable temporary files with cleanup', function () use ($reportsGenerator, $root) {
    expect($reportsGenerator)->toContain("tempnam(sys_get_temp_dir(), 'cacti-report-')")
        ->and($reportsGenerator)->toContain('finally')
        ->and($reportsGenerator)->not->toContain("'/tmp/' . time() . '.png'");

    $program = <<<'PHP'
namespace ReportPngRuntime;
$GLOBALS['tmpdir'] = \sys_get_temp_dir() . '/report-png-' . bin2hex(random_bytes(4));
mkdir($GLOBALS['tmpdir']);
function sys_get_temp_dir() { return $GLOBALS['tmpdir']; }
function imagecreatefrompng($file) { return $GLOBALS['decode_ok'] ? 'image' : false; }
function ImageCreate($width, $height) { return 'fallback'; }
function ImageColorAllocate($image, $red, $green, $blue) { return 'color'; }
function ImageFilledRectangle($image, $x1, $y1, $x2, $y2, $color) {}
function ImageString($image, $font, $x, $y, $string, $color) {}
function imagejpeg($image) { echo 'jpeg'; }
function imagegif($image) { echo 'gif'; }
$source = file_get_contents(getcwd() . '/lib/reports.php');
preg_match('/^function png2jpeg .*?^}\n/ms', $source, $jpeg);
preg_match('/^function png2gif .*?^}\n/ms', $source, $gif);
if (empty($jpeg) || empty($gif)) { exit(2); }
eval('namespace ReportPngRuntime; ' . $jpeg[0] . $gif[0]);
$GLOBALS['decode_ok'] = true;
$jpegData = png2jpeg('png-data');
$GLOBALS['decode_ok'] = false;
$gifData = png2gif('bad-data');
$remaining = glob($GLOBALS['tmpdir'] . '/cacti-report-*');
rmdir($GLOBALS['tmpdir']);
echo json_encode(array($jpegData, $gifData, $remaining));
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    expect($exit)->toBe(0, $stderr)
        ->and(json_decode($stdout, true))->toBe(array('jpeg', 'gif', array()));
});
