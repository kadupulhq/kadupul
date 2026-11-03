<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

/** Private native-probe evidence: never publish response bodies or exception messages. */
final class InstallerProbeDiagnostics
{
    public const int MAX_LOG_BYTES = 65536;

    /** @return array{http_status:int,request_started_us:int,request_finished_us:int,response_bytes:int,response_sha256:string} */
    public static function request(int $status, string $body, float $started, float $finished): array
    {
        return ['http_status' => $status, 'request_started_us' => (int) round($started * 1000000),
            'request_finished_us' => (int) round($finished * 1000000), 'response_bytes' => strlen($body),
            'response_sha256' => hash('sha256', $body)];
    }

    /** @return array{http_status:int,request_started_us:int,request_finished_us:int,response_bytes:int,response_sha256:string} */
    public static function savedRequest(mixed $request): array
    {
        if (!is_array($request)) {
            throw new \RuntimeException('The saved request diagnostic is unavailable.');
        }
        foreach (['http_status', 'request_started_us', 'request_finished_us', 'response_bytes'] as $key) {
            if (!is_int($request[$key] ?? null) || $request[$key] < 0) {
                throw new \RuntimeException('The saved request diagnostic is unavailable.');
            }
        }
        if (!is_string($request['response_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $request['response_sha256']) !== 1) {
            throw new \RuntimeException('The saved response fingerprint is unavailable.');
        }
        return ['http_status' => $request['http_status'], 'request_started_us' => $request['request_started_us'],
            'request_finished_us' => $request['request_finished_us'], 'response_bytes' => $request['response_bytes'],
            'response_sha256' => $request['response_sha256']];
    }

    /** @return array{kind:string,exception_classes:list<string>,frames:list<array{file:string,line:int}>,unresolved_frames:list<array{path_sha256:string,line:int}>,log_sha256:string,truncated:bool} */
    public static function server(string $log, string $projectRoot): array
    {
        $truncated = strlen($log) > self::MAX_LOG_BYTES;
        $log = substr($log, -self::MAX_LOG_BYTES);
        $classes = [];
        preg_match_all('/\bUncaught(?: PHP Exception)?\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,179})\b/', $log, $matches);
        foreach (array_slice($matches[1], 0, 32) as $class) {
            try {
                // Only real Throwable types from the installed autoloaders are names,
                // rather than arbitrary strings supplied in a request or message.
                if (is_a($class, \Throwable::class, true)) {
                    $classes[$class] = $class;
                }
            } catch (\Throwable) {
                // A diagnostic must not replace the original probe failure.
            }
        }
        $frames = [];
        $unresolved = [];
        $canonicalRoot = realpath($projectRoot);
        $canonicalRoot = is_string($canonicalRoot) ? rtrim(str_replace('\\', '/', $canonicalRoot), '/') . '/' : null;
        $prefix = preg_quote(rtrim(str_replace('\\', '/', $projectRoot), '/') . '/', '~');
        preg_match_all('~' . $prefix . '([A-Za-z0-9_./-]{1,512}\.php)(?::([0-9]{1,8})|\(([0-9]{1,8})\)|\s+(?:on\s+)?line\s+([0-9]{1,8}))~', $log, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $file = $match[1];
            $parts = explode('/', $file);
            if (in_array('..', $parts, true) || in_array('.', $parts, true) || in_array('', $parts, true)) {
                continue;
            }
            $line = (int) (($match[2] ?? '') !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? 0)));
            if ($line > 0) {
                $resolved = realpath($projectRoot . '/' . $file);
                $resolved = is_string($resolved) ? str_replace('\\', '/', $resolved) : null;
                if ($canonicalRoot !== null && $resolved !== null && str_starts_with($resolved, $canonicalRoot) && is_file($resolved)) {
                    $relative = substr($resolved, strlen($canonicalRoot));
                    $frames[$relative . ':' . $line] = ['file' => $relative, 'line' => $line];
                } else {
                    // A missing cache file can be the failure itself. Fingerprint
                    // it without publishing arbitrary message text shaped as a path.
                    $digest = hash('sha256', $file);
                    $unresolved[$digest . ':' . $line] = ['path_sha256' => $digest, 'line' => $line];
                }
            }
            if (count($frames) + count($unresolved) === 32) {
                break;
            }
        }
        return ['kind' => $classes === [] && $frames === [] ? 'unclassified' : 'exception_trace',
            'exception_classes' => array_slice(array_values($classes), 0, 8), 'frames' => array_values($frames), 'unresolved_frames' => array_values($unresolved),
            'log_sha256' => hash('sha256', $log), 'truncated' => $truncated];
    }
}
