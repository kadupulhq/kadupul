<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Legacy;

use Kadupul\CollectorAdministration\Application\Port\RemoteDatabaseConnector;
use Kadupul\CollectorAdministration\Application\Port\RemoteDatabaseProbe;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\CollectorBulkAccessDenied;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\PdoCollectorOperatorAuthorization;
use Kadupul\CollectorAdministration\Domain\CollectorRevision;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class PdoRemoteDatabaseProbe implements RemoteDatabaseProbe
{
    public function __construct(
        private DatabaseConnection $database,
        private ConsoleAccess $access,
        private CollectorRevisionKey $revisionKey,
        private RemoteDatabaseConnector $connector,
    ) {}

    public function canConnect(int $actorId, #[\SensitiveParameter] array $credentials, ?int $collectorId = null, ?string $revision = null): bool
    {
        $password = (string) ($credentials['dbpass'] ?? '');
        if ($collectorId !== null) {
            $connection = $this->database->get();
            try {
                $connection->beginTransaction();
                $actor = $this->access->consoleActor();
                if ($actor === null || $actor->id !== $actorId || !$this->access->canManageDevices($actor)) {
                    throw new CollectorAccessDenied($actor === null);
                }
                try {
                    (new PdoCollectorOperatorAuthorization())->assertCanManage($connection, $actorId);
                } catch (CollectorBulkAccessDenied) {
                    throw new CollectorAccessDenied(false);
                }
                $lockingRead = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $query = $connection->prepare('SELECT name, hostname, timezone, notes, processes, threads, sync_interval,
                    dbdefault, dbhost, dbuser, dbport, dbretries, dbssl, dbsslkey, dbsslcert, dbsslca, dbpass FROM poller WHERE id = ?' . $lockingRead);
                $query->execute([$collectorId]);
                $current = $query->fetch();
                if (!$current || $collectorId < 2 || !is_string($revision)
                    || !hash_equals(CollectorRevision::fromValues($current, (string) $current['dbpass'], $this->revisionKey->get()), $revision)) {
                    $connection->rollBack();
                    return false;
                }
                if ($password === '') {
                    $password = (string) $current['dbpass'];
                }
                if (!$connection->commit()) {
                    return false;
                }
            } catch (CollectorAccessDenied $error) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $error;
            } catch (\Throwable) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                return false;
            }
        }

        $credentials['dbpass'] = $password;
        return $this->connector->connect($credentials);
    }
}
