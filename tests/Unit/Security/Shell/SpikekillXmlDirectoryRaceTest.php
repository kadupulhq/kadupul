<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace XmlTempDirRace;

require_once dirname(__DIR__, 3) . '/Helpers/SpikekillPathFunctions.php';

function random_bytes($length)
{
    $root = $GLOBALS['xml_dir_race'];
    rename($root . '/temp', $root . '/held');
    symlink($root . '/outside', $root . '/temp');
    return str_repeat('x', $length);
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/spikekill.php');
$body = '';
foreach (array('createXmlFileExclusively', 'canonicalDir') as $method) {
    if (!preg_match('/^\tprivate function ' . $method . '\(.*?^\t}\n/ms', $source, $match)) {
        throw new \RuntimeException('Missing production XML method');
    }
    $body .= $match[0];
}
eval('namespace ' . __NAMESPACE__ . '; class Probe { private $canonical_dirs = array();' . $body . '}'); // nosemgrep: php.lang.security.eval-use.eval-use

test('a directory swapped during candidate generation is refused before XML creation', function () {
    $root = sys_get_temp_dir() . '/xml-race-' . bin2hex(\random_bytes(6));
    mkdir($root . '/temp', 0700, true);
    mkdir($root . '/outside', 0700);
    $GLOBALS['xml_dir_race'] = $root;
    try {
        $method = new \ReflectionMethod(Probe::class, 'createXmlFileExclusively');
        $method->setAccessible(true);
        $result = $method->invoke(new Probe(), $root . '/temp');
        if (is_array($result) && is_resource($result['handle'])) {
            fclose($result['handle']);
        }
        expect($result)->toBeFalse()
            ->and(glob($root . '/outside/*'))->toBe(array());
    } finally {
        foreach (glob($root . '/outside/*') as $file) {
            unlink($file);
        }
        if (is_link($root . '/temp')) {
            unlink($root . '/temp');
        } else {
            rmdir($root . '/temp');
        }
        if (is_dir($root . '/held')) {
            rmdir($root . '/held');
        }
        rmdir($root . '/outside');
        rmdir($root);
        unset($GLOBALS['xml_dir_race']);
    }
});
