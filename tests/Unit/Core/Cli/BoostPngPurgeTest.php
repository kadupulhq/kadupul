<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/**
 * Run the real boost_purge_cached_png_files() in a child process over a cache
 * directory holding $files (name => array(mode, age in seconds)). One user
 * cannot create files owned by another, so $foreign names files the child's
 * fileowner() reports as someone else's, and $unlinkFails makes unlink() fail.
 */
function boost_png_purge_run(array $files, int $directoryMode = 0700, array $foreign = array(), bool $unlinkFails = false): array
{
    $root = dirname(__DIR__, 4);
    $base = sys_get_temp_dir() . '/boost-png-purge-' . bin2hex(random_bytes(8));
    $cache = $base . '/cache';
    mkdir($cache, 0700, true);
    foreach ($files as $name => list($mode, $age)) {
        file_put_contents($cache . '/' . $name, 'image');
        chmod($cache . '/' . $name, $mode);
        touch($cache . '/' . $name, time() - $age);
    }
    chmod($cache, $directoryMode);

    preg_match("/define\\('BOOST_PNG_TEMP_PREFIX', '([^']*)'\\);/", file_get_contents($root . '/lib/boost.php'), $prefix);
    $script = 'namespace BoostPngPurgeProbe;'
        . 'define(' . var_export('BOOST_PNG_TEMP_PREFIX', true) . ', ' . var_export($prefix[1] ?? 'boost_png_tmp_', true) . ');'
        . '$config = array("base_path" => ' . var_export($base, true) . ');'
        . '$cache = ' . var_export($cache, true) . '; $foreign = ' . var_export($foreign, true) . '; $unlinkFails = ' . var_export($unlinkFails, true) . ';'
        . '$logged = array();'
        . 'function read_config_option($name) { return $name === "boost_png_cache_directory" ? $GLOBALS["cache"] : "on"; }'
        . 'function cacti_sizeof($value) { return count($value); }'
        . 'function cacti_log($message) { $GLOBALS["logged"][] = $message; }'
        . 'function fileowner($file) { return in_array($file, $GLOBALS["foreign"], true) ? \posix_geteuid() + 1 : \fileowner($file); }'
        . 'function unlink($file) { return $GLOBALS["unlinkFails"] ? false : \unlink($file); }'
        . test_php_function_source(file_get_contents($root . '/poller_boost.php'), 'boost_purge_cached_png_files')
        . 'boost_purge_cached_png_files(false);'
        . '$left = array_values(array_diff(scandir($cache), array(".", "..")));'
        . 'echo json_encode(array($left, $logged));';

    try {
        $pipes = array();
        $process = proc_open(array(PHP_BINARY, '-r', $script), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        chmod($cache, 0700);
        foreach (glob($cache . '/*') as $file) {
            unlink($file);
        }
        rmdir($cache);
        rmdir($base);
    }
}

// The web server writes images 0644; a poller running as another user cannot write them.
test('the purge removes an old image it cannot write when the directory lets it', function () {
    list($left) = boost_png_purge_run(array('modern_lgi_7_rrai_1.png' => array(0444, 7200), 'modern_lgi_8_rrai_1.png' => array(0644, 7200)));

    expect($left)->toBe(array());
});

test('the purge keeps images younger than an hour and files that are not images', function () {
    list($left) = boost_png_purge_run(array('modern_lgi_7_rrai_1.png' => array(0444, 60), 'notes.txt' => array(0644, 7200), 'notes.png.bak' => array(0644, 7200), 'logo.JPG' => array(0644, 7200)));
    sort($left);

    expect($left)->toBe(array('modern_lgi_7_rrai_1.png', 'notes.png.bak', 'notes.txt'));
});

test('the purge removes temporary images a dead writer left behind once they are an hour old', function () {
    list($left) = boost_png_purge_run(array('boost_png_tmp_old123' => array(0600, 7200), 'boost_png_tmp_new456' => array(0600, 60)));

    expect($left)->toBe(array('boost_png_tmp_new456'));
});

test('a sticky cache directory limits the purge to images the poller owns', function () {
    list($left) = boost_png_purge_run(
        array('modern_lgi_7_rrai_1.png' => array(0644, 7200), 'modern_lgi_8_rrai_1.png' => array(0644, 7200)),
        01700,
        array('modern_lgi_8_rrai_1.png', '.')
    );

    expect($left)->toBe(array('modern_lgi_8_rrai_1.png'));
});

test('the purge removes nothing from a directory it cannot write', function () {
    list($left, $logged) = boost_png_purge_run(array('modern_lgi_7_rrai_1.png' => array(0644, 7200)), 0500);

    expect($left)->toBe(array('modern_lgi_7_rrai_1.png'))->and($logged)->toBe(array());
});

test('an image the purge cannot remove is logged and the purge goes on', function () {
    list($left, $logged) = boost_png_purge_run(array('modern_lgi_7_rrai_1.png' => array(0644, 7200), 'modern_lgi_8_rrai_1.png' => array(0644, 7200)), 0700, array(), true);
    // readdir() order is up to the file system.
    sort($logged);

    expect($left)->toHaveCount(2)
        ->and($logged)->toBe(array(
            "WARNING: Boost could not remove the cached image 'modern_lgi_7_rrai_1.png'",
            "WARNING: Boost could not remove the cached image 'modern_lgi_8_rrai_1.png'",
        ));
});
