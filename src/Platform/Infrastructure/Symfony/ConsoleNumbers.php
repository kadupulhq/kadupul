<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Twig\Attribute\AsTwigFilter;

final class ConsoleNumbers
{
    #[AsTwigFilter('console_number')]
    public function format(int $number, string $locale): string
    {
        $value = (new \NumberFormatter($locale, \NumberFormatter::DECIMAL))->format($number);
        if ($value === false) {
            throw new \RuntimeException('Console count could not be formatted.');
        }

        return $value;
    }
}
