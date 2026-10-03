<?php

/*
 * SPDX-FileCopyrightText: 2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use PHPUnit\Framework\TestCase;

final class HostDiskCapacityTest extends TestCase
{
    public function testCapacityRequiresValidUnitsAndRejectsNegativeSamples(): void
    {
        $source = file_get_contents(__DIR__ . '/../../scripts/ss_host_disk.php');
        self::assertIsString($source);

        $function = $this->extractFunction($source, 'ss_host_disk');
        $cases = [
            ['valid positive sample', '42', '4096', 172032],
            ['missing allocation units', '42', null, 'U'],
            ['invalid allocation units', '42', '4096 bytes', 'U'],
            ['zero allocation units', '42', '0', 'U'],
            ['negative allocation units', '42', '-4096', 'U'],
            ['negative sample', '-1', '4096', 'U'],
            ['minimum signed sample', '-2147483648', '4096', 'U'],
            ['non-numeric sample', 'unknown', '4096', 'U'],
        ];

        foreach ($cases as [$name, $sample, $units, $expected]) {
            $script = "<?php\n"
                . "define('SNMP_POLLER', 1);\n"
                . "function api_plugin_hook_function(\$name, \$value) { return \$value; }\n"
                . 'function db_fetch_cell_prepared($sql, $values) { return $GLOBALS["units"]; }' . "\n"
                . 'function cacti_snmp_get(...$args) { return $GLOBALS["sample"]; }' . "\n"
                . $function . "\n"
                . '$GLOBALS["units"] = ' . var_export($units, true) . ";\n"
                . '$GLOBALS["sample"] = ' . var_export($sample, true) . ";\n"
                . 'echo json_encode(ss_host_disk("host", 1, "2:161:1000:3:10:public", "get", "total", "1"));';
            $child = tempnam(sys_get_temp_dir(), 'host-disk-capacity-');
            self::assertNotFalse($child);
            file_put_contents($child, $script);
            $process = proc_open(
                [PHP_BINARY, $child],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process, $name . ': subprocess did not start');
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            unlink($child);

            self::assertSame(0, $status, $name . ': ' . $error . $output);
            self::assertSame($expected, json_decode($output, true), $name);
        }
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
