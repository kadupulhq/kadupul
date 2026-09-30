<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class FormColorDropdownOutputTest extends TestCase
{
    /** @dataProvider dropdowns */
    public function testNativeDropdownEncodesEveryOutputAndKeepsItsSessionKey(array $scenario): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/color-dropdown-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $command = array(PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/color-dropdown-native.php', json_encode($scenario, JSON_THROW_ON_ERROR));
            if ($coverage !== null) {
                $command[] = $directory;
            }
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
            self::assertSame('', $stderr);
            $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                self::assertTrue($document->loadHTML($result['html'], LIBXML_NONET));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new DOMXPath($document);
            self::assertCount(1, $xpath->query('//select'));
            $select = $xpath->query('//select')->item(0);
            self::assertSame($scenario['name'], $select->getAttribute('id'));
            self::assertSame($scenario['name'], $select->getAttribute('name'));
            self::assertSame('colordropdown' . ($scenario['class'] !== '' ? ' ' . $scenario['class'] : ''), $select->getAttribute('class'));
            self::assertFalse($select->hasAttribute('onfocus'));
            $expectedPrevious = $scenario['previous'] === '' ? $scenario['default'] : $scenario['previous'];
            $expectedColor = '';
            $selected = '';
            foreach ($scenario['colors'] as $color) {
                if ($color[0] === $expectedPrevious) {
                    $expectedColor = $color[1];
                    $selected = (string) (int) $color[0];
                }
            }
            self::assertSame('background-color: #' . $expectedColor . ';', $select->getAttribute('style'));
            $none = !empty($scenario['handoff']) ? 'None' : $scenario['none'];
            $options = $xpath->query('//select/option');
            self::assertCount(count($scenario['colors']) + ($none !== '' ? 1 : 0), $options);
            if ($none !== '') {
                self::assertSame('0', $options->item(0)->getAttribute('value'));
                self::assertSame($none, $options->item(0)->textContent);
            }
            foreach ($scenario['colors'] as $index => $color) {
                $option = $options->item($index + ($none !== '' ? 1 : 0));
                self::assertSame((string) (int) $color[0], $option->getAttribute('value'));
                self::assertSame($color[1], $option->getAttribute('data-color'));
                self::assertSame('background-color: #' . $color[1] . ';', $option->getAttribute('style'));
                self::assertSame($color[0] === $expectedPrevious, $option->hasAttribute('selected'));
                self::assertSame($color[2] === '' ? 'Kadupul Color (' . $color[1] . ')' : $color[2] . ' (' . $color[1] . ')', $option->textContent);
            }
            self::assertSame('this.style.backgroundColor=this.options[this.selectedIndex].style.backgroundColor;setColour()', $result['session']['form_change_actions'][$scenario['name']]);
            self::assertCount(0, $xpath->query('//x|//script'));
            if (!empty($scenario['handoff']) && !empty($scenario['spacer'])) {
                $spacer = $xpath->query('//div[contains(@class, "formHeaderText")]');
                self::assertCount(1, $spacer);
                self::assertSame('Header <x>untrusted</x>', $spacer->item(0)->textContent);
                self::assertCount(0, $xpath->query('//*[@onfocus]'));
            }
            if (!empty($scenario['handoff'])) {
                $row = $xpath->query('//div[contains(@class, "formRow")]')->item(0);
                self::assertSame('row_' . $scenario['name'], $row->getAttribute('id'));
            }
            if (!empty($scenario['controls'])) {
                foreach (array('font', 'dirpath', 'filepath') as $id) {
                    $input = $xpath->query('//input[@id="' . $id . '"]')->item(0);
                    $value = $scenario['session']['sess_field_values'][$id] ?? (!empty($scenario['controls_empty']) ? 'default' : 'saved');
                    self::assertSame($value, $input->getAttribute('value'));
                    self::assertSame('64', $input->getAttribute('maxlength'));
                    self::assertSame(!empty($scenario['session']['sess_error_fields'][$id]), str_contains($input->getAttribute('class'), 'txtErrorTextBox'));
                }
                self::assertTrue($xpath->query('//input[@id="enabled"]')->item(0)->hasAttribute('checked'));
                self::assertSame('.xml', $xpath->query('//input[@id="file"]')->item(0)->getAttribute('accept'));
            }
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }

    public function dropdowns(): array
    {
        $plain = array('name' => 'colour', 'previous' => '5', 'default' => '', 'none' => 'None', 'class' => '', 'colors' => array(array('5', 'FFFFFF', 'White')));
        $hostile = array_replace($plain, array('name' => "n'><x", 'class' => "c' onfocus='alert(1)", 'none' => 'None<i>', 'colors' => array(array('5', "A'\"><x", 'Name<i>'))));
        $plain['spacer'] = true;
        return array(array($hostile), array(array_replace($plain, array('previous' => '', 'default' => '5', 'none' => ''))), array(array_replace($plain, array('previous' => 'missing', 'colors' => array()))), array(array_replace($plain, array('colors' => array(array('7junk', '123456', 'Prefix'), array('junk', 'ABCDEF', ''))))), array(array_replace($hostile, array('handoff' => true))), array(array_replace($plain, array('handoff' => true, 'controls' => true, 'controls_empty' => true))), array(array_replace($plain, array('handoff' => true, 'controls' => true, 'session' => array('sess_error_fields' => array('font' => true, 'dirpath' => true), 'sess_field_values' => array('font' => 'submitted', 'dirpath' => 'submitted'))))));
    }
}
