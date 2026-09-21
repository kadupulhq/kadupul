<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Run from an extracted release, ideally in a container with --network none.
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = getcwd();
$kernel = require $root . '/config/bootstrap.php';
$response = $kernel->handle(Request::create('/healthz'));
if ($response->getStatusCode() !== 200 || $response->getContent() !== '{"status":"ok"}') {
    throw new RuntimeException('Offline Symfony boot failed');
}
$response = $kernel->handle(Request::create('/session'));
if ($response->getStatusCode() !== 401 || !is_file($root . '/app.php')) {
    throw new RuntimeException('Offline identity bridge missing or not closed to unauthenticated requests');
}
$response = $kernel->handle(Request::create('/inventory/devices'));
if ($response->getStatusCode() !== 401 || !is_file($root . '/templates/inventory/devices.html.twig')) {
    throw new RuntimeException('Offline Inventory route or template missing');
}
$response = $kernel->handle(Request::create('/inventory/devices/1/edit'));
if ($response->getStatusCode() !== 401 || !is_file($root . '/templates/inventory/edit.html.twig') || !is_file($root . '/bin/legacy-device-edit.php')) {
    throw new RuntimeException('Offline Inventory edit route, template or worker missing');
}
$response = $kernel->handle(Request::create('/inventory/devices.csv'));
if ($response->getStatusCode() !== 401) {
    throw new RuntimeException('Offline Inventory CSV route missing or not protected');
}
$response = $kernel->handle(Request::create('/inventory/sites/new'));
if ($response->getStatusCode() !== 401 || !is_file($root . '/templates/inventory/site_create.html.twig')) {
    throw new RuntimeException('Offline site creation route or template missing');
}
$kernel->shutdown();
foreach (['Kadupul\\Inventory\\Application\\Query\\ListDevices', 'Kadupul\\Inventory\\Infrastructure\\Symfony\\Controller\\DeviceListController'] as $class) {
    if (!class_exists($class)) {
        throw new RuntimeException('Missing Inventory module: ' . $class);
    }
}
if (!is_file($root . '/include/admin_notifications.php')) {
    throw new RuntimeException('Offline administrator notification bridge missing');
}
foreach (['HTMLPurifier', 'phpseclib4\\Crypt\\RSA', 'Symfony\\Component\\Mailer\\Mailer', 'Kadupul\\Alerting\\Infrastructure\\Symfony\\TestMailCommand', 'Kadupul\\Alerting\\Infrastructure\\Legacy\\AdministratorNotificationBridge'] as $class) {
    if (!class_exists($class)) {
        throw new RuntimeException('Missing production dependency: ' . $class);
    }
}
$manifest = json_decode(file_get_contents($root . '/tools/dependencies/legacy-files.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['files'] as $file => $digest) {
    if (!is_file($root . '/' . $file) || hash_file('sha256', $root . '/' . $file) !== $digest) {
        throw new RuntimeException('Offline compatibility dependency mismatch: ' . $file);
    }
}
foreach (['include/js/purify.js', 'include/js/jquery-ui.js', 'include/js/d3.js', 'include/fa/css/all.css', 'include/vendor/flag-icons/flags/4x3/us.svg'] as $file) {
    if (!is_file($root . '/' . $file) || filesize($root . '/' . $file) === 0) {
        throw new RuntimeException('Missing offline asset: ' . $file);
    }
}
foreach (['node_modules', 'include/vendor/phpunit', 'include/config.php', 'include/vendor/csrf/csrf-secret.php'] as $path) {
    if (file_exists($root . '/' . $path)) {
        throw new RuntimeException('Development dependency or installation state in bundle: ' . $path);
    }
}
echo "Offline framework, PHP dependencies, compatibility files and browser assets verified.\n";
