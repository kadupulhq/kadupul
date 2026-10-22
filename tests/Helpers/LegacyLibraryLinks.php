<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Native fixtures copy a lib/ file into a temporary directory so the child
 * runs a byte-identical copy. That copy resolves its own __DIR__ requires
 * inside the fixture, so a new require in the shipped file breaks every
 * fixture that copied it. Linking the lib/ files the fixture did not copy or
 * stub keeps those requires resolving. PHP reports a linked file's real path,
 * so the linked file's own relative requires resolve in the checkout.
 */
function link_legacy_library(string $root, string $directory): void
{
    $sources = glob($root . '/lib/*.php');
    if ($sources === false || $sources === []) {
        throw new RuntimeException('Cannot list legacy library files');
    }
    foreach ($sources as $source) {
        $target = $directory . '/' . basename($source);
        if (file_exists($target) || is_link($target)) {
            continue;
        }
        if (!symlink($source, $target)) {
            throw new RuntimeException('Cannot link legacy library file: ' . basename($source));
        }
    }
}
