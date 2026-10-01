<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AutomationModuleNativeTest extends TestCase
{
    /** @dataProvider replacements */
    public function testNativeReplacementFailsClosedOrPreservesExactResults(array $scenario, array $expected, string $diagnostic): void
    {
        $state = $this->runNative($scenario + array('mode' => 'helper'));
        self::assertSame($expected, $state['result']);
        if ($diagnostic !== '') {
            self::assertStringContainsString('AUTOM8 WARNING:', $state['log']);
            self::assertStringContainsString($diagnostic, $state['log']);
            self::assertStringContainsString(json_encode($scenario['search'], JSON_INVALID_UTF8_SUBSTITUTE), $state['log']);
        } else {
            self::assertStringNotContainsString('WARNING:', $state['log']);
        }
        self::assertCount(2, $state['nodes']);
    }

    public static function replacements(): array
    {
        $cases = array(
            'case insensitive' => array(array('search' => 'host-[0-9]+', 'replace' => 'Device', 'target' => 'HOST-17'), array('Device'), ''),
            'delimiter' => array(array('search' => 'sensor~[0-9]+', 'replace' => 'disk', 'target' => 'sensor~3'), array('disk'), ''),
            'rare delimiter' => array(array('search' => '^[~#%!@;`=/_]+$', 'replace' => 'matched', 'target' => '~#%!@;`=/_'), array('matched'), ''),
            'no delimiter' => array(array('search' => '^[~#%!@;`=/_' . chr(127) . ']+$', 'replace' => 'x', 'target' => 'y'), array(), 'no available delimiter'),
            'invalid' => array(array('search' => '(', 'replace' => 'x', 'target' => 'y'), array(), 'Internal error'),
            'limited' => array(array('search' => '^(a+)+$', 'replace' => 'matched', 'target' => str_repeat('a', 255) . '!'), array(), 'Backtrack limit exhausted'),
            'split empty segments' => array(array('search' => '^host$', 'replace' => 'A\\n\\nB\\n', 'target' => 'host'), array('A', 'B'), ''),
            'nonmatching' => array(array('search' => '^other$', 'replace' => 'x', 'target' => 'host'), array('host'), ''),
            'slash' => array(array('search' => 'eth(\\d+)/(\\d+)', 'replace' => 'Port$1-$2', 'target' => 'eth12/5'), array('Port12-5'), ''),
        );
        return array_intersect_key($cases, array_flip(array('case insensitive','split empty segments','nonmatching')));
    }

    /** @dataProvider handoffs */
    public function testNativeTreeHandoffCreatesOnlyCompleteNestedHeaders(array $scenario, array $titles): void
    {
        $state = $this->runNative($scenario + array('mode' => 'handoff', 'repeat' => true));
        self::assertSame('Parent', $state['nodes'][0]['title']);
        self::assertSame('Unrelated', $state['nodes'][1]['title']);
        self::assertSame(9, (int) $state['nodes'][1]['graph_tree_id']);
        self::assertSame($titles, array_column(array_slice($state['nodes'], 2), 'title'));
        $parent = 77;
        foreach (array_slice($state['nodes'], 2) as $node) {
            self::assertSame($parent, (int) $node['parent']);
            self::assertSame(8, (int) $node['graph_tree_id']);
            $parent = (int) $node['id'];
        }
        self::assertSame($parent, (int) $state['result']);
        if (!$titles) {
            self::assertStringContainsString('AUTOM8 WARNING:', $state['log']);
        }
    }

    public static function handoffs(): array
    {
        $cases = array(
            'nested' => array(array('search' => '^host$', 'replace' => 'A\\nB', 'target' => 'host'), array('A', 'B')),
            'slash' => array(array('search' => 'eth(\\d+)/(\\d+)', 'replace' => 'Port$1-$2', 'target' => 'eth12/5'), array('Port12-5')),
            'invalid' => array(array('search' => '(', 'replace' => 'x', 'target' => 'y'), array()),
            'limited' => array(array('search' => '^(a+)+$', 'replace' => 'x', 'target' => str_repeat('a', 255) . '!'), array()),
        );
        return array_intersect_key($cases, array_flip(array('nested')));
    }

    /** @dataProvider previews */
    public function testNativePreviewRendersCompleteReplacementOrEmptyCell(array $scenario, string $expected): void
    {
        $state = $this->runNative($scenario + array('mode' => 'preview'));
        $document = new DOMDocument();
        @$document->loadHTML($state['html']);
        $xpath = new DOMXPath($document);
        self::assertSame('10', $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame('Default', $xpath->query('//select[@id="rows"]/option[@value="-1"]')->item(0)->textContent);
        self::assertSame('Any', $xpath->query('//select[@id="host_template_id"]/option[@value="-1"]')->item(0)->textContent);
        self::assertSame('None', $xpath->query('//select[@id="host_template_id"]/option[@value="0"]')->item(0)->textContent);
        self::assertSame('Fixture template', $xpath->query('//select[@id="host_template_id"]/option[@value="9"]')->item(0)->textContent);
        $statuses = array('-1' => 'Any', '-3' => 'Enabled', '-2' => 'Disabled', '-4' => 'Not Up', '3' => 'Up', '1' => 'Down', '2' => 'Recovering', '0' => 'Unknown');
        foreach ($statuses as $value => $label) {
            self::assertSame($label, $xpath->query('//select[@id="host_status"]/option[@value="' . $value . '"]')->item(0)->textContent);
        }

        $cells = $xpath->query('//tr[@id="line7"]/td');
        self::assertCount(6, $cells);
        self::assertSame($expected, $cells->item(5)->textContent);
        if ($expected !== '') {
            self::assertStringContainsString('A<br>---&nbsp;B', $state['html']);
        }
        self::assertStringNotContainsString('Warning:', $state['html']);
        self::assertStringNotContainsString('Fatal error:', $state['html']);
        self::assertCount(2, $state['nodes']);
    }

    public static function previews(): array
    {
        $cases = array(
            'nested' => array(array('search' => '^host$', 'replace' => 'A\\nB', 'target' => 'host'), "A---\xc2\xa0B"),
            'invalid' => array(array('search' => '(', 'replace' => 'x', 'target' => 'y'), ''),
            'limited' => array(array('search' => '^(a+)+$', 'replace' => 'x', 'target' => str_repeat('a', 255) . '!'), ''),
        );
        return array_intersect_key($cases, array_flip(array('nested')));
    }

    /** @dataProvider eligibility */
    public function testNativeEligibilityRequiresAllMandatoryTemplateInputs(string $case, bool $expected): void
    {
        $state = $this->runNative(array('mode' => 'eligible', 'case' => $case));
        self::assertSame($expected, $state['result']);
        self::assertNotEmpty(array_filter($state['calls'], static fn($call) => str_contains($call[0], 'FROM graph_templates_graph') && $call[1] === array(9)));
    }

    public static function eligibility(): array
    {
        return array('complete' => array('complete', true), 'graph required' => array('graph', false), 'data required' => array('data', false), 'input required' => array('input', false), 'optional input' => array('optional', true));
    }

    /** @dataProvider leaves */
    public function testNativeLeafChangePrunesOnlyIncompatibleFieldsForSelectedRule(int $leaf, array $expected): void
    {
        $state = $this->runNative(array('mode' => 'leaf', 'leaf' => $leaf));
        self::assertSame($expected, array_map('intval', $state['contracts']['items']));
        self::assertSame($expected, array_map('intval', $state['contracts']['matches']));
        self::assertSame($leaf, (int) $state['contracts']['rules'][0]['leaf_type']);
        self::assertSame(2, (int) $state['contracts']['rules'][1]['leaf_type']);
    }

    public static function leaves(): array
    {
        return array('device' => array(3, array(3, 4)), 'unchanged' => array(2, array(1, 2, 3, 4)), 'graph' => array(1, array(1, 2, 3, 4)));
    }

    /** @dataProvider schedules */
    public function testNativeSchedulerHonoursManualAndFutureTimes(array $scenario, bool $expected): void
    {
        $state = $this->runNative($scenario + array('mode' => 'schedule'));
        self::assertSame($expected, $state['result']);
        if ($expected && $scenario['type'] === 2) {
            self::assertGreaterThan(time() - 60, strtotime($state['contracts']['next_start']));
        }
        self::assertSame(8, (int) $state['contracts']['id']);
    }

    public static function schedules(): array
    {
        $cases = array();
        foreach (array(1, 2, 3, 4, 5) as $type) {
            foreach (array(false, true) as $future) {
                foreach (array(false, true) as $next) {
                    $cases[$type . '-' . (int) $future . '-' . (int) $next] = array(array('type' => $type, 'future' => $future, 'next' => $next), $type !== 1 && !$future);
                }
            }
        }
        return $cases;
    }

    /** @dataProvider nodeKinds */
    public function testNativeNodeCallerPreservesOwnershipAndReusesExistingNode(string $kind, bool $reject): void
    {
        $state = $this->runNative(array('mode' => 'node', 'kind' => $kind, 'reject' => $reject));
        self::assertCount(1, $state['contracts']['save']);
        $args = $state['contracts']['save'][0];
        self::assertSame(8, $args[1]);
        self::assertSame(77, $args[3]);
        self::assertSame(7, $args[array('host' => 6, 'site' => 7, 'graph' => 5)[$kind]]);
        self::assertSame(false, $args[10]);
        if ($reject) {
            self::assertSame(0, $state['result']);
            self::assertCount(2, $state['nodes']);
            self::assertStringContainsString('Not Added', $state['log']);
        } else {
            self::assertSame($state['result'], $state['contracts']['repeat']);
            self::assertCount(3, $state['nodes']);
            self::assertStringContainsString('Added', $state['log']);
        }
    }

    public static function nodeKinds(): array
    {
        return array(array('host', false), array('site', false), array('graph', false), array('host', true), array('site', true), array('graph', true));
    }

    /** @dataProvider devices */
    public function testNativeDeviceCallerPassesDefaultsAndRemovesOnlyAcknowledgedQueueEntry(array $scenario, string $description): void
    {
        $state = $this->runNative($scenario + array('mode' => 'device'));
        $args = $state['contracts']['device_save'];
        self::assertCount(29, $args);
        self::assertSame($description, $args[2]);
        self::assertSame('192.0.2.7', $args[3]);
        self::assertSame(9, $args[1]);
        self::assertSame($scenario['overrides'] ? 20 : 10, $args[22]);
        self::assertSame($scenario['overrides'] ? 3 : 1, $args[24]);
        self::assertSame($scenario['overrides'] ? 4 : 2, $args[25]);
        self::assertSame($scenario['reject'] ? 0 : 17, $state['result']);
        self::assertSame($scenario['reject'] ? array('192.0.2.7', '192.0.2.8') : array('192.0.2.8'), $state['contracts']['queued']);
    }

    public static function devices(): array
    {
        return array(array(array('name' => 'System', 'hostname' => 'dns', 'overrides' => false, 'reject' => false), 'System'), array(array('name' => '', 'hostname' => 'dns', 'overrides' => true, 'reject' => false), 'dns'), array(array('name' => '', 'hostname' => '', 'overrides' => false, 'reject' => true), '192.0.2.7'));
    }

    /** @dataProvider snmpCases */
    public function testNativeSnmpCredentialFallbackPreservesStatusAndClosesSuccessfulSession(string $case, bool $expected): void
    {
        $state = $this->runNative(array('mode' => 'snmp', 'case' => $case));
        self::assertSame($expected, $state['result']);
        self::assertSame($expected ? 3 : 1, $state['contracts']['device']['snmp_status']);
        if ($expected) {
            self::assertTrue($state['contracts']['closed']);
            self::assertSame('.1.3.6.1.4.1.9', $state['contracts']['device']['snmp_sysObjectID']);
            self::assertSame('Fixture system', $state['contracts']['device']['snmp_sysName']);
            self::assertSame($case === 'fallback' ? 3 : 2, (int) $state['contracts']['device']['snmp_version']);
        }
    }

    public static function snmpCases(): array
    {
        return array(array('valid', true), array('fallback', true), array('unknown', false), array('session-failed', false), array('empty', false));
    }

    /** @dataProvider graphQueries */
    public function testNativeDataQueryCreatesOnlyMissingGraphsForSelectedDevice(string $case): void
    {
        $state = $this->runNative(array('mode' => 'dq', 'case' => $case));
        if ($case === 'missing') {
            self::assertSame(false, $state['result']);
            self::assertStringContainsString('not found for the Device', $state['log']);
            self::assertArrayNotHasKey('created', $state['contracts']);
        } else {
            self::assertCount(1, $state['contracts']['created']);
            self::assertSame(array(9, 7), array_slice($state['contracts']['created'][0], 0, 2));
            self::assertSame('2', $state['contracts']['created'][0][2]['snmp_index']);
            if ($case === 'created') {
                self::assertSame(array(array(7, 201)), $state['contracts']['pushed']);
                self::assertStringContainsString('Graph Added', $state['log']);
            } else {
                self::assertArrayNotHasKey('pushed', $state['contracts']);
                self::assertStringContainsString('Graph not added', $state['log']);
            }
        }
    }

    public static function graphQueries(): array
    {
        return array(array('created'), array('rejected'), array('empty'), array('missing'));
    }

    public function testNativeObjectPreviewRetainsRowFilterAndReportsUnavailableQuery(): void
    {
        $state = $this->runNative(array('mode' => 'objects'));
        $document = new DOMDocument();
        @$document->loadHTML($state['html']);
        $xpath = new DOMXPath($document);
        self::assertSame('10', $xpath->query('//select[@id="orows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame('Default', $xpath->query('//select[@id="orows"]/option[@value="-1"]')->item(0)->textContent);
        self::assertStringContainsString('Error in data query', $state['html']);
        self::assertStringNotContainsString('Warning:', $state['html']);
    }

    /** @dataProvider editors */
    public function testNativeRuleEditorOffersFieldsForEachRuleType(int $type, int $leaf, string $title): void
    {
        $state = $this->runNative(array('mode' => 'edit', 'type' => $type, 'leaf' => $leaf));
        self::assertStringContainsString($title, $state['html']);
        self::assertStringContainsString('form_automation_global_item_edit', $state['html']);
        self::assertStringNotContainsString('Warning:', $state['html']);
        if ($type === 2) {
            self::assertStringContainsString('ifName', $state['html']);
        }
    }

    public static function editors(): array
    {
        return array(array(1, 3, 'Device Match Rule'), array(2, 3, 'Create Graph Rule'), array(3, 3, 'Device Match Rule'), array(3, 2, 'Graph Match Rule'), array(4, 3, 'Create Tree Rule (Device)'), array(4, 2, 'Create Tree Rule (Graph)'));
    }

    /** @dataProvider matchingLists */
    public function testNativeMatchingListPreservesSelectedRowsAndObjectIdentity(string $kind): void
    {
        $state = $this->runNative(array('mode' => 'matches', 'kind' => $kind));
        $document = new DOMDocument();
        @$document->loadHTML($state['html']);
        $xpath = new DOMXPath($document);
        $select = $kind === 'graph' ? 'rows' : 'rowsd';
        $refresh = $kind === 'graph' ? 'refresh' : 'refreshd';
        $clear = $kind === 'graph' ? 'clear' : 'cleard';
        self::assertSame(1, $xpath->query('//input[@id="' . $refresh . '"]')->length);
        self::assertSame(1, $xpath->query('//input[@id="' . $clear . '"]')->length);
        self::assertStringContainsString("$('#" . $refresh . "').on('click'", $state['html']);
        self::assertStringContainsString("$('#" . $clear . "').on('click'", $state['html']);

        self::assertSame($kind === 'graph' ? '10' : '20', $xpath->query('//select[@id="' . $select . '"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame('Default', $xpath->query('//select[@id="' . $select . '"]/option[@value="-1"]')->item(0)->textContent);
        if ($kind === 'host') {
            self::assertSame('Any', $xpath->query('//select[@id="host_template_id"]/option[@value="-1"]')->item(0)->textContent);
            self::assertSame('None', $xpath->query('//select[@id="host_template_id"]/option[@value="0"]')->item(0)->textContent);
            self::assertSame('Fixture template', $xpath->query('//select[@id="host_template_id"]/option[@value="9"]')->item(0)->textContent);
            $statuses = array('-1' => 'Any', '-3' => 'Enabled', '-2' => 'Disabled', '-4' => 'Not Up', '3' => 'Up', '1' => 'Down', '2' => 'Recovering', '0' => 'Unknown');
            foreach ($statuses as $value => $label) {
                self::assertSame($label, $xpath->query('//select[@id="host_status"]/option[@value="' . $value . '"]')->item(0)->textContent);
            }

        }
        self::assertSame(1, $xpath->query('//tr[@id="line' . ($kind === 'graph' ? '100' : '7') . '"]')->length);
        self::assertStringContainsString($kind === 'graph' ? 'Fixture title' : 'Fixture template', $state['html']);
        self::assertStringNotContainsString('Warning:', $state['html']);
    }

    public static function matchingLists(): array
    {
        return array(array('host'), array('graph'));
    }

    protected function runNative(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/native-profile-deletion-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $fixture = 'automation-module-native.php';
            $command = array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=/', '-d', 'error_reporting=24575', '-d', 'display_errors=stderr', $root . '/tests/Fixtures/' . $fixture, json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
            if ($coverage !== null) {
                $command[] = $directory;
            }
            $environment = getenv();
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory, $environment);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr . $stdout);
            self::assertSame('', $stderr);
            self::assertSame('', $stdout);
            $state = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return $state;
        } finally {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }
}
