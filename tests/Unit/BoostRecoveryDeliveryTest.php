<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use PHPUnit\Framework\TestCase;

final class BoostRecoveryDeliveryTest extends TestCase
{
    public function testRecoveryRetainsRowsUntilEveryPacketIsAcknowledged(): void
    {
        $source = file_get_contents(__DIR__ . '/../../poller_recovery.php');
        self::assertIsString($source);
        $function = $this->extractFunction($source, 'poller_recovery_transfer_rows');
        $function = preg_replace(
            '/\b(poller_recovery_transfer_rows|db_qstr|cacti_sizeof|cacti_log|db_execute_prepared|db_execute)\(/',
            'recoveryTest_$1(',
            $function,
        );

        $rows = [
            ['local_data_id' => 1, 'rrd_name' => 'in', 'time' => '2026-01-01 00:00:01', 'output' => '10'],
            ['local_data_id' => 2, 'rrd_name' => 'out', 'time' => '2026-01-01 00:00:02', 'output' => '20'],
            ['local_data_id' => 3, 'rrd_name' => 'errors', 'time' => '2026-01-01 00:00:03', 'output' => '30'],
        ];
        $cases = [
            'final_packet_failure' => ['rows' => [$rows[0]], 'limit' => 1000, 'insert_failure' => 1, 'delete_failure' => 0],
            'later_packet_failure' => ['rows' => $rows, 'limit' => 65, 'insert_failure' => 2, 'delete_failure' => 0],
            'all_packets_succeed' => ['rows' => $rows, 'limit' => 65, 'insert_failure' => 0, 'delete_failure' => 0],
            'local_delete_failure' => ['rows' => [$rows[0]], 'limit' => 1000, 'insert_failure' => 0, 'delete_failure' => 1],
            'oversized_row' => ['rows' => [$rows[0]], 'limit' => 10, 'insert_failure' => 0, 'delete_failure' => 0],
        ];

        $script = "<?php\n"
            . '$GLOBALS["cases"] = ' . var_export($cases, true) . ";\n"
            . 'function recoveryTest_db_qstr($value, $connection = false) { return "\'" . addslashes((string) $value) . "\'"; }' . "\n"
            . 'function recoveryTest_cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }' . "\n"
            . 'function recoveryTest_cacti_log($message, ...$args) {}' . "\n"
            . 'function recoveryTest_db_execute($sql, ...$args) {' . "\n"
            . '    $state =& $GLOBALS["state"]; $state["inserts"][] = $sql;' . "\n"
            . '    return $state["insert_failure"] !== count($state["inserts"]);' . "\n"
            . '}' . "\n"
            . 'function recoveryTest_db_execute_prepared($sql, $params, ...$args) {' . "\n"
            . '    $state =& $GLOBALS["state"]; $state["deletes"][] = ["sql" => $sql, "params" => $params];' . "\n"
            . '    return $state["delete_failure"] !== count($state["deletes"]);' . "\n"
            . '}' . "\n"
            . $function . "\n"
            . '$results = [];' . "\n"
            . 'foreach ($GLOBALS["cases"] as $name => $case) {' . "\n"
            . '    $GLOBALS["state"] = ["inserts" => [], "deletes" => [], "insert_failure" => $case["insert_failure"], "delete_failure" => $case["delete_failure"]];' . "\n"
            . '    $count = 0;' . "\n"
            . '    $success = recoveryTest_poller_recovery_transfer_rows($case["rows"], $case["limit"], "remote", new stdClass(), $count);' . "\n"
            . '    $results[$name] = ["success" => $success, "count" => $count] + $GLOBALS["state"];' . "\n"
            . '}' . "\n"
            . 'echo json_encode($results);';

        $child = tempnam(sys_get_temp_dir(), 'boost-recovery-delivery-');
        self::assertNotFalse($child);
        file_put_contents($child, $script);

        $process = proc_open([PHP_BINARY, $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'recovery delivery subprocess did not start');
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        unlink($child);

        self::assertSame(0, $status, $error);
        $results = json_decode($output, true);
        self::assertIsArray($results);

        foreach (['final_packet_failure', 'later_packet_failure', 'local_delete_failure', 'oversized_row'] as $name) {
            self::assertFalse($results[$name]['success'], $name);
            if ($name !== 'local_delete_failure') {
                self::assertSame([], $results[$name]['deletes'], $name . ' must retain all selected local rows');
            }
        }
        self::assertSame(0, $results['final_packet_failure']['count']);
        self::assertSame(1, $results['later_packet_failure']['count']);
        self::assertSame(1, $results['local_delete_failure']['count']);
        self::assertSame(3, $results['all_packets_succeed']['count']);
        self::assertTrue($results['all_packets_succeed']['success']);
        self::assertCount(1, $results['all_packets_succeed']['deletes']);
        self::assertStringContainsString('output = ?', $results['all_packets_succeed']['deletes'][0]['sql']);
        self::assertSame(
            [1, 'in', '2026-01-01 00:00:01', '10', 2, 'out', '2026-01-01 00:00:02', '20', 3, 'errors', '2026-01-01 00:00:03', '30'],
            $results['all_packets_succeed']['deletes'][0]['params'],
        );
    }

    private function extractFunction(string $source, string $functionName): string
    {
        $tokens = token_get_all($source);
        $start = null;

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            for ($nameIndex = $index + 1; $nameIndex < count($tokens); $nameIndex++) {
                if (is_array($tokens[$nameIndex]) && $tokens[$nameIndex][0] === T_STRING) {
                    if ($tokens[$nameIndex][1] === $functionName) {
                        $start = $index;
                        break 2;
                    }
                    break;
                }
            }
        }

        self::assertNotNull($start, $functionName . '() must exist');
        $depth = 0;
        $function = '';
        $started = false;
        for ($index = $start; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;
            $function .= $text;
            if (!is_array($token) && $text === '{') {
                $depth++;
                $started = true;
            } elseif (!is_array($token) && $text === '}' && $started && --$depth === 0) {
                return $function;
            }
        }

        self::fail('Could not extract ' . $functionName . '()');
    }
}
