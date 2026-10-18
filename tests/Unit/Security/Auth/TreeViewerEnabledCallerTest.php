<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The tree picker hides disabled and missing trees. Code that shows a tree to
 * a viewer passes $enabled_only to is_tree_allowed() so a request by id is
 * denied the same way. Every production call is pinned by its arguments, so a
 * new caller has to decide whether it is a viewer or works on unpublished
 * trees.
 */

/** Arguments of every is_tree_allowed() call outside the tests, read from tokens so comments do not count. */
function tree_viewer_caller_arguments(): array
{
    $root = dirname(__DIR__, 4);
    $skip = '#^(tests|include/vendor|vendor|node_modules|cache|log|rra|\.git)/#';
    $calls = array();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);

        if ($file->getExtension() !== 'php' || preg_match($skip, $relative)) {
            continue;
        }

        $tokens = array_values(array_filter(
            token_get_all(file_get_contents($file->getPathname())),
            static fn($token) => !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)
        ));

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[1] !== 'is_tree_allowed' || ($tokens[$index + 1] ?? null) !== '(') {
                continue;
            }

            if (is_array($tokens[$index - 1] ?? null) && $tokens[$index - 1][0] === T_FUNCTION) {
                continue;
            }

            $depth = 0;
            $arguments = '';

            for ($i = $index + 1; isset($tokens[$i]); $i++) {
                $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

                if ($text === '(' && $depth++ === 0) {
                    continue;
                }

                if ($text === ')' && --$depth === 0) {
                    break;
                }

                $arguments .= $text . ($text === ',' ? ' ' : '');
            }

            $calls[$relative][] = $arguments;
        }
    }

    ksort($calls);

    return $calls;
}

test('viewer paths ask for an enabled tree', function () {
    expect(tree_viewer_caller_arguments())->toBe(array(
        'graph_view.php' => array('$tree_id, 0, true'),
        'lib/auth.php' => array('$h[\'tree_id\'], 0, true'),
        'lib/reports.php' => array('$item[\'tree_id\'], $report[\'user_id\'], true'),
    ));
});
