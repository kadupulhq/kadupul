<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Child runtime for tests requiring a temporary PHP_INI_PERDIR CA trust file.
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
$insert = $pdo->prepare('INSERT INTO settings VALUES (?, ?)');
foreach (json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) as $name => $value) {
    $insert->execute([$name, $value]);
}
$kernel = new Kernel('test', true);
$kernel->boot();
$kernel->getContainer()->get('test.service_container')->set(DatabaseConnection::class, new class ($pdo) implements DatabaseConnection {
    public function __construct(private readonly PDO $pdo) {}

    public function get(): PDO
    {
        return $this->pdo;
    }
});
$application = new Application($kernel);
$application->setAutoExit(false);
try {
    $status = $application->run(new ArrayInput(['command' => 'kadupul:mail:test', '-vvv' => true]));
} finally {
    $kernel->shutdown();
}
exit($status);
