<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

/** Keep serialized child coverage on the parent's actual installed library. */
final class NativeCoverageStack
{
    private const array VENDORS = ['application' => 'include/vendor', 'tests' => 'tests/vendor'];

    /** @return array{stack:string,version:string,class_sha256:string} */
    public static function capture(string $root): array
    {
        $active = (new \ReflectionClass(\SebastianBergmann\CodeCoverage\CodeCoverage::class))->getFileName();
        $active = is_string($active) ? realpath($active) : false;
        foreach (self::VENDORS as $stack => $vendor) {
            $candidate = realpath($root . '/' . $vendor . '/phpunit/php-code-coverage/src/CodeCoverage.php');
            if ($active !== false && $candidate !== false && $active === $candidate) return self::describe($root, $stack);
        }
        throw new \RuntimeException('The active coverage library is outside the installed test stacks.');
    }

    /**
     * Only the two fixed vendor roots are selectable. No path from the caller
     * is ever required as PHP, even for an invalid handoff.
     * @param array<string,mixed> $expected
     */
    public static function bootstrap(string $root, array $expected): string
    {
        $stack = $expected['stack'] ?? null;
        if (!is_string($stack) || !array_key_exists($stack, self::VENDORS) || self::describe($root, $stack) !== $expected) {
            throw new \RuntimeException('The requested coverage stack is missing or stale.');
        }
        if (class_exists(\SebastianBergmann\CodeCoverage\CodeCoverage::class, false)) self::verify($root, $expected);
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false) throw new \RuntimeException('The coverage project root is unavailable.');
        $autoload = realpath($canonicalRoot . '/' . self::VENDORS[$stack] . '/autoload.php');
        if ($autoload === false) throw new \RuntimeException('The coverage autoloader is unavailable.');
        require_once $autoload;
        self::verify($root, $expected);
        return dirname($autoload);
    }

    /** @param array<string,mixed> $expected */
    public static function verify(string $root, array $expected): void
    {
        if (self::capture($root) !== $expected) throw new \RuntimeException('The coverage stack does not match its parent.');
    }

    /** Inspect an installed stack without loading it alongside the active one.
     * @return array{stack:string,version:string,class_sha256:string}
     */
    public static function installed(string $root, string $stack): array
    {
        return self::describe($root, $stack);
    }

    /** @return array{stack:string,version:string,class_sha256:string} */
    private static function describe(string $root, string $stack): array
    {
        $canonicalRoot = realpath($root);
        $vendor = self::VENDORS[$stack] ?? null;
        if ($canonicalRoot === false || $vendor === null) throw new \RuntimeException('Unknown coverage stack.');
        $class = realpath($canonicalRoot . '/' . $vendor . '/phpunit/php-code-coverage/src/CodeCoverage.php');
        $metadata = $canonicalRoot . '/' . $vendor . '/composer/installed.json';
        $autoload = realpath($canonicalRoot . '/' . $vendor . '/autoload.php');
        if ($class === false || $autoload === false || !str_starts_with($class, $canonicalRoot . DIRECTORY_SEPARATOR)
            || !str_starts_with($autoload, $canonicalRoot . DIRECTORY_SEPARATOR) || !is_file($metadata)) {
            throw new \RuntimeException('The requested coverage library is unavailable.');
        }
        $raw = file_get_contents($metadata);
        if ($raw === false) throw new \RuntimeException('Coverage package metadata is unavailable.');
        $installed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($installed) || !is_array($installed['packages'] ?? null)) throw new \RuntimeException('Invalid coverage package metadata.');
        $versions = [];
        foreach ($installed['packages'] as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'phpunit/php-code-coverage') $versions[] = $package['version_normalized'] ?? null;
        }
        $hash = hash_file('sha256', $class);
        if (count($versions) !== 1 || !is_string($versions[0]) || $versions[0] === '' || $hash === false) {
            throw new \RuntimeException('Coverage package identity is unavailable.');
        }
        return ['stack' => $stack, 'version' => $versions[0], 'class_sha256' => $hash];
    }
}
