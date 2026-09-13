<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * PHP casts a float to a string with 14 significant digits, so a microtime(true)
 * within 50 microseconds of a whole second loses its fraction, and 'U.u' then
 * refuses it. The settings table hands the same digits back as a string.
 *
 * lib/installer.php only defines the class and pulls in lib/poller.php, which
 * only defines functions, so the helper runs in this process where coverage
 * can see it.
 */

$root            = dirname(__DIR__, 2);
$installerSource = file_get_contents($root . '/lib/installer.php');

if ($installerSource === false) {
    throw new RuntimeException('Unable to read installer source');
}

require_once $root . '/lib/installer.php';

function installer_microtime_date($value)
{
    $parse = new ReflectionMethod(Installer::class, 'dateFromMicrotime');
    $parse->setAccessible(true);

    $date = $parse->invoke(null, $value);

    return $date === false ? false : $date->format('Y-m-d H:i:s.u');
}

test('installer timestamps parse through the microtime helper', function () use ($installerSource) {
    expect($installerSource)->not->toContain("DateTime::createFromFormat('U.u', \$background");
});

test('values with a fraction take the original parse', function () {
    expect(installer_microtime_date(1789333661.25))->toBe('2026-09-13 21:07:41.250000')
        ->and(installer_microtime_date('1789333661.1235'))->toBe('2026-09-13 21:07:41.123500');
});

test('whole-second floats fall back to a six-digit fraction', function () {
    expect(installer_microtime_date(1789333661.0))->toBe('2026-09-13 21:07:41.000000')
        ->and(installer_microtime_date(1789333661.99996))->toBe('2026-09-13 21:07:41.999960');
});

test('stored settings strings parse whatever their precision', function () {
    expect(installer_microtime_date((string) 1789333661.0))->toBe('2026-09-13 21:07:41.000000')
        ->and(installer_microtime_date('1789333661.0000'))->toBe('2026-09-13 21:07:41.000000')
        ->and(installer_microtime_date('1789333661.1234567'))->toBe('2026-09-13 21:07:41.123457');
});

test('non-numeric values still return false', function () {
    expect(installer_microtime_date(''))->toBeFalse()
        ->and(installer_microtime_date('-b'))->toBeFalse()
        ->and(installer_microtime_date(false))->toBeFalse();
});

test('every value the old parse accepted keeps its output', function () {
    $changed = 0;

    for ($i = 0; $i < 20000; $i++) {
        $value = 1789333661 + $i / 20000;
        $old   = DateTime::createFromFormat('U.u', $value);

        if ($old !== false && $old->format('Y-m-d H:i:s.u') !== installer_microtime_date($value)) {
            $changed++;
        }
    }

    expect($changed)->toBe(0);
});
