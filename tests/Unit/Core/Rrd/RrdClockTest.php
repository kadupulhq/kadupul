<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/include/vendor/autoload.php';
require_once $projectRoot . '/lib/rrd.php';

test('RRD clock helper uses the supplied application clock', function () {
    $instant = new DateTimeImmutable('2026-09-25T12:34:56+00:00');
    $clock = new class ($instant) implements \Kadupul\Platform\Application\Port\Clock {
        public function __construct(private DateTimeImmutable $instant) {}

        public function now(): DateTimeImmutable
        {
            return $this->instant;
        }
    };

    expect(rrdtool_clock_now($clock))->toBe($instant);
});
