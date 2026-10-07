<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Shared by lib/functions.php for the font settings and lib/rrd.php for
// rendering. Pollers and RRDtool helper processes load lib/rrd.php on its own.

/**
 * graph_font_resolver - the resolver shared by graph rendering and the font settings
 *
 * Callers without Composer's autoloader get the classes required here.
 *
 * @return - a \Kadupul\Graphing\Domain\Font\GraphFontResolver
 */
function graph_font_resolver(): \Kadupul\Graphing\Domain\Font\GraphFontResolver
{
    static $resolver = null;

    if ($resolver === null) {
        if (!class_exists(\Kadupul\Graphing\Domain\Font\GraphFontResolver::class)) {
            foreach (array('GraphFontMethod', 'GraphFont', 'GraphFontProfile', 'GraphFontResolver') as $class) {
                require_once __DIR__ . '/../src/Graphing/Domain/Font/' . $class . '.php';
            }
        }

        $resolver = new \Kadupul\Graphing\Domain\Font\GraphFontResolver();
    }

    return $resolver;
}
