<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;

final class PermissionFilterRendererTest extends TestCase
{
    private static array $coverageEvidenceChecked = array();

    /** @dataProvider filters */
    public function testNativeControllersPreserveFilterChoicesAndRoutes(string $page, string $function, string $tab, string $label, bool $defaults): void
    {
        $scenario = array('page' => $page, 'function' => $function);
        if ($defaults) {
            $scenario += array('rows' => '-1', 'associated' => '', 'graph_template_id' => '-1', 'host_template_id' => '0');
        }
        $result = $this->render($scenario);
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($result['html'], LIBXML_NOERROR | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        self::assertCount(1, $xpath->query('//form[@id="forms" and @action="' . $page . '"]'));
        self::assertSame($page === 'user_admin.php' ? 'user_edit' : 'edit', $xpath->query('//input[@name="action"]')->item(0)->getAttribute('value'));
        self::assertSame($tab, $xpath->query('//input[@name="tab"]')->item(0)->getAttribute('value'));
        self::assertSame('7', $xpath->query('//input[@name="id"]')->item(0)->getAttribute('value'));
        self::assertSame('A & B', $xpath->query('//input[@id="filter"]')->item(0)->getAttribute('value'));
        self::assertSame($defaults ? '-1' : '25', $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame('Twenty & five', $xpath->query('//select[@id="rows"]/option[@value="25"]')->item(0)->textContent);
        self::assertSame(!$defaults, $xpath->query('//input[@id="associated"]')->item(0)->hasAttribute('checked'));
        self::assertSame($label, trim($xpath->query('//label[@for="associated"]')->item(0)->textContent));
        self::assertSame($page === 'user_admin.php' ? 'Go' : 'filter: use:Go', $xpath->query('//input[@id="go"]')->item(0)->getAttribute('value'));
        self::assertSame($page === 'user_admin.php' ? 'Clear' : ($function === 'member' ? 'filter reset:Clear' : 'filter: reset:Clear'), $xpath->query('//input[@id="clear"]')->item(0)->getAttribute('value'));

        $field = array('graph' => 'graph_template_id', 'device' => 'host_template_id')[$function] ?? '';
        self::assertCount($field === '' ? 0 : 1, $result['queries']);
        if ($field !== '') {
            $options = $xpath->query('//select[@id="' . $field . '"]/option');
            self::assertSame(array('-1', '0', $function === 'graph' ? '9' : '5', $function === 'graph' ? '3' : '4'), array_map(static fn($option) => $option->getAttribute('value'), iterator_to_array($options)));
            self::assertSame($defaults ? ($function === 'graph' ? '-1' : '0') : ($function === 'graph' ? '3' : '4'), $xpath->query('//select[@id="' . $field . '"]/option[@selected]')->item(0)->getAttribute('value'));
            self::assertStringContainsString('SELECT', $result['queries'][0]);
        }
    }

    public function filters(): array
    {
        $cases = array();
        foreach (array('user_admin.php', 'user_group_admin.php') as $page) {
            $tabs = array('graph' => 'permsg', 'device' => 'permsd', 'template' => 'permste', 'tree' => 'permstr', 'member' => 'members');
            if ($page === 'user_admin.php') {
                $tabs['group'] = 'permsgr';
            }
            foreach ($tabs as $function => $tab) {
                $label = $page === 'user_admin.php' && in_array($function, array('graph', 'group'), true)
                    ? 'Show All' : ($page === 'user_group_admin.php' && $function === 'member' ? 'Show Members' : 'Only Show Exceptions');
                foreach (array(false, true) as $defaults) {
                    $cases[$page . ' ' . $function . ($defaults ? ' defaults' : ' selected')] = array($page, $function, $tab, $label, $defaults);
                }
            }
        }
        return $cases;
    }

    public function testEmptyTemplateAndRowCatalogsKeepSentinelChoices(): void
    {
        foreach (array('user_admin.php', 'user_group_admin.php') as $page) {
            foreach (array('graph', 'device') as $function) {
                $result = $this->render(array('page' => $page, 'function' => $function, 'empty' => true, 'rows' => '-1', 'associated' => 'on'));
                $document = new DOMDocument();
                self::assertTrue($document->loadHTML($result['html'], LIBXML_NOERROR | LIBXML_NONET));
                $xpath = new DOMXPath($document);
                $field = $function === 'graph' ? 'graph_template_id' : 'host_template_id';
                self::assertCount(2, $xpath->query('//select[@id="' . $field . '"]/option'));
                self::assertCount(1, $xpath->query('//select[@id="rows"]/option'));
                self::assertSame('-1', $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
                self::assertTrue($xpath->query('//input[@id="associated"]')->item(0)->hasAttribute('checked'));
            }
        }
    }

    public function testHostileFilterAndIdentifierRemainPlainFormValues(): void
    {
        $filter = "\"'><img src=x onerror=alert(1)>";
        $result = $this->render(array('page' => 'user_admin.php', 'function' => 'graph', 'filter' => $filter, 'id' => '7\"><script>alert(1)</script>'));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($result['html'], LIBXML_NOERROR | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        self::assertCount(0, $xpath->query('//img'));
        self::assertCount(1, $xpath->query('//script'));
        self::assertSame($filter, $xpath->query('//input[@id="filter"]')->item(0)->getAttribute('value'));
        self::assertSame('7', $xpath->query('//input[@name="id"]')->item(0)->getAttribute('value'));
        self::assertCount(1, $xpath->query('//label[@for="filter"]'));
        self::assertCount(1, $xpath->query('//label[@for="rows"]'));
        self::assertCount(1, $xpath->query('//label[@for="graph_template_id"]'));
    }

    public function testExistingEntitiesAndGraveAccentsKeepLegacyFilterRoundTrips(): void
    {
        $result = $this->render(array('page' => 'user_admin.php', 'function' => 'graph', 'filter' => 'A &amp; B ` &lt;value&gt;'));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($result['html'], LIBXML_NOERROR | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        self::assertSame('A & B ` <value>', $xpath->query('//input[@id="filter"]')->item(0)->getAttribute('value'));
        self::assertStringNotContainsString('&amp;amp;', $result['html']);
        self::assertStringContainsString('&#96;', $result['html']);
    }

    /** @dataProvider associations */
    public function testNativeAssociationWritesPreserveOtherItemsAndSubjects(string $page, string $flag, int $type, bool $add): void
    {
        $result = $this->render(array('page' => $page, 'association' => $flag, 'type' => $type, 'add' => $add));
        $expected = $add ? array(array('subject' => 7, 'item' => 41), array('subject' => 7, 'item' => 42), array('subject' => 7, 'item' => 43), array('subject' => 77, 'item' => 41))
            : array(array('subject' => 7, 'item' => 42), array('subject' => 77, 'item' => 41));
        self::assertSame($expected, $result['rows']);
        $writes = array_values(array_filter($result['queries'], static fn(array $query): bool => str_starts_with($query[0], 'REPLACE INTO') || str_starts_with($query[0], 'DELETE FROM')));
        self::assertCount(2, $writes);
    }

    public function associations(): array
    {
        $cases = array();
        foreach (array('user_admin.php', 'user_group_admin.php') as $page) {
            $flags = array('associate_host' => 3, 'associate_graph' => 1, 'associate_template' => 4, 'associate_tree' => 2, $page === 'user_admin.php' ? 'associate_groups' : 'associate_member' => 0);
            foreach ($flags as $flag => $type) {
                foreach (array(false, true) as $add) {
                    $cases[$page . ' ' . $flag . ($add ? ' add' : ' remove')] = array($page, $flag, $type, $add);
                }
            }
        }
        return $cases;
    }

    public function testOtherActionsDoNotWriteAssociations(): void
    {
        foreach (array('user_admin.php', 'user_group_admin.php') as $page) {
            $result = $this->render(array('page' => $page, 'no_association' => true, 'unrelated_association' => '1'));
            self::assertNull($result['tab']);
            self::assertSame(array(), $result['queries']);
        }
    }

    private function render(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/permission-filter-' . bin2hex(random_bytes(8));
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/permission-filter-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
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
                $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/permission-filter-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'lib/auth.php', 'user_admin.php', 'user_group_admin.php', 'lib/html.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionFilter.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), isset($scenario['association']) ? array('association-state-readback') : (!empty($scenario['no_association']) ? array('association-contract-returned') : array('native-filter-rendered', 'script-nodes-observed')), isset($scenario['association']) || !empty($scenario['no_association']) ? array('src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php') : array('src/IdentityAccess/Infrastructure/Legacy/PermissionFilter.php'));
                $evidenceKind = isset($scenario['association']) ? 'association' : (!empty($scenario['no_association']) ? 'contract' : 'filter');
                if (!isset(self::$coverageEvidenceChecked[$evidenceKind])) {
                    self::assertSame($evidenceKind === 'filter' ? 30 : 29, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/permission-filter-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'lib/auth.php', 'user_admin.php', 'user_group_admin.php', 'lib/html.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionFilter.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), isset($scenario['association']) ? array('association-state-readback') : (!empty($scenario['no_association']) ? array('association-contract-returned') : array('native-filter-rendered', 'script-nodes-observed')), isset($scenario['association']) || !empty($scenario['no_association']) ? array('src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php') : array('src/IdentityAccess/Infrastructure/Legacy/PermissionFilter.php'), 'lib/rrd.php'));
                    self::$coverageEvidenceChecked[$evidenceKind] = true;
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
