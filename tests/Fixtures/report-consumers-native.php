<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[, $root, $directory, $input] = $argv;
$case = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
require_once $root . '/tests/Helpers/NativeReportConsumers.php';
require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
if (getenv('REPORT_CONSUMERS_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (['lib/html_reports.php', 'lib/database.php', 'lib/auth.php', 'lib/html_form.php'] as $source) $filter->includeFile($root . '/' . $source);
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/report-consumers-native.php', $input, NativeReportConsumers::sources());
    $coverage->start('native report consumers');
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $testLoader): void {
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $testLoader): void {
            if (($GLOBALS['nativeReportMarkers'] ?? []) !== NativeReportConsumers::markers()) throw new RuntimeException('Report outcomes did not complete');
            $testLoader->unregister();
            $testLoader->register(true);
            $coverage->stop();
            $serialized = serialize($coverage);
            $report = $directory . '/report.coverage';
            if (file_put_contents($report, $serialized) !== strlen($serialized)) throw new RuntimeException('Cannot preserve report coverage');
            NativeChildCoverageEvidence::write($report, $root, $snapshot, NativeReportConsumers::markers());
        });
    });
}
$definitions = require $root . '/tests/Fixtures/legacy-form-golden-scenarios.php';
$scenario = $definitions['pages']['reports_admin-edit'];
$scenario['page'] = 'reports_admin.php';
$scenario['request'] = $case['request'] + ['header' => 'false'];
if (isset($case['post'])) $scenario['request']['action'] = 'native_report_bootstrap';
if (file_put_contents($directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot preserve report request');
$database = NativeReportConsumers::database($root, $directory);
$insert = static function (string $table, array $row) use ($database): void {
    $database->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0,count($row),'?')) . ')')->execute(array_values($row));
};
foreach ([7,8] as $id) $insert('reports', ['id'=>$id,'user_id'=>$id===7 && ($case['mode']??'')==='own' ? 2 : 1,'name'=>'Report <' . $id . '>','email'=>($case['mode']??'')==='missing-email' ? '' : 'viewer@example.invalid','from_name'=>'Sender','from_email'=>'sender@example.invalid','bcc'=>'','enabled'=>($case['mode']??'')==='disable' ? 'on' : '']);
$insert('reports_items',['id'=>1,'report_id'=>7,'sequence'=>3,'item_type'=>1,'local_graph_id'=>200,'host_id'=>100,'graph_template_id'=>6,'font_size'=>12,'item_text'=>'stored <note>']);
$insert('reports_items',['id'=>99,'report_id'=>8,'sequence'=>17,'item_text'=>'Adjacent untouched']);
$insert('host',['id'=>100,'description'=>'Router <one>','hostname'=>'router.invalid','host_template_id'=>9,'site_id'=>2]);
$insert('host_template',['id'=>9,'name'=>'Device template <one>']);
$insert('sites',['id'=>2,'name'=>'Site <one>']);
$insert('graph_local',['id'=>200,'host_id'=>100,'graph_template_id'=>6]);
$insert('graph_templates',['id'=>6,'name'=>'Graph template <one>']);
$insert('graph_templates_graph',['id'=>200,'local_graph_id'=>200,'title_cache'=>'Graph <one>']);
$insert('graph_tree',['id'=>1,'name'=>'Tree <one>','enabled'=>'on']);
$insert('graph_tree_items',['id'=>3,'graph_tree_id'=>1,'host_id'=>100]);
$insert('user_auth',['id'=>1,'username'=>'admin','enabled'=>'on','reset_perms'=>0,'policy_trees'=>1,'policy_hosts'=>1,'policy_graphs'=>1,'policy_graph_templates'=>1]);
$insert('user_auth_realm',['realm_id'=>21,'user_id'=>1]);
$before = NativeReportConsumers::snapshot($database);
$expected = $before;
if (in_array($case['mode'] ?? '', ['enable','disable','own'], true)) {
    foreach ($expected['reports'] as &$row) {
        if ($row['id']===7) {
            if ($case['mode']==='own') $row['user_id']=1;
            else $row['enabled']=$case['mode']==='enable' ? 'on' : '';
        }
    } unset($row);
}
$writes = match($case['mode']??'') {
    'own'=>['UPDATE reports SET user_id = ? WHERE id = ?'],
    'enable'=>['UPDATE reports SET enabled="on" WHERE id = ?'],
    'disable'=>['UPDATE reports SET enabled="" WHERE id = ?'],
    default=>[],
};
$native = new NativeDeviceConnection($database, array_merge(NativeReportConsumers::extraTables(),['graph_templates_graph']), $writes);
$connection = new NativeReportConnection($native);
define('PRESENTATION_PAGE_NATIVE', true);
$GLOBALS['nativePresentationBootstrap'] = static function () use ($connection,$case): void {
    $GLOBALS['database_sessions']=array_fill_keys(array_keys($GLOBALS['database_sessions']),$connection);
    if (session_status()!==PHP_SESSION_ACTIVE && !session_start()) throw new RuntimeException('Cannot retain owned report session');
    if (isset($case['post'])) {
        $_SERVER['REQUEST_METHOD']='POST';
        $_POST=$case['post']+$case['request'];
        $_POST['__csrf_magic']=csrf_get_tokens();
        $_GET=[];
        foreach ($_POST as $name=>$value) {
            if (is_string($value) && str_starts_with($value,'REPORTS_') && defined($value)) $_POST[$name]=constant($value);
            set_request_var($name,$_POST[$name]);
        }
        cacti_require_post_actions(['save','actions','send']);
    }
};
$GLOBALS['nativePresentationObserver'] = static function (array &$result) use ($database,$expected,$native,$case): void {
    if (LegacyFormGoldenFiles::$transformedIncludes!==0) throw new RuntimeException('Report consumer executed transformed source');
    if ($result['diagnostics']!==[]) throw new RuntimeException('Report consumer emitted unexpected diagnostics: '.json_encode($result['diagnostics']));
    if ($expected!==NativeReportConsumers::snapshot($database)) throw new RuntimeException('Report consumer changed unexpected persisted or adjacent rows');
    $allowed=array_flip(NativeReportConsumers::sources());
    foreach(get_included_files() as $file) {
        $canonical=realpath($file);
        if ($canonical!==false && str_starts_with($canonical,$GLOBALS['root'].'/')) {
            $relative=substr($canonical,strlen($GLOBALS['root'])+1);
            if (!str_starts_with($relative,'include/vendor/') && !str_starts_with($relative,'tests/vendor/') && !isset($allowed[$relative])) throw new RuntimeException('Unregistered report consumer source: '.$relative);
        }
    }
    if($native->queries===[]) throw new RuntimeException('Report consumer did not query actual persisted records');
    $result['report_queries']=$native->queries;
    $result['report_state']=NativeReportConsumers::snapshot($database);
    $result['report_messages']=$_SESSION['sess_messages']??[];
    $result['report_errors']=$_SESSION['sess_error_fields']??[];
    $GLOBALS['nativeReportMarkers']=NativeReportConsumers::markers();
};
$argv=[__FILE__,$root,$directory];
require $root.'/tests/Fixtures/legacy-form-golden.php';
