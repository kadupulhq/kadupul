<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 1) . '/Helpers/PestCodeCoverageCompatibility.php';

final class AdminListNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    private static bool $coverageEvidenceChecked = false;
    private static bool $gridCoverageEvidenceChecked = false;

    #[\PHPUnit\Framework\Attributes\DataProvider('listCases')]
    public function testNativeListsPreserveFiltersPaginationAndScopedRows(bool $group, array $request, array $expectedRows): void
    {
        $state = $this->render(array('group' => $group, 'request' => $request));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $actualRows = array();
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $actualRows[] = $row->getAttribute('id');
        }
        self::assertSame($expectedRows, $actualRows);
        self::assertSame((string) ($request['rows'] ?? 2), $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame('Two & more', $xpath->query('//select[@id="rows"]/option[@value="2"]')->item(0)->textContent);
        self::assertSame($request['filter'] ?? '', $xpath->query('//input[@id="filter"]')->item(0)->getAttribute('value'));
        self::assertCount(0, $xpath->query('//script[contains(text(),"Alpha")]'));
        if (!$group) {
            self::assertSame('A & B', $xpath->query('//select[@id="group"]/option[@value="7"]')->item(0)->textContent);
            self::assertSame((string) ($request['group'] ?? -1), $xpath->query('//select[@id="group"]/option[@selected]')->item(0)->getAttribute('value'));
        }
        if (in_array($group ? 'line7' : 'line1', $expectedRows, true)) {
            self::assertStringContainsString('Alpha & <script>', $xpath->query('//tr[@id="' . ($group ? 'line7' : 'line1') . '"]')->item(0)->textContent);
        }
        if ($group && in_array('line7', $expectedRows, true)) {
            $cells = $xpath->query('//tr[@id="line7"]/td');
            self::assertSame('2', trim($cells->item(1)->textContent));
            self::assertSame('DENY', trim($cells->item(4)->textContent));
            self::assertSame('Yes', trim($cells->item(6)->textContent));
        }
        if (($request['page'] ?? 1) === 2) {
            self::assertGreaterThan(0, $xpath->query('//a[contains(@data-url,"page=1")]')->length);
        }
        self::assertGreaterThanOrEqual(2, count($state['queries']));
    }

    public static function listCases(): array
    {
        return array(
            'users default' => array(false, array(), array('line1', 'line2')),
            'users page two' => array(false, array('page' => 2), array('line3')),
            'users descending' => array(false, array('sort_direction' => 'DESC'), array('line3', 'line2')),
            'users realm' => array(false, array('realm' => 2), array('line3')),
            'users selected group' => array(false, array('group' => 8), array('line3')),
            'users filter' => array(false, array('filter' => 'Alpha'), array('line1')),
            'users no match' => array(false, array('filter' => 'missing'), array()),
            'groups default rows' => array(true, array('rows' => -1), array('line7', 'line8')),
            'groups page two' => array(true, array('rows' => 1, 'page' => 2), array('line8')),
            'groups literal ampersand' => array(true, array('filter' => 'A & B'), array('line7')),
            'groups no match' => array(true, array('filter' => 'missing'), array()),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templateCases')]
    public function testTemplateGridsUseTargetTypedExceptionsAndActualGraphCounts(bool $group, int $policy, string $associated, string $filter): void
    {
        $state = $this->render(array('group' => $group, 'grid' => true, 'policy' => $policy, 'associated' => $associated, 'request' => array('filter' => $filter)));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $expected = $filter === 'missing' ? array() : ($filter === 'Beta' ? array('line20') : ($associated === 'true' ? array('line10') : array('line10', 'line20')));
        $actual = array();
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $actual[] = $row->getAttribute('id');
        }
        self::assertSame($expected, $actual);
        $expectedNavigation = $expected ? 'All ' . count($expected) . ' Graph Templates' : 'No Graph Templates Found';
        self::assertSame($expectedNavigation, trim($xpath->query('//div[@class="navBarNavigationNone"]')->item(0)->textContent));
        foreach ($expected as $row) {
            $cells = $xpath->query('//tr[@id="' . $row . '"]/td');
            $exception = $row === 'line10';
            $granted = ($policy === 1) !== $exception;
            self::assertSame($granted ? 'Access Granted' : 'Access Restricted', trim($cells->item(2)->textContent));
            self::assertSame($exception ? '2' : '1', trim($cells->item(3)->textContent));
        }
        self::assertSame((string) $policy, $xpath->query('//select[@id="policy_graph_templates"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame('permste', $xpath->query('//form[@id="chk"]//input[@name="tab"]')->item(0)->getAttribute('value'));
        self::assertSame($group ? '7' : '1', $xpath->query('//form[@id="chk"]//input[@name="id"]')->item(0)->getAttribute('value'));
        self::assertSame('1', $xpath->query('//form[@id="chk"]//input[@name="associate_template"]')->item(0)->getAttribute('value'));
        self::assertSame($state['permissions_before'], $state['permissions_after']);
        self::assertCount(0, $xpath->query('//script[contains(text(),"Alpha")]'));
    }

    public static function templateCases(): array
    {
        $cases = array();
        foreach (array(false, true) as $group) {
            foreach (array(1, 2) as $policy) {
                $cases[($group ? 'group' : 'user') . ' policy ' . $policy] = array($group, $policy, 'false', '');
            }
            $cases[($group ? 'group' : 'user') . ' exceptions only'] = array($group, 1, 'true', '');
            $cases[($group ? 'group' : 'user') . ' foreign exception ignored'] = array($group, 1, 'false', 'Beta');
            $cases[($group ? 'group' : 'user') . ' missing'] = array($group, 1, 'false', 'missing');
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templateWorkCases')]
    public function testTemplateCountsAvoidGraphInventoryWorkAndPreserveRowTotals(bool $group, string $associated, array $request, array $expectedRows, array $expectedTotals, int $total): void
    {
        $state = $this->render(array('group' => $group, 'grid' => true, 'graph_work' => true, 'policy' => 1, 'associated' => $associated, 'request' => $request));
        $counts = array_values(array_filter($state['query_work'], static fn($query) => str_contains($query['sql'], 'COUNT(DISTINCT gt.id)')));
        $lists = array_values(array_filter($state['query_work'], static fn($query) => str_contains($query['sql'], 'COUNT(DISTINCT gl.id)')));
        self::assertCount(1, $counts);
        self::assertCount(1, $lists);
        self::assertSame(0, $counts[0]['graph_reads'], 'Template count must not scan graph instances.');
        self::assertSame($total, $counts[0]['result']);
        self::assertGreaterThan(0, $lists[0]['graph_reads']);
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $rows = array();
        $totals = array();
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $rows[] = $row->getAttribute('id');
            $totals[] = trim($xpath->query('./td', $row)->item(3)->textContent);
        }
        self::assertSame($expectedRows, $rows);
        self::assertSame($expectedTotals, $totals);
        self::assertSame($state['permissions_before'], $state['permissions_after']);
        if (($request['page'] ?? 1) === 2) {
            self::assertGreaterThan(0, $xpath->query('//a[contains(@data-url,"page=3")]')->length);
        } else {
            self::assertSame('All ' . $total . ' Graph Templates', trim($xpath->query('//div[@class="navBarNavigationNone"]')->item(0)->textContent));
        }
    }

    public static function templateWorkCases(): array
    {
        $cases = array();
        foreach (array(false, true) as $group) {
            $prefix = $group ? 'group ' : 'user ';
            $cases[$prefix . 'all including empty'] = array($group, 'false', array('rows' => 10), array('line10', 'line20', 'line30'), array('2002', '1', '0'), 3);
            $cases[$prefix . 'scoped exceptions with graph fanout'] = array($group, 'true', array('rows' => 10), array('line10'), array('2002'), 1);
            $cases[$prefix . 'foreign and wrong-type grants'] = array($group, 'false', array('filter' => 'Beta'), array('line20'), array('1'), 1);
            $cases[$prefix . 'empty graph template'] = array($group, 'false', array('filter' => 'Empty'), array('line30'), array('0'), 1);
            $cases[$prefix . 'second page'] = array($group, 'false', array('rows' => 1, 'page' => 2), array('line20'), array('1'), 3);
        }
        return $cases;
    }

    private function render(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/permission-filter-' . bin2hex(random_bytes(8));
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/admin-list-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
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
                require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
                $requiredHits = array($scenario['group'] ? 'user_group_admin.php' : 'user_admin.php', 'lib/html.php');
                if (!empty($scenario['grid'])) {
                    $requiredHits[] = 'src/IdentityAccess/Infrastructure/Legacy/PermissionTemplateGrid.php';
                }
                $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/admin-list-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('user_admin.php', 'user_group_admin.php', 'lib/html.php', 'lib/html_form.php', 'lib/html_utility.php', 'lib/functions.php', 'lib/variables.php', 'include/global_constants.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionTemplateGrid.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array('native-list-rendered', 'list-state-readback'), $requiredHits);
                if (!self::$coverageEvidenceChecked || (!empty($scenario['grid']) && !self::$gridCoverageEvidenceChecked)) {
                    self::assertSame(32, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/admin-list-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('user_admin.php', 'user_group_admin.php', 'lib/html.php', 'lib/html_form.php', 'lib/html_utility.php', 'lib/functions.php', 'lib/variables.php', 'include/global_constants.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionTemplateGrid.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array('native-list-rendered', 'list-state-readback'), $requiredHits, 'lib/rrd.php'));
                    self::$coverageEvidenceChecked = true;
                    if (!empty($scenario['grid'])) {
                        self::$gridCoverageEvidenceChecked = true;
                    }
                }
                $coverage->merge($childCoverage);
            }
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage') as $report) {
                if (is_file($report . '.json')) {
                    unlink($report . '.json');
                }
                unlink($report);
            }
            unlink($directory . '/include/auth.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
}
