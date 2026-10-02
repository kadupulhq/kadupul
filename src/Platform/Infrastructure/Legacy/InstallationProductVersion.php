<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Application\Port\ProductVersion;
use Kadupul\Platform\Application\ReadModel\ProductRelease;
use Symfony\Component\Filesystem\Filesystem;

/** get_cacti_version_text() inputs without executing legacy globals/configuration. */
final readonly class InstallationProductVersion implements ProductVersion
{
    public function __construct(private string $projectDir, private Filesystem $filesystem) {}

    public function release(): ProductRelease
    {
        $version = trim($this->filesystem->readFile($this->projectDir . '/include/cacti_version'));
        $beta = null;
        // Use PHP's built-in tokenizer: production installs omit development
        // parsers. Ignore comments and nested/conditional calls; never eval or
        // include the source. Only a standalone literal define is supported.
        $tokens = array_values(array_filter(token_get_all($this->filesystem->readFile($this->projectDir . '/include/global.php')), static fn($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $braces = 0;
        $parentheses = 0;
        foreach ($tokens as $index => $token) {
            $prior = $tokens[$index - 1] ?? null;
            $standalone = $prior === ';' || $prior === '}' || (is_array($prior) && $prior[0] === T_OPEN_TAG);
            if ($braces === 0 && $parentheses === 0 && $standalone && is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'define') {
                $name = $tokens[$index + 2] ?? null;
                $value = $tokens[$index + 4] ?? null;
                if (($tokens[$index + 1] ?? null) === '(' && ($tokens[$index + 3] ?? null) === ',' && ($tokens[$index + 5] ?? null) === ')' && ($tokens[$index + 6] ?? null) === ';' && is_array($name) && $name[0] === T_CONSTANT_ENCAPSED_STRING && self::literal($name[1]) === 'CACTI_VERSION_BETA' && is_array($value) && in_array($value[0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER], true)) {
                    $beta = $value[0] === T_LNUMBER ? (string) intval(str_replace('_', '', $value[1]), 0) : self::literal($value[1]);
                    break;
                }
            }
            $braces += (int) ($token === '{') - (int) ($token === '}');
            $parentheses += (int) ($token === '(') - (int) ($token === ')');
        }
        return new ProductRelease($version, $beta);
    }

    private static function literal(string $value): string
    {
        $text = substr($value, 1, -1);
        return $value[0] === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $text) : stripcslashes($text);
    }
}
