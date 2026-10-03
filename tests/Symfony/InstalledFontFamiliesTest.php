<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Infrastructure\Fontconfig\InstalledFontFamilies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstalledFontFamiliesTest extends TestCase
{
    // What fc-list --format '%{family}\n' prints: one line per face, and every
    // name of a family with more than one, separated by commas. A comma inside a
    // name is escaped with a backslash.
    private const LISTING = "DejaVu Sans\nDejaVu Sans Mono\nDejaVu Sans\nNoto Sans CJK JP,Noto Sans CJK JP Regular\nCaf\u{e9} Sans\nAcme\\, Inc Sans\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-fc-list-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** A stand-in fc-list that prints $output, records its arguments and exits with $status. */
    private function fcList(string $output, int $status = 0): string
    {
        $path = $this->root . '/fc-list';
        file_put_contents($this->root . '/output', $output);
        file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> '" . $this->root . "/calls'\ncat '" . $this->root . "/output'\nexit " . $status . "\n");
        chmod($path, 0700);

        return $path;
    }

    /** @return iterable<string, array{string, bool}> */
    public static function descriptions(): iterable
    {
        yield 'a family' => ['DejaVu Sans', true];
        yield 'another case and no blanks' => ['dejavusans', true];
        yield 'styles and a size' => ['DejaVu Sans Mono Bold Italic 9', true];
        yield 'the second name of a family' => ['Noto Sans CJK JP Regular', true];
        yield 'a later family in a list' => ['Ubuntu, DejaVu Sans Bold', true];
        yield 'a generic family' => ['Monospace 8', true];
        yield 'accents' => ["Caf\u{e9} Sans", true];
        yield 'a family that is not installed' => ['Roboto Mono', false];
        yield 'a longer name that starts like one' => ['DejaVuSansCondensed', false];
        yield 'a style alone' => ['Bold', false];
        yield 'the end of a name that holds a comma' => ['Inc Sans', false];
    }

    #[DataProvider('descriptions')]
    public function testADescriptionMatchesWhenItNamesAnInstalledFamily(string $description, bool $installed): void
    {
        self::assertSame($installed, (new InstalledFontFamilies($this->fcList(self::LISTING)))->contains($description));
    }

    public function testFontconfigIsAskedOnceForTheFamilies(): void
    {
        $families = new InstalledFontFamilies($this->fcList(self::LISTING));

        $families->contains('DejaVu Sans');
        $families->contains('Roboto');

        self::assertSame("--format\n%{family}\\n\n", file_get_contents($this->root . '/calls'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function unanswered(): iterable
    {
        yield 'fc-list fails' => [self::LISTING, 1];
        yield 'fontconfig knows no fonts' => ['', 0];
    }

    #[DataProvider('unanswered')]
    public function testAnUnansweredListingIsUnknownRatherThanEmpty(string $output, int $status): void
    {
        self::assertNull((new InstalledFontFamilies($this->fcList($output, $status)))->contains('DejaVu Sans'));
    }

    public function testNoFcListIsUnknown(): void
    {
        self::assertNull((new InstalledFontFamilies(null))->contains('DejaVu Sans'));
        self::assertNull((new InstalledFontFamilies($this->root . '/missing'))->contains('DejaVu Sans'));
    }

    public function testAHungFcListIsGivenUpOn(): void
    {
        $path = $this->root . '/fc-list';
        file_put_contents($path, "#!/bin/sh\nexec sleep 5\n");
        chmod($path, 0700);
        $started = hrtime(true);

        self::assertNull((new InstalledFontFamilies($path, 0.2))->contains('DejaVu Sans'));
        self::assertLessThan(3, (hrtime(true) - $started) / 1e9);
    }
}
