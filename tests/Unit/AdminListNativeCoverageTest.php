<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AdminListNativeCoverageTest extends TestCase
{
    /** @dataProvider listCases */
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
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage') as $report) {
                unlink($report);
            }
            unlink($directory . '/include/auth.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
}
