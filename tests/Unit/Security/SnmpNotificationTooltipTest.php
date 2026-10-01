<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace SnmpNotificationTooltipTest;

// manager_logs() prints a notification link whose tooltip shows the
// notification name and description. Both come from MIB data and plugins, so
// they travel as escaped data attributes, not as arguments to an inline handler.

$root = dirname(__DIR__, 3);

if (!preg_match('/^function html_escape\\(.*?^\\}/ms', file_get_contents($root . '/lib/html.php'), $match)) {
    throw new \RuntimeException('Missing html_escape()');
}
eval('namespace SnmpNotificationTooltipTest; ' . $match[0]);

$managers = file_get_contents($root . '/managers.php');
$managerLogs = substr($managers, (int) strpos($managers, "\nfunction manager_logs("));

if (!preg_match('/^            if \\(\\$item\\[\'description\'\\]\\) \\{.*?^            \\}$/ms', $managerLogs, $match)) {
    throw new \RuntimeException('Missing notification cell in manager_logs()');
}

$renderCell = eval('namespace SnmpNotificationTooltipTest; return function (array $item) { ob_start(); ' . $match[0] . ' return ob_get_clean(); };');

dataset('notifications', array(
    array('coldStart', "The device restarted.\r\n  Check the uptime.  "),
    array("x', alert(1), '", "');alert(1);//\n</pre><img src=x onerror=alert(1)>"),
    array('<img src=x onerror=alert(1)>', '" onmouseover="alert(1)'),
));

test('notification links carry the tooltip text in data attributes only', function ($notification, $description) use ($renderCell) {
    $doc = new \DOMDocument();
    $doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><table><tr>' . $renderCell(array('notification' => $notification, 'description' => $description)) . '</tr></table></body></html>');

    expect($doc->getElementsByTagName('img')->length)->toBe(0);

    foreach ($doc->getElementsByTagName('*') as $element) {
        foreach ($element->attributes as $attribute) {
            expect(strncmp(strtolower($attribute->name), 'on', 2))->not->toBe(0);
        }
    }

    $link = $doc->getElementsByTagName('a')->item(0);

    expect($link->getAttribute('class'))->toBe('snmpagentNotification');
    expect($link->getAttribute('data-notification'))->toBe($notification);
    expect($link->getAttribute('data-description'))->toBe(implode("\n", array_map('trim', preg_split('/\r\n|\r|\n/', $description))));
    expect($link->textContent)->toBe($notification);
})->with('notifications');
