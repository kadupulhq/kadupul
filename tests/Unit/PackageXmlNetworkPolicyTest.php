<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('every package XML parse site explicitly denies network access', function () {
    $parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
    $nodes = $parser->parse(file_get_contents(dirname(__DIR__, 2) . '/lib/import.php'));
    $calls = (new PhpParser\NodeFinder())->find($nodes, static function ($node) {
        return $node instanceof PhpParser\Node\Expr\FuncCall && $node->name instanceof PhpParser\Node\Name && strtolower($node->name->toString()) === 'simplexml_load_string';
    });
    expect($calls)->not->toBeEmpty();
    foreach ($calls as $call) {
        expect($call->args)->toHaveCount(3)
            ->and($call->args[2]->value)->toBeInstanceOf(PhpParser\Node\Expr\ConstFetch::class)
            ->and($call->args[2]->value->name->toString())->toBe('LIBXML_NONET');
    }
});

test('production gzip package parser handles valid malformed and external DTD documents without network access', function ($xml, $expected, $fatal) {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($listener)->not->toBeFalse();
    $address = stream_socket_get_name($listener, false);
    $xml = str_replace('LISTENER', $address, $xml);
    $dir = sys_get_temp_dir() . '/package-xml-native-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    file_put_contents($dir . '/package.xml.gz', gzencode($xml));
    try {
        $report = packageXmlNativeRun('xml', $this->getTestResultObject()->getCodeCoverage(), $dir, $dir . '/package.xml.gz');
        $read = array($listener);
        $write = $except = null;
        expect($report['result']['name'] ?? null)->toBe($expected)
            ->and(stream_select($read, $write, $except, 0))->toBe(0);
        if ($fatal) {
            expect($report['logs'])->toContain('FATAL: Unable to parse package XML structure.');
        } else {
            expect($report['logs'])->toBe(array());
        }
    } finally {
        fclose($listener);
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        } rmdir($dir);
    }
})->with(array(
    array('<package><info><name>Fixture</name></info></package>', 'Fixture', false),
    array('<package><info>', null, true),
    array('<!DOCTYPE package SYSTEM "http://LISTENER/external.dtd"><package><info><name>Fixture</name></info></package>', 'Fixture', false)
));

test('production friendly-name lookup preserves each table column type label and escaping', function () {
    $report = packageXmlNativeRun('names', $this->getTestResultObject()->getCodeCoverage());
    foreach ($report['result'] as $type => $names) {
        if (!is_array($names)) {
            continue;
        }
        expect($names)->toBe(array('&lt;' . $type . '&gt;', '(<em>' . $type . '</em>) &lt;' . $type . '&gt;'));
    }
    expect($report['result']['archive'])->toBe('')->and($report['result']['invalid'])->toBe('Unknown Field')->and($report['result']['custom'])->toBe('&lt;custom&gt;');
});

test('production host-template import preserves both associations and rejection preview and cache boundaries', function ($mode) {
    $report = packageXmlNativeRun($mode, $this->getTestResultObject()->getCodeCoverage());
    $expected = $mode === 'host' ? array(array(7, 11)) : array();
    expect($report['result']['returned'])->toBe($mode !== 'invalid')
        ->and($report['result']['graphs'])->toBe($expected)
        ->and($report['result']['queries'])->toBe($mode === 'host' ? array(array(7, 12)) : array());
})->with(array('host', 'preview', 'invalid', 'uncached', 'empty'));

function packageXmlNativeRun($mode, $coverage, $directory = null, $package = '')
{
    $owned = $directory === null;
    $directory = $directory ?? sys_get_temp_dir() . '/package-native-coverage-' . bin2hex(random_bytes(8));
    if ($owned) {
        mkdir($directory, 0700);
    }
    try {
        $environment = getenv();
        if ($coverage !== null) {
            $environment['PACKAGE_XML_COVERAGE_DIRECTORY'] = $directory;
        }
        $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', dirname(__DIR__) . '/Fixtures/package-xml-native.php', $mode, $package), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $environment);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if ($owned) {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }
}
