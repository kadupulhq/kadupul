<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Execute the exact production function bodies in a fresh PHP process, avoiding
// page routing/database bootstrap and cross-test global function collisions.
function csvProductionFunction(string $file, string $name): string
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    $parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();

    foreach ($parser->parse($source) as $node) {
        if ($node instanceof PhpParser\Node\Stmt\Function_ && $node->name->toString() === $name) {
            return substr($source, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
        }
    }

    throw new RuntimeException('Missing production function: ' . $name);
}

function csvRunProduction(string $program, array $input): string
{
    $program = 'set_error_handler(function ($level, $message) { throw new RuntimeException($message); });' . $program;
    $process = proc_open([PHP_BINARY, '-d', 'error_reporting=-1', '-r', $program], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error);

    return $output;
}

test('device export emits safe public CSV without deprecated defaults', function () {
    $program = 'require ' . var_export(dirname(__DIR__, 2) . '/include/vendor/autoload.php', true) . ';';
    $program .= <<<'CODE'
$rows = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$devices = array_map(fn ($text) => new \Kadupul\Inventory\Application\ReadModel\DeviceSummary(7, $text, $text, false, 'Up', $text, $text), $rows);
echo (new \Kadupul\Inventory\Infrastructure\Symfony\Export\DevicePageCsv())->encode(new \Kadupul\Inventory\Application\ReadModel\DevicePage($devices, false));
CODE;
    $text = "=quoted \"value\",here\\path\nsecond";
    $output = csvRunProduction($program, [$text]);
    $stream = new SplTempFileObject();
    $stream->fwrite(substr($output, 3));
    $stream->rewind();
    $headers = ['ID', 'Name', 'Hostname', 'Status', 'Location', 'External ID'];
    expect($stream->fgetcsv(',', '"', ''))->toBe($headers)
        ->and($stream->fgetcsv(',', '"', ''))->toBe(['7', "'" . $text, "'" . $text, 'Up', "'" . $text, "'" . $text])
        ->and($stream->fgetcsv(',', '"', ''))->toBeFalse();
    expect(csvRunProduction($program, []))->toBe("\xEF\xBB\xBFID,Name,Hostname,Status,Location,\"External ID\"\r\n");
});

function csvBasicAuthProgram(): string
{
    $program = <<<'CODE'
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$_SERVER = $input['server'];
function read_config_option($key) { return $GLOBALS['input'][$key] ?? ''; }
function cacti_sizeof($value) { return count($value); }
function cacti_log($message, $output, $category) { $GLOBALS['logs'][] = $message; }
$logs = [];
CODE;

    return $program . csvProductionFunction('lib/auth.php', 'get_basic_auth_username')
        . 'echo json_encode(["username" => get_basic_auth_username(), "logs" => $logs], JSON_THROW_ON_ERROR);';
}

test('basic auth maps normalized domain usernames and quoted CSV fields through production code', function () {
    $map = tempnam(sys_get_temp_dir(), 'csv-auth-');
    try {
        $handle = fopen($map, 'w');
        fputcsv($handle, ['CORP\\jdoe', 'mapped "user",name'], ',', '"', '\\');
        // Production doubles domain separators before consulting the mapfile.
        fputcsv($handle, ['CORP\\\\jdoe', 'correct "user",name'], ',', '"', '\\');
        fputcsv($handle, ['alice', 'short-alice'], ',', '"', '\\');
        fclose($handle);
        $input = ['auth_method' => 2, 'path_basic_mapfile' => $map, 'server' => ['PHP_AUTH_USER' => 'CORP\jdoe']];
        $result = json_decode(csvRunProduction(csvBasicAuthProgram(), $input), true, 512, JSON_THROW_ON_ERROR);
        expect($result)->toBe(['username' => 'correct "user",name', 'logs' => []]);
        $input['server'] = ['PHP_AUTH_USER' => 'alice@example.invalid', 'REMOTE_USER' => 'ignored'];
        expect(json_decode(csvRunProduction(csvBasicAuthProgram(), $input), true, 512, JSON_THROW_ON_ERROR))
            ->toBe(['username' => 'short-alice', 'logs' => []]);
    } finally {
        unlink($map);
    }
});

test('basic auth ignores spoofed headers when another authentication method is selected', function () {
    $result = json_decode(csvRunProduction(csvBasicAuthProgram(), ['auth_method' => 1, 'server' => ['PHP_AUTH_USER' => 'administrator']]), true, 512, JSON_THROW_ON_ERROR);
    expect($result)->toBe(['username' => false, 'logs' => []]);
});

test('basic auth preserves an unmapped username and emits its existing warning', function () {
    $map = tempnam(sys_get_temp_dir(), 'csv-auth-');
    try {
        file_put_contents($map, "someone,short\n");
        $result = json_decode(csvRunProduction(csvBasicAuthProgram(), ['auth_method' => 2, 'path_basic_mapfile' => $map, 'server' => ['REMOTE_USER' => 'unmapped']]), true, 512, JSON_THROW_ON_ERROR);
        expect($result)->toBe(['username' => 'unmapped', 'logs' => ['WARNING: Username unmapped not found in basic mapfile.']]);
    } finally {
        unlink($map);
    }
});
