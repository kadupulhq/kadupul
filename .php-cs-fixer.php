<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * main follows PER-CS 2.0 for the PHP files a change edits. CI runs this
 * config through tests/tools/check_php_style.sh on changed files only, so
 * files nobody has touched keep their Cacti formatting until they are edited.
 * lts/1.2 has no fixer config and keeps upstream formatting.
 *
 * A reformat goes in its own commit and must leave the token stream unchanged
 * apart from whitespace. That keeps the commit reviewable as formatting alone.
 * The rules switched off below would add, remove, reorder or re-case tokens,
 * or rewrite line endings inside strings and heredocs (line_ending). Make
 * those modernizations, such as short array syntax or trailing commas, in
 * separate commits.
 *
 * single_quote and no_unused_imports are not part of @PER-CS2x0 in fixer
 * 3.95.25. They are pinned off so a later revision of the set cannot start
 * rewriting string delimiters or deleting imports in a formatting commit.
 *
 * visibility_required is the deprecated name of modifier_keywords in 3.95.25
 * and is not in the resolved set either. It is pinned off so that nothing
 * resolving the old name can add visibility keywords.
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude(['include/vendor', 'tests/Fixtures']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2x0' => true,
        'array_syntax' => false,
        'constant_case' => false,
        'control_structure_braces' => false,
        'elseif' => false,
        'encoding' => false,
        'full_opening_tag' => false,
        'line_ending' => false,
        'lowercase_cast' => false,
        'lowercase_keywords' => false,
        'lowercase_static_reference' => false,
        'modifier_keywords' => false,
        'new_with_parentheses' => false,
        'no_break_comment' => false,
        'no_closing_tag' => false,
        'no_leading_import_slash' => false,
        'no_unused_imports' => false,
        'ordered_class_elements' => false,
        'ordered_imports' => false,
        'short_scalar_cast' => false,
        'single_class_element_per_statement' => false,
        'single_import_per_statement' => false,
        'single_quote' => false,
        'single_trait_insert_per_statement' => false,
        'switch_case_semicolon_to_colon' => false,
        'trailing_comma_in_multiline' => false,
        'visibility_required' => false,
    ])
    ->setFinder($finder);
