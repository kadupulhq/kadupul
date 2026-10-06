<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[, $root, $directory, $input] = $argv;
$case = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
require_once $root . '/tests/Helpers/PresentationSinkContextEvidence.php';
require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
if (getenv('PRESENTATION_SINK_CONTEXT_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (['automation_snmp.php','color_templates.php','data_queries.php','data_sources.php','data_templates.php','lib/html.php','lib/html_utility.php','lib/html_validate.php','lib/database.php','lib/auth.php','lib/html_form.php'] as $source) $filter->includeFile($root . '/' . $source);
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/presentation-sink-context-native.php', $input, PresentationSinkContextEvidence::sources());
    $coverage->start('native presentation sink contexts');
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $testLoader): void {
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $testLoader): void {
            if (($GLOBALS['nativeSinkContextMarkers'] ?? []) !== PresentationSinkContextEvidence::markers()) throw new RuntimeException('Report outcomes did not complete');
            $testLoader->unregister();
            $testLoader->register(true);
            $coverage->stop();
            $serialized = serialize($coverage);
            $report = $directory . '/context.coverage';
            if (file_put_contents($report, $serialized) !== strlen($serialized)) throw new RuntimeException('Cannot preserve report coverage');
            NativeChildCoverageEvidence::write($report, $root, $snapshot, PresentationSinkContextEvidence::markers());
        });
    });
}
$definitions = require $root . '/tests/Fixtures/legacy-form-golden-scenarios.php';
$scenario = $definitions['pages']['automation_snmp-edit'];
$scenario['page'] = $case['page'];
$scenario['request'] = $case['request'] + ['header'=>'false'];
$scenario['settings']['drag_and_drop'] = 'on';
$scenario['settings']['auth_method'] = '0';
if (file_put_contents($directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot preserve actual presentation request');
$database = PresentationSinkContextEvidence::database($root, $directory);
$insert = static function (string $table, array $row) use ($database): void {
    $database->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0,count($row),'?')) . ')')->execute(array_values($row));
};
$rich = "Field ' \" & ` <label>";
$insert('snmp_query',['id'=>5,'name'=>'Query <label>','xml_path'=>'']);
$insert('snmp_query_graph',['id'=>7,'snmp_query_id'=>5,'graph_template_id'=>9,'name'=>'Associated <label>']);
$insert('graph_templates',['id'=>9,'name'=>'Graph <label>']);
$insert('data_template',['id'=>4,'name'=>'Data template <label>']);
$insert('data_template',['id'=>99,'name'=>'Adjacent unchanged']);
$insert('data_template_rrd',['id'=>20,'data_template_id'=>4,'local_data_id'=>0,'data_source_name'=>'first<label>']);
$insert('data_template_rrd',['id'=>21,'data_template_id'=>4,'local_data_id'=>0,'data_source_name'=>'second<label>']);
$insert('graph_templates_item',['id'=>10,'graph_template_id'=>9,'local_graph_id'=>0,'task_item_id'=>20]);
$insert('snmp_query_graph_rrd',['snmp_query_graph_id'=>7,'data_template_id'=>4,'data_template_rrd_id'=>20,'snmp_field_name'=>'value']);
foreach ([1,2] as $id) $insert('snmp_query_graph_sv',['id'=>$id,'snmp_query_graph_id'=>7,'sequence'=>$id,'field_name'=>$rich,'text'=>'Value <label>']);
foreach ([3,4] as $id) $insert('snmp_query_graph_rrd_sv',['id'=>$id,'snmp_query_graph_id'=>7,'data_template_id'=>4,'sequence'=>$id-2,'field_name'=>$rich,'text'=>'DS Value <label>']);
$insert('automation_snmp',['id'=>7,'name'=>'SNMP <label>']);
$insert('automation_snmp_items',['id'=>1,'snmp_id'=>7,'sequence'=>1,'snmp_community'=>'fixture-community']);
$insert('colors',['id'=>1,'hex'=>'123456']);
$insert('color_templates',['color_template_id'=>7,'name'=>'Palette <label>']);
$insert('color_template_items',['color_template_id'=>7,'color_template_item_id'=>1,'sequence'=>1,'color_id'=>1]);
$insert('data_template_data',['id'=>40,'data_template_id'=>4,'local_data_id'=>0,'name'=>'Data <label>','name_cache'=>'Data <label>','active'=>'on']);
$insert('data_template_data',['id'=>41,'data_template_id'=>4,'local_data_id'=>7,'name'=>'Local <label>','name_cache'=>'Local <label>','active'=>'on']);
if ($case['page']!=='data_templates.php') $insert('data_local',['id'=>7,'host_id'=>0,'data_template_id'=>4]);
$insert('user_auth',['id'=>1,'username'=>'admin','enabled'=>'on','reset_perms'=>0,'policy_hosts'=>1,'policy_graphs'=>1,'policy_graph_templates'=>1,'policy_trees'=>1]);
$before = PresentationSinkContextEvidence::snapshot($database);
$native = new NativeDeviceConnection($database, PresentationSinkContextEvidence::extraTables());
define('PRESENTATION_PAGE_NATIVE', true);
$GLOBALS['nativePresentationBootstrap'] = static function () use ($native,$case): void {
    $GLOBALS['database_sessions']=array_fill_keys(array_keys($GLOBALS['database_sessions']),new NativePresentationContextConnection($native));
    if (isset($case['filter'])) {
        $spec=$case['filter'];
        if ($spec['present']) set_request_var('_native_identifier',$spec['value']);
        else unset_request_var('_native_identifier');
        $mode=$spec['mode']==='numeric-array' ? FILTER_VALIDATE_IS_NUMERIC_ARRAY : FILTER_VALIDATE_INT;
        $options=$spec['options']??[];
        if (isset($options['flags']) && is_string($options['flags'])) $options['flags']=constant($options['flags']);
        $GLOBALS['nativeFilterValue']=get_filter_request_var('_native_identifier',$mode,$options);
        $GLOBALS['nativeFilterPresent']=isset_request_var('_native_identifier');
    }

};
$GLOBALS['nativePresentationObserver'] = static function (array &$result) use ($database,$before,$native,$case): void {
    if (LegacyFormGoldenFiles::$transformedIncludes!==0) throw new RuntimeException('Presentation context used transformed production source');
    if ($result['diagnostics']!==[]) throw new RuntimeException('Unexpected presentation diagnostics: '.json_encode($result['diagnostics']));
    if ($before!==PresentationSinkContextEvidence::snapshot($database)) throw new RuntimeException('Presentation context changed persisted or adjacent rows');
    $allowed=array_flip(PresentationSinkContextEvidence::sources());
    foreach (get_included_files() as $file) {
        $canonical=realpath($file);
        if ($canonical!==false && str_starts_with($canonical,$GLOBALS['root'].'/')) {
            $relative=substr($canonical,strlen($GLOBALS['root'])+1);
            if (!str_starts_with($relative,'include/vendor/') && !str_starts_with($relative,'tests/vendor/') && !isset($allowed[$relative])) throw new RuntimeException('Unregistered actual presentation context source: '.$relative);
        }
    }
    // Existing validation footer reads display preferences, never module records.
    $preferenceReads=['SELECT value FROM settings WHERE name = ?', 'SELECT value FROM settings_user WHERE name = ? AND user_id = ?', "SELECT value FROM settings_user WHERE name='selected_theme' AND user_id = ?"];
    $moduleQueries=array_values(array_filter($native->queries,static fn(string $sql): bool=>!in_array($sql,$preferenceReads,true)));
    $invalid=$case['invalid']??false;
    if ($invalid) {
        if (!str_contains($result['html'],'Validation error for variable') || $moduleQueries!==[]) throw new RuntimeException('Malformed identifier outcome: ' . json_encode(['queries'=>$native->queries,'validation'=>str_contains($result['html'],'Validation error for variable')]));
    } elseif (isset($case['request']['id']) && $case['request']['id']!=='0' && $native->queries===[]) throw new RuntimeException('Admitted presentation did not read actual persisted records');
    if (isset($case['filter'])) {
        $result['filter_value']=$GLOBALS['nativeFilterValue'];
        $result['filter_present']=$GLOBALS['nativeFilterPresent'];
    }
    $result['queries']=$native->queries;
    $result['module_queries']=$moduleQueries;
    $result['persisted_state']=PresentationSinkContextEvidence::snapshot($database);
    $GLOBALS['nativeSinkContextMarkers']=PresentationSinkContextEvidence::markers();
};
$argv=[__FILE__,$root,$directory];
require $root.'/tests/Fixtures/legacy-form-golden.php';
