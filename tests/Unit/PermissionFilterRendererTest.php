<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;

final class PermissionFilterRendererTest extends TestCase
{
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
