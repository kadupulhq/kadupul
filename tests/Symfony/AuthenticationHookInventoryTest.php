<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins every plugin hook the login, logout, password-change and profile flows
 * fire, and the inventory that lists them. ADR 0010 forbids adding, renaming or
 * reordering one while those flows move to Symfony Security, so a new call site
 * here must be a deliberate change to this list and to the inventory.
 */
final class AuthenticationHookInventoryTest extends TestCase
{
    private const FUNCTION = 'api_plugin_hook_function';
    private const ACTION = 'api_plugin_hook';

    /** Files read in full: any hook call in them belongs to the authentication surface. */
    private const AUTH_FILES = [
        'auth_login.php' => [
            'custom_login' => self::FUNCTION,
            'login_title' => self::FUNCTION,
            'login_before' => self::FUNCTION,
            'login_realms' => self::FUNCTION,
            'login_after' => self::ACTION,
        ],
        'login.php' => [],
        'logout.php' => [
            'logout_pre_session_destroy' => self::ACTION,
            'logout_post_session_destroy' => self::ACTION,
            'custom_logout_message' => self::FUNCTION,
        ],
        'auth_changepassword.php' => [
            'custom_password' => self::FUNCTION,
            'change_password_title' => self::FUNCTION,
        ],
        'auth_profile.php' => [
            'auth_profile_update_data' => self::FUNCTION,
            'auth_profile_tabs' => self::FUNCTION,
            'auth_profile_run_action' => self::FUNCTION,
            'auth_profile_reset' => self::ACTION,
            'auth_profile_reset_value' => self::FUNCTION,
            'auth_profile_save' => self::ACTION,
        ],
        'include/auth.php' => [
            'auth_alternate_realms' => self::FUNCTION,
            'custom_denied' => self::FUNCTION,
        ],
        'include/session.php' => [],
        'lib/ldap.php' => [],
    ];

    /** lib/auth.php also holds the permission code, so only the login-related hooks are pinned. */
    private const LIB_AUTH = [
        'copy_user' => self::FUNCTION,
        'user_remove' => self::FUNCTION,
        'login_process' => self::FUNCTION,
        'login_options_navigate' => self::FUNCTION,
    ];

    /** Hooks the login flow reaches through shared helpers. */
    private const HELPER_HOOKS = [
        'lib/functions.php' => [
            'get_template_account' => self::FUNCTION,
            'nav_login_before' => self::ACTION,
            'nav_login_after' => self::ACTION,
        ],
    ];

    public function testAuthenticationFilesFireOnlyTheListedHooks(): void
    {
        foreach (self::AUTH_FILES as $file => $expected) {
            self::assertSame($this->sorted($expected), $this->sorted($this->hooks($file)), $file);
        }
    }

    public function testLibAuthFiresTheListedLoginHooks(): void
    {
        $found = $this->hooks('lib/auth.php');
        foreach (self::LIB_AUTH as $name => $kind) {
            self::assertSame($kind, $found[$name] ?? null, 'lib/auth.php ' . $name);
        }
        self::assertSame([], array_diff_key($found, self::LIB_AUTH), 'lib/auth.php fires a hook this list does not know');
    }

    public function testHelpersStillFireTheListedHooks(): void
    {
        foreach (self::HELPER_HOOKS as $file => $expected) {
            $found = $this->hooks($file);
            foreach ($expected as $name => $kind) {
                self::assertSame($kind, $found[$name] ?? null, $file . ' ' . $name);
            }
        }
    }

    public function testInventoryListsEveryPinnedHook(): void
    {
        $document = file_get_contents(dirname(__DIR__, 2) . '/docs/migrations/authentication-inventory.md');
        self::assertIsString($document);
        $names = array_merge(
            ...array_values(array_map('array_keys', self::AUTH_FILES)),
            ...[array_keys(self::LIB_AUTH)],
            ...array_values(array_map('array_keys', self::HELPER_HOOKS)),
        );
        self::assertCount(count(array_unique($names)), $names, 'a hook is pinned under two files');
        foreach ($names as $name) {
            self::assertMatchesRegularExpression('/^\| `' . preg_quote($name, '/') . '` \| (fn|action) \|/m', $document, $name);
        }
    }

    /** @return array<string, string> hook name => the function that fires it */
    private function hooks(string $file): array
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        self::assertIsString($source, $file);
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $found = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], [self::FUNCTION, self::ACTION], true)) {
                continue;
            }
            $name = $tokens[$index + 2] ?? null;
            if (($tokens[$index + 1] ?? null) === '(' && is_array($name) && $name[0] === T_CONSTANT_ENCAPSED_STRING) {
                $found[trim($name[1], '\'"')] = $token[1];
            }
        }

        return $found;
    }

    /**
     * @param array<string, string> $hooks
     * @return array<string, string>
     */
    private function sorted(array $hooks): array
    {
        ksort($hooks);

        return $hooks;
    }
}
