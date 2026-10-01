<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Transitional compatibility libraries, including reviewed local security fixes.
// These are pinned source files, not substitutes for Composer-managed packages.
$root = dirname(__DIR__, 2);
$manifest = json_decode(file_get_contents(__DIR__ . '/legacy-files.json'), true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/\A[0-9a-f]{40}\z/', $manifest['revision'])) {
    throw new RuntimeException('Invalid legacy dependency revision');
}
$patches = $manifest['patches'] ?? [];
if (!is_array($patches)) {
    throw new RuntimeException('Invalid legacy dependency patches');
}
// Every patch entry is checked before anything is downloaded, so a malformed
// manifest fails with this message rather than a TypeError part-way through.
foreach ($patches as $path => $patch) {
    if (!is_array($patch) || !isset($manifest['files'][$path]) || !is_string($patch['source_sha256'] ?? null)
        || !preg_match('/\A[0-9a-f]{64}\z/', $patch['source_sha256'])
        || !is_array($patch['replacements'] ?? null) || $patch['replacements'] === []) {
        throw new RuntimeException('Invalid legacy dependency patch: ' . $path);
    }
    foreach ($patch['replacements'] as $replacement) {
        if (!is_array($replacement) || !is_string($replacement['before'] ?? null) || $replacement['before'] === ''
            || !is_string($replacement['after'] ?? null)) {
            throw new RuntimeException('Invalid legacy dependency patch: ' . $path);
        }
    }
}
$missing = [];
foreach ($manifest['files'] as $path => $digest) {
    if (!preg_match('~\Ainclude/vendor/[a-zA-Z0-9_./-]+\z~', $path) || str_contains($path, '..')) {
        throw new RuntimeException('Invalid legacy dependency path');
    }
    if (!preg_match('/\A[0-9a-f]{64}\z/', $digest)) {
        throw new RuntimeException('Invalid legacy dependency checksum');
    }
    $ancestor = $root . '/' . $path;
    while ($ancestor !== $root) {
        if (is_link($ancestor)) {
            throw new RuntimeException('Refusing symlink in legacy dependency path: ' . $path);
        }
        $ancestor = dirname($ancestor);
    }
    if (!is_file($root . '/' . $path) || hash_file('sha256', $root . '/' . $path) !== $digest) {
        $missing[$path] = $digest;
    }
}
if ($missing === []) {
    echo "Legacy compatibility dependencies verified.\n";
    exit(0);
}
$temporary = tempnam(sys_get_temp_dir(), 'kadupul-legacy-');
$archivePath = $temporary . '.tar.gz';
rename($temporary, $archivePath);
try {
    $url = 'https://codeload.github.com/kadupulhq/kadupul/tar.gz/' . $manifest['revision'];
    $context = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 0]]);
    $source = fopen($url, 'rb', false, $context);
    if ($source === false) {
        throw new RuntimeException('Cannot download pinned legacy dependencies; use the offline release bundle on disconnected hosts.');
    }
    $destination = fopen($archivePath, 'wb');
    try {
        $copied = stream_copy_to_stream($source, $destination, 100 * 1024 * 1024 + 1);
        if ($copied === false || $copied > 100 * 1024 * 1024) {
            throw new RuntimeException('Legacy archive exceeds download limit');
        }
    } finally {
        fclose($source);
        fclose($destination);
    }
    if (hash_file('sha256', $archivePath) !== $manifest['archive_sha256']) {
        throw new RuntimeException('Legacy archive checksum mismatch');
    }
    $archive = new PharData($archivePath);
    $prefix = 'kadupul-' . $manifest['revision'] . '/';
    $prepared = [];
    foreach ($missing as $path => $digest) {
        $entry = $archive[$prefix . $path];
        if (!$entry->isFile() || $entry->isLink() || $entry->getSize() > 4 * 1024 * 1024) {
            throw new RuntimeException('Invalid legacy archive entry: ' . $path);
        }
        $bytes = $entry->getContent();
        // A patched file is checked twice: the archive bytes against the reviewed
        // source, then the result against the digest recorded for installation.
        if (isset($patches[$path])) {
            if (hash('sha256', $bytes) !== $patches[$path]['source_sha256']) {
                throw new RuntimeException('Legacy dependency source checksum mismatch: ' . $path);
            }
            foreach ($patches[$path]['replacements'] as $replacement) {
                if (substr_count($bytes, $replacement['before']) !== 1) {
                    throw new RuntimeException('Legacy dependency patch no longer applies: ' . $path);
                }
                $bytes = str_replace($replacement['before'], $replacement['after'], $bytes);
            }
            if (hash('sha256', $bytes) !== $digest) {
                throw new RuntimeException('Legacy dependency patched checksum mismatch: ' . $path);
            }
        } elseif (hash('sha256', $bytes) !== $digest) {
            throw new RuntimeException('Legacy dependency checksum mismatch: ' . $path);
        }
        $prepared[$path] = $bytes;
    }
    // Nothing is written until every selected archive entry has been verified.
    foreach ($prepared as $path => $bytes) {
        $target = $root . '/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        if (file_put_contents($target, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot install legacy dependency: ' . $path);
        }
    }
    echo "Pinned legacy compatibility dependencies installed and verified.\n";
} finally {
    unlink($archivePath);
}
