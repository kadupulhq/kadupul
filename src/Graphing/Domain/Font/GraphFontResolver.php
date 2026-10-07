<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain\Font;

/** Decide the font each graph element is drawn with. */
final class GraphFontResolver
{
    public const ELEMENTS = array('title', 'axis', 'legend', 'unit', 'watermark');

    // RRDtool refuses 0 and below; 4 points and under is unreadable, and above
    // 72 a title or legend no longer fits the graph.
    public const MIN_SIZE_EXCLUSIVE = 4.0;
    public const MAX_SIZE = 72.0;

    // The longest value the Default Font setting stores.
    public const MAX_FAMILY_LENGTH = 255;

    /** Resolve the fonts for $elements.
     *
     * With System fonts the viewer's own settings, when given, replace the site
     * settings as a whole; with Theme fonts an element the theme leaves out
     * gets no font argument at all. A size RRDtool cannot draw falls back to
     * the element's default, except that large sizes are capped.
     *
     * @param list<string> $elements Elements to resolve, in any order.
     * @param array<array-key, mixed> $themeFonts The theme's $rrdfonts.
     * @param array<string, array{font?: mixed, size?: mixed}> $siteFonts Site settings by element.
     * @param array<string, array{font?: mixed, size?: mixed}>|null $viewerFonts The viewer's settings, or null when custom fonts are off.
     */
    public function resolve(
        array $elements,
        GraphFontMethod $method,
        array $themeFonts,
        array $siteFonts,
        ?array $viewerFonts,
        mixed $defaultFont = '',
    ): GraphFontProfile {
        $resolved = array();

        foreach ($elements as $element) {
            if ($method === GraphFontMethod::System) {
                $setting = ($viewerFonts ?? $siteFonts)[$element] ?? array();
                $font    = $setting['font'] ?? '';
                $size    = $setting['size'] ?? '';
            } elseif (isset($themeFonts[$element]) && is_array($themeFonts[$element]) && isset($themeFonts[$element]['font'], $themeFonts[$element]['size'])) {
                $font = $themeFonts[$element]['font'];
                $size = $themeFonts[$element]['size'];
            } else {
                continue;
            }

            $resolved[$element] = new GraphFont($this->family($font), self::size($size, $element === 'title' ? 12.0 : 8.0));
        }

        return new GraphFontProfile($method, $this->family($defaultFont), $resolved);
    }

    /** Whether RRDtool can draw $size points without it being replaced or capped. */
    public static function acceptsSize(mixed $size): bool
    {
        if (!is_numeric($size)) {
            return false;
        }

        $points = (float) $size;

        return is_finite($points) && $points > self::MIN_SIZE_EXCLUSIVE && $points <= self::MAX_SIZE;
    }

    /** The point size to draw with, or $default when $size is not one RRDtool can draw. */
    public static function size(mixed $size, float $default): float
    {
        if (self::acceptsSize($size)) {
            return (float) $size;
        }
        if (is_numeric($size) && is_finite((float) $size) && (float) $size > self::MAX_SIZE) {
            return self::MAX_SIZE;
        }

        return $default;
    }

    /** Whether $description reads as a Pango font description of fontconfig family names.
     *
     * Pango takes a comma-separated family list, style words and a size, and
     * fontconfig family names are letters, digits, blanks and a little
     * punctuation. Double quotes, backslashes, colons, slashes and control
     * characters belong to neither, so a value holding one names no installed
     * font; a font file path, which older releases asked for, is one of those.
     */
    public static function acceptsFamily(string $description): bool
    {
        return preg_match("/^[\\p{L}\\p{M}\\p{N} ,.'\\-_+&()@=#]{0," . self::MAX_FAMILY_LENGTH . '}$/uD', $description) === 1;
    }

    /** The font description to hand RRDtool for a stored value, or '' for RRDtool's own choice. */
    public function family(mixed $font): string
    {
        if (!is_string($font) && !is_int($font) && !is_float($font)) {
            return '';
        }

        $font = (string) $font;

        // A blank value is an empty setting, as graph_font_name_filter() treats it.
        return trim($font) !== '' && self::acceptsFamily($font) ? $font : '';
    }
}
