<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Reuse the native harness utilities through the shared token-aware extractor.
$root = dirname(__DIR__, 2);
require_once $root . '/tests/Helpers/PhpSource.php';
$webSource = file_get_contents(__DIR__ . '/cdef_reference_web_installer_native_probe.php');
$cliSource = file_get_contents(__DIR__ . '/cdef_reference_installer_failure_native_probe.php');
if (!is_string($webSource) || !is_string($cliSource)) {
    throw new RuntimeException('The actual native installer harness sources are required.');
}
foreach (['installerAssert', 'installerSeed', 'webRequest', 'webToken', 'webLifecycleDiagnostics'] as $function) {
    eval(test_php_function_source($webSource, $function));
}
eval(test_php_function_source($cliSource, 'installerRun'));

$mode = $argv[1] ?? '';
$initialVersion = $argv[2] ?? '';
$lateDdl = ($argv[3] ?? '') === 'late-ddl';
$failureUpgrade = in_array($argv[3] ?? '', ['failure', 'late-ddl'], true);
if ($lateDdl && ($initialVersion !== '1.2.34' || getenv('KADUPUL_REFERENCE_OWNED_BACKEND') !== '1')) {
    throw new RuntimeException('Late DDL admission requires stored 1.2.34 and an explicitly exclusively owned backend.');
}
if (!in_array($mode, ['cli', 'web'], true) || !in_array($initialVersion, ['1.2.31', '1.2.32', '1.2.33', '1.2.34'], true)) {
    throw new RuntimeException('An explicit actual entrypoint and deployed starting version are required.');
}
function failureAssert(PDO $database, bool $lateDdl): void
{
    $index = $database->query("SHOW INDEX FROM data_input_data WHERE Key_name='data_input_field_id'")->fetch(PDO::FETCH_ASSOC);
    $column = $database->query("SHOW FULL COLUMNS FROM aggregate_graphs WHERE Field='created'")->fetch(PDO::FETCH_ASSOC);
    installerAssert(
        is_array($index) && $index['Column_name'] === ($lateDdl ? 'data_input_field_id' : 'data_template_data_id')
        && is_array($column) && str_contains(strtolower($column['Extra']), 'on update'),
        $lateDdl ? 'actual late ALTER denial retains committed index, historical ON UPDATE, and retry boundary'
            : 'actual entrypoint refuses conflicting index before mutating either repair contract'
    );
}

function repairAssert(PDO $database): void
{
    $indexes = $database->query("SHOW INDEX FROM data_input_data WHERE Key_name='data_input_field_id'")->fetchAll(PDO::FETCH_ASSOC);
    installerAssert(
        count($indexes) === 1 && $indexes[0]['Column_name'] === 'data_input_field_id'
        && (int) $indexes[0]['Non_unique'] === 1 && strtoupper($indexes[0]['Index_type']) === 'BTREE'
        && $indexes[0]['Sub_part'] === null && $indexes[0]['Collation'] === 'A',
        'actual entrypoint persists the exact nonunique full-column ascending BTREE index'
    );
    $created = $database->query("SHOW FULL COLUMNS FROM aggregate_graphs WHERE Field='created'")->fetch(PDO::FETCH_ASSOC);
    installerAssert(
        is_array($created) && $created['Null'] === 'NO'
        && in_array(strtolower($created['Type']), ['timestamp', 'timestamp(0)'], true)
        && in_array(strtolower($created['Default']), ['current_timestamp', 'current_timestamp()'], true)
        && !str_contains(strtolower($created['Extra']), 'on update'),
        'actual entrypoint removes ON UPDATE while preserving created timestamp default and nullability'
    );
    $database->exec("UPDATE aggregate_graphs SET title_format='retained creation time after repair' WHERE id=15000001");
    installerAssert(
        $database->query('SELECT created FROM aggregate_graphs WHERE id=15000001')->fetchColumn() === '2001-01-02 03:04:05',
        'actual repaired schema preserves stored creation time across a later aggregate update'
    );
    installerAssert(
        $database->query('SELECT cacti FROM version')->fetchColumn() === '1.2.35',
        'actual entrypoint publishes reachable forward version 1.2.35 after repair'
    );
}
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
$fixtureAccount = null;
$restoreTrust = null;
$probeFailure = null;
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
    $environment = getenv();
    $environment['KADUPUL_REFERENCE_RUNTIME_SCHEMA'] = $schema;
    $seedDatabase = $database;
    if ($lateDdl) {
        // The explicitly owned test backend may need its trigger-creation
        // prerequisite; capture and restore it without broad account grants.
        $features = $database->query('SELECT @@GLOBAL.log_bin AS binary_log, @@GLOBAL.log_bin_trust_function_creators AS trust')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($features) || !in_array((string) $features['binary_log'], ['0', '1'], true)
            || !in_array((string) $features['trust'], ['0', '1'], true)) {
            throw new RuntimeException('Owned backend trigger-creation features could not be confirmed.');
        }
        echo 'BACKEND binary_log=' . $features['binary_log'] . ' trust_function_creators=' . $features['trust'] . "\n";
        if ((int) $features['binary_log'] === 1 && (int) $features['trust'] === 0) {
            $restoreTrust = 0;
            $database->exec('SET GLOBAL log_bin_trust_function_creators=1');
            installerAssert(
                (string) $database->query('SELECT @@GLOBAL.log_bin_trust_function_creators')->fetchColumn() === '1',
                'exclusively owned backend admits native fixture trigger creation'
            );
        }
        // Account mutations are confined to the explicitly owned backend.
        $accountName = 'kadupul_sr_' . bin2hex(random_bytes(8));
        $accountPassword = bin2hex(random_bytes(24));
        $fixtureAccount = $database->quote($accountName) . "@'%'";
        try {
            $createAccount = $database->prepare('CREATE USER ' . $fixtureAccount . ' IDENTIFIED BY ?');
            $createAccount->execute([$accountPassword]);
            $database->exec("GRANT ALL PRIVILEGES ON `$schema`.* TO $fixtureAccount");
            $database->exec("GRANT SELECT ON mysql.time_zone_name TO $fixtureAccount");
        } catch (Throwable $error) {
            throw new RuntimeException('Owned backend fixture account privileges could not be established.');
        }
        $environment['KADUPUL_REFERENCE_TEST_USER'] = $accountName;
        $environment['KADUPUL_REFERENCE_TEST_PASSWORD'] = $accountPassword;
        $options = array_filter(
            explode(';', substr((string) getenv('KADUPUL_REFERENCE_TEST_DSN'), 6)),
            static fn(string $option): bool => preg_match('/^\s*dbname\s*=/i', $option) !== 1
        );
        $seedDsn = 'mysql:' . implode(';', $options) . ';dbname=' . $schema;
        $seedDatabase = new PDO($seedDsn, $accountName, $accountPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        installerAssert(
            $seedDatabase->query('SELECT DATABASE()')->fetchColumn() === $schema,
            'limited fixture seed connects directly to its exclusively owned schema'
        );
        $seedDatabase->exec("USE `$schema`");
    }
    installerSeed($seedDatabase, $root);
    // Preserve the actual earlier CDEF guards under the same installer actor.
    require_once $root . '/lib/cdef_reference.php';
    (new \Kadupul\Platform\Infrastructure\Legacy\CdefReferenceContract($seedDatabase, 1))->install();
    // Current source schema plus explicit historical drift: this is admission
    // evidence for stored versions, not a reconstruction of their whole schema.
    $setVersion = $database->prepare('UPDATE version SET cacti=?');
    $setVersion->execute([$initialVersion]);
    $database->exec('ALTER TABLE data_input_data DROP INDEX data_input_field_id');
    $database->exec('ALTER TABLE aggregate_graphs MODIFY created timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    if ($failureUpgrade && !$lateDdl) {
        $database->exec('ALTER TABLE data_input_data ADD INDEX data_input_field_id (data_template_data_id)');
    }
    $database->exec("INSERT INTO aggregate_graphs (id,aggregate_template_id,template_propogation,local_graph_id,title_format,graph_template_id,gprint_prefix,gprint_format,graph_type,total,total_type,total_prefix,order_type,created,user_id) VALUES (15000001,0,'',0,'historical creation time',0,'','',0,0,0,'',0,'2001-01-02 03:04:05',1)");
    if ($lateDdl) {
        $database->exec("REVOKE ALTER ON `$schema`.* FROM $fixtureAccount");
        $database->exec("GRANT ALTER ON `$schema`.data_input_data TO $fixtureAccount");
    }
    if ($mode === 'cli') {
        [$exit, $output, $errors] = installerRun($root, $environment, 'cli/upgrade_database.php', []);
        if ($failureUpgrade) {
            installerAssert(
                $exit !== 0 && $database->query('SELECT cacti FROM version')->fetchColumn() === '1.2.34',
                'actual CLI repair failure retains completed intermediate version and refuses final publication'
            );
            failureAssert($database, $lateDdl);
            if ($lateDdl) {
                $database->exec("GRANT ALTER ON `$schema`.aggregate_graphs TO $fixtureAccount");
            } else {
                $database->exec('ALTER TABLE data_input_data DROP INDEX data_input_field_id');
            }
            [$exit, $output, $errors] = installerRun($root, $environment, 'cli/upgrade_database.php', []);
        }
        installerAssert($exit === 0 && $errors === '', 'actual normal CLI upgrade completes without force or bypass');
        repairAssert($database);
        [$exit, $output, $errors] = installerRun($root, $environment, 'cli/upgrade_database.php', []);
        installerAssert($exit === 0 && $errors === '', 'actual normal CLI upgrade is idempotent at final version');
        repairAssert($database);
        echo "PASS actual forward schema cli admission and cleanup complete\n";
        return;
    }
    $runtimePhp = $database->prepare("REPLACE INTO settings (name,value) VALUES ('path_php_binary',?)");
    $runtimePhp->execute([PHP_BINARY]);
    $password = bin2hex(random_bytes(24));
    $statement = $database->prepare("UPDATE user_auth SET password=?, enabled='on', locked='', must_change_password='', password_change='' WHERE id=1");
    $statement->execute([password_hash($password, PASSWORD_DEFAULT)]);
    $database->exec("REPLACE INTO settings (name,value) VALUES ('auth_method','1')");
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
    $nextTemplates = null;
    $deadline = microtime(true) + 300;
    while (microtime(true) < $deadline) {
        $stepFields = ['Step' => $step, 'Eula' => 1, 'AutomationMode' => 0];
        if ($nextTemplates !== null) {
            $stepFields['Templates'] = $nextTemplates;
            $nextTemplates = null;
        }
        [$status, $body] = webRequest($base . 'step_json.php', $cookies, ['__csrf_magic' => $token,
            'data' => $stepFields]);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || !isset($data['Step'], $data['Next'])) {
            // Locate PHP output that corrupts the response without retaining
            // form values, credentials, tokens or exception arguments.
            $diagnostic = ['http_status' => $status, 'json_error' => json_last_error(), 'php_locations' => []];
            $plain = strip_tags(substr($body, 0, 8192));
            if (preg_match_all('/\bin ([^\r\n]+?\.php) on line ([0-9]+)/', $plain, $locations, PREG_SET_ORDER) !== false) {
                foreach (array_slice($locations, 0, 10) as $location) {
                    $file = realpath($location[1]);
                    if ($file !== false && str_starts_with($file, realpath($root) . DIRECTORY_SEPARATOR)) {
                        $diagnostic['php_locations'][] = ['file' => basename($file), 'line' => (int) $location[2]];
                    }
                }
            }
            echo 'WEB_RESPONSE_DIAGNOSTIC ' . json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
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
                (string) $database->query("SELECT value FROM settings WHERE name='install_error'")->fetchColumn() !== '',
                'actual web background upgrade reports schema repair failure'
            );
            installerAssert(
                $database->query('SELECT cacti FROM version')->fetchColumn() === '1.2.34',
                'failed actual web upgrade retains completed intermediate version and refuses final publication'
            );
            failureAssert($database, $lateDdl);
            if ($lateDdl) {
                $database->exec("GRANT ALTER ON `$schema`.aggregate_graphs TO $fixtureAccount");
            } else {
                $database->exec('ALTER TABLE data_input_data DROP INDEX data_input_field_id');
            }
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
        if ((int) $data['Step'] === 8) {
            // Exercise the actual template-selection handoff while keeping this
            // forward schema repair probe independent of importing every vendor package.
            $availableTemplates = $data['StepData']['Templates'] ?? null;
            $selectedTemplate = 'chk_template_Local_Linux_Machine_xml_gz';
            installerAssert(
                is_array($availableTemplates) && array_key_exists($selectedTemplate, $availableTemplates)
                && str_contains($data['Html'], $selectedTemplate),
                'actual web installer renders the selected Local Linux template'
            );
            $nextTemplates = array_fill_keys(array_keys($availableTemplates), false);
            $nextTemplates[$selectedTemplate] = true;
        }
        $step = (int) $data['Next']['Step'];
    }
    installerAssert(!$failureUpgrade || $repaired, 'upgrade failure fixture reaches real failure and repaired retry');
    installerAssert(isset($data) && (int) $data['Step'] === 98, 'actual web background Installer reaches completion');
    installerAssert(
        $database->query('SELECT cacti FROM version')->fetchColumn() === trim(file_get_contents($root . '/include/cacti_version')),
        'actual web Installer records the current version'
    );
    repairAssert($database);
    require_once $root . '/lib/cdef_reference.php';
    installerAssert(
        (new \Kadupul\Platform\Infrastructure\Legacy\CdefReferenceContract($seedDatabase, 1))->ready(),
        'actual web Installer installs the exact native CDEF contract and data readiness'
    );
    [$status, $body] = webRequest($base . 'install.php', $cookies);
    installerAssert(
        in_array($status, [200, 302], true) && !str_contains($body, 'login_username'),
        'completed actual web installer preserves authenticated already-current admission'
    );
    repairAssert($database);
    echo "PASS actual forward schema web admission and cleanup complete\n";
} catch (Throwable $error) {
    $probeFailure = $error;
    throw $error;
} finally {
    if ($created && $mode === 'web') {
        webLifecycleDiagnostics($database, $installerLogPath, $initialInstallerLogBytes);
    }
    $trustRestoreFailed = false;
    if ($restoreTrust !== null) {
        try {
            if ($database->exec('SET GLOBAL log_bin_trust_function_creators=' . $restoreTrust) === false
                || (string) $database->query('SELECT @@GLOBAL.log_bin_trust_function_creators')->fetchColumn() !== (string) $restoreTrust) {
                throw new RuntimeException('Owned backend trigger prerequisite restoration was not confirmed.');
            }
            echo "PASS exclusively owned backend restores its exact prior trigger prerequisite\n";
        } catch (Throwable $error) {
            $trustRestoreFailed = true;
            fwrite(STDERR, "FAIL exclusively owned backend trigger prerequisite restoration\n");
        }
    }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
    if ($fixtureAccount !== null) {
        $database->exec('DROP USER IF EXISTS ' . $fixtureAccount);
    }
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
    if ($trustRestoreFailed && $probeFailure === null) {
        throw new RuntimeException('Owned backend trigger prerequisite restoration failed after completed probe.');
    }
}
