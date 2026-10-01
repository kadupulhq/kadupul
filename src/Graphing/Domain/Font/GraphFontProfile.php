<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain\Font;

/** The fonts one graph render uses, after theme, site and viewer settings are applied. */
final readonly class GraphFontProfile
{
    /** @param array<string, GraphFont> $elements Only elements that get a --font argument. */
    public function __construct(public GraphFontMethod $method, public string $defaultFont, public array $elements) {}

    public function element(string $name): ?GraphFont
    {
        return $this->elements[$name] ?? null;
    }

    /** A stable string that differs whenever the rendered fonts would. */
    public function fingerprint(): string
    {
        $elements = array();
        foreach ($this->elements as $name => $font) {
            $elements[$name] = array($font->family, $font->size);
        }
        ksort($elements, SORT_STRING);

        return serialize(array($this->method->value, $this->defaultFont, $elements));
    }
}
