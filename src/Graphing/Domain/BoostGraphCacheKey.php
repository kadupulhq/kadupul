<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Domain;

use InvalidArgumentException;

/**
 * Names the Boost PNG cache file for one rendered graph. Every per-viewer
 * input that changes the rendered image is an explicit argument, so an image
 * rendered for one viewer is never served to another. The legacy wrapper in
 * lib/boost.php resolves those inputs from the session, cookies and settings.
 */
final readonly class BoostGraphCacheKey
{
    /**
     * The basename shape fileName() produces. The purge deletes only names that match, so a change
     * to fileName() has to change this pattern with it.
     */
    public const string FILE_PATTERN = '/^[A-Za-z0-9_-]+_lgi_\d+_rrai_\d+(?:_tsi_\d+)?(?:_height_-?\d+)?(?:_width_-?\d+)?_rk_[0-9a-f]{64}(?:_thumb)?\.png$/D';

    private const VIEWER_INPUTS = ['theme', 'selected_theme', 'color_mode', 'fonts', 'date_format', 'datechar',
        'php_tz', 'tz', 'lang', 'locale', 'country'];

    private function __construct(
        private int $localGraphId,
        private int $rraId,
        private int $timespan,
        private string $selectedTheme,
        private ?int $height,
        private ?int $width,
        private bool $thumbnail,
        private string $renderKey,
    ) {}

    /**
     * @param array<string, mixed>  $graphDataArray the request's graph_* fields
     * @param array<string, string> $viewer         resolved per-viewer inputs: theme (the render
     *                                              theme), selected_theme (the file name theme),
     *                                              color_mode, fonts (the font profile fingerprint),
     *                                              date_format, datechar, php_tz, tz, lang, locale, country
     */
    public static function fromGraphRequest(int $localGraphId, int $rraId, int $timespan, array $graphDataArray, array $viewer): self
    {
        if ($localGraphId < 0 || $rraId < 0 || $timespan < 0) {
            throw new InvalidArgumentException('Graph, RRA and timespan ids must not be negative.');
        }

        $missing = array_diff(self::VIEWER_INPUTS, array_keys($viewer));
        if ($missing !== []) {
            throw new InvalidArgumentException('Missing viewer input: ' . implode(', ', $missing));
        }

        $parts = ['theme' => $viewer['theme'], 'color_mode' => $viewer['color_mode'], 'fonts' => $viewer['fonts'],
            'date_format' => $viewer['date_format'], 'datechar' => $viewer['datechar'],
            'php_tz' => $viewer['php_tz'], 'tz' => $viewer['tz'], 'lang' => $viewer['lang'],
            'locale' => $viewer['locale'], 'country' => $viewer['country']];

        // A window the caller leaves out is keyed as absent.
        $parts['graph_start'] = (string) ($graphDataArray['graph_start'] ?? '');
        $parts['graph_end'] = (string) ($graphDataArray['graph_end'] ?? '');

        // The file name casts dimensions and tests thumbnails with isset(), while the
        // renderer validates dimensions and tests the legend value, so the key keeps
        // the original values and omission state to avoid those collisions.
        foreach (['graph_width', 'graph_height', 'graph_nolegend'] as $field) {
            $parts[$field] = [array_key_exists($field, $graphDataArray), $graphDataArray[$field] ?? null];
        }

        $parts['graphv'] = isset($graphDataArray['graphv']) ? 'on' : '';
        $parts['image_format'] = (string) ($graphDataArray['image_format'] ?? '');

        return new self(
            $localGraphId,
            $rraId,
            $timespan,
            $viewer['selected_theme'],
            isset($graphDataArray['graph_height']) ? (int) $graphDataArray['graph_height'] : null,
            isset($graphDataArray['graph_width']) ? (int) $graphDataArray['graph_width'] : null,
            isset($graphDataArray['graph_nolegend']),
            // serialize() is binary safe, so no part has to be valid UTF-8.
            hash('sha256', serialize($parts)),
        );
    }

    /** Hex SHA-256 over the viewer and request inputs that change the image; it is the _rk_ part of the file name. */
    public function renderKey(): string
    {
        return $this->renderKey;
    }

    /**
     * @throws InvalidArgumentException when the directory is empty or contains a NUL byte, or the theme name is not
     *     made of letters, digits, underscores and hyphens
     */
    public function fileName(string $cacheDirectory): string
    {
        if ($cacheDirectory === '' || str_contains($cacheDirectory, "\0")) {
            throw new InvalidArgumentException('The cache directory must be a non-empty path.');
        }

        // The only free text in the name is the theme; every other part is an integer or hex.
        if (preg_match('/^[A-Za-z0-9_-]+$/', $this->selectedTheme) !== 1) {
            throw new InvalidArgumentException('The theme name cannot be used in a cache file name.');
        }

        $name = $this->selectedTheme . '_lgi_' . $this->localGraphId . '_rrai_' . $this->rraId;
        if ($this->rraId <= 0) {
            $name .= '_tsi_' . $this->timespan;
        }

        if ($this->height !== null) {
            $name .= '_height_' . $this->height;
        }

        if ($this->width !== null) {
            $name .= '_width_' . $this->width;
        }

        $name .= '_rk_' . $this->renderKey;

        return $cacheDirectory . '/' . $name . ($this->thumbnail ? '_thumb.png' : '.png');
    }
}
