<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class ManagerNativeCoverageTest extends TestCase
{
    /** @dataProvider managerCases */
    public function testOriginalManagerDispatchQueriesAndRendersPersistedReceivers(array $request, array $ids, int $total): void
    {
        $state = $this->render($request);
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $actual = array();
        $expected = array(
            1 => array('Receiver Alpha & <script>', 'Enabled', '192.0.2.10', '2', '2'),
            2 => array('Receiver Beta', 'Disabled', '192.0.2.2', '1', '1'),
            3 => array('Receiver Gamma', 'Enabled', '192.0.2.30', '0', '0'),
        );
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $id = (int) substr($row->getAttribute('id'), 4);
            $actual[] = $id;
            $cells = $xpath->query('./td', $row);
            foreach (array(0, 2, 3, 4, 5) as $index => $column) {
                self::assertSame($expected[$id][$index], trim($cells->item($column)->textContent));
            }
            foreach (array(0 => '', 4 => '&tab=notifications', 5 => '&tab=logs') as $column => $tab) {
                self::assertSame('/managers.php?action=edit' . $tab . '&id=' . $id, $xpath->query('./a', $cells->item($column))->item(0)->getAttribute('href'));
            }
            self::assertSame((string) $id, trim($cells->item(1)->textContent));
            self::assertCount(0, $xpath->query('.//script', $row));
        }
        self::assertSame($ids, $actual);
        self::assertSame($state['before'], $state['after']);
        self::assertSame('preserved', $state['session']['sentinel']);
        self::assertSame($request['filter'] ?? '', $xpath->query('//input[@id="filter"]')->item(0)->getAttribute('value'));
        self::assertSame((string) ($request['rows'] ?? -1), $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame('Two & more', $xpath->query('//select[@id="rows"]/option[@value="2"]')->item(0)->textContent);
        self::assertCount(1, $xpath->query('//form[@id="form_snmpagent_managers"]'));
        self::assertCount(1, $xpath->query('//input[@name="action_receivers"][@value="1"]'));
        $size = $request['rows'] ?? 2;
        if ($total > 0) {
            self::assertStringContainsString($total <= $size ? 'All ' . $total . ' Receivers' : 'of ' . $total . ' [', $document->textContent);
        }
        $reads = array_values(array_filter($state['queries'], static fn(array $query): bool => str_contains($query[0], 'FROM snmpagent_managers AS sm')));
        self::assertCount(2, $reads);
        self::assertStringContainsString('COUNT(sm.id)', $reads[0][0]);
        self::assertStringContainsString('GROUP BY manager_id', $reads[1][0]);
        $offset = $size * (($request['page'] ?? 1) - 1);
        self::assertStringContainsString(' LIMIT ' . $offset . ',' . $size, $reads[1][0]);
        if ($ids === array()) {
            self::assertStringContainsString('No SNMP Notification Receivers', $document->textContent);
        }
    }

    public function testHostnameHighlightKeepsStoredMarkupAsText(): void
    {
        $state = $this->render(array('filter' => 'Receiver'), true);
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $cell = $xpath->query('//tr[@id="line1"]/td[4]')->item(0);
        self::assertSame('Receiver & <script>', trim($cell->textContent));
        self::assertSame('Receiver', $xpath->query('./span[@class="filteredValue"]', $cell)->item(0)?->textContent);
        self::assertCount(0, $xpath->query('.//script', $cell));
        self::assertSame($state['before'], $state['after']);
    }

    /** @dataProvider tabCases */
    public function testOriginalManagerTabsKeepScopedNotificationsAndLogRows(string $tab, int $id, array $filters, array $expected): void
    {
        $state = $this->render(array_merge(array('action' => 'edit', 'tab' => $tab, 'id' => $id), $filters));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        self::assertSame('managers.php?action=edit&id=' . $id . '&tab=' . $tab, ltrim($xpath->query('//li[@class="subTab"]/a[@class="selected"]')->item(0)->getAttribute('href'), '/'));
        $actual = array();
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $cells = $xpath->query('./td', $row);
            $actual[] = trim($cells->item($tab === 'logs' ? 2 : 0)->textContent);
            self::assertCount(0, $xpath->query('.//script', $row));
            if ($tab === 'logs') {
                self::assertSame($actual[array_key_last($actual)] === 'Name & <script>' ? 'Bind & <script>' : ($id === 2 ? 'Foreign' : 'Second'), trim($cells->item(3)->textContent));
            } else {
                $enabled = ($id === 1 && in_array(end($actual), array('Name & <script>', 'Other'), true)) || ($id === 2 && end($actual) === 'Foreign');
                self::assertSame($enabled ? 'Enabled' : 'Disabled', trim($cells->item(5)->textContent));
            }
        }
        self::assertSame($expected, $actual);
        self::assertSame($state['before'], $state['after']);
        self::assertSame($filters['filter'] ?? '', $xpath->query('//input[@id="filter"]')->item(0)->getAttribute('value'));
        if ($tab === 'notifications') {
            self::assertSame((string) ($filters['mib'] ?? -1), $xpath->query('//select[@id="mib"]/option[@selected]')->item(0)->getAttribute('value'));
            self::assertSame('Two & more', $xpath->query('//select[@id="rows"]/option[@value="2"]')->item(0)->textContent);
            $lookups = array_values(array_filter($state['queries'], static fn(array $query): bool => str_contains($query[0], 'SELECT notification, mib')));
            self::assertCount(1, $lookups);
            self::assertSame(array($id), $lookups[0][1]);
        } else {
            self::assertSame((string) ($filters['severity'] ?? -1), $xpath->query('//select[@id="severity"]/option[@selected]')->item(0)->getAttribute('value'));
            $lookups = array_values(array_filter($state['queries'], static fn(array $query): bool => str_contains($query[0], 'FROM snmpagent_notifications_log AS snl')));
            self::assertCount(2, $lookups);
            foreach ($lookups as $query) {
                self::assertStringContainsString("snl.manager_id='" . $id . "'", $query[0]);
            }
        }
        if ($expected === array()) {
            self::assertStringContainsString($tab === 'logs' ? 'No SNMP Notification Log Entries' : 'No SNMP Notifications', $document->textContent);
        }
    }

    public static function tabCases(): array
    {
        return array(
            'registered notification rows' => array('notifications', 1, array(), array('Name & <script>', 'Other')),
            'notification next page unregistered' => array('notifications', 1, array('page' => 2), array('Foreign')),
            'selected mib and manager registration' => array('notifications', 2, array('mib' => 'MIB-B'), array('Foreign')),
            'empty notification search' => array('notifications', 1, array('filter' => 'missing'), array()),
            'logs exclude another receiver' => array('logs', 1, array(), array('Other', 'Name & <script>')),
            'foreign receiver logs' => array('logs', 2, array(), array('Foreign')),
            'selected severity logs' => array('logs', 1, array('severity' => 1), array('Name & <script>')),
            'empty receiver log search' => array('logs', 1, array('filter' => 'missing'), array()),
        );
    }

    public static function managerCases(): array
    {
        return array(
            'default persisted count and aggregates' => array(array(), array(2, 1), 3),
            'next page zero aggregates' => array(array('page' => 2), array(3), 3),
            'explicit rows with escaped label' => array(array('rows' => 1), array(2), 3),
            'description literal markup filter' => array(array('filter' => 'Alpha & <script>'), array(1), 1),
            'hostname filter' => array(array('filter' => '192.0.2.2'), array(2), 1),
            'empty search results' => array(array('filter' => 'missing'), array(), 0),
            'descending identity order' => array(array('sort_column' => 'id', 'sort_direction' => 'DESC'), array(3, 2), 3),
            'all records one page' => array(array('rows' => 10), array(2, 1, 3), 3),
        );
    }

    private function render(array $request, bool $hostnameMarkup = false): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/manager-view-' . bin2hex(random_bytes(8));
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $scenario = json_encode(array('view' => 'manager', 'request' => $request, 'hostname_markup' => $hostnameMarkup), JSON_THROW_ON_ERROR);
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
                $sources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'tests/Unit/UtilityViewNativeCoverageTest.php', 'tests/Unit/ManagerNativeCoverageTest.php', 'utilities.php', 'managers.php', 'lib/html.php', 'lib/html_utility.php', 'lib/functions.php', 'lib/clog_webapi.php', 'src/Platform/Infrastructure/Legacy/UtilityRows.php', 'include/global_constants.php', 'include/global_session.php', 'lib/html_form.php', 'lib/variables.php', 'lib/utility.php');
                $markers = array('utility-view-observed:manager');
                $hits = array('managers.php', 'lib/html.php', 'lib/functions.php');
                $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/utility-view-native.php', $scenario, $sources, $markers, $hits);
                static $omissionsVerified = false;
                if (!$omissionsVerified) {
                    self::assertSame(38, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/utility-view-native.php', $scenario, $sources, $markers, $hits, 'lib/boost.php'));
                    $omissionsVerified = true;
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
