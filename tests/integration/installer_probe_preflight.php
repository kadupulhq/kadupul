<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require dirname(__DIR__, 2) . '/lib/installer.php';

$class = new ReflectionClass('Installer');
$installer = $class->newInstanceWithoutConstructor();
$probe = $class->getMethod('probePhpBinary');
$probe->setAccessible(true);

foreach (array(2, 7, 64) as $input) {
    if ($probe->invoke($installer, PHP_BINARY, $input) !== (string) ($input * $input)) {
        throw new RuntimeException('Default CLI probe failed');
    }
}
$installer_allowed_php_binaries = array();
if ($probe->invoke($installer, PHP_BINARY, 7) !== false) {
    throw new RuntimeException('Empty allowlist did not fail closed');
}
$installer_allowed_php_binaries = array(PHP_BINARY);
if ($probe->invoke($installer, PHP_BINARY, 7) !== '49') {
    throw new RuntimeException('Explicit CLI allowlist failed');
}
echo "Installer probe preflight passed\n";
