<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class DeviceRouteNativeHarness
{
    public static function run(array $scenario, ?SebastianBergmann\CodeCoverage\CodeCoverage $coverage): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/device-route-' . bin2hex(random_bytes(8));
        mkdir($directory . '/config', 0700, true);
        try {
            if (!copy($root . '/host.php', $directory . '/host.php') || hash_file('sha256', $directory . '/host.php') !== hash_file('sha256', $root . '/host.php')) {
                throw new RuntimeException('Cannot copy the exact compatibility wrapper');
            }
            file_put_contents($directory . '/config/bootstrap.php', '<?php return $GLOBALS["deviceRouteKernel"];');
            $encoded = json_encode($scenario, JSON_THROW_ON_ERROR);
            $environment = array_merge(getenv(), ['DEVICE_ROUTE_ROOT' => $root, 'DEVICE_ROUTE_DIRECTORY' => $directory, 'DEVICE_ROUTE_SCENARIO' => $encoded, 'DEVICE_ROUTE_COVERAGE' => $coverage === null ? '0' : '1']);
            $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests/vendor)/~', $root . '/tests/Fixtures/device-route-native.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/stdout.log', 'w'], 2 => ['file', $directory . '/stderr.log', 'w']], $pipes, $root, $environment);
            if (!is_resource($process)) throw new RuntimeException('Cannot start owned route fixture');
            fclose($pipes[0]);
            $exit = proc_close($process);
            $stdout = file_get_contents($directory . '/stdout.log');
            $stderr = file_get_contents($directory . '/stderr.log');
            if ($exit !== 0 || !preg_match('/^DEVICE_ROUTE_RESULT=(.+)$/m', $stdout, $match)) {
                throw new RuntimeException('Active route fixture failed: ' . $stdout . $stderr);
            }
            $result = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
            $result += ['stdout' => $stdout, 'stderr' => $stderr];
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                if (count($reports) !== 1) throw new RuntimeException('Route coverage missing');
                $prefix = 'src/Inventory/Infrastructure/Symfony/Controller/';
                $hits = $scenario['kind'] === 'presentation' ? ['host.php', $prefix . 'LegacyDevicesController.php'] : ($scenario['kind'] === 'wrapper' ? ['host.php', $prefix . 'LegacyDevicesController.php'] : [$prefix . 'DeviceMaintenanceController.php', $prefix . 'DeviceAssociationController.php', $prefix . 'DeviceCreateController.php', $prefix . 'DeviceStateController.php', $prefix . 'DeviceBulkAssignmentController.php']);
                if ($scenario['kind'] === 'presentation') {
                    $action = $scenario['fields']['action'] ?? '';
                    $adapter = 'src/Inventory/Infrastructure/';
                    if (!empty($scenario['json']) || isset($scenario['paths'])) $hits[] = $adapter . 'Legacy/LegacyDeviceCatalog.php';
                    elseif ($action === 'ajax_locations') $hits[] = $adapter . 'Legacy/LegacyDeviceLocations.php';
                    elseif ($action === 'edit') $hits[] = ($scenario['fields']['id'] ?? '0') === '0' ? $adapter . 'Persistence/DoctrineDeviceCreationCatalog.php' : $adapter . 'Legacy/LegacyDeviceEditor.php';
                    elseif ($action === 'query_add') $hits[] = $adapter . 'Legacy/LegacyDeviceAssociations.php';
                }
                $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/device-route-native.php', $encoded, DeviceRouteCoverageRegistration::SOURCES, DeviceRouteCoverageRegistration::MARKERS, $hits);
                static $verified = false;
                if (!$verified) {
                    $result['integrity_controls'] = NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/device-route-native.php', $encoded, DeviceRouteCoverageRegistration::SOURCES, DeviceRouteCoverageRegistration::MARKERS, $hits, 'config/bootstrap.php');
                    if ($result['integrity_controls'] !== count(DeviceRouteCoverageRegistration::SOURCES) + 12) throw new RuntimeException('Incomplete route evidence rejection controls');
                    $verified = true;
                }
                $coverage->merge($child);
            }
            return $result;
        } finally {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($directory);
        }
    }
}
