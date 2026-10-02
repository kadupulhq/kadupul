<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/** Versioned literal-text exports preserve exact UTF-8 values on reimport. */
final class PaletteCsv
{
    public const int MAX_BYTES = 1048576;
    public const int MAX_ROWS = 5000;
    public const string LITERAL_MARKER = 'kadupul_literal_v1';
    /** @return list<array{name: string, hex: string}> */
    public static function parse(string $csv): array
    {
        if ($csv === '' || strlen($csv) > self::MAX_BYTES || preg_match('//u', $csv) !== 1 || str_contains($csv, "\0")) {
            throw new \InvalidArgumentException('CSV must be valid UTF-8 and no larger than 1 MiB.');
        }
        self::assertSyntax($csv);
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, $csv);
            rewind($stream);
            $headers = fgetcsv($stream, null, ',', '"', '');
            $literal = is_array($headers) && count($headers) === 3 && in_array(self::LITERAL_MARKER, $headers, true);
            $expected = $literal ? ['name', 'hex', self::LITERAL_MARKER] : ['name', 'hex'];
            if (!is_array($headers) || count($headers) !== count($expected) || count(array_unique($headers)) !== count($expected) || array_diff($headers, $expected) !== []) {
                throw new \InvalidArgumentException('CSV requires name and hex columns, with only the supported literal-text marker.');
            }
            $rows = [];
            $hexes = [];
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if (count($values) !== count($expected) || count(array_filter($values, is_string(...))) !== count($expected)) {
                    throw new \InvalidArgumentException('CSV contains an invalid row.');
                }
                $row = array_combine($headers, $values);
                if ($literal) {
                    if ($row[self::LITERAL_MARKER] !== '1' || !str_starts_with($row['name'], "'") || !str_starts_with($row['hex'], "'")) {
                        throw new \InvalidArgumentException('CSV contains an invalid literal-text marker.');
                    }
                    $row['name'] = substr($row['name'], 1);
                    $row['hex'] = substr($row['hex'], 1);
                    unset($row[self::LITERAL_MARKER]);
                }
                PaletteColor::validate($row['name'], $row['hex']);
                $key = strtolower($row['hex']);
                if (isset($hexes[$key])) {
                    throw new \InvalidArgumentException('CSV contains a duplicate hex value.');
                }
                $hexes[$key] = true;
                $rows[] = $row;
                if (count($rows) > self::MAX_ROWS) {
                    throw new \InvalidArgumentException('CSV may contain at most 5000 colors.');
                }
            }
            if ($rows === []) {
                throw new \InvalidArgumentException('CSV contains no colors.');
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }
    private static function assertSyntax(string $csv): void
    {
        $state = 'start';
        $length = strlen($csv);
        for ($i = 0; $i < $length; $i++) {
            $char = $csv[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($csv[$i + 1] ?? '') === '"') {
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                }
                continue;
            }
            if ($char === ',' || $char === "\r" || $char === "\n") {
                $state = 'start';
                continue;
            }
            if ($state === 'closed' || ($state === 'unquoted' && $char === '"')) {
                throw new \InvalidArgumentException('CSV contains invalid quoting.');
            }
            $state = $state === 'start' && $char === '"' ? 'quoted' : 'unquoted';
        }
        if ($state === 'quoted') {
            throw new \InvalidArgumentException('CSV contains invalid quoting.');
        }
    }
    /** @param list<PaletteColor> $colors */
    public static function export(array $colors): string
    {
        $stream = fopen('php://temp', 'w+');
        try {
            fputcsv($stream, ['name', 'hex', self::LITERAL_MARKER], ',', '"', '', "\r\n");
            foreach ($colors as $color) {
                // Match the existing device exporter: all operator-controlled
                // cells are spreadsheet text, even after whitespace/control prefixes.
                // Only the explicit versioned marker permits reversing this encoding.
                fputcsv($stream, ["'" . $color->name, "'" . $color->hex, '1'], ',', '"', '', "\r\n");
            }
            rewind($stream);
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
