<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class DataDebugNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;
    #[\PHPUnit\Framework\Attributes\DataProvider('listCases')]
    public function testFullControllerKeepsScopedPersistedChecks(array $request, array $ids): void
    {
        $state = $this->render($request);
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        $actual = array();
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $id = (int) substr($row->getAttribute('id'), 4);
            $actual[] = $id;
            self::assertSame('data_debug.php?action=view&id=' . $id, $xpath->query('./td[1]/a', $row)->item(0)->getAttribute('href'));
            self::assertCount(0, $xpath->query('.//script', $row));
        }
        self::assertSame($ids, $actual);
        self::assertSame($state['before'], $state['after']);
        self::assertSame('preserved', $state['session']['sentinel']);
        self::assertSame((string) ($request['rows'] ?? -1), $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame('Two & more', $xpath->query('//select[@id="rows"]/option[@value="2"]')->item(0)->textContent);
        self::assertSame('Default & profile', $xpath->query('//select[@id="profile"]/option[@value="1"]')->item(0)->textContent);
        self::assertSame('Template & <script>', $xpath->query('//select[@id="template_id"]/option[@value="10"]')->item(0)->textContent);
        $reads = array_values(array_filter($state['queries'], static fn(array $q): bool => str_contains($q[0], 'FROM data_local AS dl')));
        self::assertCount(2, $reads);
        self::assertStringContainsString('SELECT COUNT(*)', $reads[0][0]);
        self::assertStringContainsString('SELECT dd.*', $reads[1][0]);
        if ($ids === array()) {
            self::assertStringContainsString('No Checks', $document->textContent);
        }
    }

    public static function listCases(): array
    {
        return array(
            'default page' => array(array(), array(101, 102)),
            'next page' => array(array('page' => 2), array(103)),
            'all rows' => array(array('rows' => 10), array(101, 102, 103)),
            'site device template profile' => array(array('site_id' => 1, 'host_id' => 1, 'template_id' => 10, 'profile' => 1), array(101, 102)),
            'inactive checks' => array(array('status' => 2), array(103)),
            'regex title' => array(array('rfilter' => 'Alpha', 'rows' => 1), array(101)),
            'empty regex' => array(array('rfilter' => 'missing'), array()),
            'no-debug empty' => array(array('debug' => 0), array()),
            'only debugging' => array(array('debug' => 1, 'rows' => 10), array(101, 102, 103)),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusCases')]
    public function testRealStatusAndDetailedCheckRendering(string $status, string $heading, string $result): void
    {
        $state = $this->render(array('action' => 'view', 'id' => 101), array('state' => $status));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        self::assertStringContainsString($heading, $document->textContent);
        self::assertStringContainsString('RRDfile Owner', $document->textContent);
        self::assertStringContainsString('native owner', $document->textContent);
        self::assertStringContainsString($result, $document->textContent);
        self::assertCount(14, $xpath->query('//tr[starts-with(@id,"line") and not(contains(@id,"_"))]'));
        self::assertSame($state['before'], $state['after']);
        $queries = array_values(array_filter($state['queries'], static fn(array $q): bool => str_contains($q[0], 'WHERE datasource = ?')));
        self::assertCount(3, $queries);
        foreach ($queries as $query) {
            self::assertSame(array(101), $query[1]);
        }
    }

    public static function statusCases(): array
    {
        return array(
            'waiting' => array('waiting', 'Auto Refreshing till Complete', 'Waiting on analysis and RRDfile update'),
            'analysis' => array('analysis', 'Auto Refreshing till RRDfile Update', 'Waiting on analysis and RRDfile update'),
            'complete' => array('complete', 'Analysis Complete!', 'Rerun Analysis'),
            'failed' => array('failed', 'Analysis Complete!', 'Polling issue'),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mutationCases')]
    public function testDebugMutationsKeepUnselectedPersistedChecks(string $operation): void
    {
        $request = $operation === 'runall' ? array('action' => 'runall', 'host_id' => 1) : array();
        $state = $this->render($request, array('operation' => $operation));
        $before = array_column($state['before']['data_debug'], null, 'datasource');
        $after = array_column($state['after']['data_debug'], null, 'datasource');
        self::assertSame($before[103], $after[103]);
        foreach ($state['before'] as $table => $rows) {
            if ($table !== 'data_debug') {
                self::assertSame($rows, $state['after'][$table]);
            }
        }
        if ($operation === 'delete') {
            self::assertArrayNotHasKey(101, $after);
            self::assertSame($before[102], $after[102]);
        } else {
            foreach ($operation === 'runall' ? array(101, 102) : array(101) as $id) {
                self::assertSame(0, $after[$id]['done']);
                self::assertGreaterThan($before[$id]['started'], $after[$id]['started']);
                $info = unserialize($after[$id]['info'], array('allowed_classes' => false));
                self::assertSame('', $info['last_result']);
                self::assertSame('', $info['rrd_match']);
            }
            if ($operation === 'rerun') {
                self::assertSame($before[102], $after[102]);
            }
        }
        self::assertSame('preserved', $state['session']['sentinel']);
    }

    public static function mutationCases(): array
    {
        return array('existing rerun' => array('rerun'), 'delete' => array('delete'), 'filtered run all' => array('runall'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cleanerCases')]
    public function testCleanerFullListKeepsRowsAndUnusedFileScope(array $request, array $names): void
    {
        $state = $this->render($request, array('view' => 'cleaner'));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        $actual = array();
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $actual[] = trim($xpath->query('./td[1]', $row)->item(0)->textContent);
        }
        self::assertSame($names, $actual);
        self::assertSame('Two & more', $xpath->query('//select[@id="rows"]/option[@value="2"]')->item(0)->textContent);
        self::assertSame((string) ($request['rows'] ?? -1), $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame($state['before'], $state['after']);
        self::assertSame($state['log_before'], $state['log_after']);
        $reads = array_values(array_filter($state['queries'], static fn(array $q): bool => str_contains($q[0], 'FROM data_source_purge_temp AS rc')));
        self::assertCount(3, $reads);
        foreach ($reads as $read) {
            self::assertStringContainsString('WHERE in_cacti=0', $read[0]);
        }
    }

    public static function cleanerCases(): array
    {
        return array('default rows' => array(array(), array('one.rrd', 'two.rrd')), 'selected rows' => array(array('rows' => 2), array('one.rrd', 'two.rrd')), 'filtered file' => array(array('filter' => 'one'), array('one.rrd')), 'empty result' => array(array('filter' => 'missing'), array()));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('iconCases')]
    public function testLoadedProductionStatusHelpersNameEachResult(mixed $result, string $status, string $valid): void
    {
        $state = $this->render(array(), array('view' => 'debug-icons', 'result' => $result));
        foreach (array('status' => $status, 'valid' => $valid) as $kind => $label) {
            $document = new DOMDocument();
            self::assertTrue($document->loadHTML($state['icons'][$kind], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
            $icons = (new DOMXPath($document))->query('//i[@role="img"]');
            self::assertCount(1, $icons);
            self::assertSame($label, $icons->item(0)->getAttribute('aria-label'));
            self::assertFalse($icons->item(0)->hasAttribute('aria-hidden'));
        }
        self::assertSame($state['before'], $state['after']);
        self::assertSame($state['log_before'], $state['log_after']);
        self::assertNotEmpty($state['queries']);
    }

    public static function iconCases(): array
    {
        return array(
            'empty pending' => array('', 'Running', 'Running'),
            'false pending' => array(false, 'Running', 'Running'),
            'not applicable' => array('-', 'Not Applicable', 'Not Applicable'),
            'valid values' => array(array('in' => '42', 'out' => '12.5'), 'Warning', 'Passed'),
            'invalid value' => array(array('in' => '42', 'out' => 'invalid'), 'Warning', 'Failed'),
            'valid scalar' => array('42', 'Warning', 'Passed'),
            'invalid scalar' => array('invalid', 'Warning', 'Failed'),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('purgePolicyCases')]
    public function testPurgeUsesActualPolicyAndPreservesForeignChecks(array $policy, array $remaining): void
    {
        $state = $this->render(['purge' => '1'], ['debug_purge_policy' => $policy]);
        $before = array_column($state['before']['data_debug'], null, 'datasource');
        $after = array_column($state['after']['data_debug'], null, 'datasource');
        self::assertSame([101,102,103,105,106,999], array_keys($before));
        self::assertSame($remaining, array_keys($after));
        foreach ($after as $id => $row) {
            self::assertSame($before[$id], $row);
        }
        foreach ($state['before'] as $table => $rows) {
            if ($table !== 'data_debug') {
                self::assertSame($rows, $state['after'][$table], $table);
            }
        }
        self::assertSame('preserved', $state['session']['sentinel']);
    }

    public static function purgePolicyCases(): iterable
    {
        yield 'administrator with restricted graph-device policy can clear orphan checks' => [['admin' => true, 'exceptions' => [1]], []];
        yield 'unrestricted device policy can clear unassigned and orphan checks' => [['policy_hosts' => 1], []];
        yield 'authentication disabled retains full purge' => [['auth_method' => 0], []];
        yield 'unrestricted user with no devices can clear unfinished orphans' => [['policy_hosts' => 1, 'empty_hosts' => true], []];
        yield 'restricted operator removes only permitted device checks' => [['exceptions' => [1]], [103,105,106,999]];
        yield 'default allow with denied device remains scoped' => [['policy_hosts' => 1, 'exceptions' => [2]], [103,105,106,999]];
        yield 'restricted operator without allowed devices removes nothing' => [[], [101,102,103,105,106,999]];
    }

    public function testDataSourceGuardUsesPersistedPolicyIncludingDisabledAndInvalidHostIdentity(): void
    {
        $state = $this->render([], ['debug_purge_policy' => ['exceptions' => [2], 'null_host' => true], 'dsdebug_ids' => [101,103,105,106,107,999]]);
        self::assertSame([101 => false, 103 => true, 105 => false, 106 => false, 107 => false, 999 => false], $state['dsdebug_admission']);
        self::assertSame($state['before'], $state['after']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scopedDebugListCases')]
    public function testDebugListAndCountFollowActualManagementPolicy(array $policy, array $request, array $ids): void
    {
        $state = $this->render($request + ['rows' => 10], ['debug_purge_policy' => $policy]);
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        $actual = [];
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) $actual[] = (int) substr($row->getAttribute('id'), 4);
        self::assertSame($ids, $actual);
        self::assertSame([count($ids)], $state['debug_counts']);
        self::assertSame($state['before'], $state['after']);
    }

    public static function scopedDebugListCases(): iterable
    {
        yield 'Any includes only owned-device data sources' => [['exceptions' => [1]], [], [101,102]];
        yield 'explicit foreign device is empty' => [['exceptions' => [1]], ['host_id' => 2], []];
        yield 'no permitted devices is empty' => [[], [], []];
        yield 'hide disabled does not deny permitted disabled device' => [['exceptions' => [2]], ['host_id' => 2], [103]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deniedDebugRequestCases')]
    public function testDirectDebugRequestsDenyBeforePersistedChanges(string $action, int $id, string $message): void
    {
        $state = $this->render(['action' => $action, 'id' => $id], ['debug_purge_policy' => ['exceptions' => [1]], 'debug_non_rendering' => true, 'debug_rejects_before_policy' => in_array($id, [0,105,999], true)]);
        self::assertSame($state['before'], $state['after']);
        self::assertArrayHasKey($message, $state['session']['sess_messages']);
        $writes = array_filter($state['queries'], static fn(array $query): bool => preg_match('/^(DELETE|INSERT|UPDATE)\s/i', $query[0]) === 1);
        self::assertSame([], array_values($writes));
    }

    public static function deniedDebugRequestCases(): iterable
    {
        yield 'foreign view' => ['view',103,'debug_access_denied'];
        yield 'zero view' => ['view',0,'debug_access_denied'];
        yield 'missing view' => ['view',999,'debug_access_denied'];
        yield 'unassigned view' => ['view',105,'debug_access_denied'];
        yield 'foreign rerun' => ['run_debug',103,'repair_error'];
        yield 'foreign repair' => ['run_repair',103,'repair_error'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('debugBulkCases')]
    public function testActualDebugBulkDispatchPreservesForeignChecks(int $action, bool $mixed): void
    {
        $request = ['action' => 'actions', 'save_list' => 'on', 'drp_action' => (string) $action, 'chk_103' => 'on'];
        if ($mixed) $request['chk_101'] = 'on';
        $state = $this->render($request, ['debug_purge_policy' => ['exceptions' => [1]], 'method' => 'POST', 'csrf_valid' => true]);
        $before = array_column($state['before']['data_debug'], null, 'datasource');
        $after = array_column($state['after']['data_debug'], null, 'datasource');
        foreach ([102,103,105,106,999] as $id) self::assertSame($before[$id], $after[$id]);
        if (!$mixed) self::assertSame($before, $after);
        elseif ($action === 2) self::assertArrayNotHasKey(101, $after);
        else {
            self::assertSame(0, $after[101]['done']);
            self::assertGreaterThan($before[101]['started'], $after[101]['started']);
        }
    }

    public static function debugBulkCases(): iterable
    {
        yield 'mixed delete' => [2,true];
        yield 'mixed rerun' => [1,true];
        yield 'all foreign delete' => [2,false];
        yield 'all foreign rerun' => [1,false];
    }

    public function testFilterUsesPolicySqlWithoutHydratingDeviceInventory(): void
    {
        $state = $this->render([], ['debug_purge_policy' => ['exceptions' => [1]], 'filter_probe' => true]);
        $hydration = array_filter($state['filter_probe'], static fn(array $query): bool => str_contains($query[0], 'SELECT h1.*'));
        self::assertCount(0, $hydration);
        self::assertSame($state['before'], $state['after']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('debugBatchSizes')]
    public function testBulkAuthorizationHasBoundedSelectionQueriesAtSupportedBatchLimits(int $count): void
    {
        $request = ['action' => 'actions', 'save_list' => 'on', 'drp_action' => '2'];
        for ($index = 0; $index < $count; $index++) $request['chk_' . (20000 + $index)] = 'on';
        $state = $this->render($request, ['debug_purge_policy' => ['exceptions' => [1], 'batch_count' => $count], 'method' => 'POST', 'csrf_valid' => true]);
        $individual = array_filter($state['queries'], static fn(array $query): bool => str_contains($query[0], 'SELECT host_id') && str_contains($query[0], 'FROM data_local'));
        self::assertCount(0, $individual);
        $batched = array_filter($state['queries'], static fn(array $query): bool => str_starts_with($query[0], 'SELECT id FROM data_local WHERE id IN ('));
        self::assertCount((int) ceil($count / 1000), $batched);
        self::assertCount($count + 6, $state['before']['data_debug']);
        self::assertCount(6, $state['after']['data_debug']);
        self::assertSame(array_slice($state['before']['data_debug'], 0, 6), $state['after']['data_debug']);
        $writes = array_filter($state['queries'], static fn(array $query): bool => preg_match('/^DELETE\s+FROM data_debug/', $query[0]) === 1);
        self::assertCount($count, $writes);
    }

    public static function debugBatchSizes(): iterable
    {
        yield 'fifty records' => [50];
        yield 'one thousand and one crosses a chunk boundary' => [1001];
        yield 'maximum supported ten thousand records' => [10000];
    }

    public function testRenderedCheckboxHandoffDeletesOnlyPersistedPermittedChecks(): void
    {
        $options = ['debug_purge_policy' => ['exceptions' => [1]]];
        $list = $this->render(['rows' => 10], $options);
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($list['html'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $request = ['action' => 'actions','save_list' => 'on','drp_action' => '2'];
        foreach ((new DOMXPath($document))->query('//input[starts-with(@name,"chk_")]') as $input) $request[$input->getAttribute('name')] = 'on';
        self::assertArrayHasKey('chk_101', $request);
        self::assertArrayHasKey('chk_102', $request);
        self::assertArrayNotHasKey('chk_103', $request);
        $state = $this->render($request, $options + ['method' => 'POST','csrf_valid' => true]);
        self::assertSame([103,105,106,999], array_column($state['after']['data_debug'], 'datasource'));
    }

    public function testAdmittedDirectViewPersistsActualNewCheckHandoff(): void
    {
        $state = $this->render(['action' => 'view','id' => 104], ['debug_purge_policy' => ['exceptions' => [2]]]);
        self::assertCount(1, $state['debug_saves']);
        $save = $state['debug_saves'][0];
        self::assertSame(104, (int) $save['datasource']);
        self::assertSame(99, $save['user']);
        self::assertIsArray(unserialize($save['info'], ['allowed_classes' => false]));
        $after = array_column($state['after']['data_debug'], null, 'datasource');
        self::assertSame(99, $after[104]['user']);
        self::assertSame(0, $after[104]['done']);
        self::assertSame($save['info'], $after[104]['info']);
        foreach ($state['before']['data_debug'] as $row) self::assertSame($row, $after[$row['datasource']]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('actualPolicyRunAllCases')]
    public function testRunAllUsesPersistedPolicyAndPreservesForeignChecks(array $policy, array $changed): void
    {
        $state = $this->render(['action' => 'runall','rows' => 10], ['debug_purge_policy' => $policy]);
        $before = array_column($state['before']['data_debug'], null, 'datasource');
        $after = array_column($state['after']['data_debug'], null, 'datasource');
        foreach ($before as $id => $row) {
            if (in_array($id, $changed, true)) {
                self::assertSame(99, $after[$id]['user']);
                self::assertGreaterThan($row['started'], $after[$id]['started']);
            } else self::assertSame($row, $after[$id]);
        }
        if (in_array(104, $changed, true)) self::assertSame(99, $after[104]['user']);
        else self::assertArrayNotHasKey(104, $after);
    }

    public static function actualPolicyRunAllCases(): iterable
    {
        yield 'permitted first device only' => [['exceptions' => [1]], [101,102]];
        yield 'disabled device remains authorized' => [['exceptions' => [2]], [103]];
        yield 'no permitted devices writes no checks' => [[], []];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unconfirmedPolicyCases')]
    public function testBulkPolicyFailureAndRevocationPreventDebugWrites(array $options): void
    {
        $state = $this->render(['action' => 'actions','save_list' => 'on','drp_action' => '2','chk_101' => 'on'], ['debug_purge_policy' => ['exceptions' => [1]],'method' => 'POST','csrf_valid' => true] + $options);
        self::assertSame($state['before']['data_debug'], $state['after']['data_debug']);
        $writes = array_filter($state['queries'], static fn(array $query): bool => preg_match('/^(DELETE|INSERT|UPDATE)\s/i', $query[0]) === 1);
        self::assertSame([], array_values($writes));
        if ($options['debug_policy_failure'] ?? false) self::assertArrayHasKey('debug_access_denied', $state['session']['sess_messages']);
        else self::assertSame(1, array_column($state['after']['user_auth'], null, 'id')[99]['reset_perms']);
    }

    public static function unconfirmedPolicyCases(): iterable
    {
        yield 'read unavailable' => [['debug_policy_failure' => true]];
        yield 'permission generation changes during read' => [['debug_policy_revocation' => true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('directMutationFailureCases')]
    public function testDirectMutationReadFailurePrecedesResetAndSuccessMessage(string $action, int $id): void
    {
        $state = $this->render(['action' => $action,'id' => $id], ['debug_purge_policy' => ['exceptions' => [2]], 'debug_policy_failure' => true, 'debug_non_rendering' => true]);
        self::assertSame($state['before'], $state['after']);
        self::assertArrayHasKey('debug_access_denied', $state['session']['sess_messages']);
        self::assertArrayNotHasKey('rerun', $state['session']['sess_messages']);
        self::assertArrayNotHasKey('repair', $state['session']['sess_messages']);
        $writes = array_filter($state['queries'], static fn(array $query): bool => preg_match('/^(DELETE|INSERT|UPDATE)\s/i', $query[0]) === 1);
        self::assertSame([], array_values($writes));
    }

    public static function directMutationFailureCases(): iterable
    {
        yield 'rerun must not reset before policy confirmation' => ['run_debug',103];
        yield 'repair must not start before policy confirmation' => ['run_repair',103];
        yield 'creating a check must not reset before policy confirmation' => ['view',104];
    }

    public function testCurrentPolicyFailureKeepsRealHttpDenialLocationAndMessage(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/debug-denial-http-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $process = null;
        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
            self::assertIsResource($socket, $error);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $environment = array_merge(getenv(), ['DEBUG_DENIAL_ROOT' => $root, 'DEBUG_DENIAL_DIRECTORY' => $directory]);
            $process = proc_open(
                [PHP_BINARY, '-d','auto_prepend_file=', '-d','error_reporting=24575', '-d','session.save_path=' . $directory, '-S',$address, $root . '/tests/Fixtures/debug-denial-native-router.php'],
                [0 => ['pipe','r'],1 => ['file',$directory . '/stdout.log','w'],2 => ['file',$directory . '/stderr.log','w']],
                $pipes,
                $directory,
                $environment
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $prior = null;
                $prior = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$prior): bool {
                    if ($severity === E_WARNING && str_starts_with($message, 'fsockopen(): Unable to connect') && str_contains($message, '(Connection refused)')) return true;
                    return $prior === null ? false : (bool) $prior($severity, $message, $file, $line);
                });
                try {
                    $probe = fsockopen('tcp://' . $address, -1, $code, $error, 0.1);
                } finally {
                    restore_error_handler();
                }
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($ready, file_get_contents($directory . '/stderr.log'));
            foreach (self::directMutationFailureCases() as [$action,$id]) {
                $scenario = ['view' => 'debug','request' => ['action' => $action,'id' => $id], 'debug_purge_policy' => ['exceptions' => [2]], 'debug_policy_failure' => true, 'debug_non_rendering' => true];
                file_put_contents($directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));
                $context = stream_context_create(['http' => ['follow_location' => 0,'ignore_errors' => true,'timeout' => 5]]);
                $body = file_get_contents('http://' . $address . '/data_debug.php?action=' . $action . '&id=' . $id, false, $context);
                self::assertNotFalse($body);
                self::assertStringContainsString(' 302 ', $http_response_header[0]);
                self::assertSame(['Location: data_debug.php?header=false'], array_values(array_filter($http_response_header, static fn(string $header): bool => str_starts_with($header, 'Location:'))));
                $state = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame($state['before'], $state['after']);
                self::assertArrayHasKey('debug_access_denied', $state['session']['sess_messages']);
                self::assertArrayNotHasKey('rerun', $state['session']['sess_messages']);
                self::assertArrayNotHasKey('repair', $state['session']['sess_messages']);
                unlink($directory . '/fixture/lib');
                unlink($directory . '/fixture/include/auth.php');
                rmdir($directory . '/fixture/include');
                foreach (glob($directory . '/fixture/*') as $file) unlink($file);
                rmdir($directory . '/fixture');
            }
            self::assertDoesNotMatchRegularExpression('/PHP (Warning|Fatal error|Notice):/', file_get_contents($directory . '/stderr.log'));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            if (is_link($directory . '/fixture/lib')) unlink($directory . '/fixture/lib');
            foreach (glob($directory . '/fixture/include/*') as $file) unlink($file);
            if (is_dir($directory . '/fixture/include')) rmdir($directory . '/fixture/include');
            foreach (glob($directory . '/fixture/*') as $file) unlink($file);
            if (is_dir($directory . '/fixture')) rmdir($directory . '/fixture');
            foreach (glob($directory . '/*') as $file) unlink($file);
            rmdir($directory);
        }
    }

    private function render(array $request, array $options = array()): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/manager-view-' . bin2hex(random_bytes(8));
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $scenario = json_encode(array_merge(array('view' => 'debug', 'request' => $request), $options), JSON_THROW_ON_ERROR);
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/utility-view-native.php', $scenario, $directory);
        if ($coverage !== null) {
            $command[] = 'coverage';
        }
        try {
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error . $output);
            self::assertSame('', $error);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                // Independent complete inventory: original nine RRD defaults,
                // real utility filter files and every executable fixture dependency.
                $sources = array('config/icons.json', 'src/Platform/Contract/IconRegistry.php', 'composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'tests/Unit/UtilityViewNativeCoverageTest.php', 'tests/Unit/DataDebugNativeCoverageTest.php', 'tests/Fixtures/data-debug-records.php', 'utilities.php', 'data_debug.php', 'rrdcleaner.php', 'lib/html.php', 'lib/html_utility.php', 'lib/functions.php', 'lib/clog_webapi.php', 'src/Platform/Infrastructure/Legacy/UtilityRows.php', 'include/global_constants.php', 'include/global_session.php', 'lib/html_form.php', 'lib/variables.php', 'src/Platform/Infrastructure/Legacy/HostDataSubstitution.php', 'lib/utility.php', 'tests/Fixtures/debug-purge-policy.php', 'lib/auth.php', 'include/csrf.php', 'tests/Helpers/PhpSource.php', 'lib/html_validate.php', 'cacti.sql', 'tests/Fixtures/debug-denial-native-router.php');
                $markers = array('utility-view-observed:' . ($options['view'] ?? 'debug'));
                if (($options['view'] ?? 'debug') === 'debug-icons') {
                    $markers[] = 'debug-icons-rendered';
                }
                $nonRendering = ($options['debug_non_rendering'] ?? false) || (isset($options['debug_purge_policy']) && ($request['action'] ?? '') === 'actions');
                $hits = array(($options['view'] ?? 'debug') === 'cleaner' ? 'rrdcleaner.php' : 'data_debug.php', 'lib/functions.php');
                if (!$nonRendering) $hits[] = 'lib/html.php';
                else $markers[] = 'debug-controller-outcome-observed';
                if (isset($options['debug_purge_policy']) && !($options['debug_rejects_before_policy'] ?? false)) {
                    $hits[] = 'lib/auth.php';
                }
                $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/utility-view-native.php', $scenario, $sources, $markers, $hits);
                static $omissionsVerified = array();
                $mode = ($options['debug_rejects_before_policy'] ?? false) ? 'debug-early-denial' : ($nonRendering ? 'debug-controller-outcome' : (isset($options['debug_purge_policy']) ? 'debug-purge-policy' : ($options['view'] ?? 'debug')));
                if (!isset($omissionsVerified[$mode])) {
                    self::assertSame(49 + count($markers), NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/utility-view-native.php', $scenario, $sources, $markers, $hits, 'lib/boost.php'));
                    $omissionsVerified[$mode] = true;
                }
                $coverage->merge($child);
            }
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage*') as $report) {
                unlink($report);
            }
            foreach (array('cacti.log', 'cacti.log-20260930', 'stderr.log') as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }
            if (is_link($directory . '/lib')) {
                unlink($directory . '/lib');
            }
            if (is_file($directory . '/include/auth.php')) {
                unlink($directory . '/include/auth.php');
                rmdir($directory . '/include');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
