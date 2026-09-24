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
}
FRAGMENT_TEXT = "<?php\n$label = 'PHP Mail() and `quoted` text';\necho $label;\n"

# remote_agent.php is self-gated on !remote_client_authorized().
SELF_GATED = "<?php\nrequire(__DIR__ . '/include/global.php');\n%sif (!remote_client_authorized()) {\n\texit;\n}\n"
SELF_GATED_MOVED = {
    'check only in a comment': "<?php\nrequire(__DIR__ . '/include/global.php');\n// if (!remote_client_authorized()) {}\n",
    'check only in a function body': "<?php\nfunction f() {\n\tif (!remote_client_authorized()) {\n\t\texit;\n\t}\n}\n",
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
}
'''
SERVICE_CONTROLLER = '''<?php
namespace Kadupul\\Fixture;
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
    'app.php/who-guarded': 'symfony:who_guarded',
    'app.php/who-discarded': 'unknown',
    'app.php/session': 'unknown',
}
# canManageDevices() counts only in a guard on the checked actor.
GRANTS = {
    'app.php/a': 'ConsoleAccess realm 8',
    'app.php/devices': 'ConsoleAccess realm 8 + realm 3',
    'app.php/devices-split': 'ConsoleAccess realm 8 + realm 3',
    'app.php/devices-discarded': 'ConsoleAccess realm 8',
    'app.php/who-guarded': 'ConsoleAccess realm 8',
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
                       ('lib/evil.php', "<?php\nunlink('/tmp/x');\n")):
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
        # Reformatting a fragment keeps its pin.
        count += 1
        spaced = FRAGMENT_TEXT.replace('echo $label;', "/* note */\necho   $label ;")
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
                           ('src/Fixture/WhoActions.php', WHO_CONTROLLER)):
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
