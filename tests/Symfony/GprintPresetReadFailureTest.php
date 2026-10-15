<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetPreferences;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GprintPresetReadFailureTest extends TestCase
{
    #[DataProvider('failures')]
    public function testAnUnconfirmedReadNeverAuthorizesOrBecomesAnAbsentPreference(string $target, string $stage): void
    {
        [$database, $access, $preferences] = $this->fixture();
        $database->target = $target;
        $database->stage = $stage;
        try {
            $target === 'gprint_presets_filters' ? $preferences->load() : $access->authorize();
            self::fail('An unconfirmed GPRINT read was accepted.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('could not be confirmed', $error->getMessage());
            self::assertTrue($database->injected, 'The requested production read must reach the injected failure.');
        }
    }

    public static function failures(): iterable
    {
        foreach (['auth_method', 'guest_user', 'gprint_presets_filters'] as $target) {
            foreach (['prepare', 'execute', 'fetch'] as $stage) {
                yield $target . ':' . $stage => [$target, $stage];
            }
        }
    }

    public function testConfirmedReadsAdmitTheActorAndDistinguishMissingAndSavedPreferences(): void
    {
        [$database, $access, $preferences] = $this->fixture();
        self::assertSame(7, $access->authorize()->id);
        self::assertNull($preferences->load());
        $query = $database->prepare("INSERT INTO settings_user VALUES(7,'gprint_presets_filters',?)");
        $query->execute([json_encode(['filter' => 'saved', 'page' => 2], JSON_THROW_ON_ERROR)]);
        self::assertSame(['filter' => 'saved', 'page' => '2'], $preferences->load());
        self::assertFalse($database->injected);
    }

    private function fixture(): array
    {
        $database = new GprintReadFailurePdo('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        $database->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [GprintReadFailureStatement::class, [$database]]);
        $database->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY,username TEXT,enabled TEXT,locked TEXT,must_change_password TEXT);
            INSERT INTO user_auth VALUES(7,'operator','on','','');
            CREATE TABLE user_auth_realm(user_id INTEGER,realm_id INTEGER);
            INSERT INTO user_auth_realm VALUES(7,5),(7,8);
            CREATE TABLE settings(name TEXT,value TEXT);
            INSERT INTO settings VALUES('auth_method','1'),('guest_user','0');
            CREATE TABLE settings_user(user_id INTEGER,name TEXT,value TEXT,PRIMARY KEY(user_id,name));");
        $connection = new class ($database) implements DatabaseConnection {
            public function __construct(private \PDO $database) {}
            public function get(): \PDO
            {
                return $this->database;
            }
        };
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor(7, 'operator'));
        $access = new LegacyGprintPresetAccess($console, $connection);
        $preferences = new LegacyGprintPresetPreferences($access, $connection, $this->createMock(LegacyConfiguration::class));

        return [$database, $access, $preferences];
    }
}

final class GprintReadFailurePdo extends \PDO
{
    public string $target = '';
    public string $stage = '';
    public bool $injected = false;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($this->stage === 'prepare' && $this->matches($query)) {
            $this->injected = true;
            return parent::prepare('SELECT missing_column FROM missing_gprint_fixture_table');
        }

        return parent::prepare($query, $options);
    }

    public function matches(string $query): bool
    {
        return $this->target !== '' && str_contains($query, "'" . $this->target . "'");
    }
}

final class GprintReadFailureStatement extends \PDOStatement
{
    protected function __construct(private GprintReadFailurePdo $database) {}

    public function execute(?array $params = null): bool
    {
        if ($this->database->stage === 'execute' && $this->database->matches($this->queryString)) {
            $this->database->injected = true;
            // Extra bound values produce a real silent-driver error receipt.
            return parent::execute([1, 2, 3]);
        }

        return parent::execute($params);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->database->stage === 'fetch' && $this->database->matches($this->queryString)) {
            $this->database->injected = true;
            parent::execute([1, 2, 3]);
            return false;
        }

        return parent::fetchColumn($column);
    }
}
