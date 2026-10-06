<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Owned HTTP transport; the complete native controller fixture retains its
// documented authentication bootstrap, page chrome and database adapter ports.
$root = getenv('TREE_CONFIRMATION_ROOT');
$directory = getenv('TREE_CONFIRMATION_DIRECTORY');
if (!is_string($root) || !is_string($directory)) throw new RuntimeException('Missing owned tree transport configuration.');
$encoded = file_get_contents($directory . '/http/scenario.json');
if ($encoded === false) throw new RuntimeException('Cannot read owned tree scenario.');
$argv = ['tree-confirmation-native.php', $encoded, $directory];
if (getenv('TREE_CONFIRMATION_COVERAGE') === '1') $argv[] = 'coverage';
require $root . '/tests/Fixtures/tree-confirmation-native.php';
