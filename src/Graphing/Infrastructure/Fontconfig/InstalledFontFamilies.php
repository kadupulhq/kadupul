<?php

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
     * style words and a size, so an item matches when it is a family or starts
     * with one followed by a blank. Fontconfig ignores case and blanks in
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

        foreach (explode(',', $description) as $item) {
            $words = preg_split('/\s+/', trim($item), -1, PREG_SPLIT_NO_EMPTY);
            if ($words === false) {
                continue;
            }

            for ($count = count($words); $count > 0; $count--) {
                $key = self::key(implode(' ', array_slice($words, 0, $count)));
                if (isset($families[$key]) || in_array($key, self::GENERIC, true)) {
                    return true;
                }
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
            foreach (explode(',', str_replace('\\', '', $line)) as $name) {
                $key = self::key($name);
                if ($key !== '') {
                    $families[$key] = true;
                }
            }
        }

        // An empty list means fontconfig found no fonts at all, not that none match.
        return $this->families = $families === array() ? null : $families;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $name) ?? '', 'UTF-8');
    }
}
