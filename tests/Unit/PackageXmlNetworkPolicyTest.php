<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$importSource = file_get_contents(__DIR__ . '/../../lib/import.php');

test('package XML parsers deny network access', function () use ($importSource) {
    expect($importSource)->toContain('simplexml_load_string($data, null, LIBXML_NONET)');
    expect($importSource)->toContain('simplexml_load_string($xml, null, LIBXML_NONET)');
    expect($importSource)->not->toContain('simplexml_load_string($data);');
    expect($importSource)->not->toContain('simplexml_load_string($xml);');
});
