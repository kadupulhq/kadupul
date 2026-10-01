<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Navigation\Domain\ExternalLink;
use Kadupul\Navigation\Infrastructure\Symfony\LinkListParameters;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExternalLinkTest extends TestCase
{
    public static function fields(): array
    {
        return ['title' => 'A <tag> 東京', 'style' => 'TAB', 'filename' => '0', 'fileurl' => 'https://example.org/?a=1&b=2', 'consolesection' => 'External Links', 'consolenewsection' => '', 'enabled' => true, 'refresh' => 60];
    }
    public function testRawFieldsAndAllowedProtocols(): void
    {
        foreach (['http', 'https', 'ftp', 'ftps'] as $scheme) {
            $fields = self::fields();
            $fields['fileurl'] = $scheme . '://example.org/?a=1&b=2';
            $values = ExternalLink::validate($fields, []);
            self::assertSame($fields['title'], $values['title']);
            self::assertSame($fields['fileurl'], $values['contentfile']);
        }
    }
    public static function invalid(): iterable
    {
        yield [['title' => '']];
        yield [['title' => "bad\xff"]];
        yield [['title' => str_repeat('é', 21)]];
        yield [['style' => 'BAD']];
        yield [['fileurl' => 'javascript:alert(1)']];
        yield [['fileurl' => 'data:text/html,x']];
        yield [['fileurl' => 'https://example.org/ x']];
        yield [['filename' => '../index.php']];
        yield [['filename' => 'unknown.php']];
        yield [['refresh' => 61]];
        yield [['enabled' => 'on']];
        yield [['style' => 'CONSOLE', 'consolesection' => '__NEW__', 'consolenewsection' => str_repeat('x', 21)]];
    }
    #[DataProvider('invalid')]
    public function testRejectsInvalidFields(array $change): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExternalLink::validate(array_replace(self::fields(), $change), ['valid.php']);
    }
    public function testInstalledFileAndSections(): void
    {
        $fields = array_replace(self::fields(), ['filename' => 'valid.php', 'style' => 'CONSOLE', 'consolesection' => '__NEW__', 'consolenewsection' => 'Section 東京']);
        $value = ExternalLink::validate($fields, ['valid.php']);
        self::assertSame('valid.php', $value['contentfile']);
        self::assertSame('Section 東京', $value['extendedstyle']);
    }
    public function testFiltersAndSelections(): void
    {
        $filter = LinkListParameters::parse(['filter' => '" onclick="x', 'sort_direction' => 'DESC'], 40);
        self::assertSame('ASC', $filter['sort_direction']);
        self::assertSame(40, $filter['limit']);
        self::assertSame([2,5], LinkListParameters::ids(['5','2']));
    }
    public function testDuplicateSelectionFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LinkListParameters::ids(['1','1']);
    }
}
