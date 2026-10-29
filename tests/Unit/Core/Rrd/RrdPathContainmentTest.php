<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\RrdPathContainment;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

// Namespaced so the loaded functions see these stubs without defining globals
// that other files in the same run rely on.
function rrdtool_uses_proxy()
{
    return $GLOBALS['rrd_containment_proxy'];
}

function cacti_log($message, ...$arguments)
{
    $GLOBALS['rrd_containment_logs'][] = $message;
}

// The functions under test are this repository's own lib/rrd.php source, as in RrdCommandArgumentValidationTest.
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');
foreach (array('rrd_check_path', 'rrd_path_is_contained', 'rrd_resolve_missing_path') as $function) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}

/** Remove a directory tree, deleting a link itself and never what it points to. */
function rrd_containment_remove(string $directory): void
{
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($directory);
}

beforeEach(function () {
    $GLOBALS['rrd_containment_proxy'] = false;
    $GLOBALS['rrd_containment_logs']  = array();
    $this->working = getcwd();
    $this->root    = realpath(sys_get_temp_dir()) . '/rrd-containment-' . bin2hex(random_bytes(8));
    $this->rra     = $this->root . '/rra';
    mkdir($this->rra . '/7', 0700, true);
    mkdir($this->root . '/outside', 0700);
    mkdir($this->root . '/rra-other', 0700);
    touch($this->rra . '/7/42.rrd');
    touch($this->root . '/outside/secret.rrd');
    symlink($this->root . '/outside', $this->rra . '/escape');
    symlink($this->rra . '/7', $this->rra . '/inner');
    symlink($this->root . '/not-yet', $this->rra . '/dangling');
    symlink($this->root . '/outside/secret.rrd', $this->rra . '/leaf.rrd');
    symlink($this->rra, $this->root . '/current');
});

afterEach(function () {
    chdir($this->working);
    rrd_containment_remove($this->root);
});

test('a file in the RRA directory is accepted whether or not it exists yet', function (string $relative) {
    expect(rrd_path_is_contained($this->rra . '/' . $relative, $this->rra))->toBeTrue();
})->with(array(
    'existing file' => array('7/42.rrd'),
    'new file in an existing directory' => array('7/43.rrd'),
    'structured path whose directories do not exist' => array('12/3/44.rrd'),
    'repeated slashes' => array('7//42.rrd'),
    'link to a directory inside the RRA directory' => array('inner/42.rrd'),
    'new file below a link inside the RRA directory' => array('inner/45.rrd'),
));

test('a trailing slash on the RRA directory makes no difference', function () {
    expect(rrd_path_is_contained($this->rra . '/7/42.rrd', $this->rra . '/'))->toBeTrue()
        ->and(rrd_path_is_contained($this->rra . '/12/new.rrd', $this->rra . '//'))->toBeTrue()
        ->and(rrd_path_is_contained($this->rra . '//12/new.rrd', $this->rra . '/'))->toBeTrue()
        ->and(rrd_path_is_contained($this->root . '/outside/secret.rrd', $this->rra . '/'))->toBeFalse();
});

test('a symlinked RRA directory accepts its configured and its resolved spelling', function () {
    $link = $this->root . '/current';

    expect(rrd_path_is_contained($link . '/7/42.rrd', $link))->toBeTrue()
        ->and(rrd_path_is_contained($link . '/12/new.rrd', $link))->toBeTrue()
        ->and(rrd_path_is_contained($this->rra . '/7/42.rrd', $link))->toBeTrue()
        ->and(rrd_path_is_contained($link . '/7/42.rrd', $this->rra))->toBeTrue()
        ->and(rrd_path_is_contained($this->root . '/outside/secret.rrd', $link))->toBeFalse()
        ->and(rrd_path_is_contained($link . '/escape/secret.rrd', $link))->toBeFalse();
});

test('a relative path is read against the working directory', function () {
    chdir($this->root);

    expect(rrd_path_is_contained('rra/7/42.rrd', $this->rra))->toBeTrue()
        ->and(rrd_path_is_contained('rra/12/new.rrd', $this->rra))->toBeTrue()
        ->and(rrd_path_is_contained('outside/secret.rrd', $this->rra))->toBeFalse();
});

test('a path that leaves the RRA directory is refused', function (string $path) {
    expect(rrd_path_is_contained(str_replace('<rra>', $this->rra, str_replace('<root>', $this->root, $path)), $this->rra))->toBeFalse();
})->with(array(
    'file elsewhere' => array('<root>/outside/secret.rrd'),
    'directory with the same prefix' => array('<root>/rra-other/x.rrd'),
    'the RRA directory itself' => array('<rra>'),
    'the RRA directory with a slash' => array('<rra>/'),
    'link to a directory elsewhere' => array('<rra>/escape/secret.rrd'),
    'new file below a link to a directory elsewhere' => array('<rra>/escape/new.rrd'),
    'link to a file elsewhere' => array('<rra>/leaf.rrd'),
    'dangling link to a directory' => array('<rra>/dangling/new.rrd'),
    'parent segment' => array('<rra>/7/../../outside/secret.rrd'),
    'parent segment through a directory that does not exist' => array('<rra>/missing/../../outside/secret.rrd'),
    'parent segment at the end' => array('<rra>/7/..'),
    'current directory segment' => array('<rra>/./7/42.rrd'),
    'relative parent segment' => array('../outside/secret.rrd'),
));

test('a value RRDtool cannot be given is refused', function ($path) {
    expect(rrd_path_is_contained($path === 'prefix' ? $this->rra . "/7/\n42.rrd" : $path, $this->rra))->toBeFalse();
})->with(array('empty' => array(''), 'array' => array(array('x')), 'null' => array(null), 'prefix' => array('prefix')));

test('control characters and a missing RRA directory are refused', function () {
    expect(rrd_path_is_contained($this->rra . "/7/4\x002.rrd", $this->rra))->toBeFalse()
        ->and(rrd_path_is_contained($this->rra . "/7/4\r2.rrd", $this->rra))->toBeFalse()
        ->and(rrd_path_is_contained($this->rra . '/7/42.rrd', $this->root . '/absent'))->toBeFalse()
        ->and(rrd_path_is_contained($this->rra . '/7/42.rrd', ''))->toBeFalse();
});

test('the RRDtool proxy resolves its own paths, so only the text is checked', function () {
    expect(rrd_path_is_contained('7/42.rrd', $this->root . '/absent', true))->toBeTrue()
        ->and(rrd_path_is_contained('/remote/rra/7/42.rrd', $this->root . '/absent', true))->toBeTrue()
        ->and(rrd_path_is_contained('7/../42.rrd', $this->root . '/absent', true))->toBeFalse()
        ->and(rrd_path_is_contained("7/42.rrd\n", $this->root . '/absent', true))->toBeFalse();

    $GLOBALS['rrd_containment_proxy'] = true;

    expect(rrd_check_path('7/42.rrd', $this->root . '/absent'))->toBeTrue()
        ->and(rrd_check_path('../42.rrd', $this->root . '/absent'))->toBeFalse();
});

test('a refused path is logged under the category the caller gives', function () {
    expect(rrd_check_path($this->root . '/outside/secret.rrd', $this->rra, 'POLLER'))->toBeFalse()
        ->and($GLOBALS['rrd_containment_logs'])->toHaveCount(1)
        ->and($GLOBALS['rrd_containment_logs'][0])->toContain('outside the RRA directory');

    $GLOBALS['rrd_containment_logs'] = array();

    expect(rrd_check_path($this->rra . '/7/42.rrd', $this->rra, 'POLLER'))->toBeTrue()
        ->and(rrd_check_path($this->root . '/outside/secret.rrd', $this->rra))->toBeFalse()
        ->and($GLOBALS['rrd_containment_logs'])->toBe(array());
});

test('the RRA directory defaults to the rra_path setting', function () {
    $GLOBALS['config']['rra_path'] = $this->rra;

    try {
        expect(rrd_check_path($this->rra . '/7/42.rrd'))->toBeTrue()
            ->and(rrd_check_path($this->root . '/outside/secret.rrd'))->toBeFalse();
    } finally {
        unset($GLOBALS['config']['rra_path']);
    }
});
