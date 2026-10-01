<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Records what draw_edit_form() and the pages that call it print today, so a
// new renderer can prove it prints the same thing. The goldens in
// tests/Golden/forms include output that looks wrong; rewrite one only for
// an intended change: run tests/bin/forms-golden and review the diff.

namespace Kadupul\Tests\Forms;

use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class LegacyFormGoldenTest extends TestCase
{
    /** @dataProvider methods */
    public function testFieldMethodMatchesGolden(string $name, array $scenario): void
    {
        $this->assertGolden('methods/' . $name, $scenario);
    }

    /** @dataProvider pages */
    public function testPageMatchesGoldenAndKeepsScriptTargets(string $name, array $scenario): void
    {
        $observed = $this->assertGolden('pages/' . $name, $scenario, true);
        $golden = json_decode(file_get_contents(self::goldenPath('pages/' . $name . '.json')), true, 512, JSON_THROW_ON_ERROR);

        // The page's own script finds these elements by id or name. A renderer
        // that changes markup must keep every one of them.
        foreach ($golden['script_ids'] as $id) {
            self::assertContains($id, $observed['ids'], $name . ' script targets #' . $id);
        }
        foreach ($golden['script_names'] as $field) {
            self::assertContains($field, $observed['names'], $name . ' script targets [name=' . $field . ']');
        }
    }

    public function testEveryFieldMethodOnMainHasAGolden(): void
    {
        $covered = array();
        foreach (self::scenarios()['methods'] as $scenario) {
            foreach ($scenario['form']['fields'] as $field) {
                $covered[$field['method']] = true;
            }
        }

        // Field arrays live in the pages and in include/ and lib/.
        $root = dirname(__DIR__, 3);
        foreach (array_merge(glob($root . '/*.php'), glob($root . '/include/*.php'), glob($root . '/lib/*.php')) as $file) {
            preg_match_all("/['\"]method['\"]\\s*=>\\s*['\"]([a-z_]+)['\"]/", file_get_contents($file), $matches);
            foreach ($matches[1] as $method) {
                self::assertArrayHasKey($method, $covered, basename($file) . " uses field method '" . $method . "' with no golden scenario");
            }
        }
    }

    public static function methods(): array
    {
        return self::cases('methods');
    }

    public static function pages(): array
    {
        return self::cases('pages');
    }

    private static function cases(string $kind): array
    {
        $cases = array();
        foreach (self::scenarios()[$kind] as $name => $scenario) {
            $cases[$name] = array($name, $scenario);
        }

        return $cases;
    }

    private static function scenarios(): array
    {
        static $scenarios = null;

        return $scenarios ??= require dirname(__DIR__, 2) . '/Fixtures/legacy-form-golden-scenarios.php';
    }

    private static function goldenPath(string $name): string
    {
        return dirname(__DIR__, 2) . '/Golden/forms/' . $name;
    }

    /**
     * Compare the printed markup and what it means to the browser with the
     * goldens. For a page, the markup golden holds only its forms, which
     * contain everything draw_edit_form() prints; the rest of the page is
     * covered by the element inventory and the script targets.
     */
    private function assertGolden(string $name, array $scenario, bool $forms_only = false): array
    {
        $result = $this->render($scenario);
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $result['html'] . '</body></html>', LIBXML_NOERROR);
        $observed = array(
            'session' => $result['session'],
            'diagnostics' => $result['diagnostics'],
            'log' => $result['log'],
        ) + self::inventory($document);
        $recorded = array_diff_key($observed, array('ids' => true, 'names' => true));
        $markup = $result['html'];
        if ($forms_only) {
            $markup = '';
            foreach ($document->getElementsByTagName('form') as $form) {
                $markup .= $document->saveHtml($form) . "\n";
            }
        }
        $files = array(
            $name . '.html' => $markup,
            $name . '.json' => json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        );
        foreach ($files as $file => $contents) {
            $path = self::goldenPath($file);
            if (getenv('FORMS_GOLDEN_UPDATE') === '1') {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
                file_put_contents($path, $contents);
            }
            self::assertFileExists($path);
            self::assertSame(file_get_contents($path), $contents, 'Golden ' . $file);
        }

        return $observed;
    }

    /**
     * The elements a script or a form submission can address, as the HTML5
     * parser builds them, and the ids and names the page's scripts select
     * that exist in the markup.
     */
    private static function inventory(HTMLDocument $document): array
    {
        $elements = array();
        $ids = array();
        $names = array();
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element->hasAttribute('id') && !$element->hasAttribute('name')) {
                continue;
            }
            $entry = strtolower($element->tagName);
            foreach (array('type', 'id', 'name') as $attribute) {
                if ($element->hasAttribute($attribute)) {
                    $entry .= ' ' . $attribute . '=' . json_encode($element->getAttribute($attribute), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
            $elements[] = $entry;
            if ($element->hasAttribute('id')) {
                $ids[$element->getAttribute('id')] = true;
            }
            if ($element->hasAttribute('name')) {
                $names[$element->getAttribute('name')] = true;
            }
        }

        $script_ids = array();
        $script_names = array();
        foreach ($document->querySelectorAll('script') as $script) {
            preg_match_all('/#([A-Za-z_][\w\-]*)|getElementById\(\s*[\'"]([^\'"]+)[\'"]/', $script->textContent, $matches);
            foreach (array_merge($matches[1], $matches[2]) as $id) {
                if ($id !== '' && isset($ids[$id])) {
                    $script_ids[$id] = true;
                }
            }
            preg_match_all('/\[name\s*[\^$*]?=\s*[\'"]?([^\'"\]]+)[\'"]?\]/', $script->textContent, $matches);
            foreach ($matches[1] as $field) {
                if (isset($names[$field])) {
                    $script_names[$field] = true;
                }
            }
        }
        ksort($script_ids);
        ksort($script_names);

        return array('elements' => $elements, 'ids' => array_keys($ids), 'names' => array_keys($names), 'script_ids' => array_keys($script_ids), 'script_names' => array_keys($script_names));
    }

    private function render(array $scenario): array
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/legacy-form-golden-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        try {
            // Settings the output depends on are pinned rather than read from
            // the local php.ini.
            $command = array(
                PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'auto_prepend_file=',
                '-d', 'date.timezone=UTC', '-d', 'session.gc_maxlifetime=1440', '-d', 'upload_max_filesize=2M',
                '-d', 'post_max_size=8M', '-d', 'memory_limit=256M', '-d', 'max_execution_time=0', '-d', 'default_charset=UTF-8',
                $root . '/tests/Fixtures/legacy-form-golden.php', $root, $directory,
            );
            $process = proc_open($command, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $directory . '/stderr', 'w')), $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            $error = file_get_contents($directory . '/stderr');
            self::assertSame(0, $status, $error . $output);
            self::assertSame('', $error);

            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }
}
