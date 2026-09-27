<?php

declare(strict_types=1);

require '/var/www/html/cacti/include/global.php';
require_once '/var/www/html/cacti/lib/rrd.php';

$state = '/run/rrd-proxy-e2e';
$clientPublic = file_get_contents($state . '/client-public.pem');
$clientPrivate = file_get_contents($state . '/client-private.pem');
$proxyPublic = file_get_contents($state . '/proxy-public.pem');
if ($clientPublic === false || $clientPrivate === false || $proxyPublic === false) {
    fwrite(STDERR, "RRDtool proxy E2E key material is missing.\n");
    exit(1);
}

$fingerprint = rrdtool_proxy_cipher()->fingerprint($proxyPublic);
foreach (array(
    'rsa_public_key' => $clientPublic,
    'rsa_private_key' => $clientPrivate,
    'storage_location' => '0',
    'rrdp_server' => 'rrdproxy',
    'rrdp_port' => '40301',
    'rrdp_fingerprint' => $fingerprint,
    'rrdp_server_backup' => '',
    'rrdp_port_backup' => '40301',
    'rrdp_fingerprint_backup' => '',
) as $name => $value) {
    db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $value));
}

$templateName = 'RRDtool Proxy E2E Device Template';
if (!db_fetch_cell_prepared('SELECT id FROM host_template WHERE name = ?', array($templateName))) {
    db_execute_prepared('INSERT INTO host_template (hash, name, class) VALUES (?, ?, ?)', array(
        bin2hex(random_bytes(16)),
        $templateName,
        '',
    ));
}

fwrite(STDOUT, $fingerprint . "\n");
