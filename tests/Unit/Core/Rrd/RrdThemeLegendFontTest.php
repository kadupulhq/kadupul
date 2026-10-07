<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// GPRINT legends line columns up with spaces, so they need a monospace face.
// The container image has only DejaVu, where fontconfig maps Courier to
// DejaVu Sans Mono but an absent 'Roboto Mono' to the proportional DejaVu Sans.
test('every theme draws graph legends in a font that stays monospace with only DejaVu installed', function () {
    $themes = glob(dirname(__DIR__, 4) . '/include/themes/*/rrdtheme.php');
    expect($themes)->not->toBeEmpty();

    foreach ($themes as $file) {
        $rrdfonts = array();
        $rrdcolors = array();
        include $file;

        expect($rrdfonts['legend']['font'] ?? 'Courier')->toBeIn(array('Courier', 'DejaVu Sans Mono', 'Monospace'), basename(dirname($file)));
    }
});
