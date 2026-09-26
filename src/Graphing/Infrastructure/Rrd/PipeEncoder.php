<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Rrd;

use Kadupul\Graphing\Domain\RrdCommand;

/**
 * Writes a command as one input line for `rrdtool -`.
 *
 * No shell reads this line. RRDtool splits it itself, in CountArgs() and
 * CreateArgs() in src/rrd_tool.c, with the same rules from 1.3.0 to 1.11.0:
 *
 * - Only an ASCII space separates arguments. A tab is an ordinary byte.
 * - ' and " both quote, and each is literal inside the other. A quoted run
 *   may start or end anywhere in an argument: a'b c'd is "ab cd".
 * - A backslash is literal and escapes nothing, so the shell form '\'' leaves
 *   a backslash in the argument and loses the quote.
 * - '' is an empty argument.
 * - A newline ends the command and a NUL ends the line early. Bytes up to
 *   space are trimmed from both ends of the line, and so are bytes above 0x7f
 *   where char is signed, so an unquoted last argument can lose its end.
 * - An unclosed quote rejects the whole line.
 *
 * Every argument is therefore single-quoted, with each ' written as '"'"'.
 * RRDtool keeps a CR inside quotes, but the RRDtool proxy refuses any command
 * that contains one, so CR is refused along with LF and NUL.
 */
final class PipeEncoder
{
    public function encode(RrdCommand $command): string
    {
        $line = $command->verb;
        foreach ($command->arguments as $argument) {
            $line .= ' ' . $this->quote($argument);
        }

        return $line;
    }

    public function quote(string $argument): string
    {
        if (strpbrk($argument, "\0\r\n") !== false) {
            throw new UnrepresentableArgument();
        }

        return "'" . str_replace("'", "'\"'\"'", $argument) . "'";
    }
}
