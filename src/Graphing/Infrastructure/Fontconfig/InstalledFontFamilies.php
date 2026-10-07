<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Fontconfig;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/** The font families fontconfig reports as installed on the web server. */
final class InstalledFontFamilies
{
    // fontconfig resolves these through its own aliases rather than an installed family.
    private const GENERIC = array('sans', 'sans-serif', 'serif', 'monospace', 'mono', 'cursive', 'fantasy', 'system-ui', 'emoji', 'math');

    /** @var array<string, true>|null */
    private ?array $families = null;

    private bool $listed = false;

    /** @param string|null $fcList The fc-list binary, or null when there is none. */
    public function __construct(private readonly ?string $fcList, private readonly float $timeout = 10.0) {}

    /** Whether a family named in $description is installed.
     *
     * Pango reads a description as a comma-separated family list followed by
     * recognized style words, a size and variations. Unknown trailing words
     * remain part of the family name. Fontconfig ignores case and blanks in
     * family names, and so does this.
     *
     * @return bool|null Null when fontconfig could not be asked.
     */
    public function contains(string $description): ?bool
    {
        $families = $this->families();
        if ($families === null) {
            return null;
        }

        // A successful empty listing is authoritative: even generic aliases
        // cannot select an installed font when fontconfig reports none.
        if ($families === array()) {
            return false;
        }

        // Match Pango's right-to-left description parsing, including the comma
        // that disambiguates a family whose last word is a style name.
        $familyList = trim($description);
        $familyList = preg_replace('/\\s+@\\S+$/', '', $familyList) ?? $familyList;
        if (preg_match('/(?:^|\\s)([+-]?(?:[0-9]+(?:\\.[0-9]*)?|\\.[0-9]+)(?:[eE][+-]?[0-9]+)?)(px)?$/', $familyList, $size)
            && (float) $size[1] >= 0 && (float) $size[1] <= 1000000) {
            $familyList = substr($familyList, 0, -strlen($size[0]));
        }
        while (preg_match('/(?:^|\\s)([^\\s,]+)$/', rtrim($familyList), $word) && self::isStyle($word[1])) {
            $familyList = substr(rtrim($familyList), 0, -strlen($word[0]));
        }
        foreach (explode(',', rtrim($familyList, " ,")) as $family) {
            $key = self::key($family);
            if (isset($families[$key]) || in_array($key, self::GENERIC, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true>|null */
    private function families(): ?array
    {
        if ($this->listed) {
            return $this->families;
        }
        $this->listed = true;

        if ($this->fcList === null) {
            return null;
        }

        try {
            $process = new Process(array($this->fcList, '--format', '%{family}\n'), null, null, null, $this->timeout);
            $process->run();
        } catch (ExceptionInterface) {
            return null;
        }
        if (!$process->isSuccessful()) {
            return null;
        }

        $families = array();
        foreach (preg_split('/\R/', $process->getOutput(), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
            // A family with more than one name lists them all, separated by commas.
            // fontconfig puts a backslash before special characters inside a name,
            // so only an unescaped comma separates two names.
            preg_match_all('/(?:\\\\.|[^,\\\\])+/s', $line, $names);
            foreach ($names[0] as $name) {
                $key = self::key(preg_replace('/\\\\(.)/s', '$1', $name) ?? '');
                if ($key !== '') {
                    $families[$key] = true;
                }
            }
        }

        return $this->families = $families;
    }

    private static function isStyle(string $word): bool
    {
        // Names and optional hyphens accepted by Pango's FieldMap parser.
        $styles = array(
            'style' => array('roman', 'oblique', 'italic'),
            'variant' => array('small-caps', 'all-small-caps', 'petite-caps', 'all-petite-caps', 'unicase', 'title-caps'),
            'weight' => array('thin', 'ultra-light', 'extra-light', 'light', 'semi-light', 'demi-light', 'book', 'regular', 'medium', 'semi-bold', 'demi-bold', 'bold', 'ultra-bold', 'extra-bold', 'heavy', 'black', 'ultra-heavy', 'extra-heavy', 'ultra-black', 'extra-black'),
            'stretch' => array('ultra-condensed', 'extra-condensed', 'condensed', 'semi-condensed', 'semi-expanded', 'expanded', 'extra-expanded', 'ultra-expanded'),
            'gravity' => array('not-rotated', 'south', 'upside-down', 'north', 'rotated-left', 'east', 'rotated-right', 'west'),
        );
        if ($word === 'Normal' || strcasecmp($word, 'normal') === 0) {
            return true;
        }
        if (preg_match('/^(weight|style|stretch|variant|gravity)=(.+)$/', $word, $field)) {
            // Named numeric fields are nonnegative signed 32-bit integers in Pango.
            if (preg_match('/^[+-]?[0-9]+$/', $field[2]) === 1 && (float) $field[2] >= 0 && (float) $field[2] <= 2147483647) {
                return true;
            }
            $styles = array($styles[$field[1]]);
            $word = $field[2];
        }
        foreach ($styles as $names) {
            foreach ($names as $name) {
                if (preg_match('/^' . str_replace('-', '-?', $name) . '$/iD', $word) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $name) ?? '', 'UTF-8');
    }
}
