<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/** Exact UTF-8 names and hex survive RFC 4180 export/import, including quotes and newlines. */
final class PaletteCsv
{
    public const int MAX_BYTES = 1048576;
    public const int MAX_ROWS = 5000;
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
            if (!is_array($headers) || count($headers) !== 2 || count(array_unique($headers)) !== 2 || array_diff($headers, ['name', 'hex']) !== []) {
                throw new \InvalidArgumentException('CSV requires exactly the name and hex header columns.');
            }
            $rows = [];
            $hexes = [];
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if (count($values) !== 2 || !is_string($values[0]) || !is_string($values[1])) {
                    throw new \InvalidArgumentException('CSV contains an invalid row.');
                }
                $row = array_combine($headers, $values);
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
            fputcsv($stream, ['name', 'hex'], ',', '"', '', "\r\n");
            foreach ($colors as $color) {
                fputcsv($stream, [$color->name, $color->hex], ',', '"', '', "\r\n");
            }
            rewind($stream);
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
