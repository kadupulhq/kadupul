<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Contract\IconRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IconRegistryTest extends TestCase
{
    public function testThemesRedrawOnlyTheNamesTheyOverride(): void
    {
        $icons = new IconRegistry(
            ['add' => 'fa fa-plus', 'export' => 'fa fa-arrow-down'],
            ['paw' => ['export' => 'fa fa-chevron-down']],
        );

        self::assertSame('fa fa-arrow-down', $icons->classes('export', 'modern'));
        self::assertSame('fa fa-chevron-down', $icons->classes('export', 'paw'));
        self::assertSame('fa fa-plus', $icons->classes('add', 'paw'));
        self::assertSame(['add' => 'fa fa-plus', 'export' => 'fa fa-chevron-down'], $icons->forTheme('paw'));
        self::assertSame(['add' => 'fa fa-plus', 'export' => 'fa fa-arrow-down'], $icons->forTheme('not-installed'));
        self::assertTrue($icons->has('add'));
        self::assertFalse($icons->has('fa fa-plus'), 'a class list is not a name');
    }

    public function testAnUnknownNameIsAnError(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Unknown icon: ad'));

        (new IconRegistry(['add' => 'fa fa-plus']))->classes('ad', 'modern');
    }

    /**
     * @param array<mixed> $icons
     * @param array<mixed> $themes
     */
    #[DataProvider('invalidRegistries')]
    public function testRejectsEntriesThatCouldNotRenderSafely(array $icons, array $themes, string $message): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException($message));

        new IconRegistry($icons, $themes);
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>, string}> */
    public static function invalidRegistries(): iterable
    {
        yield 'empty' => [[], [], 'The icon registry is empty'];
        yield 'upper-case name' => [['Add' => 'fa fa-plus'], [], 'Invalid icon name in icons: Add'];
        yield 'list instead of map' => [['fa fa-plus'], [], 'Invalid icon name in icons: 0'];
        yield 'quote in classes' => [['add' => "fa fa-plus' onclick='x"], [], 'Icon add in icons needs a space-separated class list'];
        yield 'empty classes' => [['add' => ''], [], 'Icon add in icons needs a space-separated class list'];
        yield 'double space' => [['add' => 'fa  fa-plus'], [], 'Icon add in icons needs a space-separated class list'];
        yield 'non-string classes' => [['add' => ['fa']], [], 'Icon add in icons needs a space-separated class list'];
        yield 'theme path' => [['add' => 'fa fa-plus'], ['../x' => []], 'Invalid theme name in the icon registry: ../x'];
        yield 'theme not a map' => [['add' => 'fa fa-plus'], ['paw' => 'fa fa-paw'], 'Theme paw must map icon names to classes'];
        yield 'theme adds a name' => [['add' => 'fa fa-plus'], ['paw' => ['paw' => 'fa fa-paw']], 'Theme paw overrides unknown icons: paw'];
        yield 'bad theme classes' => [['add' => 'fa fa-plus'], ['paw' => ['add' => '<i>']], 'Icon add in theme paw needs a space-separated class list'];
    }

    public function testReadsTheJsonDocument(): void
    {
        $icons = IconRegistry::fromJson('{"icons": {"add": "fa fa-plus"}, "themes": {"midwinter": {"add": "fas fa-plus"}}}');

        self::assertSame('fas fa-plus', $icons->classes('add', 'midwinter'));
        self::assertSame(['add' => 'fa fa-plus'], IconRegistry::fromJson('{"icons": {"add": "fa fa-plus"}}')->forTheme('midwinter'));
    }

    #[DataProvider('invalidDocuments')]
    public function testRejectsMalformedDocuments(string $json, string $exception): void
    {
        $this->expectException($exception);

        IconRegistry::fromJson($json);
    }

    /** @return iterable<string, array{string, class-string<\Throwable>}> */
    public static function invalidDocuments(): iterable
    {
        yield 'missing file read as empty' => ['', \JsonException::class];
        yield 'truncated' => ['{"icons": {', \JsonException::class];
        yield 'too deep' => ['{"icons": {"add": {"x": {"y": "z"}}}}', \JsonException::class];
        yield 'no icons' => ['{"themes": {}}', \InvalidArgumentException::class];
        yield 'icons not an object' => ['{"icons": "fa fa-plus"}', \InvalidArgumentException::class];
        yield 'themes not an object' => ['{"icons": {"add": "fa fa-plus"}, "themes": "paw"}', \InvalidArgumentException::class];
        yield 'top level list' => ['["fa fa-plus"]', \InvalidArgumentException::class];
    }

    public function testTheShippedRegistryLoads(): void
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/config/icons.json');
        self::assertIsString($json);
        $icons = IconRegistry::fromJson($json);

        foreach (['add', 'delete', 'edit', 'filter', 'refresh', 'help', 'collapse-all', 'expand-all'] as $name) {
            self::assertTrue($icons->has($name), $name);
        }
        self::assertSame('fas fa-sliders', $icons->classes('filter', 'midwinter'), 'the regular face has no sliders glyph');
    }
}
