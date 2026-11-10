<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Translation;

use Kadupul\Platform\Infrastructure\Symfony\GettextMoLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;

final class GettextMoLoaderTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/translation/plugins/i18nprobe/locales/LC_MESSAGES/de-DE.mo';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
        $this->files = [];
    }

    public function testKeepsEveryCompiledEntryUnderItsGettextIdentity(): void
    {
        $catalogue = (new GettextMoLoader())->load(self::FIXTURE, 'de_DE', 'i18nprobe');
        self::assertSame([
            '' => "Project-Id-Version: i18nprobe\nLanguage: de-DE\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\nPlural-Forms: nplurals=2; plural=n != 1;\n",
            '%d Minutes' => '%d Sondenminuten',
            "%d probe\0%d probes" => "%d Sonde\0%d Sonden",
            "%d slot\0%d slots" => "%d Platz\0",
            'Delete' => 'Löschen (Sonde)',
            'Devices' => 'Sondengeräte',
            'Left|Right' => 'Links|Rechts',
            "One|item\0Many|items" => "Ein|Element\0Viele|Elemente",
            'Plain value' => 'Wert %%d',
            'Probe %s ready' => 'Sonde %s bereit',
            'Usage %d%%' => 'Auslastung %d%%',
            "probe\x04Delete" => 'Sonde entfernen',
            "probe\x04Item\0Items" => "Sondenelement\0Sondenelemente",
        ], $catalogue->all('i18nprobe'));
        self::assertSame(['i18nprobe'], $catalogue->getDomains());
        self::assertSame('de_DE', $catalogue->getLocale());
        self::assertEquals([new FileResource(self::FIXTURE)], $catalogue->getResources());
    }

    public function testReadsBothByteOrders(): void
    {
        $entries = ['' => "Plural-Forms: nplurals=3; plural=n==1 ? 0 : n==2 ? 1 : 2;\n", "ctx\x04One" => 'Eins', "a\0b" => "x\0\0z"];
        foreach ([false, true] as $bigEndian) {
            self::assertSame($entries, (new GettextMoLoader())->load($this->mo($entries, $bigEndian), 'xx', 'probe')->all('probe'));
        }
    }

    public function testAcceptsACatalogWithoutHeaderOrEntries(): void
    {
        self::assertSame([], (new GettextMoLoader())->load($this->mo([]), 'xx', 'probe')->all('probe'));
        self::assertSame(['a' => 'b'], (new GettextMoLoader())->load($this->mo(['a' => 'b']), 'xx', 'probe')->all('probe'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function resources(): iterable
    {
        yield 'array' => [['path' => self::FIXTURE]];
        yield 'empty' => [''];
        yield 'NUL' => [self::FIXTURE . "\0.mo"];
        yield 'integer' => [42];
    }

    #[DataProvider('resources')]
    public function testRefusesAResourceThatIsNotAPath(mixed $resource): void
    {
        $this->expectException(InvalidResourceException::class);
        (new GettextMoLoader())->load($resource, 'de_DE', 'cacti');
    }

    public function testReportsAMissingCatalog(): void
    {
        $this->expectException(NotFoundResourceException::class);
        (new GettextMoLoader())->load(__DIR__ . '/absent.mo', 'de_DE', 'cacti');
    }

    /** @return iterable<string, array{\Closure(string): string, string}> */
    public static function corruptions(): iterable
    {
        yield 'truncated header' => [static fn(string $mo): string => substr($mo, 0, 19), 'truncated'];
        yield 'wrong magic' => [static fn(string $mo): string => "\0\0\0\0" . substr($mo, 4), 'not a gettext catalog'];
        yield 'major revision 2' => [static fn(string $mo): string => substr_replace($mo, pack('V', 2 << 16), 4, 4), 'revision'];
        yield 'too many strings' => [static fn(string $mo): string => substr_replace($mo, pack('V', 100000), 8, 4), 'out of bounds'];
        yield 'originals table past the end' => [static fn(string $mo): string => substr_replace($mo, pack('V', strlen($mo)), 12, 4), 'out of bounds'];
        yield 'translations table past the end' => [static fn(string $mo): string => substr_replace($mo, pack('V', strlen($mo) - 4), 16, 4), 'out of bounds'];
        yield 'string past the end' => [static fn(string $mo): string => substr_replace($mo, pack('V', 1 << 20), 28 + 4, 4), 'out of bounds'];
        yield 'string length past the end' => [static fn(string $mo): string => substr_replace($mo, pack('V', 1 << 20), 28, 4), 'out of bounds'];
    }

    /** @param \Closure(string): string $corrupt */
    #[DataProvider('corruptions')]
    public function testRefusesACorruptCatalog(\Closure $corrupt, string $message): void
    {
        $path = $this->mo(['' => "Plural-Forms: nplurals=2; plural=n != 1;\n", 'One' => 'Eins', 'Two' => 'Zwei']);
        file_put_contents($path, $corrupt((string) file_get_contents($path)));
        $this->expectException(InvalidResourceException::class);
        $this->expectExceptionMessage($message);
        (new GettextMoLoader())->load($path, 'de_DE', 'cacti');
    }

    public function testRefusesARepeatedOriginal(): void
    {
        $this->expectExceptionMessage('repeats a message');
        (new GettextMoLoader())->load($this->mo([['One', 'Eins'], ['One', 'Uno']]), 'de_DE', 'cacti');
    }

    public function testRefusesARuleItCannotEvaluate(): void
    {
        $this->expectExceptionMessage('invalid Plural-Forms rule');
        (new GettextMoLoader())->load($this->mo(['' => "Plural-Forms: nplurals=2; plural=n + system(1);\n"]), 'de_DE', 'cacti');
    }

    /**
     * A .mo file as GNU msgfmt lays it out (no hash table).
     *
     * @param array<string, string>|list<array{string, string}> $entries
     */
    private function mo(array $entries, bool $bigEndian = false): string
    {
        $pairs = array_is_list($entries) && $entries !== [] && is_array($entries[0]) ? $entries : array_map(null, array_keys($entries), array_values($entries));
        $format = $bigEndian ? 'N' : 'V';
        $count = count($pairs);
        $originals = 28;
        $translations = $originals + $count * 8;
        $pool = $translations + $count * 8;
        $tables = ['', ''];
        $strings = '';
        foreach ([0, 1] as $column) {
            foreach ($pairs as $pair) {
                $text = (string) $pair[$column];
                $tables[$column] .= pack($format . '2', strlen($text), $pool + strlen($strings));
                $strings .= $text . "\0";
            }
        }
        $bytes = pack($format . '7', 0x950412DE, 0, $count, $originals, $translations, 0, $pool) . $tables[0] . $tables[1] . $strings;
        $path = (string) tempnam(sys_get_temp_dir(), 'kadupul-mo-');
        $this->files[] = $path;
        file_put_contents($path, $bytes);

        return $path;
    }
}
