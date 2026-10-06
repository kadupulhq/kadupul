<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = $argv[1];
$directory = $argv[2];
$scenario = unserialize(base64_decode($argv[3], true), array('allowed_classes' => false));
if ($argv[4] === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
$cacheDirectory = $directory . '/cache';
mkdir($cacheDirectory, 0700);
$physicalCacheDirectory = $cacheDirectory;
if (isset($scenario['publication'])) {
    require __DIR__ . '/boost-cache-write-stream.php';
    BoostCacheWriteStream::$directory = $cacheDirectory;
    BoostCacheWriteStream::$fault = $scenario['publication'];
    stream_wrapper_register('boostwrite', BoostCacheWriteStream::class);
    $cacheDirectory = 'boostwrite://cache';
}
$GLOBALS['cacheWriteCount'] = 0;
$config = array('library_path' => $directory, 'base_path' => $root, 'poller_id' => 1, 'connection' => 'online');
$context = array();
require $root . '/include/global_constants.php';
require $root . '/lib/time.php';
require $root . '/tests/Helpers/PhpSource.php';
$functions = file_get_contents($root . '/lib/functions.php');
eval(test_php_function_source($functions, 'cacti_browser_zone_enabled')); // nosemgrep: php.lang.security.eval-use.eval-use
eval(test_php_function_source($functions, 'cacti_time_zone_set')); // nosemgrep: php.lang.security.eval-use.eval-use
eval(test_php_function_source($functions, 'cacti_system_zone_set')); // nosemgrep: php.lang.security.eval-use.eval-use
// The render key resolves fonts the way the render does.
require $root . '/lib/graph_fonts.php';
$rrd = file_get_contents($root . '/lib/rrd.php');
eval(test_php_function_source($rrd, 'rrdtool_theme_fonts')); // nosemgrep: php.lang.security.eval-use.eval-use
eval(test_php_function_source($rrd, 'rrdtool_graph_font_profile')); // nosemgrep: php.lang.security.eval-use.eval-use
function read_config_option($name)
{
    $settings = array('boost_png_cache_enable' => 'on', 'boost_png_cache_directory' => $GLOBALS['cacheDirectory'],
        'poller_interval' => 300, 'font_method' => 0, 'legend_font' => 'DejaVuSans', 'legend_size' => 8,
        'default_date_format' => 1, 'default_datechar' => 1);
    return array_merge($settings, $GLOBALS['context']['config'] ?? array())[$name] ?? '';
}
function read_user_setting($name, $default = false)
{
    return $GLOBALS['context']['user'][$name] ?? $default;
}
function get_selected_theme()
{
    return $GLOBALS['context']['theme'] ?? 'modern';
}
function cacti_validate_theme($requested)
{
    return in_array($requested, array('modern', 'dark', 'classic'), true) ? $requested : 'modern';
}
function set_default_action() {}
function get_request_var($name)
{
    return '';
}
function cacti_log($message, ...$args)
{
    if (str_starts_with($message, 'ERROR')) {
        throw new RuntimeException('Unexpected boost error: ' . $message);
    }
}
class MibCache
{
    public function __construct($mib) {}
    private string $name = '';
    public function object($name)
    {
        $this->name = $name;
        return $this;
    }
    public function count()
    {
        if ($this->name === 'boostStatsTotalsImagesCacheWrites') $GLOBALS['cacheWriteCount']++;
    }
    public function set($value) {}
}
file_put_contents($directory . '/poller.php', '<?php');
require $root . '/lib/boost.php';

if (isset($scenario['legacy_lock'])) {
    $GLOBALS['legacyLockCalls'] = array();
    function db_fetch_cell_prepared($sql, $parameters)
    {
        $GLOBALS['legacyLockCalls'][] = array($sql, $parameters);
        return count($GLOBALS['legacyLockCalls']) >= $GLOBALS['scenario']['legacy_lock']['succeed_at'];
    }
    $lock = $scenario['legacy_lock'];
    $acquired = boost_acquire_legacy_lock($lock['id'], $lock['attempts']);
    echo json_encode(array('acquired' => $acquired, 'calls' => $GLOBALS['legacyLockCalls']), JSON_THROW_ON_ERROR);
    return;
}

function boost_fixture_enter(array $viewer)
{
    $GLOBALS['context'] = $viewer;
    $GLOBALS['graph_data_array'] = $viewer['graph'] ?? array('graph_height' => 150, 'graph_width' => 600);
    $_SESSION = array();
    $_COOKIE = isset($viewer['color_mode']) ? array('CactiColorMode' => $viewer['color_mode']) : array();
    // What cacti_time_zone_set() and the render's LANG fallback leave behind for this viewer.
    ini_set('date.timezone', $viewer['php_tz'] ?? 'UTC');
    putenv(isset($viewer['tz']) ? 'TZ=' . $viewer['tz'] : 'TZ');
    putenv(isset($viewer['lang']) ? 'LANG=' . $viewer['lang'] : 'LANG');
    // include/global_languages.php sets these per viewer; number_format_i18n() reads them.
    $GLOBALS['cacti_locale'] = $viewer['locale'] ?? 'en-US';
    $GLOBALS['cacti_country'] = $viewer['country'] ?? 'us';
    // include/global.php hands the CactiTimeZone cookie to the real setter.
    if (isset($viewer['cookie_offset'])) {
        cacti_time_zone_set($viewer['cookie_offset']);
    }
    // graph pages resolve a preset against the last poller run, as set_preset_timespan() does.
    if (isset($viewer['preset'])) {
        $span = array();
        get_timespan($span, 1790510400, constant($viewer['preset']), read_user_setting('first_weekdayid'));
        $GLOBALS['graph_data_array']['graph_start'] = $span['begin_now'];
        $GLOBALS['graph_data_array']['graph_end'] = $span['end_now'];
    }
}

$files = array();
foreach (array('writer', 'reader') as $role) {
    boost_fixture_enter($scenario[$role]);
    // Absent before the fix; the served image below still shows the leak.
    $files[$role] = function_exists('boost_graph_cache_filename')
        ? boost_graph_cache_filename($cacheDirectory, 7, 1, 0, $GLOBALS['graph_data_array']) : null;
}

boost_fixture_enter($scenario['writer']);
$image = 'PNG rendered for the writer';
if (isset($scenario['publication'])) {
    $target = BoostCacheWriteStream::path($files['writer']);
    file_put_contents($target, 'existing complete PNG');
    boost_graph_set_file($image, 7, 1);
    echo json_encode(array('served' => file_get_contents($target),
        'written' => array_map('basename', glob($physicalCacheDirectory . '/*')),
        'writes' => $GLOBALS['cacheWriteCount'], 'rename_attempts' => BoostCacheWriteStream::$renameAttempts), JSON_THROW_ON_ERROR);
    return;
}
if (!empty($scenario['writer']['check_first'])) {
    // rrdtool_function_graph() order: the check names the file, on-demand Boost
    // updates move PHP to the server zone, then the render writes the image.
    $graph = $GLOBALS['graph_data_array'];
    $path = null;
    boost_graph_cache_check(7, 1, false, $graph, false, $path);
    cacti_system_zone_set();
    boost_graph_set_file($image, 7, 1, $graph, $path);
} elseif (!empty($scenario['writer']['function_scope'])) {
    // remote_agent.php and lib/reports.php build the array locally, not in the global.
    $local = $GLOBALS['graph_data_array'];
    $GLOBALS['graph_data_array'] = array();
    boost_graph_set_file($image, 7, 1, $local);
} else {
    boost_graph_set_file($image, 7, 1);
}
if (!empty($scenario['truncate'])) {
    foreach (glob($cacheDirectory . '/*') as $file) {
        file_put_contents($file, '');
    }
}
if (isset($scenario['age'])) {
    foreach (glob($cacheDirectory . '/*') as $file) {
        touch($file, time() - $scenario['age']);
    }
}

boost_fixture_enter($scenario['reader']);
$graph = $GLOBALS['graph_data_array'];
$served = boost_graph_cache_check(7, 1, false, $graph, false);

echo json_encode(array('cache' => $cacheDirectory, 'files' => $files, 'written' => array_map('basename', glob($cacheDirectory . '/*')),
    'served' => $served), JSON_THROW_ON_ERROR);
