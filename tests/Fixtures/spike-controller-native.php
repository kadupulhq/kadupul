<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** The processor port records handoff; existing RRD tests exercise the actual engine. */
final class spikekill
{
    public bool $dryrun = false;
    public bool $html = false;
    private array $arguments;

    public function __construct(mixed ...$arguments)
    {
        $this->arguments = $arguments;
    }

    public function remove_spikes(): bool
    {
        $statement = $GLOBALS['db']->prepare('INSERT INTO spike_processor_calls(arguments,dryrun,html) VALUES(?,?,?)');
        $statement->execute([json_encode($this->arguments, JSON_THROW_ON_ERROR), (int) $this->dryrun, (int) $this->html]);
        return !($GLOBALS['scenario']['processor_failure'] ?? false);
    }

    public function get_output(): string
    {
        return 'native processor output';
    }

    public function get_errors(): string
    {
        return 'native processor refusal';
    }
}

function get_data_source_path(mixed $id, bool $expand): string
{
    $GLOBALS['spikePathCalls'][] = [$id, $expand];
    $statement = $GLOBALS['db']->prepare('SELECT path FROM spike_source_paths WHERE local_data_id=?');
    $statement->execute([$id]);
    return (string) $statement->fetchColumn();
}

function __(string $message, mixed ...$arguments): string
{
    return $arguments ? vsprintf($message, $arguments) : $message;
}

/** Native validation renderer uses these owned presentation/diagnostic ports. */
function __esc(string $message, mixed ...$arguments): string
{
    return htmlspecialchars(__($message, ...$arguments), ENT_QUOTES);
}

function get_client_addr(): string
{
    return '192.0.2.42';
}

function cacti_debug_backtrace(string $message, bool $display): void
{
    $GLOBALS['spikeValidationDiagnostics'][] = [$message, $display];
}

function bottom_footer(): void
{
    $GLOBALS['spikeValidationFooter']++;
}

function csrf_startup(): void
{
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'owned-spike-controller-test-secret');
}

function spike_controller_fixture_run(): never
{
    global $db, $scenario, $root, $config;
    require $root . '/include/vendor/csrf/csrf-magic.php';
    require $root . '/lib/html_utility.php';
    require $root . '/lib/html_validate.php';
    require_once $root . '/tests/Helpers/PhpSource.php';
    $htmlSource = file_get_contents($root . '/lib/html.php');
    if (!is_string($htmlSource)) throw new RuntimeException('Cannot read native HTML escaping source.');
    eval(test_php_function_source($htmlSource, 'html_escape_charset'));
    eval(test_php_function_source($htmlSource, 'html_escape'));
    $GLOBALS['spikeValidationDiagnostics'] = [];
    $GLOBALS['spikeValidationFooter'] = 0;
    require $root . '/include/global_constants.php';
    $db->exec("INSERT INTO user_auth_realm VALUES(42,1043);
        INSERT INTO host(id,description,host_template_id) VALUES(110,'spike host',120),(111,'denied host',121);
        INSERT INTO graph_templates VALUES(120,'spike graph template'),(121,'denied graph template');
        INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(100,110,120),(101,111,121),(102,110,120);
        INSERT INTO graph_templates_graph(local_graph_id,title_cache,width,height) VALUES(100,'owned graph',1,1),(101,'denied graph',1,1),(102,'empty graph',1,1);
        INSERT INTO user_auth_perms VALUES(42,1,101),(42,3,111),(42,4,121);
        CREATE TABLE data_template_rrd(id INTEGER PRIMARY KEY,local_data_id INTEGER);
        CREATE TABLE graph_templates_item(id INTEGER PRIMARY KEY,local_graph_id INTEGER,task_item_id INTEGER);
        CREATE TABLE spike_source_paths(local_data_id INTEGER PRIMARY KEY,path TEXT);
        CREATE TABLE spike_processor_calls(arguments TEXT,dryrun INTEGER,html INTEGER);
        INSERT INTO data_template_rrd VALUES(200,300),(201,0);
        INSERT INTO graph_templates_item VALUES(400,100,200),(401,100,200),(402,102,201),(403,101,200);
        INSERT INTO spike_source_paths VALUES(300,'/owned/spike-source.rrd');");
    session_id('owned-spike-controller-test');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_REQUEST = ['local_graph_id' => $scenario['graph_id'] ?? '100'];
    if ($scenario['omit_graph_id'] ?? false) unset($_REQUEST['local_graph_id']);
    foreach (['method','dryrun','avgnan','outlier-start','outlier-end'] as $field) {
        if (array_key_exists($field, $scenario)) $_REQUEST[$field] = $scenario[$field];
    }
    $_POST = $_REQUEST;
    $_POST['__csrf_magic'] = csrf_get_tokens();
    $GLOBALS['spikePathCalls'] = [];
    $GLOBALS['spikeLookupParameters'] = [];
    $directory = sys_get_temp_dir() . '/spike-controller-cwd-' . bin2hex(random_bytes(8));
    foreach (['','/include','/lib'] as $suffix) {
        if (!mkdir($directory . $suffix, 0700)) throw new RuntimeException('Cannot create owned controller fixture.');
    }
    foreach (['include/auth.php','lib/spikekill.php'] as $path) {
        if (file_put_contents($directory . '/' . $path, '<?php // Native policy is loaded; processor uses the owned port.') === false) {
            throw new RuntimeException('Cannot create controller dependency port.');
        }
    }
    $previous = getcwd();
    if (!chdir($directory)) throw new RuntimeException('Cannot enter owned controller fixture.');
    $config['base_path'] = $directory;
    ob_start();
    register_shutdown_function(static function () use ($directory, $previous, $db): void {
        $response = ob_get_clean();
        $calls = $db->query('SELECT arguments,dryrun,html FROM spike_processor_calls')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($calls as &$call) {
            $call['arguments'] = json_decode($call['arguments'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($call);
        $state = ['status' => http_response_code() ?: 200, 'response' => $GLOBALS['spikeValidationDiagnostics'] === [] ? json_decode($response, true, 512, JSON_THROW_ON_ERROR) : null, 'raw_response' => $response,
            'validation_diagnostics' => $GLOBALS['spikeValidationDiagnostics'], 'validation_footer' => $GLOBALS['spikeValidationFooter'],
            'lookup_parameters' => $GLOBALS['spikeLookupParameters'], 'path_calls' => $GLOBALS['spikePathCalls'], 'processor_calls' => $calls,
            'policy_rows' => $db->query('SELECT user_id,type,item_id FROM user_auth_perms')->fetchAll(PDO::FETCH_ASSOC)];
        $GLOBALS['nativeChildCoverageMarkers'] = SpikeControllerCoverageRegistration::MARKERS;
        if ($GLOBALS['spikeValidationDiagnostics'] !== []) $GLOBALS['nativeChildCoverageMarkers'][] = 'actual-spike-validation-rendered';
        if (!chdir($previous)) throw new RuntimeException('Cannot restore controller fixture directory.');
        foreach (['include/auth.php','lib/spikekill.php'] as $path) unlink($directory . '/' . $path);
        rmdir($directory . '/include');
        rmdir($directory . '/lib');
        rmdir($directory);
        print json_encode($state, JSON_THROW_ON_ERROR);
    });
    require $root . '/spikekill.php';
    exit;
}
