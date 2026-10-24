<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs a byte-identical copy of include/global.php against the scenario's
// config.php and prints $config as it stands when global.php includes
// lib/database.php, which is the first include after every path is set.
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
foreach (['include', 'lib'] as $subdirectory) {
    if (!mkdir($directory . '/' . $subdirectory, 0700)) {
        throw new RuntimeException('Cannot create owned bootstrap directory.');
    }
}
foreach (['global.php', 'runtime.php', 'cacti_version'] as $file) {
    $source = file_get_contents($root . '/include/' . $file);
    if (!is_string($source) || file_put_contents($directory . '/include/' . $file, $source) !== strlen($source)) {
        throw new RuntimeException('Cannot create exact bootstrap copy.');
    }
}
$configSource = '<?php ' . $scenario['config'];
if (file_put_contents($directory . '/include/config.php', $configSource) !== strlen($configSource)) {
    throw new RuntimeException('Cannot create owned config.php.');
}
$port = <<<'PHPPORT'
<?php
print json_encode($config, JSON_THROW_ON_ERROR);
exit;
PHPPORT;
if (file_put_contents($directory . '/lib/database.php', $port) !== strlen($port)) {
    throw new RuntimeException('Cannot create owned database boundary.');
}
if ($scenario['cli']) {
    define('CACTI_CLI_ONLY', true);
}
require $directory . '/include/global.php';
throw new RuntimeException('Bootstrap did not reach its database boundary.');
