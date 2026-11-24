<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Stands in for include/vendor/motranslator/autoload.php, which Kadupul does
// not ship: an operator installs phpmyadmin/motranslator there to select that
// handler. Only the handler selection is under test, so the class keeps the
// library's signatures and answers every lookup with the message id.

namespace PhpMyAdmin\MoTranslator;

class Translator
{
    public function __construct(public readonly ?string $filename) {}

    public function gettext(string $msgid): string
    {
        return $msgid;
    }

    public function ngettext(string $msgid, string $msgidPlural, int $number): string
    {
        return $number === 1 ? $msgid : $msgidPlural;
    }
}
