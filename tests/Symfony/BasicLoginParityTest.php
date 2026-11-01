<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Kadupul\Tests\Fixtures\LegacyLoginParity;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/LegacyLoginParity.php';

/**
 * The ADR 0010 parity matrix for web server Basic authentication (auth_method
 * 2), pinned against the legacy include/auth.php, auth_login.php and lib/auth.php
 * as they are today, over HTTP and against a real MariaDB like
 * LocalLoginParityTest. The router stands in for the web server's
 * authentication module and sets REMOTE_USER or REDIRECT_REMOTE_USER on request.
 *
 * tests/Unit/Security/Auth/BasicAuthAuthorizationTest covers the same code with
 * a SQLite stub and stubbed logging: which server variables
 * get_basic_auth_username() reads, and the middleware's event order. This class
 * adds what that stub cannot show: the responses, user_log rows, AUTH lines,
 * session keys, created accounts and the client headers PHP turns into
 * PHP_AUTH_USER.
 *
 * Several pinned results contradict ADR 0010 section 8 or look wrong; their test
 * names say so. They are recorded, not endorsed, so that the Symfony
 * authenticator changes them on purpose or not at all.
 */
final class BasicLoginParityTest extends TestCase
{
    use LegacyLoginParity;

    private const string USER = 'basic-user';
    private const int USER_ID = 10;
    private const string OTHER = 'basic-other';
    private const int OTHER_ID = 12;
    private const int GUEST_ID = 3;
    private const int TEMPLATE_ID = 4;
    // An unset password: the generation of a Basic account with no local password.
    private const string EMPTY_PASSWORD_KEY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    protected function setUp(): void
    {
        $this->bootLegacyLogin('basic_login_parity', ['settings', 'settings_user', 'settings_tree', 'user_auth', 'user_auth_cache', 'user_auth_perms', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_perms', 'user_auth_group_realm', 'user_log'], [
            'auth_method' => '2',
            'auth_cache_enabled' => 'on',
            'guest_user' => '0',
            'user_template' => '0',
            'secpass_lockfailed' => '3',
            'secpass_unlocktime' => '15',
            'log_destination' => '1',
            'log_verbosity' => '2',
            'ldap_tls_certificate' => '2',
        ], ['KADUPUL_PARITY_SERVER_IDENTITY' => '1']);
        // include/auth.php includes it at the end of the permission-denied page.
        file_put_contents($this->directory . '/include-path/global_session.php', "<?php\n");

        foreach ([[self::USER_ID, self::USER], [self::OTHER_ID, self::OTHER]] as [$id, $username]) {
            $this->database->insert('user_auth', ['id' => $id, 'username' => $username, 'password' => '', 'realm' => 2, 'login_opts' => 3, 'enabled' => 'on']);
            $this->database->insert('user_auth_realm', ['user_id' => $id, 'realm_id' => 7]);
        }
        // The guest and template rows cacti.sql ships, disabled as shipped.
        $this->database->insert('user_auth', ['id' => self::GUEST_ID, 'username' => 'guest', 'password' => '', 'realm' => 0, 'login_opts' => 3, 'enabled' => '']);
        $this->database->insert('user_auth_realm', ['user_id' => self::GUEST_ID, 'realm_id' => 7]);
        $this->database->insert('user_auth', ['id' => self::TEMPLATE_ID, 'username' => 'template-user', 'password' => '', 'realm' => 0, 'login_opts' => 3, 'enabled' => '']);
        $this->database->insert('user_auth_realm', ['user_id' => self::TEMPLATE_ID, 'realm_id' => 7]);
    }

    protected function tearDown(): void
    {
        $this->tearDownLegacyLogin();
    }

    /**
     * @param array<string, string> $server  REMOTE_USER and REDIRECT_REMOTE_USER as the web server would set them
     * @param array<string, string> $cookies
     * @param list<string>          $headers client request headers
     * @param array<string, string> $form
     * @return array{status: int, location: ?string, cookies: array<string, string>, body: string, session: array<string, mixed>, elapsed_ms: int, php_errors: list<string>}
     */
    private function basic(string $path, array $server, array $cookies = [], array $headers = [], array $form = [], bool $phpErrorsExpected = false): array
    {
        if ($server !== []) {
            $headers[] = 'X-Kadupul-Test-Server-Identity: ' . base64_encode(json_encode($server, JSON_THROW_ON_ERROR));
        }

        return $this->request($path, $form, $cookies, $headers, $phpErrorsExpected);
    }

    /** @return array{status: int, location: ?string, cookies: array<string, string>, body: string, session: array<string, mixed>, elapsed_ms: int, php_errors: list<string>} */
    private function gated(string $remoteUser, array $cookies = []): array
    {
        return $this->basic('/gated.php', ['REMOTE_USER' => $remoteUser], $cookies);
    }

    /** @return array{failed_attempts: int, locked: string, enabled: string} */
    private function account(int $id = self::USER_ID): array
    {
        $row = $this->database->fetchAssociative('SELECT failed_attempts, locked, enabled FROM user_auth WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return ['failed_attempts' => (int) $row['failed_attempts'], 'locked' => (string) $row['locked'], 'enabled' => (string) $row['enabled']];
    }

    /** @return list<array{id: int, username: string, realm: int}> */
    private function accounts(): array
    {
        return array_map(
            static fn(array $row): array => ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'realm' => (int) $row['realm']],
            $this->database->fetchAllAssociative('SELECT id, username, realm FROM user_auth ORDER BY id'),
        );
    }

    /** The full-page error auth_display_custom_error_message() prints; it is not the login form. */
    private function failurePage(string $body): string
    {
        self::assertStringContainsString('<title>Kadupul Login Failure</title>', $body);
        self::assertSame(1, preg_match('#<div class="ui-state-error ui-corner-all"[^>]*><p>(.*?)</p><p>(.*?)</p></div>#s', $body, $match), 'The response is not the login failure page.');
        self::assertSame('', $match[2], 'basic_auth_fail_message is unset, so the second paragraph is empty.');
        self::assertStringNotContainsString('Login Again', $body, 'Method 2 offers no way back to a login form.');

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    /** @param array{cookies: array<string, string>, session: array<string, mixed>} $response */
    private function assertCookiesCleared(array $response): void
    {
        self::assertSame(['Cacti' => 'deleted', 'cacti_remembers' => 'deleted'], $response['cookies']);
        self::assertSame([], $response['session']);
    }

    /** @param array{status: int, location: ?string, cookies: array<string, string>, body: string, session: array<string, mixed>} $response */
    private function assertSignedIn(array $response, int $userId = self::USER_ID, string $credential = self::EMPTY_PASSWORD_KEY): void
    {
        self::assertSame(200, $response['status']);
        self::assertNull($response['location']);
        self::assertSame('GATED:' . $userId, $response['body']);
        self::assertSame(['sess_user_id' => $userId, 'sess_user_credential' => $credential], $response['session']);
        self::assertArrayHasKey('Cacti', $response['cookies']);
        self::assertNotSame('deleted', $response['cookies']['Cacti']);
        self::assertArrayNotHasKey('cacti_remembers', $response['cookies'], 'Basic never issues a remembered login.');
    }

    private function basicLine(string $username): string
    {
        return "LOGIN: User '" . $username . "' authenticated via Basic Authentication from IP Address '127.0.0.1'";
    }

    /** Store a token the way set_auth_cookie() does; the cookie's realm field names the account's realm, 2. */
    private function seedToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->database->executeStatement('INSERT INTO user_auth_cache (user_id, hostname, last_update, token) VALUES (?, ?, NOW(), ?)', [$userId, '127.0.0.1', hash('sha512', $token)]);

        return $userId . ',2,' . $token;
    }

    // ---- Server identity ----

    public function testRemoteUserForAnEnabledBasicAccountSignsInAndBindsTheSessionToTheCredential(): void
    {
        $response = $this->gated(self::USER);

        $this->assertSignedIn($response);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([$this->basicLine(self::USER)], $this->authLog());
        self::assertSame(['failed_attempts' => 0, 'locked' => '', 'enabled' => 'on'], $this->account());
    }

    public function testSessionIsBoundToTheStoredCredentialGeneration(): void
    {
        $generation = bin2hex(random_bytes(32));
        $this->database->insert('settings_user', ['user_id' => self::USER_ID, 'name' => 'auth_credential_generation', 'value' => self::EMPTY_PASSWORD_KEY . ':' . $generation]);

        $this->assertSignedIn($this->gated(self::USER), self::USER_ID, $generation);
    }

    public function testRedirectRemoteUserSignsInTheSameWay(): void
    {
        $response = $this->basic('/gated.php', ['REDIRECT_REMOTE_USER' => self::USER]);

        $this->assertSignedIn($response);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([$this->basicLine(self::USER)], $this->authLog());
    }

    public function testRemoteUserTakesPrecedenceOverRedirectRemoteUser(): void
    {
        $this->assertSignedIn($this->basic('/gated.php', ['REMOTE_USER' => self::OTHER, 'REDIRECT_REMOTE_USER' => self::USER]), self::OTHER_ID);
    }

    /**
     * ADR 0010 section 8: an Authorization header alone must never authenticate.
     * PHP turns a client's Authorization: Basic header into PHP_AUTH_USER (here
     * the built-in server; other SAPIs do the same whenever the header reaches
     * PHP), and get_basic_auth_username() reads PHP_AUTH_USER before REMOTE_USER.
     * The password in the header is never checked.
     */
    public function testClientAuthorizationHeaderWithoutRemoteUserSignsInContraryToAdr8(): void
    {
        $response = $this->basic('/gated.php', [], [], ['Authorization: Basic ' . base64_encode(self::USER . ':not-checked-fixture')]);

        $this->assertSignedIn($response);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([$this->basicLine(self::USER)], $this->authLog());
    }

    /** Behind a server that sets REMOTE_USER itself (SSO, Kerberos), a client header still wins. */
    public function testClientAuthorizationHeaderOverridesTheServerRemoteUserContraryToAdr8(): void
    {
        $response = $this->basic('/gated.php', ['REMOTE_USER' => self::USER], [], ['Authorization: Basic ' . base64_encode(self::OTHER . ':not-checked-fixture')]);

        $this->assertSignedIn($response, self::OTHER_ID);
        self::assertSame([$this->basicLine(self::OTHER)], $this->authLog());
    }

    public function testClientRemoteUserHeaderIsIgnored(): void
    {
        $response = $this->basic('/gated.php', [], [], ['Remote-User: ' . self::USER, 'X-Remote-User: ' . self::USER]);

        self::assertSame(200, $response['status']);
        self::assertSame('Web Basic Authentication configured, but no username was passed from the web server. Please make sure you have authentication enabled on the web server.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([], $this->userLog());
    }

    public function testNoServerIdentityShowsTheMisconfigurationPage(): void
    {
        $response = $this->basic('/gated.php', []);

        self::assertSame(200, $response['status']);
        self::assertSame('Web Basic Authentication configured, but no username was passed from the web server. Please make sure you have authentication enabled on the web server.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([], $this->userLog());
        self::assertSame(['ERROR: No username passed with Web Basic Authentication enabled.'], $this->authLog());
    }

    public function testEmptyRemoteUserIsTreatedAsNoIdentity(): void
    {
        $response = $this->gated('');

        self::assertSame(200, $response['status']);
        self::assertSame('Web Basic Authentication configured, but no username was passed from the web server. Please make sure you have authentication enabled on the web server.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([], $this->userLog());
        self::assertSame(['ERROR: No username passed with Web Basic Authentication enabled.'], $this->authLog());
    }

    /** Whitespace is not trimmed: it is looked up, and refused, as an unknown user named '  '. */
    public function testWhitespaceRemoteUserIsRefusedAsAnUnknownUser(): void
    {
        $response = $this->gated('  ');

        self::assertSame(200, $response['status']);
        self::assertSame('   authenticated by Web Server, but both Template and Guest Users are not defined in Kadupul.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([], $this->userLog());
        self::assertSame(["LOGIN FAILED: User '  ' authenticated by Web Server, but both Template and Guest Users are not defined in Kadupul.  Exiting."], $this->authLog());
    }

    /** MariaDB's PAD SPACE collation matches 'basic-user ' to 'basic-user'; user_log keeps the raw name. */
    public function testTrailingSpaceInRemoteUserSignsInTheTrimmedAccountAndLogsTheRawName(): void
    {
        $this->assertSignedIn($this->gated(self::USER . ' '));
        self::assertSame([['username' => self::USER . ' ', 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([$this->basicLine(self::USER)], $this->authLog());
    }

    /** The case-insensitive collation matches; user_log keeps the presented spelling, cacti_log the stored one. */
    public function testUsernameCaseIsIgnoredAndUserLogKeepsThePresentedSpelling(): void
    {
        $this->assertSignedIn($this->gated('Basic-User'));
        self::assertSame([['username' => 'Basic-User', 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([$this->basicLine(self::USER)], $this->authLog());
    }

    public function testRealmSuffixAfterAtSignIsStripped(): void
    {
        $this->assertSignedIn($this->gated(self::USER . '@EXAMPLE.ORG'));
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
    }

    /** get_basic_auth_username() doubles every backslash, so a DOMAIN\user account stored as such never matches. */
    public function testBackslashInRemoteUserIsDoubledAndNeverMatchesTheStoredAccount(): void
    {
        $this->database->insert('user_auth', ['id' => 13, 'username' => 'EXAMPLE\\basic', 'password' => '', 'realm' => 2, 'login_opts' => 3, 'enabled' => 'on']);
        $this->database->insert('user_auth_realm', ['user_id' => 13, 'realm_id' => 7]);

        $response = $this->gated('EXAMPLE\\basic');

        self::assertSame(200, $response['status']);
        self::assertSame('EXAMPLE\\\\basic authenticated by Web Server, but both Template and Guest Users are not defined in Kadupul.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([], $this->userLog());
        self::assertSame(["LOGIN FAILED: User 'EXAMPLE\\\\basic' authenticated by Web Server, but both Template and Guest Users are not defined in Kadupul.  Exiting."], $this->authLog());
    }

    // ---- Unknown users, the template account and the guest ----

    public function testUnknownUserWithoutTemplateOrGuestIsRefused(): void
    {
        $response = $this->gated('basic-nobody');

        self::assertSame(200, $response['status']);
        self::assertSame('basic-nobody authenticated by Web Server, but both Template and Guest Users are not defined in Kadupul.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([], $this->userLog());
        self::assertSame(["LOGIN FAILED: User 'basic-nobody' authenticated by Web Server, but both Template and Guest Users are not defined in Kadupul.  Exiting."], $this->authLog());
        self::assertCount(4, $this->accounts());
    }

    /**
     * The copy is enabled and realm 2 even though the shipped template is disabled
     * and realm 0. It gets a random password, so must_change_password is set but
     * never acted on under method 2. The login lands through auth_login.php, so
     * it redirects and logs the generic LOGIN line, not the Basic one.
     */
    public function testUnknownUserWithATemplateCreatesAnEnabledBasicAccount(): void
    {
        $this->database->update('settings', ['value' => 'template-user'], ['name' => 'user_template']);

        $response = $this->gated('basic-new');

        $created = $this->database->fetchAssociative("SELECT id, realm, enabled, must_change_password, password, login_opts FROM user_auth WHERE username = 'basic-new'");
        self::assertIsArray($created);
        $id = (int) $created['id'];
        self::assertSame(['realm' => 2, 'enabled' => 'on', 'must_change_password' => 'on', 'login_opts' => 3], ['realm' => (int) $created['realm'], 'enabled' => $created['enabled'], 'must_change_password' => $created['must_change_password'], 'login_opts' => (int) $created['login_opts']]);
        self::assertNotNull(password_get_info((string) $created['password'])['algo'], 'The copy gets a random password hash.');
        self::assertSame([7], array_map('intval', $this->database->fetchFirstColumn('SELECT realm_id FROM user_auth_realm WHERE user_id = ?', [$id])));

        self::assertSame(302, $response['status']);
        self::assertSame('/graph_view.php', $response['location']);
        self::assertSame(['sess_user_id' => $id, 'sess_user_credential' => hash('sha256', (string) $created['password'])], $response['session']);
        self::assertArrayNotHasKey('cacti_remembers', $response['cookies']);
        self::assertSame([['username' => 'basic-new', 'user_id' => $id, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([
            "NOTE: User 'basic-new' does not exist, copying template user",
            "LOGIN: User 'basic-new' authenticated from IP Address '127.0.0.1'",
        ], $this->authLog());

        $next = $this->basic('/gated.php', [], ['Cacti' => $response['cookies']['Cacti']]);
        self::assertSame('GATED:' . $id, $next['body']);
    }

    public function testTemplateTakesPrecedenceOverTheGuestFallback(): void
    {
        $this->database->update('settings', ['value' => 'template-user'], ['name' => 'user_template']);
        $this->database->update('settings', ['value' => 'guest'], ['name' => 'guest_user']);

        $response = $this->gated('basic-new');

        $id = (int) $this->database->fetchOne("SELECT id FROM user_auth WHERE username = 'basic-new' AND realm = 2");
        self::assertGreaterThan(self::OTHER_ID, $id);
        self::assertSame($id, $response['session']['sess_user_id'] ?? null);
        self::assertSame([['username' => 'basic-new', 'user_id' => $id, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
    }

    /** The unknown name is logged in user_log against the guest's id. */
    public function testUnknownUserFallsBackToTheGuestWhenOnlyAGuestIsConfigured(): void
    {
        $this->database->update('settings', ['value' => 'guest'], ['name' => 'guest_user']);

        $response = $this->gated('basic-nobody');

        self::assertSame(302, $response['status']);
        self::assertSame('/graph_view.php', $response['location']);
        self::assertSame(['sess_user_id' => self::GUEST_ID, 'sess_user_credential' => self::EMPTY_PASSWORD_KEY], $response['session']);
        self::assertSame([['username' => 'basic-nobody', 'user_id' => self::GUEST_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([
            "LOGIN: Authenticated user 'basic-nobody' using guest account 'guest'",
            "LOGIN: Guest User 'guest' in use from IP Address '127.0.0.1'",
        ], $this->authLog());
        self::assertCount(4, $this->accounts());
    }

    /**
     * On a page that does not allow the guest, include/auth.php drops the guest
     * session and auth_login.php signs the guest in again, so every such request
     * redirects to graph_view.php. The drop calls cacti_session_start(true) on a
     * destroyed session, which raises a PHP warning.
     */
    public function testGuestFallbackSessionIsSignedInAgainOnEveryNonGuestPageWithAWarning(): void
    {
        $this->database->update('settings', ['value' => 'guest'], ['name' => 'guest_user']);
        $first = $this->gated('basic-nobody');
        file_put_contents($this->directory . '/cacti.log', '');

        $next = $this->basic('/gated.php', ['REMOTE_USER' => 'basic-nobody'], ['Cacti' => $first['cookies']['Cacti']], phpErrorsExpected: true);

        self::assertSame(302, $next['status']);
        self::assertSame('/graph_view.php', $next['location']);
        self::assertSame(['sess_user_id' => self::GUEST_ID, 'sess_user_credential' => self::EMPTY_PASSWORD_KEY], $next['session']);
        self::assertSame([
            "LOGIN: Authenticated user 'basic-nobody' using guest account 'guest'",
            "LOGIN: Guest User 'guest' in use from IP Address '127.0.0.1'",
        ], $this->authLog());
        self::assertSame(['PHP Warning:  session_regenerate_id(): Session ID cannot be regenerated when there is no active session in lib/functions.php'], $next['php_errors']);
    }

    public function testRemoteUserNamedGuestWithoutAConfiguredGuestIsRefused(): void
    {
        $response = $this->gated('guest');

        self::assertSame("guest authenticated by Web Server, but both Template and Guest Users are not defined in Kadupul.", $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([], $this->userLog());
    }

    /** The guest row is realm 0, so the name does not match it directly; the guest fallback admits it. */
    public function testRemoteUserNamedGuestWithAConfiguredGuestUsesTheGuestFallback(): void
    {
        $this->database->update('settings', ['value' => 'guest'], ['name' => 'guest_user']);

        $response = $this->gated('guest');

        self::assertSame(302, $response['status']);
        self::assertSame(self::GUEST_ID, $response['session']['sess_user_id'] ?? null);
        self::assertSame([['username' => 'guest', 'user_id' => self::GUEST_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([
            "LOGIN: Authenticated user 'guest' using guest account 'guest'",
            "LOGIN: Guest User 'guest' in use from IP Address '127.0.0.1'",
        ], $this->authLog());
    }

    // ---- Account state and realm ----

    public function testDisabledAccountIsRefusedWith403AndNoUserLogRow(): void
    {
        $this->database->update('user_auth', ['enabled' => ''], ['id' => self::USER_ID]);

        $response = $this->gated(self::USER);

        self::assertSame(403, $response['status']);
        self::assertSame('', $response['body']);
        self::assertSame([], $response['session']);
        self::assertSame([], $this->userLog());
        self::assertSame(['SECURITY: auth transition blocked for unavailable, disabled or locked user: 10 reason: basic_auth'], $this->authLog());
        self::assertSame(['failed_attempts' => 0, 'locked' => '', 'enabled' => ''], $this->account());
    }

    /** Reached directly, auth_login.php records a result 1 (success) row before it refuses the disabled account. */
    public function testDisabledAccountAtTheLoginPageRecordsASuccessRowBeforeRefusing(): void
    {
        $this->database->update('user_auth', ['enabled' => ''], ['id' => self::USER_ID]);

        $response = $this->basic('/auth_login.php', ['REMOTE_USER' => self::USER]);

        self::assertSame(200, $response['status']);
        self::assertSame('Access Denied!  User account disabled.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame(["LOGIN: User 'basic-user' authenticated from IP Address '127.0.0.1'"], $this->authLog());
    }

    /** Basic never counts failures or clears an expired lock: the lock stands whatever lastfail says. */
    public function testLockedAccountIsRefusedWith403AndTheCountersAreUntouched(): void
    {
        $this->database->update('user_auth', ['locked' => 'on', 'failed_attempts' => 3, 'lastfail' => time() - 16 * 60], ['id' => self::USER_ID]);

        $response = $this->gated(self::USER);

        self::assertSame(403, $response['status']);
        self::assertSame([], $response['session']);
        self::assertSame([], $this->userLog());
        self::assertSame(['SECURITY: auth transition blocked for unavailable, disabled or locked user: 10 reason: basic_auth'], $this->authLog());
        self::assertSame(['failed_attempts' => 3, 'locked' => 'on', 'enabled' => 'on'], $this->account());
    }

    /** Reached directly, auth_login.php records a success row, then the transition refuses with a PHP warning. */
    public function testLockedAccountAtTheLoginPageRecordsASuccessRowAndWarns(): void
    {
        $this->database->update('user_auth', ['locked' => 'on', 'failed_attempts' => 3, 'lastfail' => time()], ['id' => self::USER_ID]);

        $response = $this->basic('/auth_login.php', ['REMOTE_USER' => self::USER], phpErrorsExpected: true);

        self::assertSame(200, $response['status']);
        self::assertSame('Access Denied! User account locked.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([
            "LOGIN: User 'basic-user' authenticated from IP Address '127.0.0.1'",
            'SECURITY: auth transition blocked for unavailable, disabled or locked user: 10 reason: login',
        ], $this->authLog());
        self::assertSame(['failed_attempts' => 3, 'locked' => 'on', 'enabled' => 'on'], $this->account());
        self::assertSame(['PHP Warning:  session_regenerate_id(): Session ID cannot be regenerated when there is no active session in lib/functions.php'], $response['php_errors']);
    }

    /**
     * include/auth.php signs the user in before the page's realm check, so the
     * session survives the denial. With no Referer under method 2, $goBack is
     * never assigned and the denied page raises a PHP warning.
     */
    public function testNoRealmAccessKeepsTheSessionAndDeniesThePageWithAnUndefinedVariableWarning(): void
    {
        $this->database->delete('user_auth_realm', ['user_id' => self::USER_ID]);

        $response = $this->basic('/gated.php', ['REMOTE_USER' => self::USER], phpErrorsExpected: true);

        self::assertSame(200, $response['status']);
        self::assertStringContainsString("<h1 class='loginHeading'>Permission Denied</h1>", $response['body']);
        self::assertStringNotContainsString('GATED:', $response['body']);
        self::assertSame(['sess_user_id' => self::USER_ID, 'sess_user_credential' => self::EMPTY_PASSWORD_KEY], $response['session']);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([$this->basicLine(self::USER)], $this->authLog());
        self::assertSame(['PHP Warning:  Undefined variable $goBack in include/auth.php'], $response['php_errors']);
    }

    public function testNoRealmAccessAtTheLoginPageIsRefusedAfterASuccessRow(): void
    {
        $this->database->delete('user_auth_realm', ['user_id' => self::USER_ID]);

        $response = $this->basic('/auth_login.php', ['REMOTE_USER' => self::USER]);

        self::assertSame(200, $response['status']);
        self::assertSame('You do not have access to any area of Kadupul.  Contact your administrator.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([
            "LOGIN: User 'basic-user' authenticated from IP Address '127.0.0.1'",
            'LOGIN FAILED: User basic-user with id 10 does not have access to any area of Kadupul.',
        ], $this->authLog());
    }

    /** Only realm 2 rows are Basic accounts; an enabled local (realm 0) account of the same name is not one. */
    public function testAccountWhoseRealmIsNotBasicIsRefused(): void
    {
        $this->database->update('user_auth', ['realm' => 0], ['id' => self::USER_ID]);

        $response = $this->gated(self::USER);

        self::assertSame('basic-user authenticated by Web Server, but both Template and Guest Users are not defined in Kadupul.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame([], $this->userLog());
    }

    /** With a template, the same name gets a second, realm 2 account beside the local one. */
    public function testAccountWhoseRealmIsNotBasicGetsASeparateBasicCopyFromTheTemplate(): void
    {
        $this->database->update('user_auth', ['realm' => 0], ['id' => self::USER_ID]);
        $this->database->update('settings', ['value' => 'template-user'], ['name' => 'user_template']);

        $response = $this->gated(self::USER);

        $copies = array_values(array_filter($this->accounts(), static fn(array $row): bool => $row['username'] === self::USER));
        self::assertCount(2, $copies);
        self::assertSame(['id' => self::USER_ID, 'username' => self::USER, 'realm' => 0], $copies[0]);
        self::assertSame(2, $copies[1]['realm']);
        self::assertSame(302, $response['status']);
        self::assertSame($copies[1]['id'], $response['session']['sess_user_id'] ?? null);
        self::assertSame([['username' => self::USER, 'user_id' => $copies[1]['id'], 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
    }

    // ---- The login page, remembered logins and existing sessions ----

    /** Method 2 needs no POST: the form fields and remember_me are ignored and no token is issued. */
    public function testLoginPageSignsInTheServerIdentityAndIgnoresPostedCredentialsAndRememberMe(): void
    {
        $response = $this->basic('/auth_login.php', ['REMOTE_USER' => self::USER], form: ['action' => 'login', 'login_username' => self::OTHER, 'login_password' => 'Wrong-Fixture-2!', 'realm' => '0', 'remember_me' => 'on']);

        self::assertSame(302, $response['status']);
        self::assertSame('/graph_view.php', $response['location']);
        self::assertSame(['sess_user_id' => self::USER_ID, 'sess_user_credential' => self::EMPTY_PASSWORD_KEY], $response['session']);
        self::assertArrayNotHasKey('cacti_remembers', $response['cookies']);
        self::assertSame([], $this->database->fetchAllAssociative('SELECT * FROM user_auth_cache'));
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame(["LOGIN: User 'basic-user' authenticated from IP Address '127.0.0.1'"], $this->authLog());
    }

    /**
     * include/auth.php skips the remembered-login check under method 2, so a
     * stored token that check_auth_cookie() would accept for this realm 2
     * account is neither used nor revoked.
     */
    public function testRememberedCookieWithoutRemoteUserIsIgnored(): void
    {
        $cookie = $this->seedToken(self::USER_ID);

        $response = $this->basic('/gated.php', [], ['cacti_remembers' => $cookie]);

        self::assertSame('Web Basic Authentication configured, but no username was passed from the web server. Please make sure you have authentication enabled on the web server.', $this->failurePage($response['body']));
        $this->assertCookiesCleared($response);
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM user_auth_cache'));
        self::assertSame([], $this->userLog());
        self::assertSame(['ERROR: No username passed with Web Basic Authentication enabled.'], $this->authLog());
    }

    public function testRememberedCookieDoesNotChooseTheIdentityWhenRemoteUserIsPresent(): void
    {
        $cookie = $this->seedToken(self::USER_ID);
        $stored = $this->database->fetchAllAssociative('SELECT * FROM user_auth_cache');

        $response = $this->gated(self::OTHER, ['cacti_remembers' => $cookie]);

        $this->assertSignedIn($response, self::OTHER_ID);
        self::assertSame($stored, $this->database->fetchAllAssociative('SELECT * FROM user_auth_cache'), 'The token was neither rotated nor revoked.');
        self::assertSame([['username' => self::OTHER, 'user_id' => self::OTHER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
    }

    public function testEstablishedSessionSurvivesWithoutRemoteUser(): void
    {
        $first = $this->gated(self::USER);
        $this->database->executeStatement('DELETE FROM user_log');
        file_put_contents($this->directory . '/cacti.log', '');

        $next = $this->basic('/gated.php', [], ['Cacti' => $first['cookies']['Cacti']]);

        self::assertSame('GATED:' . self::USER_ID, $next['body']);
        self::assertSame(['sess_user_id' => self::USER_ID, 'sess_user_credential' => self::EMPTY_PASSWORD_KEY], $next['session']);
        self::assertSame([], $next['cookies']);
        self::assertSame([], $this->userLog());
        self::assertSame([], $this->authLog());
    }

    /** The session is not compared with the server identity again: a different REMOTE_USER keeps the first user. */
    public function testEstablishedSessionIgnoresAChangedRemoteUser(): void
    {
        $first = $this->gated(self::USER);
        $this->database->executeStatement('DELETE FROM user_log');
        file_put_contents($this->directory . '/cacti.log', '');

        $next = $this->gated(self::OTHER, ['Cacti' => $first['cookies']['Cacti']]);

        self::assertSame('GATED:' . self::USER_ID, $next['body']);
        self::assertSame(self::USER_ID, $next['session']['sess_user_id'] ?? null);
        self::assertSame([], $this->userLog());
        self::assertSame([], $this->authLog());
    }

    /** Logout Everywhere ends the session, but the next request with REMOTE_USER signs straight back in. */
    public function testStaleGenerationSessionIsReplacedByAFreshBasicLogin(): void
    {
        $first = $this->gated(self::USER);
        $generation = bin2hex(random_bytes(32));
        $this->database->insert('settings_user', ['user_id' => self::USER_ID, 'name' => 'auth_credential_generation', 'value' => self::EMPTY_PASSWORD_KEY . ':' . $generation]);
        $this->database->executeStatement('DELETE FROM user_log');
        file_put_contents($this->directory . '/cacti.log', '');

        $next = $this->gated(self::USER, ['Cacti' => $first['cookies']['Cacti']]);

        $this->assertSignedIn($next, self::USER_ID, $generation);
        self::assertNotSame($first['cookies']['Cacti'], $next['cookies']['Cacti']);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([
            'NOTE: Session for user id 10 ended because its password binding is missing or changed',
            $this->basicLine(self::USER),
        ], $this->authLog());
    }
}
