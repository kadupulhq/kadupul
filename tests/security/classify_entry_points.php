<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Classifies HTTP entry points from the PHP AST for
 * build_entry_point_inventory.py, which owns the file list and the web
 * server rules and calls this once.
 *
 * Reads {"root", "files", "served", "plugin_realms"} as JSON on stdin and
 * prints {"rows": [[entry, gate, detail], ...]} for every served file and
 * every Symfony route. A tree this script cannot read exits 2.
 *
 * Every rule is an allowlist of node shapes. A statement, gate or route that
 * matches none of them is effectful or unknown, so a construct nobody has
 * reviewed fails CI instead of being certified.
 */

require __DIR__ . '/../../include/vendor/autoload.php';

use PhpParser\Error as ParseError;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/*
 * Pages that bootstrap with include/global.php or nothing at all and then
 * apply their own check. The check is a PHP expression, and the detail pins
 * every top-level statement up to and including the one that holds it, so
 * code added ahead of the check or an edit to it shows as drift.
 *
 * The shape says where the check must sit to count:
 * - refusal: the whole condition of an if with no other branch whose body
 *   reaches exit, return or throw (see exits()), at the top level or opening the default case of a top-level switch
 *   whose other cases all exit;
 * - admission: the whole condition of an if whose else exits, at the top
 *   level or ending the else of a top-level if whose own branch exits;
 * - anchor: anywhere outside functions. It only marks the end of the pinned
 *   code, so it is allowed only on a page that claims no gate.
 */
const SELF_GATED = [
    'auth_login.php' => [
        'anonymous-allowed',
        "db_execute_prepared('INSERT IGNORE INTO user_log\n\t\t\t(username, user_id, result, ip, time)\n\t\t\tVALUES (?, ?, 1, ?, NOW())', array(\$username, \$user['id'], \$client_addr))",
        'login handler; include/auth.php includes it after bootstrap, so a direct request stops at the first undefined function',
        'anchor',
    ],
    'auth_changepassword.php' => [
        'authenticated',
        "!isset(\$_SESSION['sess_user_id'])",
        'own session check; action=checkpass answers anonymously with the password policy verdict',
        'refusal',
    ],
    'csp_report.php' => [
        'anonymous-allowed',
        "require_once(__DIR__ . '/lib/csp_report_endpoint.php')",
        'CSP violation report sink; browsers post reports without credentials',
        'anchor',
    ],
    'link.php' => [
        'realm:10000+id',
        "is_realm_allowed(\$page['id'] + 10000)",
        'own realm check per external link id',
        'admission',
    ],
    'remote_agent.php' => [
        'anonymous-allowed',
        '!remote_client_authorized()',
        'no user session; remote_client_authorized() admits registered poller addresses only',
        'refusal',
    ],
    'service_check.php' => [
        'anonymous-allowed',
        "db_fetch_cell('SELECT cacti FROM version')",
        'service probe; prints success or fail for the schema version only',
        'anchor',
    ],
];

// Reachable without the gate they need. Each is reported, not fixed, here,
// and must leave this list in the pull request that adds the gate.
const UNGATED = [];

// Symfony routes that deliberately answer without an actor.
const ANONYMOUS_ROUTES = [
    'health' => 'liveness probe; returns a fixed status document',
];
// Actions that read the actor without the guard shape, traced by hand. The
// detail pins the action body, so an edit to it shows as drift.
const REVIEWED_ROUTES = [
    'session' => 'answers 401 with no identity when the actor is null',
];

const BOOTSTRAP = [
    'include/auth.php' => 'auth',
    'include/global.php' => 'global',
    'include/cli_check.php' => 'cli',
    'install/cli_check.php' => 'cli',
    'config/bootstrap.php' => 'symfony',
    'public/index.php' => 'symfony',
];

// Includes built at run time that were traced by hand, each pinned to the
// variable it includes, so a new dynamic include still reports unknown.
// global_languages.php loads the provider files get_src_language_files()
// lists; the handler comes from settings, never the request.
const REVIEWED_INCLUDES = [
    'include/global_languages.php' => 'providerFull',
];
// Fragment requires that end a direct request, traced by hand: the path is
// built from $config, which only the bootstrap defines, so without it the
// require names a file under / and PHP stops. Nothing after it runs.
const HALTING_REQUIRES = [
    'include/csrf.php' => 'include/vendor/csrf/csrf-conf.php',
];

const SIDE_EFFECT_CALLS = [
    'file_put_contents', 'fputs', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'chown', 'symlink',
    'move_uploaded_file', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'mail',
    'mb_send_mail', 'setcookie', 'assert', 'unserialize',
];
const SIDE_EFFECT_PREFIXES = ['db_execute', 'db_insert', 'db_replace'];
// Builtins a fragment may call at its top level: they read, format or
// compare, or set response headers and the locale for this request only.
const PURE_CALLS = [
    'array_combine', 'array_key_exists', 'array_keys', 'array_values', 'asort', 'basename', 'date', 'define',
    'defined', 'dir', 'explode', 'extension_loaded', 'file_exists', 'function_exists', 'gethostname', 'header',
    'http_response_code', 'ini_get', 'is_array', 'is_dir', 'is_executable', 'is_string', 'php_sapi_name', 'range',
    'setlocale', 'sprintf', 'str_replace', 'strpos', 'strstr', 'strtotime', 'ucwords', 'version_compare',
];
// Calls that run whatever callable they are given, so the name alone says
// nothing about the effect.
const CALLBACK_CALLS = [
    'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'array_map',
    'array_walk', 'array_walk_recursive', 'array_filter', 'array_reduce', 'usort', 'uasort', 'uksort',
    'iterator_apply', 'preg_replace_callback', 'preg_replace_callback_array', 'register_shutdown_function',
    'register_tick_function', 'spl_autoload_register', 'set_error_handler', 'set_exception_handler', 'ob_start',
    'session_set_save_handler',
];
// Object and callback calls at a fragment's top level that were traced by
// hand, each matched as a whole node, with the reason it is harmless.
const REVIEWED_FRAGMENT_CALLS = [
    'include/global_session.php' => [
        ['CactiSecureHeaders::getNonceAttribute()', 'returns the per-request CSP nonce attribute; generates the nonce once and stores nothing'],
        ['CactiSecureHeaders::getNonce()', 'returns the same per-request CSP nonce'],
    ],
    'include/global_languages.php' => [
        ['get_list_of_locales()', 'declared in the same file; returns a literal locale map'],
    ],
    'include/global_settings.php' => [
        ['$dir->read()', 'lists the theme directory opened by dir() on a fixed path'],
        ['$dir->close()', 'closes that directory handle'],
    ],
    'include/session.php' => [
        ["session_set_save_handler('cacti_db_session_open', 'cacti_db_session_close', 'cacti_db_session_read', 'cacti_db_session_write', 'cacti_db_session_destroy', 'cacti_db_session_clean')", 'names the database session handlers declared in the same file; they run only once a session starts'],
        ["register_shutdown_function('session_write_close')", 'writes an open session back at shutdown; a fragment requested alone has none'],
    ],
];

const INCLUDE_PREFIXES = ['base_path' => '', 'include_path' => 'include', 'library_path' => 'lib'];

const SUPERGLOBALS = ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'];

const ROUTE_ATTRIBUTES = ['Symfony\Component\Routing\Attribute\Route', 'Symfony\Component\Routing\Annotation\Route'];
const ACCESS_CHECKS = ['consoleActor', 'canManageDevices'];
const SESSION_ADAPTER = 'Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuthenticatedSession';
// The IdentityAccess types whose check methods count as a gate. The adapter
// is the only implementation, and the realms it checks label the route.
const ACCESS_TYPES = [
    'Kadupul\IdentityAccess\Contract\ConsoleAccess',
    'Kadupul\IdentityAccess\Application\Port\AuthenticatedSession',
    SESSION_ADAPTER,
];
// Service methods and value parsers an action may call before the service
// that guards it: each reads the request, translates, builds a URL, or
// validates plain values, and none reaches a repository.
const PURE_METHODS = [
    'Symfony\Component\HttpFoundation\Request' => ['isMethod', 'getRequestFormat'],
    'Symfony\Contracts\Translation\TranslatorInterface' => ['trans'],
    'Symfony\Component\Routing\Generator\UrlGeneratorInterface' => ['generate'],
];
// Responses whose constructor only stores its arguments. StreamedResponse
// and BinaryFileResponse run a callback or read a file while the response is
// sent, so building one ahead of a guard is not pure.
const PURE_RESPONSES = [
    'Symfony\Component\HttpFoundation\Response',
    'Symfony\Component\HttpFoundation\JsonResponse',
    'Symfony\Component\HttpFoundation\RedirectResponse',
];
const PURE_STATIC_CALLS = [
    'Kadupul\Inventory\Infrastructure\Symfony\DeviceListParameters' => ['parse', 'context'],
    'Kadupul\Inventory\Infrastructure\Symfony\SiteListParameters' => ['parse', 'context'],
    'Kadupul\Inventory\Domain\DeviceSelection' => ['validateIds'],
    'Kadupul\Inventory\Domain\SiteSelection' => ['validateIds'],
];
// Controller, use case, adapter.
const CALL_DEPTH = 3;

final class TreeError extends RuntimeException {}

function fail(string $message): never
{
    throw new TreeError($message);
}

/**
 * @return list<Stmt>|null null when the file does not parse
 */
function parse_file(string $root, string $path, bool $names = false): ?array
{
    static $cache = [];
    $key = ($names ? 'n:' : 'p:') . $root . '/' . $path;
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = null;
        $code = is_file($root . '/' . $path) ? file_get_contents($root . '/' . $path) : false;
        if ($code !== false) {
            try {
                $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
                if ($names) {
                    $traverser = new NodeTraverser(new NameResolver());
                    $stmts = $traverser->traverse($stmts);
                }
                $cache[$key] = $stmts;
            } catch (ParseError) {
                $cache[$key] = null;
            }
        }
    }

    return $cache[$key];
}

/**
 * The statements that run when the file is requested, in order. A namespace
 * declared with a semicolon wraps the rest of the file, so its body is
 * lifted to the top level; a braced namespace is left as one statement.
 *
 * @param list<Stmt> $stmts
 * @return list<Stmt>
 */
function top_level(array $stmts): array
{
    $out = [];
    foreach ($stmts as $stmt) {
        if ($stmt instanceof Stmt\Namespace_ && $stmt->getAttribute('kind') === Stmt\Namespace_::KIND_SEMICOLON) {
            $header = clone $stmt;
            $header->stmts = [];
            $out[] = $header;
            array_push($out, ...top_level($stmt->stmts));
        } else {
            $out[] = $stmt;
        }
    }

    return $out;
}

/**
 * The file's top-level statements, or null when it does not parse. PHP skips
 * a leading shebang line, so it is not output.
 *
 * @return list<Stmt>|null
 */
function program(string $root, string $path): ?array
{
    $stmts = parse_file($root, $path);
    if ($stmts === null) {
        return null;
    }
    $stmts = top_level($stmts);
    if ($stmts !== [] && $stmts[0] instanceof Stmt\InlineHTML && preg_match('/\A#![^\n]*\n?\z/', $stmts[0]->value) === 1) {
        array_shift($stmts);
    }

    return $stmts;
}

/**
 * Every node under $nodes. Function and class bodies are skipped unless
 * $declarations is set, because they do not run when the file is requested.
 *
 * @return iterable<Node>
 */
function walk(mixed $nodes, bool $declarations = true): iterable
{
    if (is_array($nodes)) {
        foreach ($nodes as $node) {
            yield from walk($node, $declarations);
        }
        return;
    }
    if (!$nodes instanceof Node) {
        return;
    }
    yield $nodes;
    if (!$declarations && ($nodes instanceof Node\FunctionLike || $nodes instanceof Stmt\ClassLike)) {
        return;
    }
    foreach ($nodes->getSubNodeNames() as $name) {
        yield from walk($nodes->$name, $declarations);
    }
}

function same_node(mixed $a, mixed $b): bool
{
    if ($a instanceof Node) {
        if (!$b instanceof Node || get_class($a) !== get_class($b)) {
            return false;
        }
        foreach ($a->getSubNodeNames() as $name) {
            if (!same_node($a->$name, $b->$name)) {
                return false;
            }
        }
        return true;
    }
    if (is_array($a)) {
        if (!is_array($b) || array_keys($a) !== array_keys($b)) {
            return false;
        }
        foreach ($a as $key => $value) {
            if (!same_node($value, $b[$key])) {
                return false;
            }
        }
        return true;
    }

    return $a === $b;
}

/**
 * The statements as the printer writes them with comments and source
 * spelling dropped, so only a change to the code alters the digest.
 *
 * @param list<Stmt> $stmts
 */
function digest(array $stmts): string
{
    $strip = new class extends NodeVisitorAbstract {
        public function enterNode(Node $node)
        {
            $node->setAttributes([]);
            return null;
        }
    };
    $copy = (new NodeTraverser(new CloningVisitor(), $strip))->traverse($stmts);

    return substr(hash('sha256', (new Standard())->prettyPrint($copy)), 0, 12);
}

function call_name(Node $node): ?string
{
    if (($node instanceof Expr\FuncCall) && $node->name instanceof Name && !$node->isPartialFunctionApplication()) {
        return $node->name->toLowerString();
    }

    return null;
}

/**
 * Positional arguments, or null when any is named, spread or by reference.
 *
 * @return list<Expr>|null
 */
function plain_args(Expr\CallLike $call): ?array
{
    if ($call->isPartialFunctionApplication()) {
        return null;
    }
    $args = [];
    foreach ($call->args as $arg) {
        if (!$arg instanceof Node\Arg || $arg->name !== null || $arg->unpack || $arg->byRef) {
            return null;
        }
        $args[] = $arg->value;
    }

    return $args;
}

/**
 * @param list<callable(Expr): bool> $shapes one per argument
 */
function is_call(Node $node, string $name, array $shapes = []): bool
{
    if (call_name($node) !== $name) {
        return false;
    }
    $args = plain_args($node);
    if ($args === null || count($args) !== count($shapes)) {
        return false;
    }
    foreach ($shapes as $i => $shape) {
        if (!$shape($args[$i])) {
            return false;
        }
    }

    return true;
}

function is_string_node(Node $node, ?string $pattern = null): bool
{
    return $node instanceof Scalar\String_ && ($pattern === null || preg_match($pattern, $node->value) === 1);
}

function is_const(Node $node, string ...$names): bool
{
    return $node instanceof Expr\ConstFetch && in_array($node->name->toLowerString(), $names, true);
}

function is_variable(Node $node, ?string $name = null): bool
{
    return $node instanceof Expr\Variable && is_string($node->name) && ($name === null || $node->name === $name);
}

// A value PHP computes without calling anything: scalars, constants and
// arrays or operators over them. Interpolated strings are excluded because
// "{$f()}" calls $f.
function is_literal(Node $node): bool
{
    if ($node instanceof Scalar\String_ || $node instanceof Scalar\Int_ || $node instanceof Scalar\Float_
        || $node instanceof Scalar\MagicConst || $node instanceof Expr\ConstFetch) {
        return true;
    }
    if ($node instanceof Expr\UnaryMinus || $node instanceof Expr\UnaryPlus) {
        return is_literal($node->expr);
    }
    if ($node instanceof Expr\BinaryOp) {
        return is_literal($node->left) && is_literal($node->right);
    }
    if ($node instanceof Expr\Array_) {
        foreach ($node->items as $item) {
            if ($item === null || $item->byRef || $item->unpack || !is_literal($item->value)
                || ($item->key !== null && !is_literal($item->key))) {
                return false;
            }
        }
        return true;
    }

    return false;
}

// $name or $name[<literal>]...; superglobals are request and session state,
// not scratch variables.
function is_assignable(Node $node): bool
{
    while ($node instanceof Expr\ArrayDimFetch) {
        if ($node->dim !== null && !is_literal($node->dim)) {
            return false;
        }
        $node = $node->var;
    }

    return is_variable($node) && !in_array($node->name, SUPERGLOBALS, true);
}

function expression_of(Stmt $stmt): ?Expr
{
    return $stmt instanceof Stmt\Expression ? $stmt->expr : null;
}

function is_declaration(Stmt $stmt): bool
{
    return $stmt instanceof Stmt\Function_ || $stmt instanceof Stmt\ClassLike;
}

function inert_statement(Stmt $stmt, string $root, string $source): bool
{
    if (is_declaration($stmt) || $stmt instanceof Stmt\Use_ || $stmt instanceof Stmt\GroupUse
        || $stmt instanceof Stmt\Global_ || $stmt instanceof Stmt\Nop) {
        return true;
    }
    if ($stmt instanceof Stmt\Namespace_) {
        return $stmt->stmts === [] && $stmt->getAttribute('kind') === Stmt\Namespace_::KIND_SEMICOLON;
    }
    if ($stmt instanceof Stmt\Declare_) {
        return $stmt->stmts === null;
    }
    if ($stmt instanceof Stmt\InlineHTML) {
        return trim($stmt->value) === '';
    }
    $expr = expression_of($stmt);
    if ($expr === null) {
        return false;
    }
    if ($expr instanceof Expr\Assign) {
        return is_assignable($expr->var) && is_literal($expr->expr);
    }
    if (call_name($expr) === 'define') {
        $args = plain_args($expr);
        return $args !== null && in_array(count($args), [2, 3], true) && is_string_node($args[0])
            && is_literal($args[1]) && (count($args) === 2 || is_literal($args[2]));
    }
    if (call_name($expr) === 'error_reporting') {
        $args = plain_args($expr);
        return $args !== null && count($args) <= 1 && ($args === [] || is_literal($args[0]));
    }
    if ($expr instanceof Expr\Include_) {
        // Upgrade steps pull in function libraries through $config before
        // declaring their own function; that is still declaration only.
        $target = resolve($expr->expr, $root, $source);
        return $target !== null && inert_file($root, $target);
    }

    return false;
}

/**
 * Variables the nodes may rebind, or ['*' => true] when a variable variable
 * could rebind any of them. Array element writes count, which is stricter
 * than needed but keeps the list simple.
 *
 * @return array<string, true>
 */
function rebound(mixed $nodes, bool $declarations): array
{
    $names = [];
    foreach (walk($nodes, $declarations) as $node) {
        if ($node instanceof Expr\Variable && !is_string($node->name)) {
            return ['*' => true];
        }
        $targets = match (true) {
            $node instanceof Expr\Assign, $node instanceof Expr\AssignRef, $node instanceof Expr\AssignOp => [$node->var],
            $node instanceof Stmt\Foreach_ => [$node->valueVar, $node->keyVar],
            $node instanceof Stmt\Global_, $node instanceof Stmt\Unset_ => $node->vars,
            $node instanceof Stmt\Static_ => array_map(fn(Node\StaticVar $v) => $v->var, $node->vars),
            $node instanceof Stmt\Catch_ => [$node->var],
            $node instanceof Expr\ClosureUse && $node->byRef => [$node->var],
            default => [],
        };
        foreach (walk($targets, $declarations) as $inner) {
            if (is_variable($inner)) {
                $names[$inner->name] = true;
            }
        }
    }

    return $names;
}

function inert_file(string $root, string $path): bool
{
    static $cache = [];
    $key = $root . '/' . $path;
    if (!array_key_exists($key, $cache)) {
        // A file that includes itself is not proven inert by the recursion.
        $cache[$key] = false;
        $stmts = program($root, $path);
        $cache[$key] = $stmts !== null && inert_statements($stmts, $root, $path);
    }

    return $cache[$key];
}

/**
 * @param list<Stmt> $stmts
 */
function inert_statements(array $stmts, string $root, string $source): bool
{
    foreach ($stmts as $stmt) {
        if (!inert_statement($stmt, $root, $source)) {
            return false;
        }
    }

    return true;
}

/*
 * Reviewed setup that may precede a gate: output buffering, the working
 * directory, refusals that only exit, the installer's function library, the
 * JSON response flag, and process setup in long-running CLI tools. None of
 * them acts on the request.
 */
function preamble_statement(Stmt $stmt): bool
{
    $expr = expression_of($stmt);
    if ($expr !== null) {
        $dir = fn(Expr $e) => $e instanceof Scalar\MagicConst\Dir
            || ($e instanceof Expr\BinaryOp\Concat && $e->left instanceof Scalar\MagicConst\Dir && is_string_node($e->right));
        $int = fn(Expr $e) => $e instanceof Scalar\Int_;
        $string = fn(Expr $e) => is_string_node($e);
        $setting = fn(Expr $e) => is_string_node($e, '/\A\w+\z/');
        if (is_call($expr, 'ob_start') || is_call($expr, 'chdir', [$dir])
            || is_call($expr, 'set_request_var', [fn(Expr $e) => is_string_node($e, '/\Ajson\z/'), fn(Expr $e) => is_const($e, 'true')])
            || is_call($expr, 'ini_set', [$setting, $string]) || is_call($expr, 'set_time_limit', [$int])) {
            return true;
        }
        // The installer's function library, traced by hand.
        if ($expr instanceof Expr\Include_ && $expr->type === Expr\Include_::TYPE_INCLUDE_ONCE
            && is_string_node($expr->expr, '/\Alib\/functions\.php\z/')) {
            return true;
        }
        if ($expr instanceof Expr\Assign && is_variable($expr->var) && !in_array($expr->var->name, SUPERGLOBALS, true)
            && (is_call($expr->expr, 'gethostname') || is_call($expr->expr, 'ini_get', [$setting]))) {
            return true;
        }
        return false;
    }
    if (!$stmt instanceof Stmt\If_) {
        return false;
    }
    if (is_pcntl_setup($stmt)) {
        return true;
    }
    if ($stmt->elseifs !== [] || $stmt->else !== null || !is_refusal_condition($stmt->cond)) {
        return false;
    }
    // Headers and a status, then exit, and nothing else.
    $body = $stmt->stmts;
    $last = array_pop($body);
    if ($last === null || !(expression_of($last) instanceof Expr\Exit_) || expression_of($last)->expr !== null) {
        return false;
    }
    foreach ($body as $line) {
        $call = expression_of($line);
        if ($call === null || !(is_call($call, 'header', [fn(Expr $e) => is_string_node($e)])
            || is_call($call, 'http_response_code', [fn(Expr $e) => $e instanceof Scalar\Int_]))) {
            return false;
        }
    }

    return true;
}

// Only the request checks the tree uses today:
// ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' and !is_string($_POST['k'] ?? null).
function is_refusal_condition(Expr $cond): bool
{
    $field = fn(Node $n, string $global, string $key) => $n instanceof Expr\BinaryOp\Coalesce
        && $n->left instanceof Expr\ArrayDimFetch && is_variable($n->left->var, $global)
        && $n->left->dim !== null && is_string_node($n->left->dim, $key);
    if ($cond instanceof Expr\BinaryOp\NotIdentical) {
        return $field($cond->left, '_SERVER', '/\AREQUEST_METHOD\z/') && is_string_node($cond->left->right, '/\A\z/')
            && is_string_node($cond->right, '/\A[A-Z]+\z/');
    }

    return $cond instanceof Expr\BooleanNot && is_call($cond->expr, 'is_string', [
        fn(Expr $e) => $field($e, '_POST', '/\A\w+\z/') && is_const($e->right, 'null'),
    ]);
}

// if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true); } else { declare(ticks = N); }
function is_pcntl_setup(Stmt\If_ $stmt): bool
{
    if (!is_call($stmt->cond, 'function_exists', [fn(Expr $e) => is_string_node($e, '/\Apcntl_async_signals\z/')])
        || $stmt->elseifs !== [] || $stmt->else === null || count($stmt->stmts) !== 1 || count($stmt->else->stmts) !== 1) {
        return false;
    }
    $call = expression_of($stmt->stmts[0]);
    $declare = $stmt->else->stmts[0];

    return $call !== null && is_call($call, 'pcntl_async_signals', [fn(Expr $e) => is_const($e, 'true')])
        && $declare instanceof Stmt\Declare_ && $declare->stmts === null && count($declare->declares) === 1
        && $declare->declares[0]->key->toLowerString() === 'ticks' && $declare->declares[0]->value instanceof Scalar\Int_;
}

// if (php_sapi_name() !== 'cli') { [http_response_code(N);] exit|die[(literal)]; }
function is_cli_guard(Stmt $stmt): bool
{
    if (!$stmt instanceof Stmt\If_ || $stmt->elseifs !== [] || $stmt->else !== null) {
        return false;
    }
    $cond = $stmt->cond;
    if (!($cond instanceof Expr\BinaryOp\NotIdentical || $cond instanceof Expr\BinaryOp\NotEqual)
        || !is_call($cond->left, 'php_sapi_name') || !is_string_node($cond->right, '/\Acli\z/')) {
        return false;
    }
    $body = $stmt->stmts;
    $last = array_pop($body);
    $exit = $last === null ? null : expression_of($last);
    if (!$exit instanceof Expr\Exit_ || ($exit->expr !== null && !is_literal($exit->expr))) {
        return false;
    }
    if (count($body) === 1) {
        $status = expression_of($body[0]);
        return $status !== null && is_call($status, 'http_response_code', [fn(Expr $e) => $e instanceof Scalar\Int_]);
    }

    return $body === [];
}

/**
 * Resolves a static include expression to a repository path, or null.
 */
function resolve(Expr $expr, string $root, string $source): ?string
{
    $parts = [];
    $flatten = function (Expr $e) use (&$flatten, &$parts): void {
        if ($e instanceof Expr\BinaryOp\Concat) {
            $flatten($e->left);
            $flatten($e->right);
        } else {
            $parts[] = $e;
        }
    };
    $flatten($expr);
    $here = dirname($source) === '.' ? '' : dirname($source);
    $base = null;
    $literal = '';
    foreach ($parts as $part) {
        if ($part instanceof Scalar\String_) {
            $literal .= $part->value;
        } elseif ($part instanceof Scalar\MagicConst\Dir || is_call($part, 'dirname', [fn(Expr $e) => $e instanceof Scalar\MagicConst\File])) {
            $base = $here;
        } elseif (is_call($part, 'dirname', [fn(Expr $e) => $e instanceof Scalar\MagicConst\Dir])) {
            $base = dirname($here) === '.' ? '' : dirname($here);
        } elseif ($part instanceof Expr\ArrayDimFetch && is_variable($part->var, 'config') && $part->dim !== null
            && is_string_node($part->dim) && array_key_exists($part->dim->value, INCLUDE_PREFIXES)) {
            $base = INCLUDE_PREFIXES[$part->dim->value];
        } else {
            return null;
        }
    }
    if ($literal === '') {
        return null;
    }
    // A relative path is tried against the document root first, where
    // pages run, then the including file's directory.
    $candidates = $base !== null ? [$base] : ['', $here];
    foreach ($candidates as $i => $start) {
        $stack = [];
        foreach (explode('/', $start . '/' . $literal) as $segment) {
            if ($segment === '..') {
                array_pop($stack);
            } elseif ($segment !== '' && $segment !== '.') {
                $stack[] = $segment;
            }
        }
        $path = implode('/', $stack);
        if (is_file($root . '/' . $path) || $i === count($candidates) - 1) {
            return $path;
        }
    }

    return null;
}

/**
 * @param list<string> $files
 * @return array<string, list<string>>
 */
function includers(string $root, array $files): array
{
    $found = [];
    foreach ($files as $path) {
        if (preg_match('#\A(?:tests|include/vendor|docs)/#', $path)) {
            continue;
        }
        $stmts = parse_file($root, $path);
        foreach ($stmts === null ? [] : walk($stmts) as $node) {
            if ($node instanceof Expr\Include_) {
                $target = resolve($node->expr, $root, $path);
                if ($target !== null && $target !== $path) {
                    $found[$target][$path] = true;
                }
            }
        }
    }

    return array_map(fn(array $by) => array_keys($by), $found);
}

/**
 * The files that declare each named function, keyed by lower-case name.
 *
 * @param list<string> $files
 * @return array<string, list<string>>
 */
function declared_functions(string $root, array $files): array
{
    $found = [];
    foreach ($files as $path) {
        foreach (walk(parse_file($root, $path, true) ?? []) as $node) {
            if ($node instanceof Stmt\Function_) {
                $found[strtolower($node->namespacedName?->toString() ?? $node->name->toString())][] = $path;
            }
        }
    }

    return $found;
}

/**
 * The file and every file it includes by a path the generator can resolve,
 * or null when one of them includes a path it cannot.
 *
 * @return list<string>|null
 */
function reached_files(string $root, string $path): ?array
{
    $seen = [$path => true];
    $queue = [$path];
    while ($queue !== []) {
        $current = array_shift($queue);
        foreach (walk(parse_file($root, $current) ?? []) as $node) {
            if (!$node instanceof Expr\Include_) {
                continue;
            }
            $target = resolve($node->expr, $root, $current);
            $reviewed = REVIEWED_INCLUDES[$current] ?? null;
            if ($target === null && !($reviewed !== null && is_variable($node->expr, $reviewed))) {
                return null;
            }
            if ($target !== null && !isset($seen[$target])) {
                $seen[$target] = true;
                $queue[] = $target;
            }
        }
    }

    return array_keys($seen);
}

/**
 * @return array<string, int>
 */
function realm_map(string $root): array
{
    $stmts = parse_file($root, 'include/global_arrays.php');
    if ($stmts === null) {
        fail('include/global_arrays.php does not parse');
    }
    $arrays = [];
    foreach (walk($stmts, false) as $node) {
        if ($node instanceof Expr\Assign && is_variable($node->var, 'user_auth_realm_filenames')) {
            $arrays[] = $node->expr;
        }
    }
    if (count($arrays) !== 1 || !$arrays[0] instanceof Expr\Array_) {
        fail('$user_auth_realm_filenames is not assigned one array literal in include/global_arrays.php');
    }
    $realms = [];
    foreach ($arrays[0]->items as $item) {
        $value = $item?->value;
        $realm = $value instanceof Scalar\Int_ ? $value->value
            : ($value instanceof Expr\UnaryMinus && $value->expr instanceof Scalar\Int_ ? -$value->expr->value : null);
        // Any other element would silently become realm:0.
        if ($item === null || $item->byRef || $item->unpack || $item->key === null || !is_string_node($item->key) || $realm === null) {
            fail('unparsed entry in $user_auth_realm_filenames on line ' . ($item?->getStartLine() ?? '?'));
        }
        $realms[$item->key->value] = $realm;
    }

    return $realms;
}

/**
 * @return list<string>
 */
function auth_early_returns(string $root): array
{
    $pages = [];
    foreach (program($root, 'include/auth.php') ?? [] as $stmt) {
        if ($stmt instanceof Stmt\If_ && $stmt->elseifs === [] && $stmt->else === null
            && $stmt->cond instanceof Expr\BinaryOp\Equal && is_call($stmt->cond->left, 'get_current_page')
            && is_string_node($stmt->cond->right) && count($stmt->stmts) === 1
            && $stmt->stmts[0] instanceof Stmt\Return_ && $stmt->stmts[0]->expr !== null && is_const($stmt->stmts[0]->expr, 'true')) {
            $pages[] = $stmt->cond->right->value;
        }
    }

    return $pages;
}

/**
 * True when the statements reach exit, return or throw. At a page's top level
 * each of them ends the request. A break, continue or goto ahead of it, even
 * nested, can leave the block first, so it does not count.
 *
 * @param list<Stmt> $stmts
 */
function exits(array $stmts): bool
{
    foreach ($stmts as $stmt) {
        $expr = expression_of($stmt);
        if ($stmt instanceof Stmt\Return_ || $expr instanceof Expr\Exit_ || $expr instanceof Expr\Throw_) {
            return true;
        }
        foreach (walk($stmt, false) as $node) {
            if ($node instanceof Stmt\Break_ || $node instanceof Stmt\Continue_ || $node instanceof Stmt\Goto_) {
                return false;
            }
        }
    }

    return false;
}

function refusal_if(?Stmt $stmt, Expr $check): bool
{
    return $stmt instanceof Stmt\If_ && $stmt->elseifs === [] && $stmt->else === null
        && same_node($stmt->cond, $check) && exits($stmt->stmts);
}

function admission_if(?Stmt $stmt, Expr $check): bool
{
    return $stmt instanceof Stmt\If_ && $stmt->elseifs === [] && $stmt->else !== null
        && same_node($stmt->cond, $check) && exits($stmt->else->stmts);
}

function guards(Stmt $stmt, Expr $check, string $shape): bool
{
    if ($shape === 'anchor') {
        foreach (walk($stmt, false) as $node) {
            if (same_node($node, $check)) {
                return true;
            }
        }
        return false;
    }
    if ($shape === 'refusal') {
        if (refusal_if($stmt, $check)) {
            return true;
        }
        // Without a default case an unmatched value skips the whole switch,
        // so the guard must open the default and every other case must exit.
        if (!$stmt instanceof Stmt\Switch_) {
            return false;
        }
        $guarded = false;
        foreach ($stmt->cases as $case) {
            $body = array_values(array_filter($case->stmts, fn(Stmt $s) => !$s instanceof Stmt\Nop));
            if ($case->cond === null && refusal_if($body[0] ?? null, $check)) {
                $guarded = true;
            } elseif (!exits($case->stmts)) {
                return false;
            }
        }
        return $guarded;
    }
    if ($shape === 'admission') {
        if (admission_if($stmt, $check)) {
            return true;
        }
        $else = $stmt instanceof Stmt\If_ && $stmt->elseifs === [] && $stmt->else !== null && exits($stmt->stmts)
            ? $stmt->else->stmts : [];
        return $else !== [] && admission_if($else[count($else) - 1], $check);
    }
    fail('unknown self-gated shape ' . $shape);
}

/**
 * @param list<Stmt> $stmts
 * @return array{0: string, 1: string}|null
 */
function self_gated(string $path, array $stmts): ?array
{
    [$gate, $fingerprint, $reason, $shape] = SELF_GATED[$path];
    if ($shape === 'anchor' && $gate !== 'anonymous-allowed') {
        fail($path . ': an anchor proves no gate, so it cannot label ' . $gate);
    }
    $parsed = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $fingerprint . ';');
    $check = $parsed[0]->expr;
    foreach ($stmts as $i => $stmt) {
        if (!is_declaration($stmt) && guards($stmt, $check, $shape)) {
            return [$gate, $reason . '; reviewed at ' . digest(array_slice($stmts, 0, $i + 1))];
        }
    }

    return ['unknown', 'self-gated check not found as a ' . $shape . ': ' . $fingerprint];
}

/**
 * @param list<Stmt> $before
 */
function preamble_clean(array $before, string $root, string $path): bool
{
    foreach ($before as $stmt) {
        if (!inert_statement($stmt, $root, $path) && !preamble_statement($stmt)) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, int> $realms
 * @param list<string> $early
 * @param array<string, list<string>> $includers
 * @return array{0: string, 1: string}
 */
function classify(string $root, string $path, array $realms, array $early, array $includers, array $functions): array
{
    $stmts = program($root, $path);
    if ($stmts === null) {
        return ['unknown', 'file does not parse'];
    }
    if (array_key_exists($path, UNGATED)) {
        return ['ungated', UNGATED[$path]];
    }
    if (array_key_exists($path, SELF_GATED)) {
        return self_gated($path, $stmts);
    }

    $before = [];
    foreach ($stmts as $stmt) {
        if (is_declaration($stmt)) {
            continue;
        }
        $kind = null;
        foreach (walk($stmt) as $node) {
            if ($node instanceof Expr\Include_) {
                $target = resolve($node->expr, $root, $path);
                $kind ??= BOOTSTRAP[$target] ?? null;
            }
        }
        // auth.php is the gate itself; its own bootstrap include starts it.
        if ($path === 'include/auth.php' && $kind === 'global') {
            $kind = 'auth';
        }
        $bare = expression_of($stmt) instanceof Expr\Include_;
        $guard = is_cli_guard($stmt);
        if ($kind === 'cli' || $guard) {
            // Same rule as the auth gate: unconditional, and nothing with an
            // effect ahead of it.
            if ($kind === 'cli' && !$bare) {
                return ['unknown', 'CLI include is not a bare top-level statement'];
            }
            if (!preamble_clean($before, $root, $path)) {
                return ['unknown', 'CLI guard follows statements with effects'];
            }
            return ['cli-only', 'guard: ' . ($guard ? 'php_sapi_name() check' : 'include/cli_check.php')];
        }
        if ($kind === 'auth') {
            if (!$bare) {
                return ['unknown', 'auth include is not a bare top-level statement'];
            }
            if (!preamble_clean($before, $root, $path)) {
                return ['unknown', 'statements with effects run before the auth include'];
            }
            return auth_gate($path, $stmts, $before, $realms, $early);
        }
        if ($kind === 'symfony') {
            $expr = expression_of($stmt);
            $kernel = $expr instanceof Expr\Assign && is_variable($expr->var) ? $expr->expr : $expr;
            if (!$kernel instanceof Expr\Include_ || !inert_statements($before, $root, $path)) {
                return ['unknown', 'Symfony bootstrap is not the first top-level statement with an effect'];
            }
            foreach (walk($stmts) as $node) {
                if ($node instanceof Scalar\String_ && preg_match('#app\.php(/[\w/.-]+)#', $node->value, $m)) {
                    return ['symfony:forward', 'forwards to app.php' . $m[1]];
                }
            }
            return ['symfony:front-controller', 'Symfony kernel; routes listed as app.php/... rows'];
        }
        if ($kind === 'global') {
            return ['unknown', 'bootstraps include/global.php without a known gate'];
        }
        $before[] = $stmt;
    }

    $active = [];
    foreach ($stmts as $stmt) {
        if (!inert_statement($stmt, $root, $path)) {
            $active[] = $stmt;
        }
    }
    if ($active === []) {
        return ['anonymous-allowed', 'inert: declarations and literal assignments only'];
    }
    if (is_redirect($active)) {
        return ['anonymous-allowed', 'redirect-only'];
    }
    if (array_key_exists($path, $includers)) {
        return fragment($root, $path, $active, $includers[$path], $functions);
    }

    return ['unknown', 'no gate recognised'];
}

/**
 * @param list<Stmt> $stmts
 * @param list<Stmt> $before
 * @param array<string, int> $realms
 * @param list<string> $early
 * @return array{0: string, 1: string}
 */
function auth_gate(string $path, array $stmts, array $before, array $realms, array $early): array
{
    // auth.php tests isset($guest_account), so any value but null opts in.
    $guest = false;
    foreach ($before as $stmt) {
        $expr = expression_of($stmt);
        if ($expr instanceof Expr\Assign && is_variable($expr->var, 'guest_account') && !is_const($expr->expr, 'null')) {
            $guest = true;
        }
    }
    $extras = $guest ? ['guest_account'] : [];
    foreach (['auth_json', 'auth_text'] as $flag) {
        foreach (walk($stmts, false) as $node) {
            if ($node instanceof Expr\Assign && is_variable($node->var, $flag) && is_const($node->expr, 'true')) {
                $extras[] = $flag;
                break;
            }
        }
    }
    $suffix = $extras === [] ? '' : '; ' . implode(', ', $extras);
    $name = basename($path);
    if (in_array($name, $early, true)) {
        return ['anonymous-allowed', 'include/auth.php returns before the session check' . $suffix];
    }
    // With a guest user configured, auth.php admits these pages as that
    // user before it looks up the realm.
    $prefix = $guest ? 'guest-or-' : '';
    $realm = $realms[$name] ?? 0;
    if ($realm === -1) {
        return [$prefix . 'authenticated', 'include/auth.php realm -1' . $suffix];
    }
    if ($realm === 0) {
        return [$prefix . 'realm:0', 'include/auth.php; unmapped page, denied to every account' . $suffix];
    }

    return [$prefix . 'realm:' . $realm, 'include/auth.php' . $suffix];
}

/**
 * @param list<Stmt> $active
 */
function is_redirect(array $active): bool
{
    $header = expression_of($active[0]);
    if (count($active) > 2 || $header === null
        || !is_call($header, 'header', [fn(Expr $e) => is_string_node($e, '/\Alocation\s*:/i')])) {
        return false;
    }
    if (count($active) === 2) {
        $exit = expression_of($active[1]);
        return $exit instanceof Expr\Exit_ && ($exit->expr === null || is_literal($exit->expr));
    }

    return true;
}

function reviewed_call(string $path, Node $node): bool
{
    foreach (REVIEWED_FRAGMENT_CALLS[$path] ?? [] as [$snippet]) {
        $parsed = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $snippet . ';');
        if (same_node($node, $parsed[0]->expr)) {
            return true;
        }
    }

    return false;
}

/**
 * Calls an expression makes whenever it is evaluated, in evaluation order.
 * Only the left side of a short-circuit operator and the condition of a
 * ternary always run.
 *
 * @return iterable<Expr\FuncCall>
 */
function always_calls(Expr $expr): iterable
{
    if ($expr instanceof Expr\FuncCall) {
        foreach (plain_args($expr) ?? [] as $arg) {
            yield from always_calls($arg);
        }
        yield $expr;
    } elseif ($expr instanceof Expr\BooleanNot || $expr instanceof Expr\Cast || $expr instanceof Expr\UnaryMinus) {
        yield from always_calls($expr->expr);
    } elseif ($expr instanceof Expr\BinaryOp\BooleanAnd || $expr instanceof Expr\BinaryOp\BooleanOr
        || $expr instanceof Expr\BinaryOp\LogicalAnd || $expr instanceof Expr\BinaryOp\LogicalOr
        || $expr instanceof Expr\BinaryOp\Coalesce) {
        yield from always_calls($expr->left);
    } elseif ($expr instanceof Expr\BinaryOp) {
        yield from always_calls($expr->left);
        yield from always_calls($expr->right);
    } elseif ($expr instanceof Expr\Assign || $expr instanceof Expr\AssignOp) {
        yield from always_calls($expr->expr);
    } elseif ($expr instanceof Expr\Ternary) {
        yield from always_calls($expr->cond);
    }
}

/**
 * A fragment is harmless on its own only while nothing at its top level
 * writes, executes, sends or loads code the generator cannot name. The sweep
 * separately checks it emits no content.
 *
 * Every named call must be a reviewed builtin, a reviewed call, or a function
 * declared only in files the fragment does not load. That last kind is
 * undefined on a direct request, so PHP stops there, and a top-level
 * statement that always reaches one ends the review: nothing after it runs.
 *
 * @param list<Stmt> $active
 * @param list<string> $by
 * @param array<string, list<string>> $functions
 * @return array{0: string, 1: string}
 */
function fragment(string $root, string $path, array $active, array $by, array $functions): array
{
    $reviewed = REVIEWED_INCLUDES[$path] ?? null;
    $reached = reached_files($root, $path);
    // A name nothing declares may belong to an extension the classifier
    // lacks, and a dynamic include may load its declaration, so neither stops.
    $stops = fn(string $name) => $reached !== null && ($functions[$name] ?? []) !== []
        && array_intersect($functions[$name], $reached) === [];
    foreach ($active as $stmt) {
        $head = $stmt instanceof Stmt\If_ ? $stmt->cond : ($stmt instanceof Stmt\Expression ? $stmt->expr : null);
        $halts = $head instanceof Expr\Include_ && in_array($head->type, [Expr\Include_::TYPE_REQUIRE, Expr\Include_::TYPE_REQUIRE_ONCE], true)
            && isset(HALTING_REQUIRES[$path]) && resolve($head->expr, $root, $path) === HALTING_REQUIRES[$path]
            && array_filter(iterator_to_array(walk($head->expr), false), fn(Node $n) => is_variable($n, 'config')) !== [];
        foreach ($halts || $head === null ? [] : always_calls($head) as $call) {
            $name = call_name($call);
            if ($name !== null && !function_exists($name) && $stops($name)) {
                $halts = true;
                break;
            }
        }
        foreach (walk($halts ? $head : $stmt) as $node) {
            if ($node instanceof Expr\Eval_) {
                return ['unknown', 'fragment calls eval'];
            }
            if ($node instanceof Expr\ShellExec) {
                return ['unknown', 'fragment runs a backtick command'];
            }
            if ($node instanceof Expr\Include_) {
                $target = resolve($node->expr, $root, $path);
                if ($target === null && !($reviewed !== null && is_variable($node->expr, $reviewed))) {
                    return ['unknown', 'fragment includes a path the generator cannot resolve'];
                }
                // The pin covers only this file, so what it loads must be
                // declarations that cannot change behaviour without drift here.
                if ($target !== null && !inert_file($root, $target) && !($halts && $node === $head)) {
                    return ['unknown', 'fragment includes ' . $target . ', whose top level runs code'];
                }
            }
            if ($node instanceof Expr\FuncCall && !$node->name instanceof Name) {
                return ['unknown', 'fragment makes a dynamic function call'];
            }
            // A method, static call or constructor runs code the name list
            // cannot see, and so does a function handed a callable.
            $object = $node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall
                || $node instanceof Expr\StaticCall || $node instanceof Expr\New_;
            $callback = in_array(call_name($node), CALLBACK_CALLS, true);
            if (($object || $callback) && !reviewed_call($path, $node)) {
                return ['unknown', 'fragment makes an unreviewed ' . ($object ? 'object' : 'callback') . ' call on line ' . $node->getStartLine()];
            }
            $name = call_name($node);
            if ($name === null) {
                continue;
            }
            $args = plain_args($node);
            $console = $name === 'fwrite' && $args !== null && $args !== [] && is_const($args[0], 'stderr', 'stdout');
            if (in_array($name, SIDE_EFFECT_CALLS, true) || ($name === 'fwrite' && !$console)
                || array_filter(SIDE_EFFECT_PREFIXES, fn(string $p) => str_starts_with($name, $p)) !== []) {
                return ['unknown', 'fragment has a top-level side effect: ' . $name . '()'];
            }
            if (in_array($name, PURE_CALLS, true) || $console || reviewed_call($path, $node)) {
                continue;
            }
            if (function_exists($name) || !$stops($name)) {
                return ['unknown', 'fragment makes an unreviewed call on line ' . $node->getStartLine() . ': ' . $name . '()'];
            }
        }
        if ($halts) {
            break;
        }
    }
    // A call list cannot prove a fragment harmless, so its top-level code is
    // pinned: any change to it shows up as drift and needs a fresh review.
    sort($by);

    return ['anonymous-allowed', 'fragment without bootstrap, reviewed at ' . digest($active)
        . '; included by ' . implode(', ', array_slice($by, 0, 3))];
}

/*
 * Symfony routes. Each #[Route] is credited only with the IdentityAccess
 * checks its own action reaches: calls in the action body, then the methods
 * it invokes on typed parameters, $this->property services and $this, one
 * class file at a time. A check elsewhere in a used class does not count.
 */

function load_class(string $root, string $name): ?Stmt\ClassLike
{
    static $cache = [];
    $key = $root . '|' . $name;
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = null;
        if (str_starts_with($name, 'Kadupul\\')) {
            $path = 'src/' . str_replace('\\', '/', substr($name, strlen('Kadupul\\'))) . '.php';
            foreach (walk(parse_file($root, $path, true) ?? []) as $node) {
                if ($node instanceof Stmt\ClassLike && $node->namespacedName?->toString() === $name) {
                    $cache[$key] = $node;
                    break;
                }
            }
        }
    }

    return $cache[$key];
}

function type_name(?Node $type): ?string
{
    if ($type instanceof Node\NullableType) {
        $type = $type->type;
    }

    return $type instanceof Name ? $type->toString() : null;
}

/**
 * @return array<string, string> property name => class
 */
function property_types(Stmt\ClassLike $class): array
{
    $types = [];
    foreach ($class->stmts as $member) {
        if ($member instanceof Stmt\Property && type_name($member->type) !== null) {
            foreach ($member->props as $prop) {
                $types[$prop->name->toString()] = type_name($member->type);
            }
        }
        if ($member instanceof Stmt\ClassMethod && $member->name->toLowerString() === '__construct') {
            foreach ($member->params as $param) {
                if ($param->flags !== 0 && is_variable($param->var) && type_name($param->type) !== null) {
                    $types[$param->var->name] = type_name($param->type);
                }
            }
        }
    }

    return $types;
}

function find_method(Stmt\ClassLike $class, string $method): ?Stmt\ClassMethod
{
    foreach ($class->stmts as $member) {
        if ($member instanceof Stmt\ClassMethod && $member->name->toLowerString() === strtolower($method)) {
            return $member;
        }
    }

    return null;
}

/**
 * The class a receiver holds inside one method: $this, a typed parameter or a
 * typed $this->property. $bound gives the class a caller passed for a
 * callable parameter. A parameter the body can rebind has no known class.
 *
 * @param array<string, string> $bound
 * @return Closure(Expr): ?string
 */
function receiver_types(string $root, string $class, Stmt\ClassMethod $method, array $bound = []): Closure
{
    $loaded = load_class($root, $class);
    $properties = $loaded === null ? [] : property_types($loaded);
    $written = rebound($method->stmts ?? [], true);
    $params = [];
    foreach ($method->params as $param) {
        $type = is_variable($param->var) ? (type_name($param->type) ?? $bound[$param->var->name] ?? null) : null;
        if ($type !== null && !$param->byRef && !isset($written[$param->var->name]) && !isset($written['*'])) {
            $params[$param->var->name] = $type;
        }
    }

    return function (Expr $receiver) use ($params, $properties, $class): ?string {
        if (is_variable($receiver, 'this')) {
            return $class;
        }
        if (is_variable($receiver)) {
            return $params[$receiver->name] ?? null;
        }
        if ($receiver instanceof Expr\PropertyFetch && is_variable($receiver->var, 'this') && $receiver->name instanceof Node\Identifier) {
            return $properties[$receiver->name->toString()] ?? null;
        }
        return null;
    };
}

/**
 * @return array{0: string, 1: string}|null class and method a call runs
 */
function call_target(Node $node, Closure $type_of): ?array
{
    if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
        $type = $type_of($node->var);
        return $type === null ? null : [$type, $node->name->toString()];
    }
    if ($node instanceof Expr\FuncCall && $node->name instanceof Expr) {
        // $useCase($criteria) runs the invokable's __invoke.
        $type = $type_of($node->name);
        return $type === null ? null : [$type, '__invoke'];
    }

    return null;
}

function is_access_call(?array $target, string $check): bool
{
    return $target !== null && in_array($target[0], ACCESS_TYPES, true) && $target[1] === $check;
}

/**
 * True when the expression is consoleActor(), or a call to a method whose
 * whole body returns one, such as the CurrentActor query.
 */
function yields_actor(string $root, Expr $expr, Closure $type_of, int $depth): bool
{
    $target = call_target($expr, $type_of);
    if (is_access_call($target, 'consoleActor')) {
        return true;
    }
    if ($target === null || $depth >= CALL_DEPTH) {
        return false;
    }
    $class = load_class($root, $target[0]);
    $callee = $class === null ? null : find_method($class, $target[1]);
    $body = $callee?->stmts ?? [];

    return count($body) === 1 && $body[0] instanceof Stmt\Return_ && $body[0]->expr !== null
        && yields_actor($root, $body[0]->expr, receiver_types($root, $target[0], $callee), $depth + 1);
}

/**
 * The variable an assignment of the console actor writes, or null.
 */
function actor_assignment(string $root, Stmt $stmt, Closure $type_of): ?string
{
    if (!$stmt instanceof Stmt\Expression || !$stmt->expr instanceof Expr\Assign || !is_variable($stmt->expr->var)) {
        return null;
    }

    return yields_actor($root, $stmt->expr->expr, $type_of, 0) ? $stmt->expr->var->name : null;
}

/**
 * @return list<Expr>
 */
function disjuncts(Expr $cond): array
{
    return $cond instanceof Expr\BinaryOp\BooleanOr ? [...disjuncts($cond->left), ...disjuncts($cond->right)] : [$cond];
}

function is_null_check(Expr $expr, string $var): bool
{
    if ($expr instanceof Expr\BinaryOp\Identical) {
        return (is_variable($expr->left, $var) && is_const($expr->right, 'null'))
            || (is_const($expr->left, 'null') && is_variable($expr->right, $var));
    }

    return ($expr instanceof Expr\BooleanNot && is_variable($expr->expr, $var))
        || is_call($expr, 'is_null', [fn(Expr $arg) => is_variable($arg, $var)]);
}

function is_device_denial(Expr $expr, string $var, Closure $type_of): bool
{
    if (!$expr instanceof Expr\BooleanNot || !$expr->expr instanceof Expr\CallLike) {
        return false;
    }
    $args = plain_args($expr->expr);

    return is_access_call(call_target($expr->expr, $type_of), 'canManageDevices')
        && $args !== null && count($args) === 1 && is_variable($args[0], $var);
}

/**
 * The condition of an if with no other branch whose body ends in throw, or in
 * a return $stops accepts, so a true condition stops the action.
 *
 * @param (Closure(?Expr): bool)|null $stops
 */
function refusal_guard(string $root, ?Stmt $stmt, ?Closure $stops, Closure $type_of): ?Expr
{
    if (!$stmt instanceof Stmt\If_ || $stmt->elseifs !== [] || $stmt->else !== null || $stmt->stmts === []) {
        return null;
    }
    // Nothing but the refusal itself may run for the caller being refused.
    $body = $stmt->stmts;
    $last = array_pop($body);
    $refusal = $last instanceof Stmt\Return_ && $stops !== null && $stops($last->expr) ? $last->expr
        : ($last instanceof Stmt\Expression && $last->expr instanceof Expr\Throw_ ? $last->expr->expr : false);

    return $refusal !== false && pure($root, $body, $type_of) && pure($root, $refusal, $type_of) ? $stmt->cond : null;
}

/**
 * Checks that guard everything the method does. consoleActor() counts when
 * its result is assigned at the method's top level, only literal assignments
 * come before it, and the next statement stops on a null actor; a negated
 * canManageDevices() of that variable counts in the same guard or the one
 * right after it. Only the action itself may stop with any return: a return
 * in a callee hands control back to the caller, so there only throw stops,
 * or a return the caller is known to refuse on (see refused_on()).
 *
 * @param list<Stmt> $stmts
 * @param (Closure(?Expr): bool)|null $stops
 * @return array<string, true>
 */
function guarded_checks(string $root, array $stmts, Closure $type_of, ?Closure $stops): array
{
    $list = array_values($stmts);
    foreach ($list as $i => $stmt) {
        $var = actor_assignment($root, $stmt, $type_of);
        if ($var === null) {
            $expr = expression_of($stmt);
            if ($expr instanceof Expr\Assign && is_variable($expr->var) && is_literal($expr->expr)) {
                continue;
            }
            return [];
        }
        // The null check comes first, so no other disjunct runs before it, and
        // the rest may only check the device grant or compute.
        $guard = refusal_guard($root, $list[$i + 1] ?? null, $stops, $type_of);
        $terms = $guard === null ? [] : disjuncts($guard);
        if ($terms === [] || !is_null_check($terms[0], $var)) {
            return [];
        }
        $checks = ['consoleActor' => true];
        $next = refusal_guard($root, $list[$i + 2] ?? null, $stops, $type_of);
        foreach ([$terms, $next === null ? [] : disjuncts($next)] as $group) {
            $denial = false;
            foreach ($group as $expr) {
                if (is_device_denial($expr, $var, $type_of)) {
                    $denial = true;
                } elseif (!is_null_check($expr, $var) && !pure($root, $expr, $type_of)) {
                    if ($group === $terms) {
                        return [];
                    }
                    $denial = false;
                    break;
                }
            }
            if ($denial) {
                $checks['canManageDevices'] = true;
            }
        }
        return $checks;
    }

    return [];
}

/**
 * True when every node under $nodes only reads, computes or builds a response,
 * so running it ahead of a guard changes nothing. Anything else, including a
 * call to a service the lists above do not name, is not pure.
 */
function pure(string $root, mixed $nodes, Closure $type_of): bool
{
    // Exception and Error declare getMessage() final, so on the variable a
    // catch binds it only reads the exception. Any use of that variable in the
    // catch body other than that call or a property read could rebind it, so
    // then no call there counts.
    $messages = [];
    foreach (walk($nodes, false) as $node) {
        if (!$node instanceof Stmt\Catch_ || $node->var === null || !is_string($node->var->name)) {
            continue;
        }
        $calls = [];
        $receivers = [];
        foreach (walk($node->stmts, false) as $inner) {
            if ($inner instanceof Expr\MethodCall && is_variable($inner->var, $node->var->name) && $inner->args === []
                && $inner->name instanceof Node\Identifier && $inner->name->toLowerString() === 'getmessage') {
                $calls[spl_object_id($inner)] = true;
                $receivers[spl_object_id($inner->var)] = true;
            } elseif (($inner instanceof Expr\PropertyFetch || $inner instanceof Expr\NullsafePropertyFetch)
                && is_variable($inner->var, $node->var->name)) {
                $receivers[spl_object_id($inner->var)] = true;
            }
        }
        foreach (walk($node->stmts, false) as $inner) {
            if (is_variable($inner, $node->var->name) && !isset($receivers[spl_object_id($inner)])) {
                continue 2;
            }
        }
        $messages += $calls;
    }
    foreach (walk($nodes, false) as $node) {
        if ($node instanceof Expr\Include_ || $node instanceof Expr\Eval_ || $node instanceof Expr\ShellExec
            || $node instanceof Expr\Exit_ || $node instanceof Expr\Print_ || $node instanceof Stmt\Echo_
            || $node instanceof Stmt\InlineHTML || $node instanceof Node\FunctionLike || $node instanceof Expr\Clone_
            || $node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom || $node instanceof Expr\AssignRef) {
            return false;
        }
        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp) {
            $var = $node->var;
            while ($var instanceof Expr\ArrayDimFetch) {
                $var = $var->var;
            }
            if (!is_variable($var) || is_variable($var, 'this') || in_array($var->name, SUPERGLOBALS, true)) {
                return false;
            }
        }
        if ($node instanceof Expr\FuncCall && !in_array(call_name($node), PURE_CALLS, true)) {
            return false;
        }
        if ($node instanceof Expr\StaticCall && !($node->class instanceof Name && $node->name instanceof Node\Identifier
            && in_array($node->name->toString(), PURE_STATIC_CALLS[$node->class->toString()] ?? [], true))) {
            return false;
        }
        if ($node instanceof Expr\New_ && !($node->class instanceof Name && pure_new($root, $node->class->toString()))) {
            return false;
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && !isset($messages[spl_object_id($node)]) && !pure_method_call($root, $node, $type_of)) {
            return false;
        }
    }

    return true;
}

/**
 * A plain Symfony response, a builtin exception, or a project exception whose
 * constructor only passes pure values to its parent.
 */
function pure_new(string $root, string $class): bool
{
    if (in_array($class, PURE_RESPONSES, true)) {
        return true;
    }
    if (class_exists($class, false) || interface_exists($class, false)) {
        return is_a($class, Throwable::class, true) && (new ReflectionClass($class))->isInternal();
    }
    $loaded = load_class($root, $class);
    if (!$loaded instanceof Stmt\Class_ || $loaded->extends === null || !pure_new($root, $loaded->extends->toString())) {
        return false;
    }
    $constructor = find_method($loaded, '__construct');
    foreach ($constructor?->stmts ?? [] as $stmt) {
        $call = expression_of($stmt);
        if (!$call instanceof Expr\StaticCall || !$call->class instanceof Name || $call->class->toLowerString() !== 'parent'
            || !$call->name instanceof Node\Identifier || $call->name->toLowerString() !== '__construct'
            || !pure($root, $call->getRawArgs(), fn(Expr $e): ?string => null)) {
            return false;
        }
    }

    return true;
}

function pure_method_call(string $root, Expr\MethodCall|Expr\NullsafeMethodCall $call, Closure $type_of): bool
{
    if (!$call->name instanceof Node\Identifier) {
        return false;
    }
    $name = $call->name->toString();
    $target = call_target($call, $type_of);
    if ($target !== null) {
        return in_array($name, PURE_METHODS[$target[0]] ?? [], true)
            || (is_variable($call->var, 'this') && pure_private($root, $target[0], $name));
    }
    // $request->query->all() reads a request bag.
    $bag = $call->var instanceof Expr\PropertyFetch && $call->var->name instanceof Node\Identifier
        && in_array($call->var->name->toString(), ['query', 'request', 'attributes'], true)
        && $type_of($call->var->var) === 'Symfony\Component\HttpFoundation\Request';

    return $bag && in_array($name, ['all', 'get', 'has'], true);
}

/**
 * A private method whose body is pure. $this->name() always runs this class's
 * own private method, so no subclass can replace it. A reference parameter
 * could rebind a caller's receiver, so a method with one is refused.
 */
function pure_private(string $root, string $class, string $name): bool
{
    static $open = [];
    $key = strtolower($root . '|' . $class . '::' . $name);
    $loaded = load_class($root, $class);
    $method = $loaded === null ? null : find_method($loaded, $name);
    if (isset($open[$key]) || $method === null || !$method->isPrivate() || $method->isStatic() || $method->stmts === null
        || array_filter($method->params, fn(Node\Param $p) => $p->byRef) !== []) {
        return false;
    }
    $open[$key] = true;
    try {
        return pure($root, $method->stmts, receiver_types($root, $class, $method));
    } finally {
        unset($open[$key]);
    }
}

/**
 * True when the value is certainly a response: a plain response built here,
 * or a call to a private method of $class whose declared return type is one,
 * which PHP enforces on every return.
 */
function returns_response(string $root, string $class, ?Expr $expr): bool
{
    if ($expr instanceof Expr\New_) {
        return $expr->class instanceof Name && in_array($expr->class->toString(), PURE_RESPONSES, true);
    }
    if ($expr instanceof Expr\Ternary) {
        return $expr->if !== null && returns_response($root, $class, $expr->if) && returns_response($root, $class, $expr->else);
    }
    if (!$expr instanceof Expr\MethodCall || !is_variable($expr->var, 'this') || !$expr->name instanceof Node\Identifier) {
        return false;
    }
    $loaded = load_class($root, $class);
    $method = $loaded === null ? null : find_method($loaded, $expr->name->toString());

    return $method !== null && $method->isPrivate() && !$method->isStatic() && $method->returnType instanceof Name
        && in_array($method->returnType->toString(), PURE_RESPONSES, true);
}

/**
 * True when no return under $nodes leaves before the guard runs, other than
 * one $stops accepts.
 *
 * @param (Closure(?Expr): bool)|null $stops
 */
function stops_on_return(mixed $nodes, ?Closure $stops): bool
{
    foreach (walk($nodes, false) as $node) {
        if ($node instanceof Stmt\Return_ && ($stops === null || !$stops($node->expr))) {
            return false;
        }
    }

    return true;
}

/**
 * The service call a method makes first, when everything that can run before
 * it is pure: the statements ahead of it, the other parts of its statement,
 * its arguments, and the catch blocks of a try it sits in, which run when an
 * earlier statement or the guard throws. Those must also stop the method, or
 * code after the try runs with the refusal swallowed. A return ahead of the
 * call leaves before the guard, so it too must be one $stops accepts.
 *
 * @param list<Stmt> $stmts
 * @param (Closure(?Expr): bool)|null $stops
 * @return array{0: array{0: string, 1: string}, 1: Expr\CallLike}|null the target and the call
 */
function first_service_call(string $root, array $stmts, Closure $type_of, ?Closure $stops): ?array
{
    foreach ($stmts as $stmt) {
        if ($stmt instanceof Stmt\TryCatch) {
            if ($stmt->finally !== null || !pure($root, $stmt->catches, $type_of)) {
                return null;
            }
            $found = first_service_call($root, $stmt->stmts, $type_of, $stops);
            if ($found !== null) {
                foreach ($stmt->catches as $catch) {
                    $last = $catch->stmts === [] ? null : $catch->stmts[count($catch->stmts) - 1];
                    $returns = $last instanceof Stmt\Return_ && $stops !== null && $stops($last->expr);
                    $throws = $last instanceof Stmt\Expression && $last->expr instanceof Expr\Throw_;
                    if (!($returns || $throws) || !stops_on_return($catch->stmts, $stops)) {
                        return null;
                    }
                }
                return $found;
            }
            if (!pure($root, $stmt->stmts, $type_of) || !stops_on_return($stmt, $stops)) {
                return null;
            }
            continue;
        }
        $service = null;
        foreach (walk($stmt, false) as $node) {
            $target = call_target($node, $type_of);
            if ($target !== null && !in_array($target[1], PURE_METHODS[$target[0]] ?? [], true)) {
                $service = [$node, $target];
                break;
            }
        }
        if ($service === null) {
            if (!pure($root, $stmt, $type_of) || !stops_on_return($stmt, $stops)) {
                return null;
            }
            continue;
        }
        // Only a plain call, alone or assigned to a variable, runs
        // unconditionally with nothing but its arguments ahead of it.
        [$node, $target] = $service;
        $expr = $stmt instanceof Stmt\Return_ ? $stmt->expr : expression_of($stmt);
        if ($expr instanceof Expr\Assign && is_variable($expr->var) && !is_variable($expr->var, 'this')) {
            $expr = $expr->expr;
        }
        if ($expr !== $node || !pure($root, $node->getRawArgs(), $type_of)) {
            return null;
        }
        return [$target, $node];
    }

    return null;
}

/**
 * True when the action assigns the call's result at its top level and the
 * very next statement is if ($result instanceof Response) { return $result; }.
 * A helper that answers a refusal by returning a response then stops the
 * action as surely as a throw.
 *
 * @param list<Stmt> $stmts
 */
function refused_on(array $stmts, Expr\CallLike $call): bool
{
    $list = array_values($stmts);
    foreach ($list as $i => $stmt) {
        $assign = expression_of($stmt);
        if (!$assign instanceof Expr\Assign || $assign->expr !== $call || !is_variable($assign->var)) {
            continue;
        }
        $var = $assign->var->name;
        $guard = $list[$i + 1] ?? null;
        $cond = $guard instanceof Stmt\If_ ? $guard->cond : null;

        return $guard instanceof Stmt\If_ && $guard->elseifs === [] && $guard->else === null
            && $cond instanceof Expr\Instanceof_ && is_variable($cond->expr, $var) && $cond->class instanceof Name
            && $cond->class->toString() === 'Symfony\Component\HttpFoundation\Response'
            && count($guard->stmts) === 1 && $guard->stmts[0] instanceof Stmt\Return_
            && $guard->stmts[0]->expr !== null && is_variable($guard->stmts[0]->expr, $var);
    }

    return false;
}

/**
 * @param array<string, true> $seen
 * @param array<string, string> $bound classes the caller passed for callable parameters
 * @param (Closure(?Expr): bool)|null $stops returns the caller refuses on
 * @return array<string, true>
 */
function method_checks(string $root, string $class, Stmt\ClassMethod $method, int $depth, array &$seen, array $bound = [], ?Closure $stops = null): array
{
    $type_of = receiver_types($root, $class, $method, $bound);
    // Any return ends the action itself.
    if ($depth === 0) {
        $stops = fn(?Expr $e): bool => true;
    }
    $checks = guarded_checks($root, $method->stmts ?? [], $type_of, $stops);
    if ($checks !== [] || $depth >= CALL_DEPTH) {
        return $checks;
    }
    // Without its own guard, a method is covered only by the first service it
    // calls, and only when that service guards and nothing but pure code can
    // run ahead of it.
    $found = first_service_call($root, $method->stmts ?? [], $type_of, $stops);
    if ($found === null) {
        return [];
    }
    [$target, $call] = $found;
    if (in_array($target[0], ACCESS_TYPES, true) && $target[1] !== 'consoleActor') {
        return [];
    }
    [$type, $name] = $target;
    $key = strtolower($type . '::' . $name);
    if (isset($seen[$key])) {
        return [];
    }
    $seen[$key] = true;
    $loaded = load_class($root, $type);
    $callee = $loaded === null ? null : find_method($loaded, $name);
    if ($callee === null) {
        return [];
    }
    // A service handed as a callable is the class the caller's variable holds.
    $args = plain_args($call) ?? [];
    $handed = [];
    foreach ($callee->params as $i => $param) {
        $arg = $args[$i] ?? null;
        if ($param->type instanceof Node\Identifier && $param->type->toLowerString() === 'callable'
            && is_variable($param->var) && !$param->variadic && $arg !== null && $type_of($arg) !== null) {
            $handed[$param->var->name] = $type_of($arg);
        }
    }
    // Only the action's own guard ends the request; one level down a
    // returned response would go back to a caller that carries on.
    $refuses = $depth === 0 && refused_on($method->stmts ?? [], $call)
        ? fn(?Expr $e): bool => returns_response($root, $type, $e) : null;

    return method_checks($root, $type, $callee, $depth + 1, $seen, $handed, $refuses);
}

/**
 * @return array<string, int> check method => realm it requires
 */
function session_realms(string $root, array $files): array
{
    // The realm labels are only true while the adapter is the one
    // implementation of the access contracts.
    foreach ($files as $path) {
        foreach (walk(parse_file($root, $path, true) ?? []) as $node) {
            if ($node instanceof Stmt\Class_ && $node->namespacedName?->toString() !== SESSION_ADAPTER) {
                foreach ($node->implements as $interface) {
                    if (in_array($interface->toString(), ACCESS_TYPES, true)) {
                        fail($path . ' also implements ' . $interface->toString());
                    }
                }
            }
        }
    }
    $adapter = load_class($root, SESSION_ADAPTER);
    if ($adapter === null) {
        fail(SESSION_ADAPTER . ' not found');
    }
    $realms = [];
    foreach (ACCESS_CHECKS as $check) {
        $method = find_method($adapter, $check);
        $found = [];
        foreach (walk($method?->stmts ?? []) as $node) {
            if ($node instanceof Expr\MethodCall && is_variable($node->var, 'this') && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'hasRealm') {
                $args = plain_args($node);
                if ($args !== null && count($args) === 2 && $args[1] instanceof Scalar\Int_) {
                    $found[] = $args[1]->value;
                }
            }
        }
        if (count($found) !== 1) {
            fail('realm check for ' . $check . ' not found in LegacyAuthenticatedSession');
        }
        $realms[$check] = $found[0];
    }

    return $realms;
}

function is_route(Node\Attribute $attribute): bool
{
    return in_array($attribute->name->toString(), ROUTE_ATTRIBUTES, true);
}

/**
 * @return array{path: string, name: string, detail: string}|null null when an argument is not a literal
 */
function route_arguments(Node\Attribute $attribute): ?array
{
    $named = [];
    foreach ($attribute->args as $i => $arg) {
        // Only the path is read by position; a later positional argument
        // would be misread, so it is refused.
        if ($arg->unpack || $arg->byRef || ($arg->name === null && $i > 0)) {
            return null;
        }
        $named[$arg->name?->toString() ?? 'path'] = $arg->value;
    }
    $path = $named['path'] ?? null;
    $name = $named['name'] ?? null;
    if ($path === null || $name === null || !is_string_node($path) || !is_string_node($name)) {
        return null;
    }
    $methods = 'ANY';
    if (isset($named['methods'])) {
        $list = $named['methods'] instanceof Expr\Array_ ? $named['methods']->items : [$named['methods']];
        $verbs = [];
        foreach ($list as $item) {
            $value = $item instanceof Node\ArrayItem ? ($item->key === null && !$item->unpack ? $item->value : null) : $item;
            if ($value === null || !is_string_node($value)) {
                return null;
            }
            $verbs[] = $value->value;
        }
        $methods = implode('|', $verbs);
    }
    $detail = 'methods=' . $methods;
    if (isset($named['requirements'])) {
        if (!$named['requirements'] instanceof Expr\Array_) {
            return null;
        }
        $pairs = [];
        foreach ($named['requirements']->items as $item) {
            if ($item->unpack || $item->key === null || !is_string_node($item->key) || !is_string_node($item->value)) {
                return null;
            }
            $pairs[] = $item->key->value . '=' . $item->value->value;
        }
        $detail .= $pairs === [] ? '' : ' requirements=' . implode(',', $pairs);
    }

    return ['path' => $path->value, 'name' => $name->value, 'detail' => $detail];
}

/**
 * @param list<string> $files
 * @return list<array{0: string, 1: string, 2: string}>
 */
function symfony_routes(string $root, array $files): array
{
    $sources = array_values(array_filter($files, fn(string $f) => str_starts_with($f, 'src/')));
    $rows = [];
    $realms = null;
    foreach ($sources as $path) {
        $stmts = parse_file($root, $path, true);
        if ($stmts === null) {
            $rows[] = ['app.php', 'unknown', 'file does not parse: ' . $path];
            continue;
        }
        $file = basename($path);
        $found = 0;
        foreach (walk($stmts) as $class) {
            if (!$class instanceof Stmt\ClassLike) {
                continue;
            }
            foreach ($class->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if (is_route($attribute)) {
                        $found++;
                        $rows[] = ['app.php', 'unknown', 'class-level #[Route] prefix in ' . $file];
                    }
                }
            }
            foreach ($class->getMethods() as $method) {
                foreach ($method->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        if (!is_route($attribute)) {
                            continue;
                        }
                        $found++;
                        $route = route_arguments($attribute);
                        if ($route === null) {
                            $rows[] = ['app.php', 'unknown', 'unreadable #[Route] arguments in ' . $file];
                            continue;
                        }
                        if (!$method->isPublic() || $method->isStatic()) {
                            $rows[] = ['app.php' . $route['path'], 'unknown', 'route on a method that is not a public action in ' . $file];
                            continue;
                        }
                        $seen = [];
                        $name = $class->namespacedName?->toString() ?? '';
                        $checks = method_checks($root, $name, $method, 0, $seen);
                        $detail = $route['detail'];
                        $reviewed = '';
                        if (!isset($checks['consoleActor']) && isset(REVIEWED_ROUTES[$route['name']])) {
                            $type_of = receiver_types($root, $name, $method);
                            foreach ($method->stmts ?? [] as $stmt) {
                                if (actor_assignment($root, $stmt, $type_of) !== null) {
                                    $checks['consoleActor'] = true;
                                    $reviewed = '; reviewed at ' . digest($method->stmts) . ': ' . REVIEWED_ROUTES[$route['name']];
                                    break;
                                }
                            }
                        }
                        if (isset($checks['consoleActor'])) {
                            $realms ??= session_realms($root, $sources);
                            $grant = 'realm ' . $realms['consoleActor'];
                            if (isset($checks['canManageDevices'])) {
                                $grant .= ' + realm ' . $realms['canManageDevices'];
                            }
                            $rows[] = ['app.php' . $route['path'], 'symfony:' . $route['name'], $detail . '; ConsoleAccess ' . $grant . $reviewed];
                        } elseif (array_key_exists($route['name'], ANONYMOUS_ROUTES)) {
                            $rows[] = ['app.php' . $route['path'], 'symfony:' . $route['name'], $detail . '; anonymous-allowed: ' . ANONYMOUS_ROUTES[$route['name']]];
                        } else {
                            $rows[] = ['app.php' . $route['path'], 'unknown', $detail . '; no IdentityAccess check reached from ' . $file];
                        }
                    }
                }
            }
        }
        // A #[Route] the walk above did not see, such as one under an alias
        // or on a closure, must fail the check rather than vanish.
        $text = (string) file_get_contents($root . '/' . $path);
        if (substr_count($text, '#[Route') !== $found) {
            $rows[] = ['app.php', 'unknown', 'unparsed #[Route] attribute in ' . $file];
        }
    }

    return $rows;
}

function main(): int
{
    $request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $root = rtrim($request['root'], '/');
    $files = $request['files'];
    try {
        $realms = realm_map($root);
        foreach ($request['plugin_realms'] as $name => $realm) {
            $realms[$name] = $realm;
        }
        $early = auth_early_returns($root);
        $includers = includers($root, $files);
        $functions = declared_functions($root, $files);
        $rows = [];
        foreach ($request['served'] as $path) {
            [$gate, $detail] = classify($root, $path, $realms, $early, $includers, $functions);
            $rows[] = [$path, $gate, $detail];
        }
        array_push($rows, ...symfony_routes($root, $files));
    } catch (TreeError $error) {
        fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
        return 2;
    }
    print json_encode(['rows' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    return 0;
}

exit(main());
