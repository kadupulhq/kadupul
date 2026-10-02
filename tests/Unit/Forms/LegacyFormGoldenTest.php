<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Records what draw_edit_form() and the pages that call it print today, so a
// new renderer can prove it prints the same thing. The goldens in
// tests/Golden/forms include output that looks wrong; rewrite one only for
// an intended change: run tests/bin/forms-golden and review the diff.
//
// Markup is compared as the HTML5 parser builds it: attribute order, quote
// style and whitespace between elements do not count; text, attribute
// values, element order and nesting do. A renderer that differs on purpose
// declares each difference in tests/Golden/forms/allowlist.json:
//   {"pages/settings-general.html": [{"reason": "...", "golden": "...", "rendered": "..."}]}
// where "golden" is text in the golden file and "rendered" replaces it.

namespace Kadupul\Tests\Forms;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use PHPUnit\Framework\TestCase;

final class LegacyFormGoldenTest extends TestCase
{
    // Each of these starts a line in a stored golden; everything else stays
    // on its parent's line. A div with an id is a form row or a box.
    private const BLOCKS = array('form', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'ul', 'ol', 'li', 'select', 'option', 'optgroup', 'textarea', 'script', 'style');
    private const VOID = array('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr');
    private const RAW = array('script', 'style');

    /** @dataProvider methods */
    public function testFieldMethodMatchesGolden(string $name, array $scenario): void
    {
        $this->assertGolden('methods/' . $name, $scenario, false);
    }

    /** @dataProvider pages */
    public function testPageMatchesGoldenAndKeepsScriptTargets(string $name, array $scenario): void
    {
        $this->assertGolden('pages/' . $name, $scenario, true);
    }

    public function testEveryFieldMethodOnMainHasAGolden(): void
    {
        $covered = array();
        foreach (self::scenarios()['methods'] as $scenario) {
            foreach ($scenario['form']['fields'] as $field) {
                $covered[$field['method']] = true;
            }
        }

        // Field arrays live in the pages and in include/, lib/ and install/.
        $root = dirname(__DIR__, 3);
        foreach (array_merge(glob($root . '/*.php'), glob($root . '/include/*.php'), glob($root . '/lib/*.php'), glob($root . '/install/*.php')) as $file) {
            preg_match_all("/['\"]method['\"]\\s*=>\\s*['\"]([a-z_]+)['\"]/", file_get_contents($file), $matches);
            foreach ($matches[1] as $method) {
                self::assertArrayHasKey($method, $covered, basename($file) . " uses field method '" . $method . "' with no golden scenario");
            }
        }
    }

    public function testEveryGoldenHasAScenario(): void
    {
        $scenarios = self::scenarios();
        foreach (array('methods', 'pages') as $kind) {
            foreach (glob(self::goldenPath($kind . '/*')) as $file) {
                self::assertArrayHasKey(pathinfo($file, PATHINFO_FILENAME), $scenarios[$kind], $kind . '/' . basename($file) . ' has no scenario');
            }
        }
    }

    public function testAllowlistEntriesGiveAReason(): void
    {
        self::assertIsArray(self::allowlist());
        foreach (self::allowlist() as $file => $entries) {
            self::assertFileExists(self::goldenPath($file), 'allowlist names a golden that does not exist');
            foreach ($entries as $entry) {
                self::assertNotSame('', trim($entry['reason'] ?? ''), $file . ': allowlist entry without a reason');
                self::assertIsString($entry['golden'] ?? null, $file . ': allowlist entry without golden text');
                self::assertIsString($entry['rendered'] ?? null, $file . ': allowlist entry without rendered text');
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

    private static function allowlist(): array
    {
        return json_decode(file_get_contents(self::goldenPath('allowlist.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function goldenPath(string $name): string
    {
        return dirname(__DIR__, 2) . '/Golden/forms/' . $name;
    }

    /**
     * For a method, the markup golden holds everything draw_edit_form()
     * printed. For a page it holds the page's forms, which contain everything
     * draw_edit_form() prints; the JSON golden lists the elements outside them
     * and what the page's scripts select.
     */
    private function assertGolden(string $name, array $scenario, bool $page): void
    {
        $result = $this->render($scenario);
        $document = self::parse($result['html']);
        $markup = $page ? self::serialize(iterator_to_array($document->getElementsByTagName('form'))) : self::serialize(iterator_to_array($document->body->childNodes));
        $recorded = array('session' => $result['session'], 'diagnostics' => $result['diagnostics'], 'log' => $result['log']);
        if ($page) {
            $recorded += self::targets($document);
        }
        $files = array(
            $name . '.html' => $markup,
            $name . '.json' => json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        );

        // Before anything else: the ids and names that existed, and the ones
        // the page's scripts select, must still exist.
        if (is_file(self::goldenPath($name . '.json'))) {
            $before = self::inventory(file_get_contents(self::goldenPath($name . '.html')), json_decode(file_get_contents(self::goldenPath($name . '.json')), true, 512, JSON_THROW_ON_ERROR));
            $after = self::inventory($markup, $recorded);
            $lost = array();
            foreach (array('script_ids' => 'page script selects #%s', 'script_names' => 'page script selects [name=%s]', 'ids' => 'id %s', 'names' => 'name %s') as $key => $label) {
                foreach (array_diff($before[$key], $key === 'script_ids' ? $after['ids'] : ($key === 'script_names' ? $after['names'] : $after[$key])) as $gone) {
                    $lost[] = sprintf($label, $gone);
                }
            }
            if ($lost && getenv('FORMS_GOLDEN_ALLOW_REMOVAL') !== '1') {
                self::fail($name . (getenv('FORMS_GOLDEN_UPDATE') === '1' ? ': refusing to update; the render no longer has: ' : ': the render no longer has: ')
                    . implode(', ', array_unique($lost)) . '. Set FORMS_GOLDEN_ALLOW_REMOVAL=1 with FORMS_GOLDEN_UPDATE=1 if that is intended.');
            }
        }

        $allowlist = self::allowlist();
        foreach ($files as $file => $contents) {
            $path = self::goldenPath($file);
            if (getenv('FORMS_GOLDEN_UPDATE') === '1') {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
                file_put_contents($path, $contents);
            }
            self::assertFileExists($path);
            $golden = file_get_contents($path);
            foreach ($allowlist[$file] ?? array() as $entry) {
                self::assertStringContainsString($entry['golden'], $golden, $file . ': allowlist entry no longer matches the golden (' . $entry['reason'] . ')');
                $golden = str_replace($entry['golden'], $entry['rendered'], $golden);
            }
            self::assertSame($golden, $contents, 'Golden ' . $file);
        }
    }

    private static function parse(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    }

    /**
     * The elements outside the stored forms that have an id or a name, and
     * the ids and names the page's scripts select that exist on the page.
     */
    private static function targets(HTMLDocument $document): array
    {
        $outside = array();
        $ids = array();
        $names = array();
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute('id')) {
                $ids[$element->getAttribute('id')] = true;
            }
            if ($element->hasAttribute('name')) {
                $names[$element->getAttribute('name')] = true;
            }
            if (($element->hasAttribute('id') || $element->hasAttribute('name')) && $element->closest('form') === null) {
                $outside[] = self::describe($element);
            }
        }

        $script_ids = array();
        $script_names = array();
        foreach ($document->getElementsByTagName('script') as $script) {
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

        return array('elements_outside_forms' => $outside, 'script_ids' => array_keys($script_ids), 'script_names' => array_keys($script_names));
    }

    /** Every id and name in a golden pair, and the script targets it records. */
    private static function inventory(string $markup, array $recorded): array
    {
        $ids = array();
        $names = array();
        foreach (self::parse($markup)->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute('id')) {
                $ids[] = $element->getAttribute('id');
            }
            if ($element->hasAttribute('name')) {
                $names[] = $element->getAttribute('name');
            }
        }
        foreach ($recorded['elements_outside_forms'] ?? array() as $entry) {
            if (preg_match('/ id=("(?:[^"\\\\]|\\\\.)*")/', $entry, $match)) {
                $ids[] = json_decode($match[1]);
            }
            if (preg_match('/ name=("(?:[^"\\\\]|\\\\.)*")/', $entry, $match)) {
                $names[] = json_decode($match[1]);
            }
        }

        return array(
            'ids' => array_values(array_unique($ids)),
            'names' => array_values(array_unique($names)),
            'script_ids' => $recorded['script_ids'] ?? array(),
            'script_names' => $recorded['script_names'] ?? array(),
        );
    }

    private static function describe(Element $element): string
    {
        $entry = strtolower($element->localName);
        foreach (array('type', 'id', 'name') as $attribute) {
            if ($element->hasAttribute($attribute)) {
                $entry .= ' ' . $attribute . '=' . json_encode($element->getAttribute($attribute), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $entry;
    }

    /**
     * Write nodes the same way whatever the source looked like: attributes
     * sorted and double-quoted, whitespace runs in text collapsed, and each
     * form row, table row, select, option and script on its own line.
     */
    private static function serialize(array $nodes): string
    {
        $out = '';
        $break = false;
        foreach ($nodes as $node) {
            self::write($node, 0, $out, $break);
            $break = true;
        }

        return $out === '' ? '' : $out . "\n";
    }

    /** Append $node; returns whether it started a line of its own. */
    private static function write(Node $node, int $depth, string &$out, bool &$break): bool
    {
        if ($node instanceof Text) {
            $parent = strtolower($node->parentNode->localName ?? '');
            $text = $node->data;
            if (in_array($parent, self::RAW, true)) {
                $out .= $text;

                return false;
            }
            if ($parent !== 'textarea' && $parent !== 'pre') {
                if (trim($text) === '') {
                    return false;
                }
                $text = preg_replace('/\s+/', ' ', $text);
            }
            if ($break) {
                $out .= "\n" . str_repeat('  ', $depth);
                $break = false;
            }
            $out .= htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5);

            return false;
        }
        if (!$node instanceof Element) {
            return false;
        }

        $tag = strtolower($node->localName);
        $block = in_array($tag, self::BLOCKS, true) || ($tag === 'div' && $node->hasAttribute('id'));
        if ($block || $break) {
            $out .= ($out === '' ? '' : "\n") . str_repeat('  ', $depth);
            $break = false;
        }
        $attributes = array();
        foreach ($node->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }
        ksort($attributes, SORT_STRING);
        $out .= '<' . $tag;
        foreach ($attributes as $attribute => $value) {
            $out .= ' ' . $attribute . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5) . '"';
        }
        $out .= '>';
        if (in_array($tag, self::VOID, true)) {
            $break = $block;

            return $block;
        }

        $nested = false;
        foreach ($node->childNodes as $child) {
            $nested = self::write($child, $depth + ($block ? 1 : 0), $out, $break) || $nested;
        }
        if ($nested) {
            $out .= "\n" . str_repeat('  ', $depth);
        }
        $out .= '</' . $tag . '>';
        $break = $block;

        return $block || $nested;
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
