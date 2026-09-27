<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use Kadupul\Graphing\Infrastructure\Rrd\LocalRrdtool;

test('local RRDtool leaves streams it does not own untouched', function () {
    $rrdtool = new LocalRrdtool();
    $stream = fopen('php://memory', 'r+');

    expect($rrdtool->close($stream))->toBeFalse()
        ->and($rrdtool->terminate($stream))->toBeFalse();

    $rrdtool->closeAll();

    expect(is_resource($stream))->toBeTrue();
    fclose($stream);
});
