<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Graphing;

use Kadupul\Graphing\Infrastructure\Rrd\RrdLocaleEnvironment;
use Kadupul\Platform\Application\Locale\KnownLocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RrdLocaleEnvironmentTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function validLocales(): iterable
    {
        yield 'US English' => ['en-US', 'en_US.UTF-8'];
        yield 'German' => ['de-DE', 'de_DE.UTF-8'];
        yield 'Japanese' => ['ja-JP', 'ja_JP.UTF-8'];
        yield 'Moroccan Arabic' => ['ar-MA', 'ar_MA.UTF-8'];
        yield 'Egyptian Arabic' => ['ar-EG', 'ar_EG.UTF-8'];
        yield 'Canadian French' => ['fr-CA', 'fr_CA.UTF-8'];
        yield 'Moroccan French' => ['fr-MA', 'fr_MA.UTF-8'];
        yield 'Simplified Chinese' => ['zh-CN', 'zh_CN.UTF-8'];
        yield 'Malagasy' => ['mg-MG', 'mg_MG.UTF-8'];
        yield 'Kinyarwanda' => ['rw-RW', 'rw_RW.UTF-8'];
        yield 'Somali' => ['so-SO', 'so_SO.UTF-8'];
        yield 'POSIX underscore form' => ['de_DE', 'de_DE.UTF-8'];
        yield 'POSIX UTF-8 form' => ['ar_MA.UTF-8', 'ar_MA.UTF-8'];
    }

    #[DataProvider('validLocales')]
    public function testNormalizeTimeLocaleMapsKadupulLocalesToPosixUtf8(string $input, string $expected): void
    {
        $known = array_keys(KnownLocales::ALL);

        self::assertSame($expected, RrdLocaleEnvironment::normalizeTimeLocale($input, $known));
        self::assertSame($expected, RrdLocaleEnvironment::normalizeTimeLocale($input));
    }

    /** @return iterable<string, array{?string}> */
    public static function neutralOrInvalidLocales(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'C' => ['C'];
        yield 'POSIX' => ['POSIX'];
        yield 'C.UTF-8' => ['C.UTF-8'];
        yield 'legacy neutral en' => ['en'];
        yield 'bogus legacy en_EN.UTF-8' => ['en_EN.UTF-8'];
        yield 'unlisted locale' => ['xx-ZZ'];
        yield 'wrong case' => ['DE-de'];
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'newline env injection' => ["de-DE\nLC_NUMERIC=de_DE"];
        yield 'trailing newline' => ["de-DE\n"];
        yield 'NUL byte' => ["de-DE\0"];
        yield 'shell metacharacters' => ['de-DE;id'];
        yield 'non-UTF-8 codeset' => ['de_DE.ISO-8859-1'];
    }

    #[DataProvider('neutralOrInvalidLocales')]
    public function testNormalizeTimeLocaleFallsBackToCForNeutralOrUntrustedInput(?string $input): void
    {
        $known = array_keys(KnownLocales::ALL);

        self::assertSame('C', RrdLocaleEnvironment::normalizeTimeLocale($input, $known));
    }

    public function testProcessOverridesPinsLcNumericToCAndUnsetsLcAll(): void
    {
        $known = array_keys(KnownLocales::ALL);

        self::assertSame(
            [
                'LC_ALL' => false,
                'LC_NUMERIC' => 'C',
                'LC_TIME' => 'de_DE.UTF-8',
                'LANG' => 'de_DE.UTF-8',
            ],
            RrdLocaleEnvironment::processOverrides('de-DE', $known),
        );

        self::assertSame(
            [
                'LC_ALL' => false,
                'LC_NUMERIC' => 'C',
                'LC_TIME' => 'C',
                'LANG' => 'C',
            ],
            RrdLocaleEnvironment::processOverrides('xx-ZZ', $known),
        );
    }

    public function testApplyAndRestoreManageScopedProcessEnvironmentIncludingUnsetVariables(): void
    {
        $managed = ['LC_ALL', 'LC_NUMERIC', 'LC_TIME', 'LANG'];
        $original = [];
        foreach ($managed as $name) {
            $original[$name] = getenv($name);
        }

        try {
            putenv('LC_ALL=fr_FR.UTF-8');
            putenv('LC_NUMERIC=de_DE.UTF-8');
            putenv('LC_TIME');
            putenv('LANG=en_US.UTF-8');

            $env = new RrdLocaleEnvironment();
            // Empty restore is a safe no-op.
            $env->restore();

            $applied = $env->apply('ar-MA', array_keys(KnownLocales::ALL));
            self::assertSame('ar_MA.UTF-8', $applied);
            self::assertFalse(getenv('LC_ALL'));
            self::assertSame('C', getenv('LC_NUMERIC'));
            self::assertSame('ar_MA.UTF-8', getenv('LC_TIME'));
            self::assertSame('ar_MA.UTF-8', getenv('LANG'));

            // Nested neutral override (e.g. fetch/info) restores back to outer ar_MA.UTF-8.
            self::assertSame('C', $env->apply('en', array_keys(KnownLocales::ALL)));
            self::assertFalse(getenv('LC_ALL'));
            self::assertSame('C', getenv('LC_NUMERIC'));
            self::assertSame('C', getenv('LC_TIME'));
            self::assertSame('C', getenv('LANG'));

            $env->restore();
            self::assertFalse(getenv('LC_ALL'));
            self::assertSame('C', getenv('LC_NUMERIC'));
            self::assertSame('ar_MA.UTF-8', getenv('LC_TIME'));
            self::assertSame('ar_MA.UTF-8', getenv('LANG'));

            $env->restore();
            self::assertSame('fr_FR.UTF-8', getenv('LC_ALL'));
            self::assertSame('de_DE.UTF-8', getenv('LC_NUMERIC'));
            self::assertFalse(getenv('LC_TIME'));
            self::assertSame('en_US.UTF-8', getenv('LANG'));
        } finally {
            foreach ($original as $name => $value) {
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $value);
                }
            }
        }
    }
}
