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
 * apply their own check. The check is a PHP expression matched as a node
 * outside function bodies, and the detail pins every top-level statement up
 * to and including the one that holds it, so code added ahead of the check
 * or an edit to it shows as drift.
 */
const SELF_GATED = [
    'script_server.php' => [
        'cli-only',
        "require(__DIR__ . '/include/cli_check.php')",
        'FINDING: argument parsing runs before this guard, so an HTTP request fails with HTTP 500 before reaching it',
    ],
    'auth_login.php' => [
        'anonymous-allowed',
        "db_execute_prepared('INSERT IGNORE INTO user_log\n\t\t\t(username, user_id, result, ip, time)\n\t\t\tVALUES (?, ?, 1, ?, NOW())', array(\$username, \$user['id'], \$client_addr))",
        'login handler; include/auth.php includes it after bootstrap, so a direct request stops at the first undefined function',
    ],
    'auth_changepassword.php' => [
        'authenticated',
        "!isset(\$_SESSION['sess_user_id'])",
        'own session check; action=checkpass answers anonymously with the password policy verdict',
    ],
    'csp_report.php' => [
        'anonymous-allowed',
        "require_once(__DIR__ . '/lib/csp_report_endpoint.php')",
        'CSP violation report sink; browsers post reports without credentials',
    ],
    'link.php' => [
        'realm:10000+id',
        "is_realm_allowed(\$page['id'] + 10000)",
        'own realm check per external link id',
    ],
    'remote_agent.php' => [
        'anonymous-allowed',
        '!remote_client_authorized()',
        'no user session; remote_client_authorized() admits registered poller addresses only',
    ],
    'service_check.php' => [
        'anonymous-allowed',
        "db_fetch_cell('SELECT cacti FROM version')",
        'service probe; prints success or fail for the schema version only',
    ],
];

// Reachable without the gate they need. Each is reported, not fixed, here,
// and must leave this list in the pull request that adds the gate.
const UNGATED = [
    'include/themes/midwinter/update_hash.php' => 'FINDING: theme build script runs over HTTP with no CLI guard and rewrites theme CSS files; expected cli-only or web-server-denied',
];

// Symfony routes that deliberately answer without an actor.
const ANONYMOUS_ROUTES = [
    'health' => 'liveness probe; returns a fixed status document',
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

const SIDE_EFFECT_CALLS = [
    'file_put_contents', 'fputs', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'chown', 'symlink',
    'move_uploaded_file', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'mail',
    'mb_send_mail', 'setcookie', 'assert', 'unserialize',
];
const SIDE_EFFECT_PREFIXES = ['db_execute', 'db_insert', 'db_replace'];
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
 * @param list<Stmt> $stmts
 * @return array{0: string, 1: string}|null
 */
function self_gated(string $path, array $stmts): ?array
{
    [$gate, $fingerprint, $reason] = SELF_GATED[$path];
    $parsed = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $fingerprint . ';');
    $needle = $parsed[0]->expr;
    foreach ($stmts as $i => $stmt) {
        if (is_declaration($stmt)) {
            continue;
        }
        foreach (walk($stmt, false) as $node) {
            if (same_node($node, $needle)) {
                return [$gate, $reason . '; reviewed at ' . digest(array_slice($stmts, 0, $i + 1))];
            }
        }
    }

    return ['unknown', 'self-gated check not found: ' . $fingerprint];
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
function classify(string $root, string $path, array $realms, array $early, array $includers): array
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
        return fragment($root, $path, $active, $includers[$path]);
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
    $extras = [];
    foreach ($before as $stmt) {
        $expr = expression_of($stmt);
        if ($expr instanceof Expr\Assign && is_variable($expr->var, 'guest_account')) {
            $extras[] = 'guest_account';
            break;
        }
    }
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
    $realm = $realms[$name] ?? 0;
    if ($realm === -1) {
        return ['authenticated', 'include/auth.php realm -1' . $suffix];
    }
    if ($realm === 0) {
        return ['realm:0', 'include/auth.php; unmapped page, denied to every account' . $suffix];
    }

    return ['realm:' . $realm, 'include/auth.php' . $suffix];
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
 * A fragment is harmless on its own only while nothing at its top level
 * writes, executes, sends or loads code the generator cannot name. The sweep
 * separately checks it emits no content.
 *
 * @param list<Stmt> $active
 * @param list<string> $by
 * @return array{0: string, 1: string}
 */
function fragment(string $root, string $path, array $active, array $by): array
{
    $reviewed = REVIEWED_INCLUDES[$path] ?? null;
    foreach (walk($active) as $node) {
        if ($node instanceof Expr\Eval_) {
            return ['unknown', 'fragment calls eval'];
        }
        if ($node instanceof Expr\ShellExec) {
            return ['unknown', 'fragment runs a backtick command'];
        }
        if ($node instanceof Expr\Include_ && resolve($node->expr, $root, $path) === null
            && !($reviewed !== null && is_variable($node->expr, $reviewed))) {
            return ['unknown', 'fragment includes a path the generator cannot resolve'];
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
 * @param array<string, true> $seen
 * @return array<string, true>
 */
function method_checks(string $root, string $class, Stmt\ClassMethod $method, int $depth, array &$seen): array
{
    $loaded = load_class($root, $class);
    $properties = $loaded === null ? [] : property_types($loaded);
    $params = [];
    foreach ($method->params as $param) {
        if (is_variable($param->var) && type_name($param->type) !== null) {
            $params[$param->var->name] = type_name($param->type);
        }
    }
    $type_of = function (Expr $receiver) use ($params, $properties, $class): ?string {
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

    $checks = [];
    $calls = [];
    foreach (walk($method->stmts ?? []) as $node) {
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
            $type = $type_of($node->var);
            $name = $node->name->toString();
            if ($type !== null && in_array($type, ACCESS_TYPES, true) && in_array($name, ACCESS_CHECKS, true)) {
                $checks[$name] = true;
            } elseif ($type !== null) {
                $calls[] = [$type, $name];
            }
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Expr) {
            // $useCase($criteria) runs the invokable's __invoke.
            $type = $type_of($node->name);
            if ($type !== null) {
                $calls[] = [$type, '__invoke'];
            }
        }
    }
    if ($depth >= CALL_DEPTH) {
        return $checks;
    }
    foreach ($calls as [$type, $name]) {
        $key = strtolower($type . '::' . $name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $target = load_class($root, $type);
        $callee = $target === null ? null : find_method($target, $name);
        if ($callee !== null) {
            $checks += method_checks($root, $type, $callee, $depth + 1, $seen);
        }
    }

    return $checks;
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
                        $checks = method_checks($root, $class->namespacedName?->toString() ?? '', $method, 0, $seen);
                        $detail = $route['detail'];
                        if (isset($checks['consoleActor'])) {
                            $realms ??= session_realms($root, $sources);
                            $grant = 'realm ' . $realms['consoleActor'];
                            if (isset($checks['canManageDevices'])) {
                                $grant .= ' + realm ' . $realms['canManageDevices'];
                            }
                            $rows[] = ['app.php' . $route['path'], 'symfony:' . $route['name'], $detail . '; ConsoleAccess ' . $grant];
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
        $rows = [];
        foreach ($request['served'] as $path) {
            [$gate, $detail] = classify($root, $path, $realms, $early, $includers);
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
