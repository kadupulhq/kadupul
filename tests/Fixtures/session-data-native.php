<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// C: remains a supported legacy wire value on both maintained PHP lines.
error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__, 2) . '/lib/session_data.php';
final class SessionDataMemory implements SessionHandlerInterface {
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $maximum): int|false { return 0; }
}
final class SessionDataWake {
    public static int $awoken = 0;
    public string $note = 'opaque;sess_last_activity|i:9999;';
    public function __wakeup(): void { ++self::$awoken; }
}
final class SessionDataOpaque implements Serializable {
    public mixed $value;
    public function serialize(): string { return serialize($this->value); }
    public function unserialize(string $data): void { $this->value = unserialize($data); }
}
enum SessionDataChoice { case Preserved; }
$serializer = $argv[1] ?? 'php';
$scenario = $argv[2] ?? 'note';
ini_set('session.serialize_handler', $serializer);
session_set_save_handler(new SessionDataMemory());
session_start();
$make = static function(int $time) use ($scenario): void {
    $activity = $time;
    $user = 42;
    $credential = str_repeat('a', 64);
    $epoch = str_repeat('b', 64);
    $_SESSION = ['note' => "bytes\0\xff;sess_last_activity|i:" . ($time + 7000) . ';',
        'sess_user_id' => $user, 'sess_user_credential' => $credential, 'sess_user_epoch' => $epoch];
    if ($scenario === 'large') $_SESSION['cache'] = str_repeat('x', 8 * 1024 * 1024);
    if ($scenario === 'plugin-state') $_SESSION['sess_user_epoch'] = 123;
    if ($scenario === 'array') $_SESSION['before'] = ['nested' => ['sess_last_activity' => 'literal|i:7777;'], 'binary' => "\0\xff", 'float' => [INF, -INF, NAN, -0.0]];
    if ($scenario === 'reference') {
        $_SESSION['before'] = ['time' => &$activity];
        $_SESSION['sess_user_id'] = &$user;
        $_SESSION['user_alias'] = &$user;
        $_SESSION['sess_user_credential'] = &$credential;
        $_SESSION['credential_alias'] = &$credential;
    }
    if ($scenario === 'objects') {
        $object = new SessionDataWake();
        $_SESSION['object'] = $object;
        $_SESSION['same_object'] = $object;
        $_SESSION['choice'] = SessionDataChoice::Preserved;
        $_SESSION['before'] = ['time' => &$activity];
    }
    if ($scenario === 'opaque') {
        $object = new SessionDataOpaque();
        $object->value = 'opaque;sess_last_activity|i:9999;';
        $_SESSION['object'] = $object;
    }
    if ($scenario === 'opaque-reference') {
        $object = new SessionDataOpaque();
        $object->value = ['time' => &$activity];
        $_SESSION['object'] = $object;
    }
    if ($scenario === 'nested') {
        $value = 'tail';
        for ($depth = 0; $depth < 200; ++$depth) $value = ['item' => $value];
        $_SESSION['before'] = $value;
    }
    $_SESSION['sess_last_activity'] = &$activity;
};
$make(2000);
$stored = session_encode();
$make(1000);
$data = session_encode();
$_SESSION['sess_last_activity'] = 2000;
$expected = session_encode();
if (!is_string($stored) || !is_string($data) || !is_string($expected)) throw new RuntimeException('Native session encoding failed.');
$result = CactiSessionData::monotonic($stored, $data, $serializer);
$metadata = CactiSessionData::inspect($data, $serializer);
$state = ['matches' => $result === $expected, 'refused' => $result === false, 'parser_awoken' => SessionDataWake::$awoken,
    'user_id' => $metadata === false ? null : $metadata['user_id'], 'activity' => $metadata === false ? null : $metadata['activity'], 'peak_bytes' => memory_get_peak_usage(true)];
if (is_string($result)) {
    $_SESSION = [];
    $state['decoded'] = session_decode($result);
    $state['decoded_activity'] = $_SESSION['sess_last_activity'] ?? null;
    $state['note_preserved'] = ($_SESSION['note'] ?? null) === "bytes\0\xff;sess_last_activity|i:8000;";
}
session_abort();
define('NATIVE_SESSION_SCENARIO_COMPLETED', true);echo json_encode($state, JSON_THROW_ON_ERROR), PHP_EOL;
