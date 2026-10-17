<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Tools;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Compare the locked Pest runner's explicit discovery with its recorded runs. */
final class PestInventory
{
    private static function xml(string $path): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->load($path, LIBXML_NONET) || $document->doctype !== null) {
                throw new RuntimeException('Invalid XML report: ' . $path);
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<string> Canonical selectors relative to the configuration. */
    public static function files(string $configuration): array
    {
        $root = realpath(dirname($configuration));
        $files = [];
        $xpath = new DOMXPath(self::xml($configuration));
        foreach ($xpath->query('/phpunit/testsuites/testsuite/file') as $entry) {
            $file = realpath($root . '/' . trim($entry->textContent));
            if ($file === false || !str_starts_with($file, $root . '/Unit/') || !is_file($file)) {
                throw new RuntimeException('Missing or unsupported registered file: ' . $entry->textContent);
            }
            $selector = substr($file, strlen($root) + 1);
            if (in_array($selector, $files, true)) {
                throw new RuntimeException('Duplicate registered file: ' . $selector);
            }
            $files[] = $selector;
        }
        if ($files === [] || $xpath->query('/phpunit/testsuites/testsuite/directory')->length !== 0) {
            throw new RuntimeException('Expected a nonempty explicit file inventory.');
        }

        return $files;
    }

    /**
     * Skipped cases must still be recorded. Across the supplied modes, each
     * registered file must also have at least one case that actually ran.
     *
     * @param list<string> $files
     * @param list<string> $reports
     * @return array<string, array{discovered: int, recorded: int, skipped: int}>
     */
    public static function verify(array $files, string $discovery, array $reports): array
    {
        $counts = array_fill_keys($files, ['discovered' => 0, 'recorded' => 0, 'skipped' => 0]);
        foreach (self::xml($discovery)->getElementsByTagName('testClass') as $class) {
            // Pest 4 emits its generated class under P\ followed by the test
            // path. The XML file attribute points at eval() in its factory.
            $name = $class->getAttribute('name');
            $file = str_starts_with($name, 'P\\') ? str_replace('\\', '/', substr($name, 2)) . '.php' : '';
            if (!isset($counts[$file]) || $counts[$file]['discovered'] !== 0) {
                throw new RuntimeException('Unexpected or duplicate discovered class: ' . $name);
            }
            $counts[$file]['discovered'] = $class->getElementsByTagName('testMethod')->length;
        }
        foreach ($counts as $file => $count) {
            if ($count['discovered'] === 0) {
                throw new RuntimeException('No discovered cases: ' . $file);
            }
        }
        if ($reports === []) {
            throw new RuntimeException('At least one JUnit report is required.');
        }
        foreach ($reports as $report) {
            $recorded = array_fill_keys($files, 0);
            foreach (self::xml($report)->getElementsByTagName('testcase') as $case) {
                $file = explode('::', $case->getAttribute('file'), 2)[0];
                if (!isset($counts[$file])) {
                    throw new RuntimeException('Unexpected JUnit case file: ' . $file);
                }
                if ($case->getElementsByTagName('failure')->length || $case->getElementsByTagName('error')->length) {
                    throw new RuntimeException('Failed JUnit case: ' . $file);
                }
                ++$recorded[$file];
                ++$counts[$file]['recorded'];
                $counts[$file]['skipped'] += $case->getElementsByTagName('skipped')->length ? 1 : 0;
            }
            foreach ($recorded as $file => $count) {
                if ($count !== $counts[$file]['discovered']) {
                    throw new RuntimeException(sprintf('%s: %s discovered %d cases, recorded %d', $report, $file, $counts[$file]['discovered'], $count));
                }
            }
        }
        foreach ($counts as $file => $count) {
            if ($count['recorded'] === $count['skipped']) {
                throw new RuntimeException('No executed cases in any supplied mode: ' . $file);
            }
        }

        return $counts;
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        if (count($arguments) < 3) {
            throw new RuntimeException('Usage: verify_pest_inventory.php CONFIGURATION JUNIT [JUNIT ...]');
        }
        $configuration = realpath($arguments[1]);
        if ($configuration === false) {
            throw new RuntimeException('Configuration not found.');
        }
        $files = self::files($configuration);
        $discovery = tempnam(sys_get_temp_dir(), 'kadupul-pest-discovery-');
        if ($discovery === false) {
            throw new RuntimeException('Cannot create discovery report.');
        }
        try {
            $process = new Process([PHP_BINARY, 'vendor/bin/pest', '--test-directory=.', '--configuration=' . $configuration, '--list-tests-xml=' . $discovery, ...$files], dirname($configuration));
            $process->setTimeout(60);
            $process->mustRun();
            $counts = self::verify($files, $discovery, array_slice($arguments, 2));
            foreach ($counts as $file => $count) {
                printf("%s: discovered=%d recorded=%d skipped=%d\n", $file, $count['discovered'], $count['recorded'], $count['skipped']);
            }
            printf("PASS: %d registered files match explicit Pest discovery in %d JUnit report(s).\n", count($counts), count($arguments) - 2);

            return 0;
        } finally {
            unlink($discovery);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
    try {
        exit(PestInventory::main($argv));
    } catch (\Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
