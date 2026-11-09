<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Application\Locale\KnownLocales;
use Kadupul\Platform\Application\Locale\LocaleInput;
use Kadupul\Platform\Application\Locale\LocaleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleResolverTest extends TestCase
{
    private const SHIPPED = ['de-DE', 'fr-FR', 'pt-BR', 'pt-PT', 'ru-RU', 'zh-CN', 'zh-TW'];

    /** @param array<string, mixed> $values */
    private static function input(array $values = [], ?array &$calls = null): LocaleInput
    {
        $calls = [];

        return new LocaleInput(
            $values['support'] ?? null,
            $values['detection'] ?? null,
            $values['default'] ?? null,
            static function () use ($values, &$calls): ?string {
                $calls[] = 'settingDefault';

                return $values['settingDefault'] ?? null;
            },
            $values['forced'] ?? null,
            static function () use ($values, &$calls): ?string {
                $calls[] = 'request';

                return $values['request'] ?? null;
            },
            $values['session'] ?? null,
            static function () use ($values, &$calls): string|false|null {
                $calls[] = 'saved';

                return $values['saved'] ?? false;
            },
            $values['accept'] ?? null,
            static function (string $locale) use (&$calls): bool {
                $calls[] = 'catalog:' . $locale;

                return in_array($locale, self::SHIPPED, true);
            },
        );
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('selections')]
    public function testSelectsTheLocale(array $values, string $locale, bool $english): void
    {
        $resolution = (new LocaleResolver())->resolve(self::input($values));

        self::assertSame($locale, $resolution->locale);
        self::assertSame($english, $resolution->showsEnglish());
    }

    /** @return iterable<string, array{array<string, mixed>, string, bool}> */
    public static function selections(): iterable
    {
        yield 'nothing names a language' => [[], 'en-US', true];
        yield 'strict mode is still enabled' => [['support' => '2', 'accept' => 'de'], 'de-DE', false];
        yield 'forced zero is empty' => [['forced' => '0', 'request' => 'ru'], 'ru-RU', false];
        yield 'request zero is empty' => [['request' => '0', 'session' => 'fr-FR'], 'fr-FR', false];
        yield 'session zero is empty' => [['session' => '0', 'saved' => 'ru-RU'], 'ru-RU', false];
        yield 'saved zero is repaired to nothing' => [['saved' => '0', 'accept' => 'de'], 'de-DE', false];
        yield 'saved null is no preference' => [['saved' => null, 'accept' => 'de'], 'de-DE', false];
        yield 'bare zh is the first listed Chinese' => [['saved' => 'zh'], 'zh-CN', false];
        yield 'one character is no locale' => [['saved' => 'd', 'default' => 'ru-RU'], 'ru-RU', false];
        yield 'a known locale without a catalog stops the search' => [['saved' => 'sr-RS', 'accept' => 'de'], 'sr-RS', true];
        yield 'detection reads numeric one loosely' => [['detection' => ' 1', 'accept' => 'de'], 'de-DE', false];
        yield 'detection reads other values as off' => [['detection' => 'on', 'accept' => 'de', 'default' => 'ru'], 'ru-RU', false];
        yield 'unknown default uses the setting default' => [['default' => 'xx', 'settingDefault' => 'pt_BR'], 'pt-BR', false];
        yield 'unknown default and setting default select nothing' => [['default' => 'xx', 'settingDefault' => 'yy'], 'en-US', true];
        yield 'Accept-Language case does not matter' => [['accept' => 'PT-br'], 'pt-BR', false];
        yield 'Accept-Language underscore spelling' => [['accept' => 'pt_BR'], 'pt-BR', false];
        yield 'Accept-Language wildcard names nothing' => [['accept' => '*', 'default' => 'ru'], 'ru-RU', false];
        yield 'Accept-Language skips empty members' => [['accept' => ' , ,de;q=0.4'], 'de-DE', false];
        yield 'Accept-Language skips q=0' => [['accept' => 'fr;q=0,de;q=0.1'], 'de-DE', false];
        yield 'Accept-Language skips an invalid weight' => [['accept' => 'fr;q=2,ru;q=abc,de;q=0.001'], 'de-DE', false];
        yield 'Accept-Language reads the weight parameter by name' => [['accept' => 'fr;level=1;Q=0.2,de;q=0.3'], 'de-DE', false];
        yield 'Accept-Language keeps order between equal weights' => [['accept' => 'ru;q=0.5,de;q=0.5'], 'ru-RU', false];
        yield 'Accept-Language skips a locale without a catalog' => [['accept' => 'zh-HK,zh-TW;q=0.9'], 'zh-TW', false];
        yield 'Accept-Language English needs no catalog' => [['accept' => 'en-AU,de;q=0.9'], 'en-US', true];
    }

    public function testLaterSourcesAreReadOnlyWhenNeeded(): void
    {
        $resolver = new LocaleResolver();

        $resolution = $resolver->resolve(self::input(['forced' => 'de_DE', 'request' => 'fr', 'session' => 'ru-RU'], $calls));
        self::assertSame(['catalog:de-DE'], $calls);
        self::assertTrue($resolution->requested);
        self::assertSame('de-DE', $resolution->requestLanguage);
        self::assertSame('de-DE', $resolution->selected);

        $resolution = $resolver->resolve(self::input(['request' => 'xx', 'session' => 'ru-RU'], $calls));
        self::assertSame(['request', 'catalog:ru-RU'], $calls);
        self::assertFalse($resolution->requested);
        self::assertSame('', $resolution->requestLanguage);

        $resolution = $resolver->resolve(self::input(['saved' => 'xx', 'accept' => 'xx,yy', 'default' => 'xx', 'settingDefault' => 'yy'], $calls));
        self::assertSame(['request', 'saved', 'settingDefault', 'catalog:en-US'], $calls);

        $resolver->resolve(self::input(['session' => 'xx', 'saved' => 'xx', 'default' => 'ru'], $calls));
        self::assertSame(['request', 'saved', 'catalog:ru-RU'], $calls);
        self::assertFalse($resolution->selected);
        self::assertNull($resolution->requestLanguage);
        self::assertTrue($resolution->catalogMissing);
    }

    public function testDisabledSupportReadsNothing(): void
    {
        $resolution = (new LocaleResolver())->resolve(self::input(['support' => '0', 'forced' => 'de-DE'], $calls));

        self::assertSame([], $calls);
        self::assertTrue($resolution->disabled);
        self::assertTrue($resolution->showsEnglish());
        self::assertSame('en-US', $resolution->locale);
        self::assertNull($resolution->requestLanguage);
    }

    public function testApplyIsTheLegacyApplyLocale(): void
    {
        $resolver = new LocaleResolver();

        self::assertSame('ru-RU', $resolver->apply(self::input(), 'ru'));
        self::assertSame('de-DE', $resolver->apply(self::input(['accept' => 'de-AT']), 'xx'));
        self::assertSame('de-DE', $resolver->apply(self::input(['accept' => 'de-AT']), false));
        self::assertSame('en-US', $resolver->apply(self::input(['accept' => 'de-AT', 'detection' => '0']), null));
        self::assertSame('en-US', $resolver->apply(self::input(), ''));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function repairs(): iterable
    {
        yield 'null' => [null, 'en-US'];
        yield 'empty' => ['', 'en-US'];
        yield 'exact' => ['pt-BR', 'pt-BR'];
        yield 'underscore' => ['zh_TW', 'zh-TW'];
        yield 'bare Portuguese' => ['pt', 'pt-PT'];
        yield 'other Portuguese region' => ['pt-AO', 'pt-PT'];
        yield 'listed without a catalog' => ['zh-HK', 'zh-HK'];
        yield 'case is significant' => ['DE-de', ''];
        yield 'zero' => ['0', ''];
        yield 'path' => ['../../locales/po/ru-RU', ''];
    }

    #[DataProvider('repairs')]
    public function testRepairIsTheLegacyRepairLocale(?string $language, string $expected): void
    {
        self::assertSame($expected, (new LocaleResolver())->repair($language));
    }

    public function testStrictModeNeedsEveryPluginCatalog(): void
    {
        $resolver = new LocaleResolver();
        $strict = self::input(['support' => '2.0']);

        self::assertTrue($resolver->strictMode($strict));
        self::assertTrue($resolver->requiresStrictFallback($strict, 2, 1));
        self::assertFalse($resolver->requiresStrictFallback($strict, 2, 2));
        self::assertFalse($resolver->requiresStrictFallback($strict, 0, 0));
        foreach ([null, '', '1', '2abc', '0'] as $support) {
            self::assertFalse($resolver->strictMode(self::input(['support' => $support])));
            self::assertFalse($resolver->requiresStrictFallback(self::input(['support' => $support]), 2, 1));
        }
    }

    public function testKnownLocalesAreTheLegacyTableInOrder(): void
    {
        self::assertCount(50, KnownLocales::ALL);
        self::assertSame(['pt-PT', 'pt-BR'], array_values(array_filter(array_keys(KnownLocales::ALL), static fn(string $locale): bool => str_starts_with($locale, 'pt-'))));
        foreach (KnownLocales::ALL as $locale => $properties) {
            self::assertMatchesRegularExpression('/\A[a-z]{2}-[A-Z]{2}\z/', $locale);
            self::assertSame(['language', 'direction', 'country', 'filename'], array_keys($properties));
        }
    }
}
