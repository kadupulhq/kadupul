<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// This probe executes real CLI entrypoints in a copied candidate, using the
// credential-free fixture config. Never point it at an existing installation.
function installerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

function installerSeed(PDO $database, string $root, ?string $schemaFile = null): void
{
    $delimiter = ';';
    $buffer = '';
    $source = file_get_contents($schemaFile ?? $root . '/cacti.sql');
    // The old source has an SPDX block followed by an unused DELIMITER //
    // directive, while all its statements still end with semicolons. Preserve
    // its actual SQL; current source's END$$ trigger boundaries stay effective.
    $source = preg_replace('/\A\s*\/\*.*?\*\/\s*/s', '', $source, 1);
    $terminalSource = preg_replace('/^DELIMITER \S+\h*$/m', '', $source);
    foreach (explode("\n", $source) as $line) {
        if (preg_match('/^DELIMITER (\S+)\s*$/', trim($line), $matches)) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('Incomplete source schema statement at delimiter change.');
            }
            $declared = $matches[1];
            $delimiter = preg_match('/' . preg_quote($declared, '/') . '\h*$/m', $terminalSource) === 1 ? $declared : ';';
            continue;
        }
        if (str_starts_with(ltrim($line), '--') || trim($line) === '') {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($buffer), $delimiter)) {
            $sql = substr(rtrim($buffer), 0, -strlen($delimiter));
            $database->exec($sql);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        throw new RuntimeException('Incomplete source schema statement after seed.');
    }
}

function webRequest(string $url, string $cookies, ?array $fields = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookies,
        CURLOPT_COOKIEJAR => $cookies, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
    if ($fields !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if (!is_string($body)) {
        throw new RuntimeException('The fixture HTTP request failed with curl code ' . curl_errno($curl) . '.');
    }

    return [$status, $body];
}

function webToken(string $body): string
{
    if (preg_match('/name=["\x27]__csrf_magic["\x27]\s+value=["\x27]([^"\x27]+)/', $body, $match)
        || preg_match('/csrfMagicToken\s*=\s*["\x27]([^"\x27]+)/', $body, $match)) {
        return html_entity_decode($match[1], ENT_QUOTES);
    }
    throw new RuntimeException('The real rendered form did not supply a CSRF token.');
}

$failureUpgrade = ($argv[1] ?? '') === 'failure-upgrade';
$initialVersion = $failureUpgrade ? '1.2.33' : 'new_install';
$root = dirname(__DIR__, 2);
if (!is_file($root . '/.cdef-reference-task-owned-candidate')
    || hash_file('sha256', $root . '/include/config.php') !== hash_file('sha256', $root . '/tests/Fixtures/cdef-reference-runtime-config.php')) {
    throw new RuntimeException('An exact marked task-owned installer candidate is required.');
}
$database = new PDO(getenv('KADUPUL_REFERENCE_TEST_DSN'), getenv('KADUPUL_REFERENCE_TEST_USER'), getenv('KADUPUL_REFERENCE_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'kadupul_cdef_install_' . bin2hex(random_bytes(8));
$directory = sys_get_temp_dir() . '/cdef-web-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$cookies = $directory . '/cookies';
touch($cookies);
chmod($cookies, 0600);
$created = false;
$server = null;
try {
    echo 'SERVER ' . $database->query('SELECT VERSION()')->fetchColumn() . "\n";
    $database->exec("CREATE DATABASE `$schema`");
    $created = true;
    $database->exec("USE `$schema`");
    installerSeed($database, $root);
    if ($failureUpgrade) {
        $database->exec("UPDATE version SET cacti='1.2.33'");
        $database->exec("INSERT INTO cdef_items (hash,cdef_id,sequence,type,value) VALUES('" . str_repeat('f', 32) . "',15000001,1,1,'1')");
    }
    $runtimePhp = $database->prepare("REPLACE INTO settings (name,value) VALUES ('path_php_binary',?)");
    $runtimePhp->execute([PHP_BINARY]);
    $password = bin2hex(random_bytes(24));
    $statement = $database->prepare("UPDATE user_auth SET password=?, enabled='on', locked='', must_change_password='', password_change='' WHERE id=1");
    $statement->execute([password_hash($password, PASSWORD_DEFAULT)]);
    $database->exec("REPLACE INTO settings (name,value) VALUES ('auth_method','1')");
    $environment = getenv();
    $environment['KADUPUL_REFERENCE_RUNTIME_SCHEMA'] = $schema;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($socket)) {
        throw new RuntimeException('Cannot allocate fixture HTTP listener.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open(
        [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'zend.exception_ignore_args=1',
            '-S', $address, $root . '/tests/Fixtures/cdef-reference-web-router.php'],
        [['pipe', 'r'], ['file', $directory . '/server.log', 'a'], ['file', $directory . '/server.log', 'a']],
        $pipes,
        $root,
        $environment
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Cannot start the real-entrypoint fixture HTTP server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    installerAssert($ready, 'fixture server starts without authentication bypass');
    $base = 'http://' . $address . '/fixture/install/';
    [$status] = webRequest($base . 'step_json.php', $cookies);
    installerAssert($status === 405, 'actual web installer rejects GET mutations');
    [$status] = webRequest($base . 'step_json.php', $cookies, ['data' => ['Step' => 97]]);
    installerAssert($status === 403, 'actual web installer rejects missing CSRF before authentication');
    [$status, $body] = webRequest($base . 'install.php', $cookies);
    installerAssert($status === 200 && str_contains($body, 'login_username'), 'actual installer renders normal anonymous login form');
    $token = webToken($body);
    [$status, $anonymousBody] = webRequest($base . 'step_json.php', $cookies, ['__csrf_magic' => $token, 'data' => ['Step' => 97]]);
    $anonymous = json_decode($anonymousBody, true);
    installerAssert(
        $status === 200 && is_array($anonymous) && ($anonymous['status'] ?? null) === '500'
        && !isset($anonymous['Step']) && $database->query('SELECT cacti FROM version')->fetchColumn() === $initialVersion,
        'actual web installer refuses anonymous valid-CSRF mutation without starting installation'
    );
    [$status] = webRequest($base . 'install.php', $cookies, ['action' => 'login', 'login_username' => 'admin',
        'login_password' => $password, '__csrf_magic' => $token]);
    installerAssert($status === 302, 'actual local authentication establishes installer session');
    [$status, $body] = webRequest($base . 'install.php', $cookies);
    installerAssert($status === 200 && !str_contains($body, 'login_username'), 'authenticated installer page uses genuine cookie session');
    $token = webToken($body);
    $step = 1;
    $repaired = false;
    $deadline = microtime(true) + 300;
    while (microtime(true) < $deadline) {
        [$status, $body] = webRequest($base . 'step_json.php', $cookies, ['__csrf_magic' => $token,
            'data' => ['Step' => $step, 'Eula' => 1, 'AutomationMode' => 0]]);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || !isset($data['Step'], $data['Next'])) {
            throw new RuntimeException('The actual web step did not return its JSON contract: HTTP ' . $status . '.');
        }
        echo 'WEB step=' . (int) $data['Step'] . ' next=' . (int) $data['Next']['Step'] . ' enabled=' . (int) $data['Next']['Enabled'] . "\n";
        if ((int) $data['Step'] === 98) {
            break;
        }
        if ((int) $data['Step'] === 97) {
            $step = 97;
            usleep(250000);
            continue;
        }
        if ((int) $data['Step'] === 99 && $failureUpgrade && !$repaired) {
            installerAssert(
                $database->query("SELECT value FROM settings WHERE name='install_error'")->fetchColumn()
                === 'The primary CDEF reference contract could not be installed. Review the schema and installer privileges before retrying.',
                'actual web background upgrade reports native contract failure'
            );
            installerAssert(
                $database->query('SELECT cacti FROM version')->fetchColumn() === '1.2.33',
                'failed actual 1.2.33 web upgrade retains retryable previous version'
            );
            $database->exec("DELETE FROM cdef_items WHERE cdef_id=15000001");
            $repaired = true;
            [$status, $body] = webRequest($base . 'install.php', $cookies);
            installerAssert(
                $status === 200 && !str_contains($body, 'login_username'),
                'repaired actual web upgrade restarts through existing authenticated installer'
            );
            $token = webToken($body);
            $step = 1;
            continue;
        }
        if ((int) $data['Step'] === 99 || (!$data['Next']['Enabled'] && !in_array((int) $data['Step'], [6, 10], true))) {
            echo 'WEB errors=' . json_encode($data['Errors']) . "\n";
            throw new RuntimeException('The actual web installer refused progression at step ' . (int) $data['Step'] . '.');
        }
        // Steps 6 and 10 render real confirmation checkboxes; the browser
        // enables their existing Next button, without a new request field.
        if (in_array((int) $data['Step'], [6, 10], true)) {
            installerAssert(str_contains($data['Html'], 'id="confirm"'), 'actual web installer renders explicit acknowledgement at step ' . (int) $data['Step']);
        }
        $step = (int) $data['Next']['Step'];
    }
    installerAssert(!$failureUpgrade || $repaired, 'upgrade failure fixture reaches real failure and repaired retry');
    installerAssert(isset($data) && (int) $data['Step'] === 98, 'actual web background Installer reaches completion');
    installerAssert(
        $database->query('SELECT cacti FROM version')->fetchColumn() === trim(file_get_contents($root . '/include/cacti_version')),
        'actual web Installer records the current version'
    );
    require $root . '/lib/cdef_reference.php';
    installerAssert(
        (new \Kadupul\Platform\Infrastructure\Legacy\CdefReferenceContract($database, 1))->ready(),
        'actual web Installer installs the exact native CDEF contract and data readiness'
    );
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
