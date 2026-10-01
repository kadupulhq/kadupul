<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

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
        $selector = $view === 'user' ? '//tr[starts-with(@id,"line")]/td[1]' : ($view === 'poller' ? '//tr[@class="odd" or @class="even"]/td[1][a]' : '//tr[contains(@class,"tableRow")]/td[1]');
        foreach ($xpath->query($selector) as $cell) {
            $rows[] = trim($cell->textContent);
        }
        self::assertSame($expected, $rows);
        self::assertSame($state['before'], $state['after']);
        self::assertCount(1, $xpath->query('//script'));
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
        if ($view === 'user') {
            self::assertSame('Alpha & <script>', $xpath->query('//select[@id="username"]/option[@value="Alpha & <script>"]')->item(0)->textContent);
            if (($request['username'] ?? '') === '-2') {
                self::assertStringContainsString('(User Removed)', $xpath->query('//tr[@id="line0"]')->item(0)->textContent);
                self::assertStringContainsString('Success - Password Change', $xpath->query('//tr[@id="line0"]')->item(0)->textContent);
            }
        } else {
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

    public static function viewCases(): array
    {
        return array(
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
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage') as $report) {
                unlink($report);
            }
            unlink($directory . '/lib');
            unlink($directory . '/include/auth.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
}
