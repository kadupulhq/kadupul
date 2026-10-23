<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

/**
 * Validates documents with the Python jsonschema package (draft 2020-12), the
 * validator the schemas were written against. No PHP JSON Schema validator is
 * installed, so tests that need an independent verdict skip when Python or
 * jsonschema is missing instead of trusting the code under test.
 */
final class JsonSchemaCheck
{
    private const SCRIPT = <<<'PY'
        import json, sys
        try:
            import jsonschema
        except ImportError:
            sys.exit(3)
        request = json.load(sys.stdin)
        with open(request["schema"], encoding="utf-8") as handle:
            schema = json.load(handle)
        jsonschema.Draft202012Validator.check_schema(schema)
        validator = jsonschema.Draft202012Validator(schema, format_checker=jsonschema.Draft202012Validator.FORMAT_CHECKER)
        report = []
        for text in request["documents"]:
            errors = validator.iter_errors(json.loads(text))
            report.append(sorted("/" + "/".join(str(p) for p in e.absolute_path) + ": " + e.message for e in errors))
        json.dump(report, sys.stdout)
        PY;

    public static function skipReason(): ?string
    {
        [$status] = self::run('{"schema": "", "documents": []}', ['python3', '-I', '-c', 'import jsonschema']);

        return match ($status) {
            0 => null,
            127, -1 => 'python3 is not installed, so no independent JSON Schema validator is available.',
            default => 'The Python jsonschema package is not importable (python3 -I -c "import jsonschema" failed), so the schema cannot be checked independently.',
        };
    }

    /**
     * @param list<string> $documents JSON texts
     * @return list<list<string>> the errors for each document, empty when it is valid
     */
    public static function errors(string $schema, array $documents): array
    {
        $request = json_encode(['schema' => realpath($schema), 'documents' => $documents], JSON_THROW_ON_ERROR);
        [$status, $stdout, $stderr] = self::run($request, ['python3', '-I', '-c', self::SCRIPT]);
        if ($status !== 0) {
            throw new \RuntimeException('jsonschema run failed with status ' . $status . ': ' . $stderr);
        }

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private static function run(string $stdin, array $command): array
    {
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return [-1, '', 'proc_open failed'];
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
