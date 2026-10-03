<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class UtilityViewNativeCoverageTest extends TestCase
{
    /** @dataProvider viewCases */
    public function testNativeUtilityViewsKeepScopedRowsAndEscapedFilters(string $view, array $request, array $expected): void
    {
        $state = $this->render(array('view' => $view, 'request' => $request));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $rows = array();
        $selector = match ($view) {
            'user', 'agent' => '//tr[starts-with(@id,"line")]/td[1]',
            'event' => '//tr[starts-with(@id,"line")]/td[3]',
            'poller' => '//tr[@class="odd" or @class="even"]/td[1][a]',
            'snmp' => '//tr[contains(@class,"tableRow")]/td[1]',
        };
        foreach ($xpath->query($selector) as $cell) {
            $rows[] = trim($cell->textContent);
        }
        self::assertSame($expected, $rows);
        self::assertSame($state['before'], $state['after']);
        self::assertCount(in_array($view, array('agent', 'event'), true) ? 2 : 1, $xpath->query('//script'));
        self::assertSame('utility-fixture', $xpath->query('//script')->item(0)->getAttribute('nonce'));
        if ($view === 'poller') {
            foreach (array('Alpha DS & <script>' => 'public & <script>', 'Beta DS' => 'v3 & <script>', 'Gamma DS' => 'script & <script>', 'Delta DS' => 'server & <script>') as $source => $detail) {
                if (in_array($source, $expected, true)) {
                    $matching = $xpath->query('//tr[td[1]/a]/td[3]');
                    self::assertStringContainsString($detail, $matching->item(array_search($source, $expected, true))->textContent);
                }
            }
        }
        self::assertSame($request['filter'] ?? '', $xpath->query('//input[@id="filter"]')->item(0)->getAttribute('value'));
        self::assertSame((string) ($request['rows'] ?? -1), $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame('Two & more', $xpath->query('//select[@id="rows"]/option[@value="2"]')->item(0)->textContent);
        self::assertCount(0, $xpath->query('//script[contains(text(),"Alpha") or contains(text(),"Template") or contains(text(),"public")]'));
        self::assertSame('preserved', $state['session']['sentinel']);
        self::assertGreaterThanOrEqual(3, count($state['queries']));
        if ($view === 'event') {
            self::assertSame('Receiver & <script>', $xpath->query('//select[@id="receiver"]/option[@value="1"]')->item(0)->textContent);
            self::assertSame((string) ($request['severity'] ?? -1), $xpath->query('//select[@id="severity"]/option[@selected]')->item(0)->getAttribute('value'));
            if (($request['receiver'] ?? -1) === 99) {
                self::assertCount(0, $xpath->query('//select[@id="receiver"]/option[@selected]'));
            } else {
                self::assertSame((string) ($request['receiver'] ?? -1), $xpath->query('//select[@id="receiver"]/option[@selected]')->item(0)->getAttribute('value'));
            }
        }
        if ($view === 'agent') {
            self::assertSame((string) ($request['mib'] ?? -1), $xpath->query('//select[@id="mib"]/option[@selected]')->item(0)->getAttribute('value'));
        }
        if ($view === 'user') {
            self::assertSame('Alpha & <script>', $xpath->query('//select[@id="username"]/option[@value="Alpha & <script>"]')->item(0)->textContent);
            if (($request['username'] ?? '') === '-2') {
                self::assertStringContainsString('(User Removed)', $xpath->query('//tr[@id="line0"]')->item(0)->textContent);
                self::assertStringContainsString('Success - Password Change', $xpath->query('//tr[@id="line0"]')->item(0)->textContent);
            }
        } elseif (in_array($view, array('snmp', 'poller'), true)) {
            self::assertSame('Alpha & <script>', $xpath->query('//select[@id="host_id"]/option[@value="1"]')->item(0)->textContent);
            self::assertSame((string) ($request['host_id'] ?? -1), $xpath->query('//select[@id="host_id"]/option[@selected]')->item(0)->getAttribute('value'));
        }
        if (($request['page'] ?? 1) === 2) {
            self::assertGreaterThan(0, $xpath->query('//a[contains(@data-url,"page=1")]')->length);
        }
        if ($view === 'snmp') {
            if (($request['host_id'] ?? -1) === 0) {
                self::assertCount(0, $xpath->query('//select[@id="snmp_query_id"]/option[@value="10"]'));
            } elseif (($request['host_id'] ?? -1) !== 2) {
                self::assertSame('Query & <script>', $xpath->query('//select[@id="snmp_query_id"]/option[@value="10"]')->item(0)->textContent);
            } else {
                self::assertSame('Other query', $xpath->query('//select[@id="snmp_query_id"]/option[@value="20"]')->item(0)->textContent);
                self::assertCount(0, $xpath->query('//select[@id="snmp_query_id"]/option[@value="10"]'));
            }
            self::assertSame(($request['with_index'] ?? 0) === 1, $xpath->query('//input[@id="with_index"][@checked]')->length === 1);
        }
    }

    /** @dataProvider sameNameRealmCases */
    public function testNativeUserLogJoinsTheRecordedAccountAcrossSameNameRealms(array $request, array $names, array $dates, int $total): void
    {
        $state = $this->render(array('view' => 'user', 'request' => $request, 'same_name_realms' => true));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $actualNames = $actualDates = array();
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $cells = $xpath->query('./td', $row);
            $actualNames[] = trim($cells->item(1)->textContent);
            $actualDates[] = trim($cells->item(3)->textContent);
        }
        self::assertSame(array($total), $state['total_rows']);
        self::assertSame($names, $actualNames);
        self::assertSame($dates, $actualDates);
        self::assertSame($state['before'], $state['after']);
    }

    public static function sameNameRealmCases(): array
    {
        return array(
            'same name first page' => array(array('username' => 'Shared Name'), array('Foreign Account', 'Original Account'), array('2026-09-07', '2026-09-06'), 3),
            'same name next page' => array(array('username' => 'Shared Name', 'page' => 2), array('Original Account'), array('2026-09-05'), 3),
            'original account name filter' => array(array('filter' => 'Original Account'), array('Original Account', 'Original Account'), array('2026-09-06', '2026-09-05'), 2),
            'foreign account name filter' => array(array('filter' => 'Foreign Account'), array('Foreign Account'), array('2026-09-07'), 1),
        );
    }

    /** @dataProvider invalidPrincipalCases */
    public function testDeletedFilterMatchesTheRecordedUserIdAndUsername(array $request, array $names, array $dates, int $total): void
    {
        $state = $this->render(array('view' => 'user', 'request' => array_merge(array('username' => '-2'), $request), 'same_name_realms' => true, 'mismatched_log_principals' => true));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $actualNames = $actualDates = array();
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $cells = $xpath->query('./td', $row);
            $actualNames[] = trim($cells->item(0)->textContent);
            $actualDates[] = trim($cells->item(3)->textContent);
            self::assertStringContainsString('(User Removed)', $cells->item(1)->textContent);
        }
        self::assertSame(array($total), $state['total_rows']);
        self::assertSame($names, $actualNames);
        self::assertSame($dates, $actualDates);
        self::assertSame($state['before'], $state['after']);
        if (($request['page'] ?? 1) === 2) {
            self::assertGreaterThan(0, $xpath->query('//a[contains(@data-url,"page=1")]')->length);
        }
    }

    public static function invalidPrincipalCases(): array
    {
        return array(
            'deleted first page' => array(array(), array('Beta', 'Alpha & <script>'), array('2026-09-10', '2026-09-09'), 4),
            'deleted second page' => array(array('page' => 2), array('Shared Name', 'Removed'), array('2026-09-08', '2026-09-04'), 4),
            'same name invalid principal' => array(array('filter' => 'Shared Name'), array('Shared Name'), array('2026-09-08'), 1),
            'deleted result filter' => array(array('result' => 2), array('Alpha & <script>'), array('2026-09-09'), 1),
            'deleted combined filters' => array(array('result' => 0, 'filter' => 'Beta'), array('Beta'), array('2026-09-10'), 1),
            'valid full name excluded' => array(array('filter' => 'Foreign Account'), array(), array(), 0),
        );
    }

    public static function viewCases(): array
    {
        return array(
            'agent default' => array('agent', array(), array('1.1', '1.2')),
            'agent page two' => array('agent', array('page' => 2), array('1.3')),
            'agent mib' => array('agent', array('mib' => 'MIB-B'), array('1.3')),
            'agent label filter' => array('agent', array('filter' => 'Name & <script>'), array('1.1')),
            'agent missing' => array('agent', array('filter' => 'missing'), array()),
            'event default' => array('event', array(), array('Foreign receiver', 'Receiver & <script>')),
            'event second page' => array('event', array('page' => 2), array('Receiver & <script>')),
            'event receiver' => array('event', array('receiver' => 1), array('Receiver & <script>', 'Receiver & <script>')),
            'event severity' => array('event', array('severity' => 4), array('Foreign receiver')),
            'event empty receiver' => array('event', array('receiver' => 99), array()),
            'event literal filter' => array('event', array('filter' => 'Bind & <script>'), array('Receiver & <script>')),
            'users default' => array('user', array(), array('Removed', 'Beta')),
            'users second page' => array('user', array('page' => 2), array('Alpha & <script>', 'Alpha & <script>')),
            'users selected username and result' => array('user', array('username' => 'Beta', 'result' => 2), array('Beta')),
            'users deleted' => array('user', array('username' => '-2'), array('Removed')),
            'users literal filter' => array('user', array('filter' => 'A & <script>'), array('Alpha & <script>', 'Alpha & <script>')),
            'users no match' => array('user', array('filter' => 'missing'), array()),
            'snmp default' => array('snmp', array(), array('Alpha & <script>', 'Alpha & <script>')),
            'snmp page two' => array('snmp', array('page' => 2), array('Beta')),
            'snmp selected host' => array('snmp', array('host_id' => 2, 'snmp_query_id' => 20), array('Beta')),
            'snmp no host' => array('snmp', array('host_id' => 0), array()),
            'snmp selected query' => array('snmp', array('snmp_query_id' => 10), array('Alpha & <script>', 'Alpha & <script>')),
            'snmp index excluded' => array('snmp', array('filter' => 'index-only'), array()),
            'snmp index included' => array('snmp', array('filter' => 'index-only', 'with_index' => 1), array('Alpha & <script>')),
            'snmp literal filter' => array('snmp', array('filter' => 'Value & <script>'), array('Alpha & <script>')),
            'poller default' => array('poller', array(), array('Alpha DS & <script>', 'Beta DS')),
            'poller second page' => array('poller', array('page' => 2), array('Delta DS', 'Gamma DS')),
            'poller enabled' => array('poller', array('status' => 1), array('Alpha DS & <script>', 'Beta DS')),
            'poller disabled' => array('poller', array('status' => 0), array('Delta DS', 'Gamma DS')),
            'poller script' => array('poller', array('poller_action' => 1), array('Gamma DS')),
            'poller server' => array('poller', array('poller_action' => 2), array('Delta DS')),
            'poller host template' => array('poller', array('host_id' => 1, 'template_id' => 10), array('Alpha DS & <script>', 'Beta DS')),
            'poller no template' => array('poller', array('template_id' => 0), array('Delta DS')),
            'poller scoped filter' => array('poller', array('host_id' => 1, 'filter' => 'OID-v3'), array('Beta DS')),
            'poller hostname filter' => array('poller', array('filter' => 'beta'), array('Beta DS', 'Delta DS')),
            'poller missing' => array('poller', array('filter' => 'missing'), array()),
        );
    }

    /** @dataProvider logfileCases */
    public function testNativeLogfileViewReadsActualFilesAndKeepsOrderedFilteredContent(array $request, array $expected): void
    {
        $state = $this->render(array('view' => 'log', 'request' => $request));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $lines = array();
        foreach ($xpath->query('//tr[@class="clogError" or @class="clogWarning" or @class="clogDebug" or @class="clogStats" or @class="odd" or @class="even"]/td') as $cell) {
            $lines[] = trim($cell->textContent);
        }
        self::assertSame($expected, $lines);
        self::assertSame($state['log_before'], $state['log_after']);
        self::assertSame($state['before'], $state['after']);
        self::assertCount(2, $xpath->query('//script'));
        self::assertSame((string) ($request['tail_lines'] ?? -1), $xpath->query('//select[@id="tail_lines"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame((string) ($request['refresh'] ?? 300), $xpath->query('//select[@id="refresh"]/option[@selected]')->item(0)->getAttribute('value'));
        if (isset($request['filename'])) {
            self::assertSame($request['filename'], $xpath->query('//select[@id="filename"]/option[@selected]')->item(0)->getAttribute('value'));
        } else {
            self::assertCount(0, $xpath->query('//select[@id="filename"]/option[@selected]'));
        }
        self::assertSame($request['rfilter'] ?? '', $xpath->query('//input[@id="rfilter"]')->item(0)->getAttribute('value'));
        if (in_array('STATS Device[1] DS[101] Alpha & <script>', $expected, true)) {
            self::assertCount(1, $xpath->query('//a[@href="host.php?action=edit&id=1"]'));
            self::assertCount(1, $xpath->query('//a[@href="data_sources.php?action=ds_edit&id=101"]'));
        }
    }

    public static function logfileCases(): array
    {
        return array(
            'default newest first' => array(array(), array('Plain Fifth', 'DEBUG Delta')),
            'oldest first' => array(array('reverse' => 2), array('DEBUG Delta', 'Plain Fifth')),
            'second page' => array(array('page' => 2), array('ERROR Gamma', 'WARN Beta')),
            'stats with links and literal markup' => array(array('tail_lines' => 10, 'message_type' => 1, 'rfilter' => 'Alpha'), array('STATS Device[1] DS[101] Alpha & <script>')),
            'warnings' => array(array('message_type' => 2, 'refresh' => 60), array('WARN Beta')),
            'empty search' => array(array('rfilter' => 'missing'), array()),
            'archive file' => array(array('filename' => 'cacti.log-20260930'), array('WARN Archived')),
            'stderr file' => array(array('filename' => 'stderr.log'), array('ERROR Stderr')),
        );
    }

    /** @dataProvider boostCases */
    public function testNativeBoostStatusUsesRealDatabaseMetadataWorkersAndCacheFiles(string $status, string $expectedStatus, bool $empty): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('A real MySQL or MariaDB connection is required for Boost metadata.');
        }
        $state = $this->render(array('view' => 'boost', 'request' => array('refresh' => 60), 'status' => $status, 'empty' => $empty));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $text = $document->textContent;
        self::assertSame($state['before'], $state['after']);
        self::assertCount(1, $xpath->query('//script'));
        self::assertSame('60', $xpath->query('//select[@id="refresh"]/option[@selected]')->item(0)->getAttribute('value'));
        foreach (array('Boost On-demand Updating:' => $expectedStatus, 'Database Engine:' => 'InnoDB', 'Total Poller Items:' => '2', 'Running Processes:' => '1', 'Concurrent Processes:' => '2', 'Update Frequency:' => 'Five minutes', 'Maximum Records:' => '1,000 Records', 'Maximum Allowed Runtime:' => 'One hour') as $label => $expected) {
            self::assertSame($expected, trim($xpath->query('//tr[td[1][text()="' . $label . '"]]/td[2]')->item(0)->textContent), $label);
        }
        self::assertStringContainsString('Process: 1', $text);
        self::assertStringContainsString('Status: Running, Remaining: 1', $text);
        self::assertStringContainsString('Process: 2', $text);
        self::assertStringContainsString('Status: Idle, PrevRuntime: 0', $text);
        if ($empty) {
            self::assertStringContainsString('Directory Does NOT Exist!!', $text);
            self::assertSame('Disabled', trim($xpath->query('//tr[td[1][text()="Image Caching Status:"]]/td[2]')->item(0)->textContent));
        } else {
            self::assertSame('2 Files', trim($xpath->query('//tr[td[1][text()="Cached Files:"]]/td[2]')->item(0)->textContent));
            self::assertSame('8.00 Bytes', trim($xpath->query('//tr[td[1][text()="Cached Files Size:"]]/td[2]')->item(0)->textContent));
            self::assertStringContainsString('Records: 10 (ds rows), Time: 2 (secs)', $text);
            self::assertSame('2', trim($xpath->query('//tr[td[1][text()="RRD Updates:"]]/td[2]')->item(0)->textContent));
        }
    }

    public static function boostCases(): array
    {
        return array(
            'running and populated cache' => array('running:1700000000', 'Running', false),
            'idle and empty optional stats' => array('complete:1700000000', 'Idle', true),
            'disabled' => array('disabled', 'Disabled', false),
            'overrun' => array('overrun:1700000000', 'Overrun Warning', false),
        );
    }

    /** @dataProvider rowChoiceCases */
    public function testNativeRowChoicesPreserveOrderEscapedLabelsAndEmptyConfiguration(string $view, array $choices, int $rows): void
    {
        $state = $this->render(array('view' => $view, 'request' => array('rows' => $rows), 'choices' => $choices));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $values = $labels = array();
        foreach ($xpath->query('//select[@id="rows"]/option') as $option) {
            $values[] = $option->getAttribute('value');
            $labels[] = $option->textContent;
        }
        self::assertSame(array_merge(array('-1'), array_map('strval', array_keys($choices))), $values);
        self::assertSame(array_merge(array('Default'), array_values($choices)), $labels);
        self::assertSame((string) $rows, $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame($state['before'], $state['after']);
        self::assertCount(in_array($view, array('agent', 'event'), true) ? 2 : 1, $xpath->query('//script'));
    }

    public static function rowChoiceCases(): array
    {
        $cases = array();
        foreach (array('user', 'poller', 'agent', 'event') as $view) {
            $cases[$view . ' configured order'] = array($view, array(2 => 'Two & more', 1 => 'One'), -1);
            $cases[$view . ' selected escaped label'] = array($view, array(2 => 'Two & <script>', 4 => 'Four'), 2);
            $cases[$view . ' empty choices'] = array($view, array(), -1);
        }
        return $cases;
    }

    public function testNativeRowOptionsEscapeQuoteContainingAttributeKeys(): void
    {
        $key = "quote'\"<&";
        $label = '</option><script>markup</script> & label';
        $state = $this->render(array('view' => 'options', 'request' => array(), 'choices' => array($key => $label), 'selected' => $key));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML('<select>' . $state['html'] . '</select>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        self::assertCount(1, $xpath->query('//select/option'));
        $option = $xpath->query('//select/option')->item(0);
        self::assertSame($key, $option->getAttribute('value'));
        self::assertSame($label, $option->textContent);
        self::assertTrue($option->hasAttribute('selected'));
        self::assertCount(0, $xpath->query('//script'));
    }

    /** @dataProvider exactRowSelectionCases */
    public function testNativeRowOptionsSelectOnlyTheExactStringForm(array $choices, mixed $selected, string $expected): void
    {
        $state = $this->render(array('view' => 'options', 'request' => array(), 'choices' => $choices, 'selected' => $selected));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML('<select>' . $state['html'] . '</select>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        self::assertCount(1, $xpath->query('//select/option[@selected]'));
        self::assertSame($expected, $xpath->query('//select/option[@selected]')->item(0)->getAttribute('value'));
        $values = array();
        foreach ($xpath->query('//select/option') as $option) {
            $values[] = $option->getAttribute('value');
        }
        self::assertSame(array_map('strval', array_keys($choices)), $values);
        self::assertSame($state['before'], $state['after']);
    }

    public static function exactRowSelectionCases(): array
    {
        return array(
            'first scientific-looking string' => array(array('0e1' => 'First', '0e2' => 'Second'), '0e1', '0e1'),
            'second scientific-looking string' => array(array('0e1' => 'First', '0e2' => 'Second'), '0e2', '0e2'),
            'integer key with request string' => array(array(2 => 'Two', 4 => 'Four'), '2', '2'),
            'integer key with integer request' => array(array(2 => 'Two', 4 => 'Four'), 2, '2'),
        );
    }

    private function render(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/utility-view-' . bin2hex(random_bytes(8));
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/utility-view-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
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
                $sources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'tests/Unit/UtilityViewNativeCoverageTest.php', 'utilities.php', 'lib/html.php', 'lib/html_utility.php', 'lib/functions.php', 'lib/clog_webapi.php', 'src/Platform/Infrastructure/Legacy/UtilityRows.php', 'include/global_constants.php', 'lib/html_form.php', 'lib/variables.php', 'lib/utility.php');
                $markers = array('utility-view-observed:' . $scenario['view']);
                $hitSources = array('utilities.php');
                // The shared helper has four view callers; SNMP/log/Boost retain their original renderers.
                if (in_array($scenario['view'], array('user', 'poller', 'agent', 'event', 'options'), true)) {
                    $hitSources[] = 'src/Platform/Infrastructure/Legacy/UtilityRows.php';
                }
                $encoded = json_encode($scenario, JSON_THROW_ON_ERROR);
                $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/utility-view-native.php', $encoded, $sources, $markers, $hitSources);
                static $omissionsVerified = false;
                if (!$omissionsVerified) {
                    self::assertSame(35, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/utility-view-native.php', $encoded, $sources, $markers, $hitSources, $scenario['view'] === 'boost' ? 'lib/rrd_maintenance.php' : 'lib/boost.php'));
                    $omissionsVerified = true;
                }
                $coverage->merge($child);
            }
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage*') as $report) {
                unlink($report);
            }
            if (is_dir($directory . '/images')) {
                foreach (glob($directory . '/images/*') as $image) {
                    unlink($image);
                }
                rmdir($directory . '/images');
            }
            foreach (array('cacti.log', 'cacti.log-20260930', 'stderr.log') as $logfile) {
                unlink($directory . '/' . $logfile);
            }
            unlink($directory . '/lib');
            unlink($directory . '/include/auth.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
}
