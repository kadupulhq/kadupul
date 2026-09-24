<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require getcwd() . '/include/vendor/autoload.php';
$configuration = new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(getcwd());
$database = new \Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase($configuration);
$pdo = $database->get();
foreach (['sites', 'settings'] as $table) {
    $ddl = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(\PDO::FETCH_NUM)[1];
    $pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl));
}
$access = new class implements \Kadupul\IdentityAccess\Contract\ConsoleAccess {
    public bool $allowed = true;

    public function consoleActor(): ?\Kadupul\IdentityAccess\Contract\Actor
    {
        return new \Kadupul\IdentityAccess\Contract\Actor(42, 'operator');
    }

    public function canManageDevices(\Kadupul\IdentityAccess\Contract\Actor $actor): bool
    {
        return $this->allowed;
    }

    public function canAdministerInstallation(\Kadupul\IdentityAccess\Contract\Actor $actor): bool
    {
        return false;
    }
};
final class SiteCreationFailureStatement extends \PDOStatement
{
    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        if (($params[0] ?? '') === 'time_last_change_site_device') {
            throw new \RuntimeException('Injected marker failure.');
        }

        return parent::execute($params);
    }
}
$trail = new class ($pdo) implements \Kadupul\IdentityAccess\Contract\AuditTrail {
    public array $records = [];
    public function __construct(private \PDO $pdo) {}
    public function record(\Kadupul\IdentityAccess\Contract\AuditEvent $event): void
    {
        // Capture transaction state and committed rows at the moment of recording.
        $this->records[] = ['event' => json_decode($event->json(), true), 'json' => $event->json(), 'open' => $this->pdo->inTransaction(), 'sites' => (int) $this->pdo->query('SELECT COUNT(*) FROM sites')->fetchColumn()];
    }
};
$creator = new \Kadupul\Inventory\Infrastructure\Legacy\LegacySiteCreator($database, $access, new \Kadupul\Inventory\Infrastructure\Legacy\SiteWriteAudit($trail));
$site = new \Kadupul\Inventory\Domain\NewSite(['name' => 'rollback', 'notes' => 'password=audit-probe-secret']);
$pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [SiteCreationFailureStatement::class]);
try {
    $creator->create(42, $site);
    throw new \LogicException('Expected marker failure.');
} catch (\RuntimeException $error) {
    if ($error->getMessage() !== 'Injected marker failure.') {
        throw $error;
    }
}
$rollback = (int) $pdo->query('SELECT COUNT(*) FROM sites')->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0 && !$pdo->inTransaction();
$access->allowed = false;
try {
    $creator->create(42, $site);
    throw new \LogicException('Expected authorization failure.');
} catch (\Kadupul\Inventory\Application\Query\InventoryAccessDenied) {
    $authorization = (int) $pdo->query('SELECT COUNT(*) FROM sites')->fetchColumn() === 0 && !$pdo->inTransaction();
}
$outcomes = array_map(static fn(array $record): array => [$record['event']['action'], $record['event']['target']['id'], $record['event']['decision'], $record['event']['outcome'], $record['open'], $record['sites']], $trail->records);
$audit = $outcomes === [['inventory.site.create', 'new', 'allowed', 'failed', false, 0], ['inventory.site.create', 'new', 'denied', 'denied', false, 0]]
    && !str_contains(implode("\n", array_column($trail->records, 'json')), 'Injected marker failure')
    && !str_contains(implode("\n", array_column($trail->records, 'json')), 'audit-probe-secret');
echo json_encode(['rollback' => $rollback, 'authorization' => $authorization, 'audit' => $audit], JSON_THROW_ON_ERROR);
