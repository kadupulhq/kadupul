<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class PermissionRequestNativeTest extends TestCase
{
    /** @dataProvider requestCases */
    public function testNativePermissionFiltersPreserveSessionAndOrderedDefaults(bool $group, string $kind, string $prefix, string $selector, string $mode): void
    {
        $session = array('sentinel' => 'keep', 'sess_unrelated_filter' => 'foreign', 'sess_unrelated_page' => 6);
        $foreignPrefixes = array_diff(array('sess_uag', 'sess_uagr', 'sess_uad', 'sess_uate', 'sess_uatr', 'sess_ugg', 'sess_ugd', 'sess_ugte', 'sess_ugtr', 'sess_ugm'), array($prefix));
        foreach ($foreignPrefixes as $foreignPrefix) {
            $session[$foreignPrefix . '_filter'] = 'foreign ' . $foreignPrefix;
            $session[$foreignPrefix . '_page'] = 6;
        }
        $remembered = array('rows' => 13, 'page' => 5, 'filter' => 'remembered', 'associated' => 'false');
        $explicit = array('rows' => '9', 'page' => '4', 'filter' => 'Needle & <tag>', 'associated' => 'false');
        $defaults = array('rows' => 23, 'page' => '1', 'filter' => '', 'associated' => 'true');
        if ($selector !== '') {
            $remembered[$selector] = 18;
            $explicit[$selector] = '17';
            $defaults[$selector] = '-1';
        }
        if (in_array($mode, array('remembered', 'clear', 'changed', 'changed_rows', 'changed_associated', 'changed_selector', 'page_only', 'reset'), true)) {
            foreach ($remembered as $name => $value) {
                $session[$name === 'rows' ? 'sess_default_rows' : $prefix . '_' . $name] = $value;
            }
            // Rows intentionally use the shared key, not this namespaced key.
            $session[$prefix . '_rows'] = 88;
        }
        $request = array();
        $expected = $defaults;
        if ($mode === 'explicit' || $mode === 'reset') {
            $request = $explicit;
            $expected = array('rows' => 9, 'page' => 4, 'filter' => $explicit['filter'], 'associated' => 'false');
            if ($selector !== '') {
                $expected[$selector] = 17;
            }
            if ($mode === 'reset') {
                $request['reset'] = '1';
            }
        } elseif ($mode === 'remembered') {
            $expected = $remembered;
        } elseif ($mode === 'clear') {
            $request = array('clear' => '1') + $explicit;
        } elseif (str_starts_with($mode, 'changed')) {
            $name = array('changed' => 'filter', 'changed_rows' => 'rows', 'changed_associated' => 'associated', 'changed_selector' => $selector)[$mode];
            $value = array('changed' => 'changed', 'changed_rows' => 7, 'changed_associated' => 'true', 'changed_selector' => 22)[$mode];
            $request = array($name => (string) $value, 'page' => '8');
            $expected = $remembered;
            $expected[$name] = $value;
            $expected['page'] = 1;
        } elseif ($mode === 'page_only') {
            $request = array('page' => '8');
            $expected = $remembered;
            $expected['page'] = 8;
        } elseif ($mode === 'undefined') {
            $request = array_fill_keys(array_keys($defaults), 'undefined');
        }
        $state = $this->render(array('group' => $group, 'kind' => $kind, 'request' => $request, 'session' => $session));
        foreach ($expected as $name => $value) {
            self::assertSame($value, $state['request'][$name], $name);
            self::assertSame($value, $state['session'][$name === 'rows' ? 'sess_default_rows' : $prefix . '_' . $name], $name . ' session');
        }
        self::assertSame('keep', $state['session']['sentinel']);
        self::assertSame('foreign', $state['session']['sess_unrelated_filter']);
        self::assertSame(6, $state['session']['sess_unrelated_page']);
        if (isset($session[$prefix . '_rows'])) {
            self::assertSame(88, $state['session'][$prefix . '_rows']);
        }
        foreach ($foreignPrefixes as $foreignPrefix) {
            self::assertSame('foreign ' . $foreignPrefix, $state['session'][$foreignPrefix . '_filter']);
            self::assertSame(6, $state['session'][$foreignPrefix . '_page']);
        }
        self::assertSame(str_starts_with($mode, 'changed'), isset($state['request']['changed']));
        self::assertSame('', $state['output']);
        if ($mode === 'defaults') {
            $keys = array('action', 'rows', 'page', 'filter');
            if (!$group && $selector !== '') {
                $keys[] = $selector;
            }
            $keys[] = 'associated';
            if ($group && $selector !== '') {
                $keys[] = $selector;
            }
            self::assertSame($keys, array_keys($state['request']));
        }
    }

    public static function requestCases(): array
    {
        $choices = array(
            array(false, 'graph', 'sess_uag', 'graph_template_id'),
            array(false, 'group', 'sess_uagr', ''),
            array(false, 'device', 'sess_uad', 'host_template_id'),
            array(false, 'template', 'sess_uate', 'graph_template_id'),
            array(false, 'tree', 'sess_uatr', 'graph_template_id'),
            array(true, 'graph', 'sess_ugg', 'graph_template_id'),
            array(true, 'device', 'sess_ugd', 'host_template_id'),
            array(true, 'template', 'sess_ugte', 'host_template_id'),
            array(true, 'tree', 'sess_ugtr', ''),
            array(true, 'member', 'sess_ugm', ''),
        );
        $cases = array();
        foreach ($choices as $choice) {
            foreach (array('defaults', 'explicit', 'remembered', 'clear', 'changed', 'changed_rows', 'changed_associated', 'changed_selector', 'page_only', 'reset', 'undefined') as $mode) {
                if ($mode === 'changed_selector' && $choice[3] === '') {
                    continue;
                }
                $cases[($choice[0] ? 'group ' : 'user ') . $choice[1] . ' ' . $mode] = array_merge($choice, array($mode));
            }
        }
        return $cases;
    }

    /** @dataProvider rejectedChoices */
    public function testClosedHelperRejectsUnknownContextsWithoutChangingState(bool $group, string $kind): void
    {
        $session = array('sess_default_rows' => 13, 'sentinel' => 'keep');
        $request = array('filter' => 'unchanged');
        $state = $this->render(array('group' => $group, 'kind' => $kind, 'request' => $request, 'session' => $session, 'reject' => true));
        self::assertSame(InvalidArgumentException::class, $state['error']);
        self::assertSame(array('action' => 'fixture') + $request, $state['request']);
        self::assertSame($session, $state['session']);
        self::assertSame('', $state['output']);
    }

    public static function rejectedChoices(): array
    {
        return array(array(false, 'member'), array(true, 'group'), array(false, 'unknown'), array(true, 'unknown'));
    }

    private function render(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/permission-filter-' . bin2hex(random_bytes(8));
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/permission-request-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
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
