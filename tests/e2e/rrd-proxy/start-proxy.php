<?php

declare(strict_types=1);

require '/opt/rrdproxy/vendor/autoload.php';

use phpseclib4\Crypt\RSA;

$state = '/run/rrd-proxy-e2e';
$clientPublic = file_get_contents($state . '/client-public.pem');
$proxyPublic = file_get_contents($state . '/proxy-public.pem');
$proxyPrivate = file_get_contents($state . '/proxy-private.pem');
if ($clientPublic === false || $proxyPublic === false || $proxyPrivate === false) {
    fwrite(STDERR, "RRDtool proxy E2E key material is missing.\n");
    exit(1);
}

$include = '/opt/rrdproxy/include';
file_put_contents($include . '/public.key', $proxyPublic);
file_put_contents($include . '/private.key', $proxyPrivate);
file_put_contents($include . '/clients', '<?php $rrdp_remote_clients = ' . var_export(array(
    '172.29.241.10' => RSA::loadPublicKey($clientPublic)->getFingerprint('md5'),
), true) . ';');

$config = array(
    'version' => '1.2.17',
    'name' => 'rrdp-e2e',
    'ip_version' => 4,
    'address_4' => '0.0.0.0',
    'address_6' => '::',
    'port_client' => 40301,
    'port_server' => 40302,
    'port_admin' => 40303,
    'max_admin_cnn' => 5,
    'remote_cnn_timeout' => 30,
    'logging_size_buffered' => 10000,
    'logging_size_snmp' => 10000,
    'logging_severity_buffered' => 5,
    'logging_severity_snmp' => 5,
    'logging_severity_terminal' => 0,
    'logging_severity_console' => 0,
    'logging_category_console' => 'all',
    'logging_category_terminal' => 'all',
    'path_rra' => '/opt/rrdproxy/rra',
    'path_rra_archive' => '',
    'path_rrdtool' => '/usr/bin/rrdtool',
    'path_rrdcached' => '',
    'rrdcache_update_cycle' => 600,
    'rrdcache_update_delay' => 0,
    'rrdcache_life_cycle' => 7200,
    'rrdcache_write_threads' => 4,
    'enable_password' => '',
    'ipv6' => false,
    'slave' => '',
);
file_put_contents($include . '/config', '<?php $rrdp_config = ' . var_export($config, true) . ';');

chdir('/opt/rrdproxy');
// The upstream process detector sees this wrapper's shell command as another
// proxy process, so force the startup check inside this isolated container.
passthru(PHP_BINARY . ' ./rrdtool-proxy.php -f -s', $exitCode);
if ($exitCode !== 0) {
    exit($exitCode);
}

// rrdproxy forks its master and exits its launcher. Keep the container's PID 1
// alive while that master runs, as systemd would for the upstream service.
while (true) {
    exec("ps -eo args= | grep -E '[p]hp \\.\/rrdtool-proxy\\.php -f -s'", $processes);
    if ($processes === array()) {
        break;
    }
    sleep(1);
}
