<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 1) . '/Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;

final class ProfileReferenceDeliveryNativeTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testReferenceDeliveryRetainsOldRowsOnRefusal(string $case): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/profile-parent-copy-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $coverage = $this->getTestResultObject()->getCodeCoverage();
            $command = array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', $root . '/tests/Fixtures/profile-parent-copy-unit.php', $case, $directory);
            if ($coverage !== null) {
                $command[] = $directory;
            }
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output);
            self::assertSame('', $output);
            $state = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
            $success = in_array($case, ['reference-bulk', 'reference-device', 'reference-empty', 'reference-exclude'], true);
            self::assertSame($success, $state['success']);
            $old = ['id' => 1, 'data_source_profile_id' => 1, 'name' => 'old reference'];
            $new = ['id' => 2, 'data_source_profile_id' => 77, 'name' => 'new reference'];
            $expected = match ($case) {
                'reference-bulk' => [$new],
                'reference-device' => [$old, $new],
                'reference-empty' => [],
                'reference-exclude' => [array_replace($old, ['data_source_profile_id' => 77])],
                default => [$old],
            };
            self::assertSame($expected, $state['children']);
            self::assertSame($case === 'reference-active', $state['active']);
            if ($coverage !== null) {
                foreach (glob($directory . '/*.coverage') as $report) {
                    $coverage->merge(unserialize(file_get_contents($report)));
                }
            }
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function cases(): array
    {
        return array_map(static fn($case) => [$case], ['reference-bulk', 'reference-device', 'reference-empty', 'reference-exclude', 'reference-delete-failure', 'reference-write-failure', 'reference-parent-lost', 'reference-engine', 'reference-schema-failure', 'reference-column-mismatch', 'reference-active']);
    }
}
