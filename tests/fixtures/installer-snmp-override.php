<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
$source = file_get_contents($root . '/install/functions.php');
if ($source === false) {
	throw new RuntimeException('Installer persistence source is unavailable.');
}
eval(test_php_function_source($source, 'set_install_config_option'));

function log_install_high($section, $text, $background = false): void {}
function log_install_medium($section, $text, $background = false): void {}
function db_execute_prepared($sql, $parameters, $log = true, $connection = false): bool {
	return $connection->prepare($sql)->execute($parameters);
}

// These are the shipped settings column identities. The production setter
// and persistence helper execute unchanged SQL against this isolated database.
$local_db_cnn_id = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$local_db_cnn_id->exec("CREATE TABLE settings (name VARCHAR(255) NOT NULL PRIMARY KEY, value VARCHAR(4096) NOT NULL DEFAULT '')");
$local_db_cnn_id->exec("INSERT INTO settings VALUES ('install_automation_override', '1')");
require $root . '/lib/installer.php';
$installer = (new ReflectionClass(Installer::class))->newInstanceWithoutConstructor();
$field = new ReflectionProperty(Installer::class, 'automationOverride');
$field->setValue($installer, true);
$setter = new ReflectionMethod(Installer::class, 'setAutomationOverride');
$setter->invoke($installer, json_decode($argv[1], true, 4, JSON_THROW_ON_ERROR));
echo json_encode(array(
	'stored' => $local_db_cnn_id->query("SELECT value FROM settings WHERE name='install_automation_override'")->fetchColumn(),
	'field' => $field->getValue($installer),
), JSON_THROW_ON_ERROR), PHP_EOL;
