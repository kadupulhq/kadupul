<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Edits files and data input methods inside a bundled .xml.gz package as text,
 * so every byte it does not touch, CRLF line ends included, stays as it was.
 * Edited files lose their file signature and the package loses its signature;
 * re-sign the result with kadupul:package:sign before shipping it.
 *
 *   php tools/edit-package.php install/templates/Local_Linux_Machine.xml.gz \
 *       --replace=scripts/unix_users.pl=scripts/unix_users.php \
 *       --add=scripts/script_command.php \
 *       --update=resource/script_queries/unix_disk.xml \
 *       --input-string='79a284e136bb6b061c6f96ec219ac448=<path_php_binary> -q <path_cacti>/scripts/unix_users.php <username>'
 *
 * --replace swaps a file entry for a repository file, --update refreshes an
 * entry from the repository file of the same name, --add adds a repository
 * file, and --input-string sets the input string of the data input method
 * with that hash in the package's template.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class PackageEditFailed extends RuntimeException {}

/**
 * @param list<array{0: string, 1: string, 2?: string}> $operations
 */
function package_edit(string $xml, array $operations, string $root): string
{
    $edited = $xml;
    foreach ($operations as $operation) {
        $edited = match ($operation[0]) {
            'replace' => package_edit_file($edited, $operation[1], $operation[2], package_edit_read($root, $operation[2])),
            'update' => package_edit_file($edited, $operation[1], $operation[1], package_edit_read($root, $operation[1])),
            'add' => package_edit_add($edited, $operation[1], package_edit_read($root, $operation[1])),
            'input-string' => package_edit_input_string($edited, $operation[1], $operation[2]),
            default => throw new PackageEditFailed('Unknown operation ' . $operation[0] . '.'),
        };
    }
    if ($edited === $xml) {
        return $xml;
    }
    $signatures = preg_match_all('#<signature>[^<]*</signature>#', $edited);
    if ($signatures > 1) {
        throw new PackageEditFailed('The package has more than one signature element.');
    }
    $edited = (string) preg_replace('#<signature>[^<]*</signature>#', '<signature></signature>', $edited);
    package_edit_assert_readable($edited);

    return $edited;
}

function package_edit_read(string $root, string $path): string
{
    if (preg_match('#^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#', $path) !== 1 || str_contains($path, '..')) {
        throw new PackageEditFailed('Refusing file path ' . $path . '.');
    }
    $contents = is_file($root . '/' . $path) ? file_get_contents($root . '/' . $path) : false;
    if ($contents === false) {
        throw new PackageEditFailed('Cannot read ' . $path . '.');
    }

    return $contents;
}

/**
 * The file entries in document order, each with its offset in the package.
 *
 * @return list<array{name: string, offset: int, block: string}>
 */
function package_edit_files(string $xml): array
{
    preg_match_all('#[ \t]*<file>.*?</file>#s', $xml, $matches, PREG_OFFSET_CAPTURE);
    $files = [];
    foreach ($matches[0] as [$block, $offset]) {
        if (preg_match('#<name>([^<]*)</name>#', $block, $name) !== 1) {
            throw new PackageEditFailed('A file entry has no name.');
        }
        $files[] = ['name' => htmlspecialchars_decode($name[1], ENT_XML1), 'offset' => $offset, 'block' => $block];
    }

    return $files;
}

/** @return array{name: string, offset: int, block: string} */
function package_edit_find(string $xml, string $name): array
{
    $found = array_values(array_filter(package_edit_files($xml), static fn(array $file): bool => $file['name'] === $name));
    if (count($found) !== 1) {
        throw new PackageEditFailed('The package has ' . count($found) . ' file entries named ' . $name . '.');
    }

    return $found[0];
}

function package_edit_splice(string $xml, array $file, string $block): string
{
    return substr_replace($xml, $block, $file['offset'], strlen($file['block']));
}

/** Points an entry at $name with $contents and drops its now stale file signature. */
function package_edit_entry(string $block, string $from, string $name, string $contents): string
{
    $escapedFrom = htmlspecialchars($from, ENT_XML1);
    $escapedName = htmlspecialchars($name, ENT_XML1);
    $replacements = [
        '#<name>[^<]*</name>#' => '<name>' . $escapedName . '</name>',
        '#<data>[^<]*</data>#' => '<data>' . base64_encode($contents) . '</data>',
    ];
    foreach ($replacements as $pattern => $replacement) {
        $block = preg_replace_callback($pattern, static fn(): string => $replacement, $block, -1, $count);
        if ($count !== 1) {
            throw new PackageEditFailed('The entry for ' . $from . ' does not have exactly one ' . $pattern . '.');
        }
    }
    if ($from !== $name && preg_match('#<opath>([^<]*)</opath>#', $block, $opath) === 1) {
        if (!str_ends_with($opath[1], '/' . $escapedFrom)) {
            throw new PackageEditFailed('The output path of ' . $from . ' does not end in its name.');
        }
        $path = substr($opath[1], 0, -strlen($escapedFrom)) . $escapedName;
        $block = str_replace($opath[0], '<opath>' . $path . '</opath>', $block);
    }

    return (string) preg_replace('#\R?[ \t]*<filesignature>[^<]*</filesignature>#', '', $block);
}

function package_edit_file(string $xml, string $from, string $to, string $contents): string
{
    $names = array_column(package_edit_files($xml), 'name');
    if ($from !== $to && in_array($to, $names, true)) {
        // Already replaced by an earlier run: refresh it instead, so reruns are harmless.
        if (!in_array($from, $names, true)) {
            return package_edit_file($xml, $to, $to, $contents);
        }
        throw new PackageEditFailed('The package already has ' . $to . '.');
    }
    $file = package_edit_find($xml, $from);
    $current = preg_match('#<data>([^<]*)</data>#', $file['block'], $data) === 1 ? base64_decode($data[1], true) : false;
    if ($from === $to && $current === $contents) {
        return $xml;
    }

    return package_edit_splice($xml, $file, package_edit_entry($file['block'], $from, $to, $contents));
}

/** Adds a file before the first entry, formatted like the first script entry already there. */
function package_edit_add(string $xml, string $name, string $contents): string
{
    $files = package_edit_files($xml);
    if (array_filter($files, static fn(array $file): bool => $file['name'] === $name) !== []) {
        return package_edit_file($xml, $name, $name, $contents);
    }
    $model = array_values(array_filter($files, static fn(array $file): bool => !str_contains($file['block'], '<type>template</type>')))[0] ?? null;
    if ($model === null) {
        throw new PackageEditFailed('The package has no file entry to model ' . $name . ' on.');
    }
    preg_match('#\R#', $xml, $newline);
    $block = package_edit_entry($model['block'], $model['name'], $name, $contents);

    return substr_replace($xml, $block . ($newline[0] ?? "\n"), $files[0]['offset'], 0);
}

/** Sets the base64 input string of one data input method inside the template entry. */
function package_edit_input_string(string $xml, string $hash, string $inputString): string
{
    if (preg_match('/^[0-9a-f]{32}$/', $hash) !== 1) {
        throw new PackageEditFailed('Refusing data input hash ' . $hash . '.');
    }
    $templates = array_values(array_filter(package_edit_files($xml), static fn(array $file): bool => str_contains($file['block'], '<type>template</type>')));
    if (count($templates) !== 1 || preg_match('#<data>([^<]*)</data>#', $templates[0]['block'], $data) !== 1) {
        throw new PackageEditFailed('The package does not have exactly one template entry.');
    }
    $template = base64_decode($data[1], true);
    if ($template === false || base64_encode($template) !== $data[1]) {
        throw new PackageEditFailed('The template data is not plain base64; editing it would change its encoding.');
    }

    $pattern = '#(<hash_[0-9a-f]{6}' . $hash . '>(?:(?!</hash_).)*?<input_string>)([^<]*)(</input_string>)#s';
    $edited = preg_replace_callback($pattern, static fn(array $match): string => $match[1] . base64_encode($inputString) . $match[3], $template, -1, $count);
    if ($count !== 1) {
        throw new PackageEditFailed('The template has ' . $count . ' data input methods with hash ' . $hash . '.');
    }
    if ($edited === $template) {
        return $xml;
    }

    return package_edit_splice($xml, $templates[0], package_edit_entry($templates[0]['block'], $templates[0]['name'], $templates[0]['name'], $edited));
}

function package_edit_assert_readable(string $xml): void
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadXML($xml, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$loaded || $document->doctype !== null) {
        throw new PackageEditFailed('The edited package is not well-formed XML.');
    }
}

/**
 * @param list<string> $arguments
 * @return list<array{0: string, 1: string, 2?: string}>
 */
function package_edit_operations(array $arguments): array
{
    $operations = [];
    foreach ($arguments as $argument) {
        if (preg_match('/^--(replace|update|add|input-string)=(.+)$/s', $argument, $option) !== 1) {
            throw new PackageEditFailed('Unknown argument ' . $argument . '.');
        }
        $operations[] = in_array($option[1], ['replace', 'input-string'], true) && str_contains($option[2], '=')
            ? [$option[1], ...explode('=', $option[2], 2)]
            : [$option[1], $option[2]];
        if (in_array($option[1], ['replace', 'input-string'], true) && count(end($operations)) !== 3) {
            throw new PackageEditFailed('--' . $option[1] . ' needs a value of the form A=B.');
        }
    }

    return $operations;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $arguments = array_slice(array_map('strval', $_SERVER['argv']), 1);
    try {
        $package = array_shift($arguments) ?? throw new PackageEditFailed('Usage: php tools/edit-package.php PACKAGE.xml.gz [--replace=OLD=NEW] [--update=PATH] [--add=PATH] [--input-string=HASH=STRING]');
        $compressed = is_file($package) ? file_get_contents($package) : false;
        $xml = $compressed === false || !str_starts_with($compressed, "\x1f\x8b") ? false : gzdecode($compressed);
        if ($xml === false) {
            throw new PackageEditFailed('Cannot read ' . $package . ' as a gzip package.');
        }
        $edited = package_edit($xml, package_edit_operations($arguments), dirname(__DIR__));
        if ($edited !== $xml && file_put_contents($package, gzencode($edited, 9)) === false) {
            throw new PackageEditFailed('Cannot write ' . $package . '.');
        }
        fwrite(STDOUT, ($edited === $xml ? 'Unchanged ' : 'Edited ') . $package . PHP_EOL);
    } catch (PackageEditFailed $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
