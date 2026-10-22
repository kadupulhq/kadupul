<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Ported from scripts/motorola_surfboard_SB6141.py (Python 2, lxml and
 * requests). It walks the same libxml2 HTML tree. Where the Python script
 * crashed or printed an error instead of data, this prints U for every field
 * of the requested category.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const MOTOROLA_SB6141_TIMEOUT = 5;

/** Python's str.strip() whitespace for unicode text, which includes the non-breaking space the page uses. */
const MOTOROLA_SB6141_SPACE = '[\t\n\x{0B}\f\r\x{1C}-\x{1F} \x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

/** @return list<string> */
function motorola_sb6141_fields(string $category): array
{
    $fields = [];
    if ($category === 'Downstream') {
        foreach (['DownstreamPower', 'DownstreamNoiseRatio'] as $name) {
            foreach (range(1, 8) as $channel) {
                $fields[] = $name . $channel;
            }
        }
    } elseif ($category === 'Upstream') {
        foreach (range(5, 8) as $channel) {
            $fields[] = 'UpstreamPower' . $channel;
        }
    } elseif ($category === 'Stats') {
        foreach (range(1, 8) as $channel) {
            array_push($fields, 'StatsUnerrored' . $channel, 'StatsCorrectable' . $channel, 'StatsUncorrectable' . $channel);
        }
    }

    return $fields;
}

function motorola_sb6141_strip(string $text): string
{
    return (string) preg_replace('/^' . MOTOROLA_SB6141_SPACE . '+|' . MOTOROLA_SB6141_SPACE . '+$/u', '', $text);
}

/** lxml's .text, stripped: the text before a node's first child node; a missing one is where Python failed. */
function motorola_sb6141_text(\DOMNode $node): string
{
    if ($node instanceof \DOMComment) {
        return motorola_sb6141_strip($node->data);
    }
    $text = null;
    foreach ($node->childNodes as $child) {
        if (!$child instanceof \DOMText) {
            break;
        }
        $text .= $child->data;
    }
    if ($text === null) {
        throw new \RuntimeException('A cell has no text.');
    }

    return motorola_sb6141_strip($text);
}

/**
 * Children as lxml iterates them: elements and comments.
 *
 * @return list<\DOMNode>
 */
function motorola_sb6141_children(\DOMNode $node): array
{
    $children = [];
    foreach ($node->childNodes as $child) {
        if ($child instanceof \DOMElement || $child instanceof \DOMComment) {
            $children[] = $child;
        }
    }

    return $children;
}

/**
 * Reads the signal tables the way the Python script did: a header naming the
 * category, a Channel ID row, then rows of a label and one value per channel.
 *
 * @return array<string, array<string, array<string, string>>>
 */
function motorola_sb6141_parse(string $html): array
{
    if (trim($html) === '') {
        throw new \RuntimeException('The page is empty.');
    }
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML($html, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$loaded) {
        throw new \RuntimeException('The page is not HTML.');
    }

    $data = [];
    foreach ((new \DOMXPath($document))->query('//table/tbody') as $table) {
        $category = null;
        $channels = null;
        $dataRow = false;
        foreach (motorola_sb6141_children($table) as $row) {
            $haveLabel = false;
            $label = null;
            $channelCount = 0;
            foreach (motorola_sb6141_children($row) as $cell) {
                if ($dataRow) {
                    if ($haveLabel) {
                        if (!isset($channels[$channelCount])) {
                            throw new \RuntimeException('A row has more values than channels.');
                        }
                        $data[$category][$label][$channels[$channelCount]] = motorola_sb6141_text($cell);
                        $channelCount++;
                    } else {
                        $label = motorola_sb6141_text($cell);
                        $data[$category][$label] = [];
                    }
                } elseif (!$haveLabel && $cell->nodeName === 'th' && in_array($heading = motorola_sb6141_heading($cell), ['Downstream', 'Upstream', 'Signal Stats (Codewords)'], true)) {
                    $category = $heading === 'Signal Stats (Codewords)' ? 'Stats' : $heading;
                    $data[$category] = [];
                } elseif (!$haveLabel && $category !== null && $channels === null && motorola_sb6141_text($cell) === 'Channel ID') {
                    $channels = [];
                } elseif ($haveLabel && $channels !== null) {
                    $channels[] = motorola_sb6141_text($cell);
                }
                $haveLabel = true;
            }
            if ($channels !== null) {
                $dataRow = true;
            }
        }
    }

    return $data;
}

/** The stripped text of a header cell's first font child, as cell.find('font').text read it. */
function motorola_sb6141_heading(\DOMNode $cell): string
{
    foreach ($cell->childNodes as $child) {
        if ($child instanceof \DOMElement && $child->nodeName === 'font') {
            return motorola_sb6141_text($child);
        }
    }
    throw new \RuntimeException('A header cell has no font element.');
}

/**
 * @param array<string, array<string, array<string, string>>> $data
 * @return list<string>
 */
function motorola_sb6141_values(string $category, array $data): array
{
    $value = static function (string $section, string $label, int $channel, string $unit = '') use ($data): string {
        $text = $data[$section][$label][(string) $channel] ?? throw new \RuntimeException('The page has no ' . $label . ' for channel ' . $channel . '.');

        return $unit === '' ? $text : str_replace($unit, '', $text);
    };

    $values = [];
    if ($category === 'Downstream') {
        foreach (range(1, 8) as $channel) {
            $values[] = $value('Downstream', 'Power Level', $channel, ' dBmV');
        }
        foreach (range(1, 8) as $channel) {
            $values[] = $value('Downstream', 'Signal to Noise Ratio', $channel, ' dB');
        }
    } elseif ($category === 'Upstream') {
        foreach (range(5, 8) as $channel) {
            $values[] = $value('Upstream', 'Power Level', $channel, ' dBmV');
        }
    } else {
        foreach (range(1, 8) as $channel) {
            foreach (['Total Unerrored Codewords', 'Total Correctable Codewords', 'Total Uncorrectable Codewords'] as $label) {
                $values[] = $value('Stats', $label, $channel);
            }
        }
    }

    return $values;
}

/** The line the poller reads: field:value pairs, or field:U for all of them when the page could not be read. */
function motorola_sb6141_report(string $category, string|false $html): string
{
    $fields = motorola_sb6141_fields($category);
    try {
        if ($html === false) {
            throw new \RuntimeException('The page could not be fetched.');
        }
        $values = motorola_sb6141_values($category, motorola_sb6141_parse($html));
        // Python 2 could not print non-ASCII text to the poller's pipe.
        if (preg_match('/[^\x00-\x7F]/', implode('', $values)) === 1) {
            throw new \RuntimeException('A value is not ASCII.');
        }
    } catch (\RuntimeException) {
        $values = array_fill(0, count($fields), 'U');
    }

    return implode(' ', array_map(static fn(string $field, string $value): string => $field . ':' . $value, $fields, $values)) . "\n";
}

/** The page URL for a host name, IPv4 or IPv6 address with an optional port, or null for anything else. */
function motorola_sb6141_url(string $host): ?string
{
    if (preg_match('/^[0-9A-Fa-f:.]+$/', $host) === 1 && substr_count($host, ':') >= 2) {
        $host = '[' . $host . ']';
    }
    if (preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9.-]*|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/', $host) !== 1) {
        return null;
    }

    return 'http://' . $host . '/cmSignalData.htm';
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $arguments = array_map('strval', $_SERVER['argv']);
    $usage = 'usage: ' . $arguments[0] . ' [Downstream|Upstream|Stats] <hostname>' . "\n";
    if (count($arguments) !== 3) {
        print "category and hostname or IP of the modem are required!\n" . $usage;
        exit(1);
    }
    if (motorola_sb6141_fields($arguments[1]) === []) {
        print $usage;
        exit(1);
    }

    include_once __DIR__ . '/../include/cli_check.php';
    $url = motorola_sb6141_url($arguments[2]);
    print motorola_sb6141_report($arguments[1], $url === null ? false : cacti_http($url, MOTOROLA_SB6141_TIMEOUT));
}
