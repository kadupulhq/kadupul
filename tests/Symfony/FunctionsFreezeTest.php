<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

use function Kadupul\Tools\FunctionsInventory\find_unlisted_functions;

require_once dirname(__DIR__, 2) . '/tools/functions-inventory.php';

/**
 * lib/functions.php is being decomposed into modules under src/. It may shrink
 * but must not grow, so every function it declares has to appear in the
 * committed inventory index.
 */
final class FunctionsFreezeTest extends TestCase
{
    private string $scratch = '';

    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            exec('rm -rf ' . escapeshellarg($this->scratch));
        }
    }

    public function testLibFunctionsDeclaresNothingTheInventoryDoesNotList(): void
    {
        $unlisted = find_unlisted_functions(dirname(__DIR__, 2));

        self::assertSame(
            [],
            $unlisted,
            'lib/functions.php is frozen while it is decomposed, and declares ' . implode(', ', $unlisted)
            . ' which docs/migration/functions-inventory/index.json does not list. Put new code in a module under src/'
            . ' (Domain, Application or Infrastructure, by layer) and call it from the legacy page. Do not add the name to'
            . ' the index to make this pass; see docs/migration/functions-inventory/README.md.'
        );
    }

    public function testGuardFailsWhenAFunctionIsAdded(): void
    {
        $root = $this->copyOfInventoryInputs();
        file_put_contents($root . '/lib/functions.php', "\nfunction freshly_added_helper() {}\n", FILE_APPEND);

        self::assertSame(['freshly_added_helper'], find_unlisted_functions($root));
    }

    public function testGuardFailsWhenAConditionalFunctionIsAdded(): void
    {
        $root = $this->copyOfInventoryInputs();
        file_put_contents($root . '/lib/functions.php', "\nif (!function_exists('late_shim')) {\n    function late_shim() {}\n}\n", FILE_APPEND);

        self::assertSame(['late_shim'], find_unlisted_functions($root));
    }

    public function testGuardAllowsRemovingAFunction(): void
    {
        $root = $this->copyOfInventoryInputs();
        $source = (string) file_get_contents($root . '/lib/functions.php');
        $source = preg_replace('/^function padleft\(.*?^}\n/ms', '', $source, 1, $count);
        self::assertSame(1, $count);
        file_put_contents($root . '/lib/functions.php', $source);

        self::assertSame([], find_unlisted_functions($root));
    }

    private function copyOfInventoryInputs(): string
    {
        $root = dirname(__DIR__, 2);
        $this->scratch = sys_get_temp_dir() . '/functions-freeze-' . bin2hex(random_bytes(6));
        mkdir($this->scratch . '/lib', 0775, true);
        mkdir($this->scratch . '/docs/migration/functions-inventory', 0775, true);
        copy($root . '/lib/functions.php', $this->scratch . '/lib/functions.php');
        copy($root . '/docs/migration/functions-inventory/index.json', $this->scratch . '/docs/migration/functions-inventory/index.json');

        return $this->scratch;
    }
}
