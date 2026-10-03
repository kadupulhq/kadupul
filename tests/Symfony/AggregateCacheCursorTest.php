<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AggregateCacheCursorTest extends TestCase
{
    public static function cases(): iterable
    {
        foreach (['false','throw','late-state'] as $failure) {
            foreach (['owned','caller'] as $ownership) yield $failure . '-' . $ownership => [$failure,$ownership];
        }
    }
    #[DataProvider('cases')]
    public function testRealWritesAreRolledBackWhenCursorCloseCannotBeConfirmed(string $failure, string $ownership): void
    {
        $process = proc_open(
            [PHP_BINARY,'-d','auto_prepend_file=',dirname(__DIR__) . '/Fixtures/aggregate-cache-cursor.php',$failure,$ownership],
            [1 => ['pipe','w'],2 => ['pipe','w']],
            $pipes
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame('', $error);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($result['saved']);
        self::assertTrue($result['rows_preserved']);
        self::assertTrue($result['independent_preserved']);
        self::assertTrue($result['caller_owned']);
        if ($ownership === 'caller') self::assertSame('kept', $result['prior_work']);
    }
}
