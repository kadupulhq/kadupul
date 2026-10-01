<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace DataTemplateFieldTooltipTest;

$root = dirname(__DIR__, 3);

foreach (array('lib/html.php' => 'html_escape', 'lib/html_utility.php' => 'display_tooltip') as $file => $helper) {
    if (!preg_match('/^function ' . $helper . '\\(.*?^\\}/ms', file_get_contents($root . '/' . $file), $match)) {
        throw new \RuntimeException('Missing helper: ' . $helper);
    }
    eval('namespace DataTemplateFieldTooltipTest; ' . $match[0]);
}

// The custom data rows in template_edit() choose the tooltip text here.
if (!preg_match('/if \\(\\$field\\[\'data_name\'\\] == \'management_ip\'\\) \\{.*?\\} else \\{\\s*\\$help = [^;]+;\\s*\\}/s', file_get_contents($root . '/data_templates.php'), $match)) {
    throw new \RuntimeException('Missing data template tooltip selection');
}

$selectHelp = eval('namespace DataTemplateFieldTooltipTest; return function (array $field, array $fields_host_edit) { ' . $match[0] . ' return $help; };');

function tooltip_span(string $html): \DOMElement
{
    $doc = new \DOMDocument();
    $doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>');

    foreach (array('img', 'script', 'svg', 'iframe') as $tag) {
        expect($doc->getElementsByTagName($tag)->length)->toBe(0);
    }

    foreach ($doc->getElementsByTagName('*') as $element) {
        foreach ($element->attributes as $attribute) {
            expect(strncmp($attribute->name, 'on', 2))->not->toBe(0);
        }
    }

    $spans = $doc->getElementsByTagName('span');
    expect($spans->length)->toBe(1);

    return $spans->item(0);
}

dataset('data template field names', array(
    'Index Value',
    'réseau 日本語',
    '<img src=x onerror=alert(1)>',
    '</span><script>alert(1)</script>',
    '"><svg onload=alert(1)>',
));

test('data input field names reach the template tooltip as text', function ($name) use ($selectHelp) {
    $help = $selectHelp(array('data_name' => 'custom_field', 'name' => $name), array());

    expect(tooltip_span(display_tooltip($help))->textContent)->toBe($name);
})->with('data template field names');

test('host field descriptions keep their existing tooltip text', function () use ($selectHelp) {
    $fields = array(
        'hostname'       => array('description' => 'Fully qualified hostname or IP address.'),
        'snmp_community' => array('description' => 'SNMP read community for this device.'),
    );

    expect($selectHelp(array('data_name' => 'management_ip', 'name' => 'IP'), $fields))->toBe('Fully qualified hostname or IP address.');
    expect($selectHelp(array('data_name' => 'snmp_community', 'name' => 'Community'), $fields))->toBe('SNMP read community for this device.');
});
