# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Fixture checks that the entry-point classifier fails closed.

Each case is a page shape that must never be certified as gated or harmless.
A regression here would let the baseline record a wrong gate without the
unknown row that stops CI. The cases run through classify_entry_points.php
the way the generator calls it, one fixture tree per case.
"""
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_entry_point_inventory as inventory  # noqa: E402

GATED = "<?php\ninclude('./include/auth.php');\n"
AUTH = "include('./include/auth.php');\n"
CLI = "include('./include/cli_check.php');\n"

# page.php source -> the gate it must get.
CASES = {
    'baseline gated page': (GATED, 'realm:3'),
    'auth include inside a condition': (
        "<?php\nif (isset($_GET['x'])) { include('./include/auth.php'); }\n", 'unknown'),
    'side effect before the auth include': (
        "<?php\nfile_put_contents('/tmp/x', 'y');\n" + AUTH, 'unknown'),
    'array literal hiding a call': ("<?php\n$x = [rewrite_state()];\n", 'unknown'),
    'CLI include inside a condition': (
        "<?php\nif (!isset($_GET['k'])) { include('./include/cli_check.php'); }\n", 'unknown'),
    'write before the CLI include': (
        "<?php\nunlink($_GET['f']);\n" + CLI, 'unknown'),
    'call inside an early-exit condition': (
        "<?php\nif (!db_execute('DELETE FROM user_auth')) { exit; }\n" + AUTH, 'unknown'),
    'else branch ahead of an exit': (
        "<?php\nif ($a) { unlink($_GET['f']); } else if (1) { exit; }\n" + AUTH, 'unknown'),
    'call inside an exit condition before the CLI include': (
        "<?php\nif (unlink($_GET['f'])) { exit; }\n" + CLI, 'unknown'),
    'unreviewed library ahead of the auth include': (
        "<?php\ninclude_once('lib/evil.php');\n" + AUTH, 'unknown'),
    'reviewed preamble before the auth include': (
        "<?php\nob_start();\n$guest_account = true;\n" + AUTH, 'guest-or-realm:3'),
    'reviewed refusal before the auth include': (
        "<?php\nif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {\n\thttp_response_code(405);\n\texit;\n}\n" + AUTH, 'realm:3'),
}

# Constructs the regex lexer misread as inert or gated.
NEW_CASES = {
    'braced namespace with a call': ("<?php\nnamespace X {\n\tsystem($_GET['c']);\n}\n", 'unknown'),
    'declare block with a call': ("<?php\ndeclare(ticks=1) {\n\tunlink($_GET['f']);\n}\n", 'unknown'),
    'declare block before the auth include': (
        "<?php\ndeclare(ticks=1) {\n\tunlink($_GET['f']);\n}\n" + AUTH, 'unknown'),
    'define with a computed value': ("<?php\ndefine('X', file_put_contents('/tmp/x', 'y'));\n", 'unknown'),
    'define with a computed value before the auth include': (
        "<?php\ndefine('X', unlink($_GET['f']));\n" + AUTH, 'unknown'),
    'error_reporting with a computed argument': ("<?php\nerror_reporting(unlink($_GET['f']));\n", 'unknown'),
    'call inside string interpolation': ("<?php\n$x = \"{$f($_GET['x'])}\";\n", 'unknown'),
    'interpolated call before the auth include': ("<?php\n$x = \"{$f($_GET['x'])}\";\n" + AUTH, 'unknown'),
    'CLI guard with an else branch': (
        "<?php\nif (php_sapi_name() !== 'cli') {\n\texit;\n} else {\n\tunlink($_GET['f']);\n}\n", 'unknown'),
    'auth include inside a try block': ("<?php\ntry {\n\t" + AUTH + "} catch (Exception $e) {\n}\n", 'unknown'),
    'auth include assigned from a ternary': (
        "<?php\n$_GET['x'] ? include('./include/auth.php') : null;\n", 'unknown'),
    'gated page that does not parse': (GATED + "function (\n", 'unknown'),
    # isset() is true for false, so the guest path still applies.
    'guest flag set to false': ("<?php\n$guest_account = false;\n" + AUTH, 'guest-or-realm:3'),
    'guest flag set to null': ("<?php\n$guest_account = null;\n" + AUTH, 'realm:3'),
    # Included from a function, global makes the literal write a global one.
    'global with a literal write before the auth include': ("<?php\nglobal $x;\n$x = 1;\n" + AUTH, 'unknown'),
    'global with a literal write': ("<?php\nglobal $x;\n$x = 'a';\n", 'unknown'),
    'global naming the guest flag': ("<?php\nglobal $guest_account;\n" + AUTH, 'unknown'),
    'global nothing writes before the auth include': ("<?php\nglobal $x;\n" + AUTH, 'realm:3'),
}

FRAGMENT_EFFECTS = {
    'top-level write': "<?php\ndb_execute('DELETE FROM x');\n",
    'dynamic include': "<?php\ninclude($_REQUEST['p']);\n",
    'eval': "<?php\neval($_POST['c']);\n",
}
NEW_FRAGMENT_EFFECTS = {
    'eval inside string interpolation': "<?php\n$x = \"{${eval($_POST['c'])}}\";\n",
    'include of an interpolated path': "<?php\ninclude \"$dir/x.php\";\n",
    'dynamic include inside a block': "<?php\nif ($a) {\n\tinclude($_GET['p']);\n}\n",
    'dynamic function call': "<?php\n$f($_GET['x']);\n",
    'method call': "<?php\n$obj->save();\n",
    'nullsafe method call': "<?php\n$obj?->save();\n",
    'static call': "<?php\nFoo::write();\n",
    'constructor': "<?php\n$w = new Writer();\n",
    'callable handed to call_user_func': "<?php\ncall_user_func('unlink', $_GET['f']);\n",
    'callable handed to array_map': "<?php\narray_map('unlink', $_GET['f']);\n",
    # Reviewed for include/session.php only, so it does not carry over.
    'reviewed call in another file': "<?php\nregister_shutdown_function('session_write_close');\n",
    'call to a function it declares': "<?php\nfunction top_level_mutation() {\n\tunlink('/tmp/x');\n}\ntop_level_mutation();\n",
    'call to a function a file it includes declares': "<?php\ninclude('./lib/helpers.php');\nhelper();\n",
    'unreviewed builtin': "<?php\nignore_user_abort(true);\n",
    'call to a function nothing declares': "<?php\nextension_only_call();\n",
    'effect behind a call that does not always run': "<?php\nif (1 || helper()) {\n\tunlink($_GET['f']);\n}\n",
    # The effect is in the included file, which the fragment's pin does not cover.
    'include of a file with top-level code': "<?php\ninclude('./lib/evil.php');\n",
    'echo of request data': "<?php\necho $_SERVER['HTTP_COOKIE'];\n",
    'echo of a literal joined to a variable': "<?php\necho 'a' . $x;\n",
    'print of a variable': "<?php\nprint $x;\n",
    'exit with a variable': "<?php\nexit($x);\n",
    # Reviewed for include/runtime.php only, so it does not carry over.
    'reviewed output in another file': "<?php\n$message = 'x';\necho $message;\n",
}
# helper() is declared only in lib/helpers.php, which a direct request of the
# fragment never loads, so PHP stops there and the unlink never runs.
# include/csrf.php is the one fragment whose $config require is reviewed as
# ending a direct request; the same require without $config loads the file.
HALTING = "<?php\nrequire_once($config['include_path'] . '/vendor/csrf/csrf-conf.php');\nsystem($_GET['c']);\n"
NOT_HALTING = "<?php\nrequire_once(__DIR__ . '/vendor/csrf/csrf-conf.php');\nsystem($_GET['c']);\n"
FRAGMENT_INCLUDES_DECLARATIONS = "<?php\ninclude('./lib/declarations.php');\n"
FRAGMENT_HALTS = "<?php\n$x = helper();\nunlink($_GET['f']);\n"
FRAGMENT_TEXT = "<?php\n$label = 'PHP Mail() and `quoted` text';\necho 'PHP Mail() and `quoted` text', 1;\n"
RUNTIME = "<?php\nif (PHP_VERSION_ID < 80400) {\n\t$message = 'too old';\n\techo $message;\n\texit(1);\n}\n"

# remote_agent.php is self-gated on !remote_client_authorized().
SELF_GATED = "<?php\nrequire(__DIR__ . '/include/global.php');\n%sif (!remote_client_authorized()) {\n\texit;\n}\n"
SELF_GATED_MOVED = {
    'check only in a comment': "<?php\nrequire(__DIR__ . '/include/global.php');\n// if (!remote_client_authorized()) {}\n",
    'check only in a function body': "<?php\nfunction f() {\n\tif (!remote_client_authorized()) {\n\t\texit;\n\t}\n}\n",
}
# The check counts only in its reviewed guard shape: the whole condition of a
# statement that stops the request on the refused side.
BOOT = "<?php\nrequire(__DIR__ . '/include/global.php');\n"
REFUSE = "if (!remote_client_authorized()) {\n\t%s\n}\n"
SESSION_SWITCH = "switch ($_GET['a']) {\n\tcase 'x':\n\t\t%s\n\tdefault:\n\t\tif (!isset($_SESSION['sess_user_id'])) {\n\t\t\texit;\n\t\t}\n}\n"
REALM_IF = "if (is_realm_allowed($page['id'] + 10000)) {\n\tprint 1;\n}%s\n"
SELF_GATED_SHAPES = {
    'refusal that exits': ('remote_agent.php', BOOT + REFUSE % 'exit;', 'anonymous-allowed'),
    'refusal that returns': ('remote_agent.php', BOOT + REFUSE % 'return;', 'anonymous-allowed'),
    'refusal that throws': ('remote_agent.php', BOOT + REFUSE % 'throw new Exception();', 'anonymous-allowed'),
    'discarded check': ('remote_agent.php', BOOT + '!remote_client_authorized();\n', 'unknown'),
    'check under another condition': (
        'remote_agent.php', BOOT + 'if ($x) {\n\t' + REFUSE % 'exit;' + '}\n', 'unknown'),
    'refusal that carries on': ('remote_agent.php', BOOT + REFUSE % "print 'denied';", 'unknown'),
    # Only statements at the top of the refusal body count as stopping it.
    'refusal whose exit is nested': ('remote_agent.php', BOOT + REFUSE % 'if ($flag) {\n\t\texit;\n\t}', 'unknown'),
    'check joined to another condition': (
        'remote_agent.php', BOOT + 'if (!remote_client_authorized() && $x) {\n\texit;\n}\n', 'unknown'),
    'refusal with an else branch': (
        'remote_agent.php', BOOT + (REFUSE % 'exit;').rstrip() + " else {\n\tprint 1;\n}\n", 'unknown'),
    'switch whose other case exits': ('auth_changepassword.php', BOOT + SESSION_SWITCH % 'exit;', 'authenticated'),
    'switch whose other case falls through': ('auth_changepassword.php', BOOT + SESSION_SWITCH % 'print 1;', 'unknown'),
    'switch whose other case breaks before exit': (
        'auth_changepassword.php', BOOT + SESSION_SWITCH % 'break;\n\t\texit;', 'unknown'),
    'admission whose else exits': ('link.php', BOOT + REALM_IF % ' else {\n\texit;\n}', 'realm:10000+id'),
    'admission without an else': ('link.php', BOOT + REALM_IF % '', 'unknown'),
    'admission whose else carries on': ('link.php', BOOT + REALM_IF % " else {\n\tprint 'no';\n}", 'unknown'),
}

SESSION = '''<?php
namespace Kadupul\\IdentityAccess\\Infrastructure\\Legacy;
use Kadupul\\IdentityAccess\\Contract\\ConsoleAccess;
final class LegacyAuthenticatedSession implements ConsoleAccess
{
    public function consoleActor(): ?Actor
    {
        $id = 1;
        return $this->hasRealm($id, 8) ? new Actor() : null;
    }

    public function canManageDevices(Actor $actor): bool
    {
        return $this->hasRealm($actor->id, 3);
    }
}
'''
CONTROLLER = '''<?php
namespace Kadupul\\Fixture;
use Kadupul\\IdentityAccess\\Contract\\ConsoleAccess;
use Symfony\\Component\\HttpFoundation\\BinaryFileResponse;
use Symfony\\Component\\HttpFoundation\\JsonResponse;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\HttpFoundation\\StreamedResponse;
use Symfony\\Component\\Routing\\Attribute\\Route;
final class TwoActions
{
    public function __construct(private ConsoleAccess $access) {}

    #[Route('/a', name: 'a')]
    public function checked(): Response
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            return new Response('', 401);
        }
        return new Response();
    }

    #[Route('/b', name: 'b')]
    public function open(): Response
    {
        return new Response();
    }

    #[Route('/discarded', name: 'discarded')]
    public function discarded(): Response
    {
        $this->access->consoleActor();
        return new Response();
    }

    #[Route('/unguarded', name: 'unguarded')]
    public function unguarded(): Response
    {
        $actor = $this->access->consoleActor();
        return new Response((string) $actor?->id);
    }

    #[Route('/late-guard', name: 'late_guard')]
    public function lateGuard(Sites $sites): Response
    {
        $actor = $this->access->consoleActor();
        $sites->unchecked();
        if ($actor === null) {
            return new Response('', 401);
        }
        return new Response();
    }

    #[Route('/logged-only', name: 'logged_only')]
    public function loggedOnly(): Response
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            error_log('no actor');
        }
        return new Response();
    }

    #[Route('/effect-first', name: 'effect_first')]
    public function effectFirst(Sites $sites): Response
    {
        $sites->unchecked();
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            return new Response('', 401);
        }
        return new Response();
    }

    #[Route('/nested-guard', name: 'nested_guard')]
    public function nestedGuard(Sites $sites): Response
    {
        if ($this->access !== null) {
            $actor = $this->access->consoleActor();
            if ($actor === null) {
                return new Response('', 401);
            }
        }
        $sites->unchecked();
        return new Response();
    }

    #[Route('/effect-in-guard', name: 'effect_in_guard')]
    public function effectInGuard(Sites $sites): Response
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            $sites->unchecked();
            return new Response('', 401);
        }
        return new Response();
    }

    #[Route('/effect-before-null-check', name: 'effect_before_null_check')]
    public function effectBeforeNullCheck(Sites $sites): Response
    {
        $actor = $this->access->consoleActor();
        if ($sites->unchecked() || $actor === null) {
            return new Response('', 401);
        }
        return new Response();
    }

    #[Route('/effect-in-device-guard', name: 'effect_in_device_guard')]
    public function effectInDeviceGuard(Sites $sites): Response
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            return new Response('', 401);
        }
        if (!$this->access->canManageDevices($actor)) {
            $sites->unchecked();
            return new Response('', 403);
        }
        return new Response();
    }

    #[Route('/effect-before-device-check', name: 'effect_before_device_check')]
    public function effectBeforeDeviceCheck(Sites $sites): Response
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            return new Response('', 401);
        }
        if ($sites->unchecked() || !$this->access->canManageDevices($actor)) {
            return new Response('', 403);
        }
        return new Response();
    }

    #[Route('/devices', name: 'devices')]
    public function devices(): Response
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            return new Response('', 403);
        }
        return new Response();
    }

    #[Route('/devices-split', name: 'devices_split')]
    public function devicesSplit(): Response
    {
        $actor = $this->access->consoleActor();
        if (null === $actor) {
            throw new \\RuntimeException();
        }
        if (!$this->access->canManageDevices($actor)) {
            throw new \\RuntimeException();
        }
        return new Response();
    }

    #[Route('/json-refusal', name: 'json_refusal')]
    public function jsonRefusal(): Response
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            return new JsonResponse([], 401);
        }
        return new Response();
    }

    // The callback runs while the refused response is sent.
    #[Route('/streamed-refusal', name: 'streamed_refusal')]
    public function streamedRefusal(callable $callback): Response
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            return new StreamedResponse($callback, 401);
        }
        return new Response();
    }

    #[Route('/file-refusal', name: 'file_refusal')]
    public function fileRefusal(): Response
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            return new BinaryFileResponse('/etc/hostname', 401);
        }
        return new Response();
    }

    #[Route('/devices-discarded', name: 'devices_discarded')]
    public function devicesDiscarded(): Response
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            return new Response('', 401);
        }
        $this->access->canManageDevices($actor);
        return new Response();
    }
}
'''
# The used case checks access in one method; the action calls the other.
SERVICE = '''<?php
namespace Kadupul\\Fixture;
use Kadupul\\IdentityAccess\\Contract\\ConsoleAccess;
final class Sites
{
    public function __construct(private ConsoleAccess $access) {}

    public function checked(): void
    {
        $actor = $this->access->consoleActor();
        if (!$actor) {
            throw new \\RuntimeException();
        }
    }

    public function unchecked(): void
    {
    }

    public function effectBeforeThrow(): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            $this->unchecked();
            throw new \\RuntimeException();
        }
    }

    // A callee's catch that returns hands control back to the action.
    public function catchReturns(): void
    {
        try {
            $this->checked();
        } catch (\\RuntimeException) {
            return;
        }
    }

    public function earlyReturn(bool $flag): void
    {
        if ($flag) {
            return;
        }
        $this->checked();
    }

    // A return leaves only this method; the action carries on.
    public function returnsOnNull(): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            return;
        }
    }
}
'''
SERVICE_CONTROLLER = '''<?php
namespace Kadupul\\Fixture;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\Routing\\Attribute\\Route;
final class ServiceActions
{
    #[Route('/via-unchecked', name: 'via_unchecked')]
    public function viaUnchecked(Sites $sites): Response
    {
        $sites->unchecked();
        return new Response();
    }

    #[Route(
        '/via-checked',
        name: 'via_checked',
        methods: ['GET'],
    )]
    public function viaChecked(Sites $sites): Response
    {
        $sites->checked();
        return new Response();
    }

    #[Route('/via-return', name: 'via_return')]
    public function viaReturn(Sites $sites): Response
    {
        $sites->returnsOnNull();
        return new Response();
    }

    #[Route('/free-call-first', name: 'free_call_first')]
    public function freeCallFirst(Sites $sites): Response
    {
        unlink('/tmp/x');
        $sites->checked();
        return new Response();
    }

    #[Route('/static-call-first', name: 'static_call_first')]
    public function staticCallFirst(Sites $sites): Response
    {
        Wiper::wipe();
        $sites->checked();
        return new Response();
    }

    #[Route('/new-first', name: 'new_first')]
    public function newFirst(Sites $sites): Response
    {
        (new Wiper())->run();
        $sites->checked();
        return new Response();
    }

    #[Route('/effect-in-arguments', name: 'effect_in_arguments')]
    public function effectInArguments(Sites $sites): Response
    {
        $sites->checked(unlink('/tmp/x'));
        return new Response();
    }

    #[Route('/conditional-call', name: 'conditional_call')]
    public function conditionalCall(Sites $sites, bool $flag): Response
    {
        if ($flag) {
            $sites->checked();
        }
        return new Response();
    }

    #[Route('/effect-in-catch', name: 'effect_in_catch')]
    public function effectInCatch(Sites $sites): Response
    {
        try {
            $sites->checked();
        } catch (\\RuntimeException) {
            unlink('/tmp/x');
        }
        return new Response();
    }

    #[Route('/via-effect-before-throw', name: 'via_effect_before_throw')]
    public function viaEffectBeforeThrow(Sites $sites): Response
    {
        $sites->effectBeforeThrow();
        return new Response();
    }

    #[Route('/catch-swallows', name: 'catch_swallows')]
    public function catchSwallows(Sites $sites): Response
    {
        try {
            $sites->checked();
        } catch (\\RuntimeException) {
            $status = 502;
        }
        $sites->unchecked();
        return new Response();
    }

    #[Route('/catch-empty', name: 'catch_empty')]
    public function catchEmpty(Sites $sites): Response
    {
        try {
            $sites->checked();
        } catch (\\RuntimeException) {
        }
        return new Response();
    }

    #[Route('/via-callee-catch', name: 'via_callee_catch')]
    public function viaCalleeCatch(Sites $sites): Response
    {
        $sites->catchReturns();
        return new Response();
    }

    #[Route('/pure-then-checked', name: 'pure_then_checked')]
    public function pureThenChecked(Sites $sites): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $count = is_array($headers) ? 1 : 0;
            $sites->checked();
        } catch (\\RuntimeException $error) {
            return new Response($error->getMessage(), 401, $headers);
        }
        return new Response();
    }

    // getMessage() is a pure read only on the exception the catch binds.
    #[Route('/message-from-other', name: 'message_from_other')]
    public function messageFromOther(Sites $sites, $writer): Response
    {
        try {
            $sites->checked();
        } catch (\\RuntimeException $error) {
            return new Response($writer->getMessage(), 401);
        }
        return new Response();
    }

    #[Route('/message-after-rebind', name: 'message_after_rebind')]
    public function messageAfterRebind(Sites $sites, $writer): Response
    {
        try {
            $sites->checked();
        } catch (\\RuntimeException $error) {
            $error = $writer;
            return new Response($error->getMessage(), 401);
        }
        return new Response();
    }

    #[Route('/message-after-foreach', name: 'message_after_foreach')]
    public function messageAfterForeach(Sites $sites, array $writers): Response
    {
        try {
            $sites->checked();
        } catch (\\RuntimeException $error) {
            foreach ($writers as $error) {
            }
            return new Response($error->getMessage(), 401);
        }
        return new Response();
    }

    #[Route('/via-early-return', name: 'via_early_return')]
    public function viaEarlyReturn(Sites $sites, bool $flag): Response
    {
        $sites->earlyReturn($flag);
        return new Response();
    }

    #[Route('/after-other-call', name: 'after_other_call')]
    public function afterOtherCall(Sites $sites): Response
    {
        $sites->unchecked();
        $sites->checked();
        return new Response();
    }
}
'''
# A query that only returns the actor passes the guard duty to its caller.
WHO = '''<?php
namespace Kadupul\\Fixture;
use Kadupul\\IdentityAccess\\Contract\\ConsoleAccess;
final class Who
{
    public function __construct(private ConsoleAccess $access) {}

    public function __invoke(): ?Actor
    {
        return $this->access->consoleActor();
    }
}
'''
WHO_CONTROLLER = '''<?php
namespace Kadupul\\Fixture;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\Routing\\Attribute\\Route;
final class WhoActions
{
    #[Route('/who-guarded', name: 'who_guarded')]
    public function guarded(Who $who): Response
    {
        $actor = $who();
        if (is_null($actor)) {
            return new Response('', 401);
        }
        return new Response();
    }

    #[Route('/who-discarded', name: 'who_discarded')]
    public function discarded(Who $who): Response
    {
        $who();
        return new Response();
    }

    // Reviewed by name, but the review needs the actor to be read at all.
    #[Route('/session', name: 'session')]
    public function session(Who $who): Response
    {
        $who();
        return new Response();
    }
}
'''
# A query that throws on refusal, handed as a callable to a helper that turns
# the throw into a response, which the action returns.
CHECKED_QUERY = '''<?php
namespace Kadupul\\Fixture;
use Kadupul\\IdentityAccess\\Contract\\ConsoleAccess;
final class CheckedQuery
{
    public function __construct(private ConsoleAccess $access) {}

    public function __invoke(array $ids): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new \\RuntimeException();
        }
        return [];
    }
}
'''
SELECTION = '''<?php
namespace Kadupul\\Fixture;
use Symfony\\Component\\HttpFoundation\\Request;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\HttpFoundation\\StreamedResponse;
final class Selection
{
    public function prepare(Request $request, callable $prepare): array|Response
    {
        try {
            $ids = $request->query->all();
            $rows = $prepare($ids);
            return [$rows];
        } catch (\\RuntimeException $error) {
            return $this->deny($error->getMessage());
        }
    }

    public function catchesNull(Request $request, callable $prepare): ?array
    {
        try {
            $rows = $prepare([]);
        } catch (\\RuntimeException) {
            return null;
        }
        return $rows;
    }

    public function effectFirst(Request $request, callable $prepare): array|Response
    {
        unlink('/tmp/x');
        try {
            $rows = $prepare([]);
        } catch (\\RuntimeException) {
            return $this->deny('');
        }
        return $rows;
    }

    public function earlyReturn(Request $request, callable $prepare): array|Response
    {
        if ($request->isMethod('HEAD')) {
            return [];
        }
        try {
            $rows = $prepare([]);
        } catch (\\RuntimeException) {
            return $this->deny('');
        }
        return $rows;
    }

    public function streamed(Request $request, callable $prepare, callable $callback): array|Response
    {
        try {
            $rows = $prepare([]);
        } catch (\\RuntimeException) {
            return new StreamedResponse($callback, 403);
        }
        return $rows;
    }

    public function nullableDeny(Request $request, callable $prepare): array|Response|null
    {
        try {
            $rows = $prepare([]);
        } catch (\\RuntimeException) {
            return $this->maybeDeny();
        }
        return $rows;
    }

    public function publicDeny(Request $request, callable $prepare): array|Response
    {
        try {
            $rows = $prepare([]);
        } catch (\\RuntimeException) {
            return $this->openDeny();
        }
        return $rows;
    }

    public function impureDeny(Request $request, callable $prepare): array|Response
    {
        try {
            $rows = $prepare([]);
        } catch (\\RuntimeException) {
            return $this->loggedDeny();
        }
        return $rows;
    }

    private function deny(string $message): Response
    {
        return new Response($message, 403);
    }

    private function maybeDeny(): ?Response
    {
        return null;
    }

    public function openDeny(): Response
    {
        return new Response('', 403);
    }

    private function loggedDeny(): Response
    {
        unlink('/tmp/x');
        return new Response('', 403);
    }
}
'''
HANDED_ACTION = '''
    #[Route('/%s', name: '%s')]
    public function %s(Request $request, CheckedQuery $query, Selection $selection, Sites $sites, $other, callable $callback): Response
    {
%s
        return new Response();
    }
'''
GUARD = '''        if ($prepared instanceof Response) {
            return $prepared;
        }'''
HANDED = {
    'handed': ('$prepared = $selection->prepare($request, $query);\n' + GUARD, 'symfony:handed'),
    'handed-unguarded': ('$prepared = $selection->prepare($request, $query);', 'unknown'),
    'handed-late-guard': ('$prepared = $selection->prepare($request, $query);\n$sites->unchecked();\n' + GUARD, 'unknown'),
    'handed-other-guard': ('$prepared = $selection->prepare($request, $query);\n' + GUARD.replace('($prepared', '($other'), 'unknown'),
    'handed-returns-other': ('$prepared = $selection->prepare($request, $query);\n' + GUARD.replace('return $prepared', 'return $other'), 'unknown'),
    'handed-rebound': ('$query = $other;\n$prepared = $selection->prepare($request, $query);\n' + GUARD, 'unknown'),
    'handed-untyped': ('$prepared = $selection->prepare($request, $other);\n' + GUARD, 'unknown'),
    'handed-null-catch': ('$prepared = $selection->catchesNull($request, $query);\n' + GUARD, 'unknown'),
    'handed-effect-first': ('$prepared = $selection->effectFirst($request, $query);\n' + GUARD, 'unknown'),
    'handed-early-return': ('$prepared = $selection->earlyReturn($request, $query);\n' + GUARD, 'unknown'),
    'handed-streamed': ('$prepared = $selection->streamed($request, $query, $callback);\n' + GUARD, 'unknown'),
    'handed-nullable-deny': ('$prepared = $selection->nullableDeny($request, $query);\n' + GUARD, 'unknown'),
    'handed-public-deny': ('$prepared = $selection->publicDeny($request, $query);\n' + GUARD, 'unknown'),
    'handed-impure-deny': ('$prepared = $selection->impureDeny($request, $query);\n' + GUARD, 'unknown'),
}
HANDED_CONTROLLER = '''<?php
namespace Kadupul\\Fixture;
use Symfony\\Component\\HttpFoundation\\Request;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\Routing\\Attribute\\Route;
final class HandedActions
{%s}
''' % ''.join(HANDED_ACTION % (route, route.replace('-', '_'), route.replace('-', '_'),
                               '\n'.join('        ' + line.strip() if not line.startswith('        ') else line
                                         for line in body.split('\n')))
              for route, (body, _) in HANDED.items())
ALIASED_CONTROLLER = '''<?php
namespace Kadupul\\Fixture;
use Symfony\\Component\\Routing\\Attribute\\Route as Path;
final class Aliased
{
    #[Path('/aliased', name: 'aliased')]
    public function open(): Response
    {
        return new Response();
    }
}
'''
ROUTES = {
    'app.php/a': 'symfony:a',
    'app.php/b': 'unknown',
    'app.php/discarded': 'unknown',
    'app.php/unguarded': 'unknown',
    'app.php/late-guard': 'unknown',
    'app.php/logged-only': 'unknown',
    'app.php/devices': 'symfony:devices',
    'app.php/devices-split': 'symfony:devices_split',
    'app.php/devices-discarded': 'symfony:devices_discarded',
    'app.php/via-unchecked': 'unknown',
    'app.php/via-checked': 'symfony:via_checked',
    'app.php/via-return': 'unknown',
    'app.php/after-other-call': 'unknown',
    'app.php/free-call-first': 'unknown',
    'app.php/static-call-first': 'unknown',
    'app.php/new-first': 'unknown',
    'app.php/effect-in-arguments': 'unknown',
    'app.php/conditional-call': 'unknown',
    'app.php/effect-in-catch': 'unknown',
    'app.php/pure-then-checked': 'symfony:pure_then_checked',
    'app.php/message-from-other': 'unknown',
    'app.php/message-after-rebind': 'unknown',
    'app.php/message-after-foreach': 'unknown',
    'app.php/catch-swallows': 'unknown',
    'app.php/effect-in-guard': 'unknown',
    'app.php/effect-before-null-check': 'unknown',
    'app.php/effect-in-device-guard': 'symfony:effect_in_device_guard',
    'app.php/effect-before-device-check': 'symfony:effect_before_device_check',
    'app.php/via-effect-before-throw': 'unknown',
    'app.php/catch-empty': 'unknown',
    'app.php/via-callee-catch': 'unknown',
    'app.php/effect-first': 'unknown',
    'app.php/nested-guard': 'unknown',
    'app.php/who-guarded': 'symfony:who_guarded',
    'app.php/who-discarded': 'unknown',
    'app.php/session': 'unknown',
    'app.php/json-refusal': 'symfony:json_refusal',
    'app.php/streamed-refusal': 'unknown',
    'app.php/file-refusal': 'unknown',
    'app.php/via-early-return': 'unknown',
    **{'app.php/' + route: expected for route, (_, expected) in HANDED.items()},
}
# canManageDevices() counts only in a guard on the checked actor.
GRANTS = {
    'app.php/a': 'ConsoleAccess realm 8',
    'app.php/devices': 'ConsoleAccess realm 8 + realm 3',
    'app.php/devices-split': 'ConsoleAccess realm 8 + realm 3',
    'app.php/devices-discarded': 'ConsoleAccess realm 8',
    'app.php/who-guarded': 'ConsoleAccess realm 8',
    'app.php/effect-in-device-guard': 'ConsoleAccess realm 8',
    'app.php/effect-before-device-check': 'ConsoleAccess realm 8',
    'app.php/handed': 'ConsoleAccess realm 8 + realm 3',
}

REALMS = "<?php\n$user_auth_realm_filenames = array(\n\t'page.php' => 3,\n\t\"dq.php\" => 3,\n\t'open.php' => -1,\n);\n"
UNREADABLE_REALMS = {
    'constant value': "<?php\n$user_auth_realm_filenames = array('page.php' => 3, 'x.php' => REALM_X);\n",
    'concatenated key': "<?php\n$user_auth_realm_filenames = array('page.php' => 3, 'a' . '.php' => 3);\n",
}


def tree(directory):
    root = Path(directory)
    for path, text in (('include/auth.php', '<?php\n'), ('include/cli_check.php', '<?php\n'),
                       ('include/global_arrays.php', REALMS), ('host.php', "<?php\ninclude('./fragment.php');\n"),
                       ('lib/evil.php', "<?php\nunlink('/tmp/x');\n"),
                       ('lib/helpers.php', "<?php\nfunction helper() {\n\tunlink('/tmp/x');\n}\n"),
                       ('lib/declarations.php', "<?php\nfunction declared() {\n}\n"),
                       ('include/vendor/csrf/csrf-conf.php', "<?php\n$GLOBALS['csrf']['x'] = 1;\n"),
                       ('csrf_host.php', "<?php\ninclude('./include/csrf.php');\n")):
        (root / path).parent.mkdir(parents=True, exist_ok=True)
        (root / path).write_text(text)
    return root


def run(root, served):
    files = sorted(str(p.relative_to(root)) for p in root.rglob('*.php'))
    return {row[0]: (row[1], row[2]) for row in inventory.classify(root, files, served)}


def gate(root, name, source):
    (root / name).write_text(source)
    return run(root, [name])[name]


def main():
    failures = []
    count = 0
    with tempfile.TemporaryDirectory(prefix='entry-classifier-') as directory:
        root = tree(directory)
        for case, (source, expected) in {**CASES, **NEW_CASES}.items():
            count += 1
            got = gate(root, 'page.php', source)[0]
            if got != expected:
                failures.append('%s: expected %s, got %s' % (case, expected, got))
        (root / 'page.php').unlink()
        for case, source in {**FRAGMENT_EFFECTS, **NEW_FRAGMENT_EFFECTS}.items():
            count += 1
            got = gate(root, 'fragment.php', source)[0]
            if got != 'unknown':
                failures.append('fragment with %s: expected unknown, got %s' % (case, got))
        # Words inside string literals are data, not calls.
        count += 1
        got = gate(root, 'fragment.php', FRAGMENT_TEXT)[0]
        if got != 'anonymous-allowed':
            failures.append('fragment with call-like text in a string: expected anonymous-allowed, got %s' % got)
        count += 1
        got = gate(root, 'fragment.php', FRAGMENT_HALTS)[0]
        if got != 'anonymous-allowed':
            failures.append('fragment that stops at an undefined function: expected anonymous-allowed, got %s' % got)
        # Including declarations is fine, until the included file gains code.
        count += 1
        got = gate(root, 'fragment.php', FRAGMENT_INCLUDES_DECLARATIONS)[0]
        if got != 'anonymous-allowed':
            failures.append('fragment including declarations only: expected anonymous-allowed, got %s' % got)
        count += 1
        (root / 'lib/declarations.php').write_text("<?php\nfunction declared() {\n}\nunlink('/tmp/x');\n")
        got = gate(root, 'fragment.php', FRAGMENT_INCLUDES_DECLARATIONS)[0]
        (root / 'lib/declarations.php').write_text("<?php\nfunction declared() {\n}\n")
        if got != 'unknown':
            failures.append('fragment including a file that gained top-level code: expected unknown, got %s' % got)
        for source, expected in ((HALTING, 'anonymous-allowed'), (NOT_HALTING, 'unknown')):
            count += 1
            got = gate(root, 'include/csrf.php', source)[0]
            if got != expected:
                failures.append('include/csrf.php with %s: expected %s, got %s' % (source.splitlines()[1], expected, got))
        (root / 'include/csrf.php').unlink()
        # The one reviewed non-literal output, only where it was reviewed.
        count += 1
        (root / 'runtime_host.php').write_text("<?php\ninclude('./include/runtime.php');\n")
        got = gate(root, 'include/runtime.php', RUNTIME)[0]
        if got != 'anonymous-allowed':
            failures.append('include/runtime.php with its version notice: expected anonymous-allowed, got %s' % got)
        (root / 'include/runtime.php').unlink()
        (root / 'runtime_host.php').unlink()
        # Reformatting a fragment keeps its pin.
        count += 1
        spaced = FRAGMENT_TEXT.replace("echo 'PHP", "/* note */\necho   'PHP")
        if gate(root, 'fragment.php', spaced) != gate(root, 'fragment.php', FRAGMENT_TEXT):
            failures.append('fragment pin changed on a formatting-only edit')

        # An effect ahead of a self-gated check, or a change to it, moves the
        # pin, so it shows as drift against the baseline.
        count += 1
        clean = gate(root, 'remote_agent.php', SELF_GATED % '')
        moved = gate(root, 'remote_agent.php', SELF_GATED % "unlink($_GET['f']);\n")
        if clean[0] != 'anonymous-allowed' or moved[0] != 'anonymous-allowed' or clean[1] == moved[1]:
            failures.append('effect before a self-gated check: expected a new pin, got %s then %s' % (clean, moved))
        for case, source in SELF_GATED_MOVED.items():
            count += 1
            got = gate(root, 'remote_agent.php', source)[0]
            if got != 'unknown':
                failures.append('self-gated %s: expected unknown, got %s' % (case, got))
        for case, (name, source, expected) in SELF_GATED_SHAPES.items():
            count += 1
            got = gate(root, name, source)[0]
            (root / name).unlink()
            if got != expected:
                failures.append('self-gated %s: expected %s, got %s' % (case, expected, got))

        for case, text in UNREADABLE_REALMS.items():
            count += 1
            (root / 'include/global_arrays.php').write_text(text)
            try:
                run(root, [])
                failures.append('unreadable realm entry (%s): expected the generator to stop' % case)
            except SystemExit:
                pass
        (root / 'include/global_arrays.php').write_text(REALMS)
        count += 1
        if gate(root, 'dq.php', GATED)[0] != 'realm:3':
            failures.append('double-quoted realm key: expected realm:3')
        count += 1
        got = gate(root, 'open.php', "<?php\n$guest_account = true;\n" + AUTH)[0]
        if got != 'guest-or-authenticated':
            failures.append('guest page with realm -1: expected guest-or-authenticated, got %s' % got)

    with tempfile.TemporaryDirectory(prefix='entry-classifier-routes-') as directory:
        root = tree(directory)
        for path, text in (('src/IdentityAccess/Infrastructure/Legacy/LegacyAuthenticatedSession.php', SESSION),
                           ('src/Fixture/TwoActions.php', CONTROLLER), ('src/Fixture/Sites.php', SERVICE),
                           ('src/Fixture/ServiceActions.php', SERVICE_CONTROLLER), ('src/Fixture/Who.php', WHO),
                           ('src/Fixture/WhoActions.php', WHO_CONTROLLER),
                           ('src/Fixture/CheckedQuery.php', CHECKED_QUERY), ('src/Fixture/Selection.php', SELECTION),
                           ('src/Fixture/HandedActions.php', HANDED_CONTROLLER)):
            (root / path).parent.mkdir(parents=True, exist_ok=True)
            (root / path).write_text(text)
        # A check in one action, or in one method of a used class, must not
        # cover a sibling.
        rows = run(root, [])
        for entry, expected in ROUTES.items():
            count += 1
            got = rows.get(entry, ('missing',))[0]
            if got != expected:
                failures.append('route %s: expected %s, got %s' % (entry, expected, got))
        for entry, grant in GRANTS.items():
            count += 1
            if not rows.get(entry, ('', ''))[1].endswith('; ' + grant):
                failures.append('route %s: expected %s read from the session adapter, got %s' % (entry, grant, rows.get(entry)))
        # A route under an alias is not missed.
        count += 1
        (root / 'src/Fixture/Aliased.php').write_text(ALIASED_CONTROLLER)
        rows = run(root, [])
        if rows.get('app.php/aliased', ('missing',))[0] != 'unknown' or rows.get('app.php', ('missing',))[0] != 'unknown':
            failures.append('aliased #[Route]: expected unknown rows, got %s' % {k: v for k, v in rows.items() if 'aliased' in k or k == 'app.php'})

    for failure in failures:
        print('FAIL: ' + failure)
    if failures:
        return 1
    print('PASS: %d classifier fixtures fail closed' % count)
    return 0


if __name__ == '__main__':
    sys.exit(main())
