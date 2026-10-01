<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceTemplateDefinitions;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class DeviceTemplateDefinitionProtocolTest extends TestCase
{
    public function testWorkerProtocolRejectsMalformedAndUncorrelatedResults(): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-definition-protocol-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $db->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', PHP_BINARY]);
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($db);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['poller_id' => 1]);
        $adapter = new LegacyDeviceTemplateDefinitions($database, $directory, $configuration);
        try {
            foreach (['malformed', 'actor', 'correlation', 'target', 'partial', 'map', 'float', 'numeric', 'zero', 'oversized', 'repeated', 'new-zero', 'duplicate-marker', 'malformed-extra-marker', 'numeric-object'] as $case) {
                $script = <<<'FIXTURE'
<?php
$command = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$result = ['actor' => $command['actor'], 'action' => $command['action'], 'correlation' => $command['correlation'], 'status' => 'ok', 'ids' => [7]];
switch (CASE_NAME) {
    case 'malformed': echo "KADUPUL_DEVICE_DEFINITION_RESULT={invalid}\n"; exit;
    case 'actor': $result['actor'] = 99; break;
    case 'correlation': $result['correlation'] = str_repeat('f', 32); break;
    case 'target': $result['ids'] = [8]; break;
    case 'numeric-object': $result['ids'] = (object) [0 => 7]; break;
    case 'map': $result['ids'] = ['target' => 7]; break;
    case 'float': $result['ids'] = [7.5]; break;
    case 'numeric': $result['ids'] = ['7']; break;
    case 'new-zero': $result['ids'] = [0]; break;
    case 'repeated': $result['ids'] = [7, 7]; break;
    case 'malformed-extra-marker': echo "KADUPUL_DEVICE_DEFINITION_RESULT=not-json\n"; break;
    case 'duplicate-marker': echo 'KADUPUL_DEVICE_DEFINITION_RESULT=' . json_encode($result) . "\n"; break;
    case 'zero': $result['ids'] = [0]; break;
    case 'oversized': $result['ids'] = [16777216]; break;
    case 'partial': $result['status'] = 'partial'; break;
}
echo 'KADUPUL_DEVICE_DEFINITION_RESULT=' . json_encode($result) . "\n";
FIXTURE;
                file_put_contents($directory . '/bin/legacy-device-template-definition.php', str_replace('CASE_NAME', var_export($case, true), $script));
                try {
                    $action = $case === 'repeated' ? 'duplicate' : 'save';
                    $command = $case === 'repeated' ? ['revisions' => [1 => str_repeat('a', 64), 2 => str_repeat('b', 64)]] : ['id' => $case === 'new-zero' ? 0 : 7, 'revision' => $case === 'new-zero' ? 'new' : str_repeat('a', 64), 'data' => ['name' => 'current', 'class' => 'router']];
                    $adapter->execute(42, $action, $command);
                    self::fail('Unverified protocol accepted: ' . $case);
                } catch (\RuntimeException $error) {
                    self::assertSame('Device template outcome could not be verified.', $error->getMessage(), $case);
                }
            }
        } finally {
            unlink($directory . '/bin/legacy-device-template-definition.php');
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }
}
