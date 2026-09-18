<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace BoostArchiveRequeue;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/boost.php'), 'boost_requeue_archive'));
function db_execute($sql)
{
    $GLOBALS['requeue_statements'][] = $sql;
    return !str_starts_with($sql, $GLOBALS['requeue_fail'] ?? "\0");
}

beforeEach(function () {
    $GLOBALS['requeue_statements'] = array();
    $GLOBALS['requeue_fail'] = null;
});

test('retained archive samples return to the live queue before the archive is dropped', function () {
    expect(boost_requeue_archive('poller_output_boost_arch_1700000000'))->toBeTrue();
    $statements = $GLOBALS['requeue_statements'];
    expect($statements)->toHaveCount(2)
        ->and(preg_replace('/\s+/', ' ', $statements[0]))->toBe('INSERT IGNORE INTO poller_output_boost (local_data_id, rrd_name, time, output) SELECT local_data_id, rrd_name, time, output FROM `poller_output_boost_arch_1700000000`')
        ->and($statements[1])->toBe('DROP TABLE IF EXISTS `poller_output_boost_arch_1700000000`');
});

test('an archive is kept when its samples cannot be requeued or it cannot be dropped', function ($failing, $count) {
    $GLOBALS['requeue_fail'] = $failing;
    expect(boost_requeue_archive('poller_output_boost_arch_1700000000'))->toBeFalse()
        ->and($GLOBALS['requeue_statements'])->toHaveCount($count);
})->with(array('insert' => array('INSERT', 1), 'drop' => array('DROP', 2)));

test('names outside the archive namespace are never interpolated', function ($table) {
    expect(boost_requeue_archive($table))->toBeFalse()
        ->and($GLOBALS['requeue_statements'])->toBe(array());
})->with(array('poller_output_boost', 'poller_output_boost_arch_1`; DROP TABLE host; --', "poller_output_boost_arch_1\n"));
