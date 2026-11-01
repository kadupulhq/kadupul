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

/** Read successful controls from the shipped form, retaining duplicate hidden/checkbox order. */
function webInstallerForm(string $body, string $action): array
{
    $document = new DOMDocument();
    if (!$document->loadHTML($body, LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('Cannot parse the actual installer form.');
    }
    $xpath = new DOMXPath($document);
    $forms = $xpath->query('//form[input[@name="action"][@value="' . $action . '"]]');
    installerAssert($forms->length === 1, 'actual web installer renders the ' . $action . ' form');
    $form = $forms->item(0);
    installerAssert($form->getAttribute('method') === 'post' && $form->getAttribute('action') === '/fixture/install/install.php', 'actual web installer retains its local POST destination');
    $pairs = [];
    foreach ($xpath->query('.//input[@name] | .//select[@name]', $form) as $control) {
        $name = $control->getAttribute('name');
        if ($control->hasAttribute('disabled')) {
            continue;
        }
        if ($control->nodeName === 'select') {
            $options = $xpath->query('./option[@selected]', $control);
            $option = $options->length > 0 ? $options->item(0) : $xpath->query('./option', $control)->item(0);
            if ($option === null) {
                continue;
            }
            $value = $option->getAttribute('value');
        } else {
            if (in_array($control->getAttribute('type'), ['checkbox', 'radio'], true) && !$control->hasAttribute('checked')) {
                continue;
            }
            $value = $control->getAttribute('value');
        }
        $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
    }
    parse_str(implode('&', $pairs), $fields);
    return $fields;
}

function webInstallerReview(string $base, string $cookies, string $body): string
{
    $fields = webInstallerForm($body, 'configure');
    $selected = 'chk_template_Local_Linux_Machine_xml_gz';
    installerAssert(isset($fields['options']['Templates'][$selected]) && str_contains($body, $selected), 'actual web installer renders the selected Local Linux template');
    $fields['options']['Templates'] = array_fill_keys(array_keys($fields['options']['Templates']), '0');
    $fields['options']['Templates'][$selected] = '1';
    $fields['options']['Eula'] = '1';
    $fields['options']['AutomationMode'] = '0';
    [$status] = webRequest($base . 'install.php', $cookies, $fields);
    installerAssert($status === 303, 'actual web installer saves rendered configuration before review');
    [$status, $body] = webRequest($base . 'install.php', $cookies);
    installerAssert($status === 200 && str_contains($body, 'name="revision"'), 'actual web installer renders the saved reviewed revision');
    return $body;
}

function webInstallerStart(string $base, string $cookies, string $body, bool $retry): void
{
    $fields = webInstallerForm($body, $retry ? 'retry' : 'start');
    installerAssert(is_string($fields['revision'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $fields['revision']) === 1, 'actual web installer supplies a signed plan revision');
    $before = webInstallerStatus($base, $cookies);
    [$status] = webRequest($base . 'install.php', $cookies, $fields);
    installerAssert($status === 422, 'actual web installer requires explicit execution acknowledgement');
    $after = webInstallerStatus($base, $cookies);
    installerAssert($after['state'] === $before['state'] && $after['attempt'] === $before['attempt'] && $after['schema'] === $before['schema'], 'unacknowledged web execution does not reserve a worker or advance the schema');
    $fields['confirmed'] = '1';
    if ($retry) {
        $fields['retry_confirmed'] = '1';
    }
    [$status] = webRequest($base . 'install.php', $cookies, $fields);
    installerAssert($status === 303, 'actual web installer accepts the explicitly acknowledged reviewed plan');
}

function webInstallerStatus(string $base, string $cookies): array
{
    [$status, $body] = webRequest($base . 'install.php?view=status', $cookies);
    $state = json_decode($body, true);
    if ($status !== 200 || !is_array($state) || !is_string($state['state'] ?? null)
        || !is_int($state['progress'] ?? null) || !is_string($state['schema'] ?? null)) {
        throw new RuntimeException('The actual readonly installer status did not return its JSON contract: HTTP ' . $status . '.');
    }
    return $state;
}

/** Retain only non-secret worker state before the exclusively owned schema is removed. */
function webLifecycleDiagnostics(PDO $database, string $installerLogPath, int $initialInstallerLogBytes): void
{
    try {
        $keys = ['install_step', 'install_version', 'install_progress', 'install_started', 'install_updated', 'install_complete'];
        $statement = $database->prepare('SELECT name,value FROM settings WHERE name IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY name');
        $statement->execute($keys);
        $state = ['settings' => $statement->fetchAll(PDO::FETCH_KEY_PAIR),
            'processes' => $database->query("SELECT tasktype,taskname,taskid,pid,timeout,started,last_update FROM processes WHERE tasktype='install' AND taskname='master' AND taskid=0")->fetchAll(PDO::FETCH_ASSOC)];
        // Classify known lifecycle events without publishing raw installer logs,
        // request fields, configuration, credentials or exception arguments.
        $log = is_file($installerLogPath) ? file_get_contents($installerLogPath) : '';
        if ($log === false) {
            throw new RuntimeException('Cannot read owned installer lifecycle log.');
        }
        $log = substr($log, $initialInstallerLogBytes);
        $state['worker_registration_refused'] = str_contains($log, 'Old process still running and has not timed out!');
        $state['background_start_rejected'] = str_contains($log, 'Background was already started at');
        echo 'WEB_LIFECYCLE ' . json_encode($state, JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $diagnosticError) {
        // Preserve the original completion failure and allow owned cleanup.
        echo "WEB_LIFECYCLE unavailable\n";
    }
}

$failureUpgrade = ($argv[1] ?? '') === 'failure-upgrade';
$initialVersion = $failureUpgrade ? '1.2.33' : 'new_install';
$root = dirname(__DIR__, 2);
$currentVersionSource = file_get_contents($root . '/include/cacti_version');
if ($currentVersionSource === false) {
    throw new RuntimeException('The current installer version file could not be read.');
}
$currentVersion = trim($currentVersionSource);
if (strlen($currentVersion) > 32 || preg_match('/\A[0-9]+(?:\.[0-9]+){2}(?:[-a-zA-Z0-9]+)?\z/', $currentVersion) !== 1) {
    throw new RuntimeException('The current installer version has an unsupported format.');
}
$lastConfirmedVersion = '1.2.37';
if ($failureUpgrade) {
    installerAssert(version_compare($currentVersion, $lastConfirmedVersion, '>'), 'the final web version follows the admitted intermediate migration');
}
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
$installerLogPath = $root . '/log/cacti.log';
$initialInstallerLogBytes = 0;
try {
    $initialInstallerLogBytes = is_file($installerLogPath) ? filesize($installerLogPath) : 0;
    if ($initialInstallerLogBytes === false) {
        throw new RuntimeException('Cannot inspect owned installer log boundary.');
    }
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
    $environment['APP_SECRET'] = bin2hex(random_bytes(32));
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
    $body = webInstallerReview($base, $cookies, $body);
    webInstallerStart($base, $cookies, $body, false);
    $repaired = false;
    $deadline = microtime(true) + 300;
    while (microtime(true) < $deadline) {
        $data = webInstallerStatus($base, $cookies);
        echo 'WEB state=' . $data['state'] . ' progress=' . $data['progress'] . "\n";
        if ($data['state'] === 'succeeded') {
            break;
        }
        if ($data['state'] === 'failed' && $failureUpgrade && !$repaired) {
            installerAssert(
                $database->query("SELECT value FROM settings WHERE name='install_error'")->fetchColumn()
                === 'The primary CDEF reference contract could not be installed. Review the schema and installer privileges before retrying.',
                'actual web background upgrade reports native contract failure'
            );
            // The actual 1.2.37 migration is confirmed before the final CDEF
            // contract refuses 1.2.38; preserve that last successful marker.
            $failedVersion = $database->query('SELECT cacti FROM version')->fetchColumn();
            $observedVersion = is_string($failedVersion) && strlen($failedVersion) <= 32
                && preg_match('/\A[0-9]+(?:\.[0-9]+){2}\z/', $failedVersion) === 1 ? $failedVersion : 'unexpected';
            $versionDiagnostic = 'WEB_VERSION ' . json_encode(['initial' => $initialVersion,
                'confirmed' => $observedVersion, 'target' => $currentVersion], JSON_THROW_ON_ERROR) . "\n";
            if (fwrite(STDOUT, $versionDiagnostic) !== strlen($versionDiagnostic)) {
                throw new RuntimeException('Cannot preserve the sanitized web version diagnostic.');
            }
            installerAssert(
                $failedVersion === $lastConfirmedVersion,
                'failed actual web upgrade retains the last confirmed intermediate version'
            );
            $database->exec("DELETE FROM cdef_items WHERE cdef_id=15000001");
            $repaired = true;
            [$status, $body] = webRequest($base . 'install.php', $cookies);
            installerAssert(
                $status === 200 && !str_contains($body, 'login_username'),
                'repaired actual web upgrade restarts through existing authenticated installer'
            );
            $body = webInstallerReview($base, $cookies, $body);
            webInstallerStart($base, $cookies, $body, true);
            continue;
        }
        if (in_array($data['state'], ['failed', 'partial'], true)) {
            throw new RuntimeException('The actual web installer refused completion: ' . $data['state'] . '.');
        }
        usleep(250000);
    }
    installerAssert(!$failureUpgrade || $repaired, 'upgrade failure fixture reaches real failure and repaired retry');
    installerAssert(isset($data) && $data['state'] === 'succeeded' && $data['progress'] === 100, 'actual web background Installer reaches completion');
    installerAssert(
        $database->query('SELECT cacti FROM version')->fetchColumn() === $currentVersion,
        'actual web Installer records the current version'
    );
    installerAssert(
        (int) $database->query("SELECT COUNT(*) FROM host_template WHERE name = 'Local Linux Machine'")->fetchColumn() === 1,
        'actual web Installer imports the selected device template'
    );
    require $root . '/lib/cdef_reference.php';
    installerAssert(
        (new \Kadupul\Platform\Infrastructure\Legacy\CdefReferenceContract($database, 1))->ready(),
        'actual web Installer installs the exact native CDEF contract and data readiness'
    );
} finally {
    if ($created) {
        webLifecycleDiagnostics($database, $installerLogPath, $initialInstallerLogBytes);
    }
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
