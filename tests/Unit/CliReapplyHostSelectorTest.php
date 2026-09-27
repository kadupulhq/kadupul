<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 2) . '/lib/reapply_names.php';

test('reapply-name host selectors accept all and valid positive IDs', function () {
    expect(validate_reapply_host_selector('all'))->toBeTrue();
    expect(validate_reapply_host_selector('ALL'))->toBeTrue();
    expect(validate_reapply_host_selector('1'))->toBeTrue();
    expect(validate_reapply_host_selector('1,42,4294967295'))->toBeTrue();
});

test('reapply-name host selectors reject zero, malformed, empty, and out-of-range IDs', function () {
    foreach (['', '0', '1,0', '1,invalid', ' 1', '1,', '4294967296'] as $selector) {
        expect(validate_reapply_host_selector($selector))->toBeFalse();
    }
});
