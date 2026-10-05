<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/PhpSource.php';

final class EffectiveGraphPolicyDisplayTest extends TestCase
{
    public function testEffectivePolicyDisplayMatchesAuthorizationRules(): void
    {
        $source = file_get_contents(__DIR__ . '/../../lib/auth.php');
        self::assertIsString($source);

        $function = test_php_function_source($source, 'get_permission_string');
        $cases = [];

        foreach ([1, 2, 3, 4] as $method) {
            foreach (['user', 'group'] as $type) {
                foreach ([1, 2] as $graphDefault) {
                    foreach ([1, 2] as $deviceDefault) {
                        foreach ([1, 2] as $templateDefault) {
                            foreach ([false, true] as $graphException) {
                                foreach ([false, true] as $deviceException) {
                                    foreach ([false, true] as $templateException) {
                                        $graphAllowed = $graphDefault === 1 ? !$graphException : $graphException;
                                        $deviceAllowed = $deviceDefault === 1 ? !$deviceException : $deviceException;
                                        $templateAllowed = $templateDefault === 1 ? !$templateException : $templateException;
                                        $expected = match ($method) {
                                            1 => $graphAllowed || $deviceAllowed || $templateAllowed,
                                            2 => $graphAllowed || ($deviceAllowed && $templateAllowed),
                                            3 => $graphAllowed || $deviceAllowed,
                                            4 => $graphAllowed || $templateAllowed,
                                        };

                                        $cases[] = [
                                            'method' => $method,
                                            'expected' => $expected,
                                            'policies' => [[
                                                'id' => 41,
                                                'type' => $type,
                                                'name' => 'fixture',
                                                'policy_graphs' => $graphDefault,
                                                'policy_hosts' => $deviceDefault,
                                                'policy_graph_templates' => $templateDefault,
                                            ]],
                                            'graph' => [
                                                'disabled' => '',
                                                'graph1' => $graphException ? 41 : null,
                                                'device1' => $deviceException ? 42 : null,
                                                'template1' => $templateException ? 43 : null,
                                            ],
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $cases[] = [
            'method' => 2,
            'expected' => true,
            'policies' => [
                ['id' => 41, 'type' => 'user', 'name' => 'fixture', 'policy_graphs' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2],
                ['id' => 51, 'type' => 'group', 'name' => 'fixture-group', 'policy_graphs' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2],
            ],
            'graph' => ['disabled' => '', 'graph1' => null, 'device1' => null, 'template1' => null, 'graph2' => null, 'device2' => 42, 'template2' => 43],
        ];
        $cases[] = [
            'method' => 2,
            'expected' => false,
            'policies' => [
                ['id' => 41, 'type' => 'user', 'name' => 'fixture', 'policy_graphs' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2],
                ['id' => 51, 'type' => 'group', 'name' => 'fixture-group', 'policy_graphs' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2],
            ],
            'graph' => ['disabled' => '', 'graph1' => null, 'device1' => null, 'template1' => null, 'graph2' => null, 'device2' => 42, 'template2' => null],
        ];

        $script = "<?php\n"
            . '$GLOBALS["test_cases"] = ' . var_export($cases, true) . ";\n"
            . '$GLOBALS["method"] = 1; $GLOBALS["hide_disabled"] = false;' . "\n"
            . 'function read_config_option($key) { return $GLOBALS["method"]; }' . "\n"
            . 'function read_user_setting(...$args) { return $GLOBALS["hide_disabled"]; }' . "\n"
            . 'function get_request_var($key) { return 1; }' . "\n"
            . 'function __esc($message, ...$args) { return $args ? vsprintf($message, $args) : $message; }' . "\n"
            . 'function __($message) { return $message; }' . "\n"
            . $function . "\n"
            . '$results = [];' . "\n"
            . 'foreach ($GLOBALS["test_cases"] as $case) {' . "\n"
            . '    $GLOBALS["method"] = $case["method"];' . "\n"
            . '    $graph = $case["graph"]; $policies = $case["policies"];' . "\n"
            . '    $html = get_permission_string($graph, $policies);' . "\n"
            . '    $results[] = ["expected" => $case["expected"], "granted" => str_contains($html, "class=\'accessGranted\'")];' . "\n"
            . '}' . "\n"
            . 'echo json_encode($results);';

        $child = tempnam(sys_get_temp_dir(), 'effective-policy-');
        self::assertNotFalse($child);
        file_put_contents($child, $script);

        $process = proc_open(
            [PHP_BINARY, $child],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'policy display subprocess did not start');
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        unlink($child);

        self::assertSame(0, $status, $error);
        $results = json_decode($output, true);
        self::assertIsArray($results);
        self::assertCount(count($cases), $results);

        foreach ($results as $index => $result) {
            self::assertSame($result['expected'], $result['granted'], 'Authorization/display mismatch in case ' . $index);
        }
    }

}
