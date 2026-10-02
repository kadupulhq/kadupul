<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Evidence for byte-identical copied CLI execution, without recording secrets. */
final class CsrfRotationCoverage
{
    private static array $expected = [];

    public static function sources(string $root): array
    {
        $hashes = [];
        foreach (['tests/Helpers/CsrfRotationCoverage.php', 'tests/Helpers/PhpSource.php',
            'tests/Fixtures/rrd-process-coverage.php', 'tests/Unit/Security/CsrfMagicTokenCheckTest.php',
            'cli/refresh_csrf.php', 'include/vendor/csrf/csrf-magic.php',
            'composer.lock', 'tests/composer.lock'] as $path) {
            $hash = hash_file('sha256', $root . '/' . $path);
            if ($hash === false) {
                throw new RuntimeException('Unable to hash rotation source: ' . $path);
            }
            $hashes[$path] = $hash;
        }
        foreach ([SebastianBergmann\CodeCoverage\CodeCoverage::class,
            SebastianBergmann\CodeCoverage\Filter::class,
            SebastianBergmann\CodeCoverage\Driver\Selector::class] as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $hash = $file === false ? false : hash_file('sha256', $file);
            if ($hash === false) {
                throw new RuntimeException('Unable to hash rotation coverage dependency');
            }
            $hashes['dependency:' . $class] = $hash;
        }
        return $hashes;
    }

    public static function fixtures(string $directory): array
    {
        $hashes = [];
        foreach (['coverage-bootstrap.php', 'include/cli_check.php', 'lib/poller.php', 'lib/utility.php'] as $path) {
            $hash = hash_file('sha256', $directory . '/' . $path);
            if ($hash === false) {
                throw new RuntimeException('Unable to hash rotation fixture: ' . $path);
            }
            $hashes[$path] = $hash;
        }
        return $hashes;
    }

    public static function prepare(string $repository, string $directory, string $working, string $mode): array
    {
        $program = '<?php ';
        foreach (['RRD_TEST_COVERAGE_DIRECTORY' => $directory,
            'RRD_TEST_CLI_COVERAGE_COPY' => $directory . '/cli/refresh_csrf.php',
            'RRD_TEST_CLI_COVERAGE_SOURCE' => $repository . '/cli/refresh_csrf.php',
            'CSRF_ROTATION_TEST_COVERAGE' => $mode] as $name => $value) {
            $program .= 'define(' . var_export($name, true) . ',' . var_export($value, true) . ');';
        }
        $program .= 'require ' . var_export($repository . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
        $program .= '$working = ' . var_export($working, true) . ';'
            . '$original = ' . var_export(hash_file('sha256', $working), true) . ';';
        $program .= <<<'PHP'
register_shutdown_function(static function () use ($working, $original) {
    $generated = $GLOBALS['new_secret'] ?? null;
    $mode = CSRF_ROTATION_TEST_COVERAGE;
    if ($mode === 'entropy-failure') {
        $completed = ($GLOBALS['csrf_rotation_entropy_threw'] ?? false) === true
            && $generated === null && is_file($working) && hash_file('sha256', $working) === $original;
    } elseif ($mode === 'blocked') {
        $completed = is_string($generated) && preg_match('/\A[0-9a-f]{64}\z/D', $generated) === 1
            && is_file($working) && hash_file('sha256', $working) === $original;
    } elseif ($mode === 'success') {
        $completed = is_string($generated) && preg_match('/\A[0-9a-f]{64}\z/D', $generated) === 1
            && file_get_contents($working) === '<?php $secret = ' . var_export($generated, true) . ';' . PHP_EOL
            && (fileperms($working) & 0777) === 0640;
    } else {
        $completed = false;
    }
    $GLOBALS['csrf_rotation_completed'] = $completed;
});
PHP;
        $path = $directory . '/coverage-bootstrap.php';
        if (file_put_contents($path, $program) !== strlen($program)) {
            throw new RuntimeException('Unable to write rotation coverage bootstrap');
        }
        self::$expected[$directory] = [self::sources($repository), self::fixtures($directory)];
        return [PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'auto_prepend_file=' . $path,
            $directory . '/cli/refresh_csrf.php'];
    }

    public static function record(string $report, string $repository): void
    {
        $receipt = json_encode(['sources' => self::sources($repository),
            'fixtures' => self::fixtures(RRD_TEST_COVERAGE_DIRECTORY),
            'copy' => hash_file('sha256', RRD_TEST_CLI_COVERAGE_COPY),
            'php' => PHP_VERSION, 'pcre' => PCRE_VERSION,
            'completed' => ($GLOBALS['csrf_rotation_completed'] ?? false) === true,
            'mode' => CSRF_ROTATION_TEST_COVERAGE, 'report' => hash_file('sha256', $report)], JSON_THROW_ON_ERROR);
        if (file_put_contents($report . '.json', $receipt) !== strlen($receipt)) {
            throw new RuntimeException('Unable to write rotation coverage receipt');
        }
    }

    public static function verify(array $receipt, array $sources, array $fixtures, string $artifact, string $mode): void
    {
        if ($artifact === '' || ($receipt['sources'] ?? null) !== $sources
            || ($receipt['fixtures'] ?? null) !== $fixtures
            || ($receipt['copy'] ?? null) !== ($sources['cli/refresh_csrf.php'] ?? null)
            || ($receipt['php'] ?? null) !== PHP_VERSION || ($receipt['pcre'] ?? null) !== PCRE_VERSION
            || ($receipt['completed'] ?? null) !== true || ($receipt['mode'] ?? null) !== $mode
            || ($receipt['report'] ?? null) !== hash('sha256', $artifact)) {
            throw new RuntimeException('Incomplete or stale rotation coverage evidence');
        }
    }

    public static function cleanup(string $directory): void
    {
        unset(self::$expected[$directory]);
        foreach (array_merge(glob($directory . '/*.coverage*'), [$directory . '/coverage-bootstrap.php']) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public static function merge($coverage, string $repository, string $directory, string $mode): void
    {
        if ($coverage === null) {
            return;
        }
        $reports = glob($directory . '/*.coverage');
        if (count($reports) !== 1 || !isset(self::$expected[$directory])) {
            throw new RuntimeException('Missing rotation coverage report or expected sources');
        }
        $artifact = file_get_contents($reports[0]);
        $receipt = json_decode(file_get_contents($reports[0] . '.json'), true, flags: JSON_THROW_ON_ERROR);
        [$sources, $fixtures] = self::$expected[$directory];
        self::verify($receipt, $sources, $fixtures, $artifact, $mode);
        // Probe omissions against each actual measured source and fixture.
        foreach (['sources', 'fixtures'] as $group) {
            foreach (array_keys($receipt[$group]) as $path) {
                $omitted = $receipt;
                unset($omitted[$group][$path]);
                self::mustReject($omitted, $sources, $fixtures, $artifact, $mode);
                $stale = $receipt;
                $stale[$group][$path] = 'stale';
                self::mustReject($stale, $sources, $fixtures, $artifact, $mode);
            }
        }
        foreach (['copy', 'php', 'pcre', 'completed', 'mode', 'report'] as $key) {
            $omitted = $receipt;
            unset($omitted[$key]);
            self::mustReject($omitted, $sources, $fixtures, $artifact, $mode);
        }
        self::mustReject($receipt, $sources, $fixtures, '', $mode);
        self::mustReject($receipt, $sources, $fixtures, $artifact . 'altered', $mode);

        $child = unserialize($artifact);
        if (!$child instanceof SebastianBergmann\CodeCoverage\CodeCoverage) {
            throw new RuntimeException('Invalid rotation coverage object');
        }
        $worker = realpath($repository . '/cli/refresh_csrf.php');
        $observed = $child->getData()->lineCoverage()[$worker] ?? [];
        $required = ['$new_secret = csrf_generate_secret();'];
        $required[] = match ($mode) {
            'entropy-failure' => 'print "FATAL: Unable to generate a new CSRF secret."',
            'blocked' => 'print "FATAL: Unable to write new csrf_secret.php file."',
            'success' => 'print "NOTE: New csrf_secret.php file written."',
        };
        $lines = file($worker);
        foreach ($required as $statement) {
            $matches = [];
            foreach ($lines as $index => $line) {
                if (str_contains($line, $statement)) {
                    $matches[] = $index + 1;
                }
            }
            if (count($matches) !== 1 || empty($observed[$matches[0]])) {
                throw new RuntimeException('Missing genuine rotation branch execution');
            }
        }
        $coverage->merge($child);
    }

    private static function mustReject(array $receipt, array $sources, array $fixtures, string $artifact, string $mode): void
    {
        try {
            self::verify($receipt, $sources, $fixtures, $artifact, $mode);
        } catch (RuntimeException $error) {
            return;
        }
        throw new RuntimeException('Corrupted rotation evidence was accepted');
    }
}
