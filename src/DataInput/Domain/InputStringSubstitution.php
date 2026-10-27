<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\DataInput\Domain;

/** Builds data input command lines; also loaded before Composer by lib/functions.php. */
final class InputStringSubstitution
{
    /**
     * Replaces the <field> tokens of a data input command template in one pass
     * over the original template.
     *
     * Substituted values are never rescanned, so a field value that contains another
     * field's <token> cannot splice that field's escaped value into a new position.
     *
     * Field values arrive already wrapped in their own shell quotes by
     * cacti_escapeshellarg(). Placed inside a quote the template opened, those quotes
     * stop protecting the value: inside "..." the shell would still expand $(...) and
     * backticks from the value. The scanner therefore tracks the template's quoting.
     * A value the template wraps exactly ("<arg>" or '<arg>') replaces the pair, and a
     * value embedded anywhere else in a quoted word has the template's quote closed
     * before it and reopened after it. $(...) and backticks start a nested context
     * with fresh quoting, as the shell parses them.
     *
     * Values without their own quotes are the trusted path_* settings, which callers
     * do not escape. They are inserted as they are so a quoted "<path_php_binary>"
     * keeps its quotes and <path_cacti>/scripts/x.php stays one bare word for the
     * script server.
     *
     * Grouping, case patterns, comments, redirections and here-documents inside a
     * command substitution need more grammar than this scanner models, so such a
     * template yields an empty string rather than a command that might expose a
     * field to the outer quote. The same applies to a backslash inside backticks,
     * which the shell unescapes before parsing the inner command, and to a field or
     * backslash inside ${...}, whose word has its own quoting rules, and to bash's
     * $'...' and $"..." quoting, where a backslash can escape the closing quote.
     *
     * Ported from Cacti 1.2.x (GHSA-5v3j-wcrr-jxjg, Cacti PR #8238 and #8359).
     *
     * @param string                   $template       the data input method's input string
     * @param array<int|string, mixed> $escaped_values field name => escaped value, plus raw path_* values
     *
     * @return string the command line; unknown tokens are left for the caller to strip
     */
    public static function substitute(string $template, array $escaped_values): string
    {
        $out = '';
        $len = strlen($template);
        $i   = 0;

        // quote: the quote this frame has open; quoteEmpty: nothing emitted since it
        // opened; kind: top, cmdsub ($(...)), backtick or param (${...})
        $stack = [['quote' => '', 'quoteEmpty' => false, 'kind' => 'top']];

        while ($i < $len) {
            $top   = count($stack) - 1;
            $quote = $stack[$top]['quote'];
            $ch    = $template[$i];

            if ($ch === '<' && preg_match('/\G<([A-Za-z0-9_]+)>/', $template, $m, 0, $i)) {
                $token_len = strlen($m[0]);

                if (array_key_exists($m[1], $escaped_values)) {
                    if (in_array('param', array_column($stack, 'kind'), true)) {
                        return '';
                    }

                    $value      = (string) $escaped_values[$m[1]];
                    $value_len  = strlen($value);
                    $self_quote = $value_len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$value_len - 1] === $value[0];

                    if (!$self_quote) {
                        $out .= $value;
                    } elseif ($quote === '') {
                        // An odd run of backslashes would escape the value's opening
                        // quote and leave its closing quote unmatched.
                        if ((strlen($out) - strlen(rtrim($out, '\\'))) % 2 === 1) {
                            $out = substr($out, 0, -1);
                        }

                        $out .= $value;
                    } elseif ($stack[$top]['quoteEmpty'] && ($i + $token_len) < $len && $template[$i + $token_len] === $quote) {
                        $out = substr($out, 0, -1) . $value;
                        $i++;
                        $stack[$top]['quote'] = '';
                    } else {
                        // Inside double quotes a trailing odd backslash would escape
                        // the closing quote; inside single quotes it is literal.
                        if ($quote === '"' && (strlen($out) - strlen(rtrim($out, '\\'))) % 2 === 1) {
                            $out = substr($out, 0, -1);
                        }

                        $out .= $quote . $value . $quote;
                    }

                    $i += $token_len;
                    $stack[$top]['quoteEmpty'] = false;

                    continue;
                }

                $out .= $m[0];
                $i += $token_len;
                $stack[$top]['quoteEmpty'] = false;

                continue;
            }

            $kinds = array_column($stack, 'kind');

            if ($ch === '\\' && (in_array('backtick', $kinds, true) || in_array('param', $kinds, true))) {
                return '';
            }

            if ($quote === "'") {
                if ($ch === "'") {
                    $stack[$top]['quote'] = '';
                }

                $out .= $ch;
                $stack[$top]['quoteEmpty'] = false;
                $i++;

                continue;
            }

            if ($quote === '' && ($stack[$top]['kind'] === 'cmdsub' || $stack[$top]['kind'] === 'backtick')
                && ($ch === '(' || $ch === '#' || $ch === '<' || $ch === '>'
                    || (preg_match('/\Gcase(?=\s|$)/', $template, $unused, 0, $i)
                        && ($i === 0 || strpos(" \t\r\n;|&(", $template[$i - 1]) !== false)))) {
                return '';
            }

            if ($ch === '$' && $i + 1 < $len && $template[$i + 1] === '(') {
                $out .= '$(';
                $i += 2;
                $stack[] = ['quote' => '', 'quoteEmpty' => false, 'kind' => 'cmdsub'];

                continue;
            }

            if ($quote === '' && $ch === '$' && $i + 1 < $len && ($template[$i + 1] === "'" || $template[$i + 1] === '"')) {
                return '';
            }

            if ($ch === '$' && $i + 1 < $len && $template[$i + 1] === '{') {
                $out .= '${';
                $i += 2;
                $stack[] = ['quote' => '', 'quoteEmpty' => false, 'kind' => 'param'];

                continue;
            }

            if ($ch === '}' && $quote === '' && $stack[$top]['kind'] === 'param') {
                array_pop($stack);
                $out .= '}';
                $i++;

                continue;
            }

            if ($ch === ')' && $quote === '' && $stack[$top]['kind'] === 'cmdsub') {
                array_pop($stack);
                $out .= ')';
                $i++;

                continue;
            }

            if ($ch === '`') {
                // Inside backticks a bare backtick always ends the outer one, so a
                // nested context cannot open another.
                if ($stack[$top]['kind'] !== 'backtick' && in_array('backtick', $kinds, true)) {
                    return '';
                }

                // Only an unquoted backtick inside a backtick frame closes it.
                if ($stack[$top]['kind'] === 'backtick' && $quote === '') {
                    array_pop($stack);
                } else {
                    $stack[] = ['quote' => '', 'quoteEmpty' => false, 'kind' => 'backtick'];
                }

                $out .= '`';
                $i++;

                continue;
            }

            if ($ch === '\\' && $i + 1 < $len) {
                $next = $template[$i + 1];

                // Pair the backslash only with a character whose meaning it changes,
                // so \<arg> still substitutes.
                if ($next === '"' || $next === "'" || $next === '\\' || $next === '$' || $next === '`') {
                    $out .= $ch . $next;
                    $i += 2;
                } else {
                    $out .= $ch;
                    $i++;
                }

                if ($quote === '"') {
                    $stack[$top]['quoteEmpty'] = false;
                }

                continue;
            }

            if ($quote === '' && ($ch === '"' || $ch === "'")) {
                $stack[$top]['quote']      = $ch;
                $stack[$top]['quoteEmpty'] = true;
                $out .= $ch;
                $i++;

                continue;
            }

            if ($quote === '"' && $ch === '"') {
                $stack[$top]['quote'] = '';
                $out .= $ch;
                $i++;

                continue;
            }

            $out .= $ch;
            $stack[$top]['quoteEmpty'] = false;
            $i++;
        }

        return $out;
    }
}
