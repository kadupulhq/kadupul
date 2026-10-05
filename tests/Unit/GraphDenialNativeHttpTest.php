<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class GraphDenialNativeHttpTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('requests')]
    public function testWholeControllerPreservesAjaxDestinationAndOneTimeDenial(string $action, ?int $id, bool $denied, ?int $host = null): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/graph-denial-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        file_put_contents($directory . '/include/auth.php', '<?php');
        file_put_contents($directory . '/include/top_header.php', '<?php $GLOBALS["header_rendered"] = true;');
        symlink($root . '/lib', $directory . '/lib');
        $process = null;
        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
            self::assertIsResource($socket, $error);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $environment = array_merge(getenv(), ['GRAPH_DENIAL_ROOT' => $root, 'GRAPH_DENIAL_DIRECTORY' => $directory]);
            $process = proc_open(
                [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'session.save_path=' . $directory,
                    '-S', $address, $root . '/tests/Fixtures/graph-denial-native-router.php'],
                [0 => ['pipe','r'], 1 => ['file',$directory . '/stdout.log','w'], 2 => ['file',$directory . '/stderr.log','w']],
                $pipes,
                $directory,
                $environment,
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $priorHandler = null;
                $priorHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$priorHandler): bool {
                    if ($severity === E_WARNING && str_starts_with($message, 'fsockopen(): Unable to connect') && str_contains($message, '(Connection refused)')) {
                        return true;
                    }
                    return $priorHandler === null ? false : (bool) $priorHandler($severity, $message, $file, $line);
                });
                try {
                    $probe = fsockopen('tcp://' . $address, -1, $code, $error, 0.1);
                } finally {
                    restore_error_handler();
                }
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($ready, file_get_contents($directory . '/stderr.log'));
            $request = static function (string $path, ?string $cookie = null) use ($address): array {
                $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5,
                    'header' => $cookie === null ? '' : 'Cookie: ' . $cookie]]);
                $body = file_get_contents('http://' . $address . '/cacti/' . $path, false, $context);
                self::assertNotFalse($body);
                return [$body, $http_response_header];
            };
            [$body, $headers] = $request('graphs.php?' . http_build_query(['action' => $action, 'header' => 'false'] + ($id === null ? [] : ['id' => $id]) + ($host === null ? [] : ['host_id' => $host])));
            $cookies = array_values(array_filter($headers, static fn(string $value): bool => str_starts_with($value, 'Set-Cookie: PHPSESSID=')));
            self::assertCount(1, $cookies);
            $cookie = explode(';', substr($cookies[0], strlen('Set-Cookie: ')), 2)[0];
            if ($denied) {
                self::assertStringContainsString(' 302 ', $headers[0]);
                $locations = array_values(array_filter($headers, static fn(string $value): bool => str_starts_with($value, 'Location:')));
                self::assertSame(['Location: graphs.php?header=false'], $locations);
                self::assertSame('', $body);
                [$body, $headers] = $request('graphs.php?header=false', $cookie);
                self::assertStringContainsString(' 200 ', $headers[0]);
                self::assertJson($body, $body);
                $state = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('destination', $state['stage']);
                self::assertSame(['level' => 3, 'message' => '<span class="deviceDown">Graph access denied</span>'], $state['message']);
                self::assertSame([['User attempted to access an unauthorized graph','AUTH']], $state['session']['native_log']);
                self::assertArrayNotHasKey('sess_graph_lock_id', $state['session']);
                self::assertArrayNotHasKey('sess_graph_locked', $state['session']);
                [$repeat] = $request('graphs.php?header=false', $cookie);
                self::assertSame([], json_decode($repeat, true, 512, JSON_THROW_ON_ERROR)['message']);
            } else {
                self::assertStringContainsString(' 200 ', $headers[0]);
                self::assertJson($body, $body);
                $state = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame($action === 'item' ? 'admitted-item-view' : ($id === null ? 'admitted-new-editor' : 'admitted-editor'), $state['stage']);
                if ($id === null) {
                    self::assertArrayNotHasKey('id', $state['request']);
                    self::assertSame('1', $state['request']['host_id']);
                    self::assertSame(['form_target' => 'graphs.php'], $state['render']);
                    self::assertSame([], $state['reads']);
                    self::assertArrayNotHasKey('sess_graph_lock_id', $state['session']);
                } elseif ($action === 'item') {
                    self::assertSame([['id' => 10, 'sequence' => 1, 'text_format' => 'Admitted item']], $state['render']['items']);
                    self::assertSame('Graph Items [edit: Admitted graph]', $state['render']['box'][0]);
                    self::assertSame('graphs_items.php?action=item_edit&host_id=1&local_graph_id=1', $state['render']['box'][5]);
                    self::assertSame('graphs_items.php', $state['render']['target']);
                    self::assertSame('host_id=1&local_graph_id=1', $state['render']['anchor']);
                    self::assertFalse($state['render']['templated']);
                    self::assertCount(1, $state['reads']);
                    self::assertSame(['1'], $state['reads'][0][1]);
                }
                self::assertSame([], $state['message']);
                self::assertArrayNotHasKey('native_log', $state['session']);
                if ($action === 'lock' || $action === 'unlock') {
                    self::assertSame('1', $state['session']['sess_graph_lock_id']);
                    self::assertSame($action === 'lock', $state['session']['sess_graph_locked']);
                }
            }
            self::assertFalse($state['header_rendered']);
            self::assertSame('preserved', $state['session']['sentinel']);
            self::assertSame([['id' => 1,'host_id' => 1], ['id' => 2,'host_id' => 2]], $state['rows']);
            self::assertDoesNotMatchRegularExpression('/PHP (Warning|Fatal error|Notice):/', file_get_contents($directory . '/stderr.log'));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            unlink($directory . '/lib');
            foreach (glob($directory . '/include/*') as $file) {
                unlink($file);
            }
            rmdir($directory . '/include');
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function requests(): iterable
    {
        foreach (['graph_edit','item','lock','unlock'] as $action) {
            yield $action . ' denied graph' => [$action,9,true];
            yield $action . ' denied device' => [$action,2,true];
        }
        yield 'new editor denied device' => ['graph_edit',null,true,2];
        yield 'new editor admitted device' => ['graph_edit',null,false,1];
        foreach (['graph_edit','item','lock','unlock'] as $action) {
            yield $action . ' admitted' => [$action,1,false];
        }
    }
}
