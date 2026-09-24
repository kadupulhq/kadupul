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
foreach (['sites', 'settings', 'host'] as $table) {
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
$pdo->exec("INSERT INTO sites (id,name) VALUES (1,'First'),(2,'Second'); INSERT INTO host (id,description,hostname,site_id) VALUES (1,'Device','localhost',1)");
$trail = new class ($pdo) implements \Kadupul\IdentityAccess\Contract\AuditTrail {
    public array $records = [];
    public function __construct(private \PDO $pdo) {}
    public function record(\Kadupul\IdentityAccess\Contract\AuditEvent $event): void
    {
        // Capture transaction state and committed rows at the moment of recording.
        $this->records[] = ['event' => json_decode($event->json(), true), 'json' => $event->json(), 'open' => $this->pdo->inTransaction(), 'sites' => (int) $this->pdo->query('SELECT COUNT(*) FROM sites')->fetchColumn()];
    }
};
$adapter = new \Kadupul\Inventory\Infrastructure\Legacy\LegacySiteLifecycle($database, $access, new \Kadupul\Inventory\Infrastructure\Legacy\SiteWriteAudit($trail));
$revisions = [];
foreach ($adapter->find([1, 2]) as $site) {
    $revisions[$site->id] = $site->revision();
}
$selection = new \Kadupul\Inventory\Domain\SiteSelection($revisions);
$results = [];
$access->allowed = false;
try {
    $adapter->delete(42, $selection);
    throw new LogicException('Revoked deletion accepted');
} catch (\Kadupul\Inventory\Application\Query\InventoryAccessDenied) {
    $results['revoked'] = !$pdo->inTransaction() && (int) $pdo->query('SELECT COUNT(*) FROM sites')->fetchColumn() === 2;
}
$access->allowed = true;
$pdo->exec("UPDATE sites SET city='Changed' WHERE id=2");
try {
    $adapter->delete(42, $selection);
    throw new LogicException('Stale deletion accepted');
} catch (\Kadupul\Inventory\Domain\SiteEditConflict) {
    $results['stale'] = !$pdo->inTransaction() && (int) $pdo->query('SELECT site_id FROM host WHERE id=1')->fetchColumn() === 1;
}
$revisions = [];
foreach ($adapter->find([1, 2]) as $site) {
    $revisions[$site->id] = $site->revision();
}
$selection = new \Kadupul\Inventory\Domain\SiteSelection($revisions);
final class SiteLifecycleFailureStatement extends \PDOStatement
{
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        if (($params[0] ?? '') === 'time_last_change_site_device') {
            throw new \RuntimeException('Injected marker failure');
        }
        return parent::execute($params);
    }
}
$pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [SiteLifecycleFailureStatement::class]);
foreach (['delete', 'duplicate'] as $operation) {
    try {
        if ($operation === 'delete') {
            $adapter->delete(42, $selection);
        } else {
            $adapter->duplicate(42, $selection, '<site> copy');
        }
        throw new LogicException('Marker failure accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Injected marker failure') {
            throw $error;
        }
        $results[$operation . '_rollback'] = !$pdo->inTransaction()
            && (int) $pdo->query('SELECT COUNT(*) FROM sites')->fetchColumn() === 2
            && (int) $pdo->query('SELECT site_id FROM host WHERE id=1')->fetchColumn() === 1
            && (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0;
    }
}
$outcomes = array_map(static fn(array $record): array => [$record['event']['action'], $record['event']['target']['id'], $record['event']['decision'], $record['event']['outcome'], $record['open'], $record['sites']], $trail->records);
$expected = [];
foreach ([['inventory.site.delete', 'denied', 'denied'], ['inventory.site.delete', 'allowed', 'failed'], ['inventory.site.delete', 'allowed', 'failed'], ['inventory.site.duplicate', 'allowed', 'failed']] as [$action, $decision, $outcome]) {
    foreach (['1', '2'] as $target) {
        $expected[] = [$action, $target, $decision, $outcome, false, 2];
    }
}
$results['audit'] = $outcomes === $expected && !str_contains(implode("\n", array_column($trail->records, 'json')), 'Injected marker failure')
    && count(array_unique(array_map(static fn(array $record): string => $record['event']['correlation_id'], $trail->records))) === 4;
echo json_encode($results, JSON_THROW_ON_ERROR);
