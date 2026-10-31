<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/Fixtures/RealMariaDb.php';

final class GroupSettingsPresenceNativeTest extends TestCase
{
    use RealMariaDb;

    private ?Connection $database = null;
    private string $schema = '';
    private bool $created = false;

    protected function setUp(): void
    {
        $this->database = $this->realMariaDb(true);
        $this->schema = 'group_settings_presence_' . bin2hex(random_bytes(8));
        $this->database->executeStatement('CREATE DATABASE `' . $this->schema . '` CHARACTER SET utf8mb4');
        $this->created = true;
        $this->database->executeStatement('USE `' . $this->schema . '`');
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($source);
        foreach (['settings', 'settings_user', 'settings_user_group'] as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`?\s*\([^;]*;/s', $source, $match), $table);
            $this->database->executeStatement($match[0]);
        }
        $this->database->executeStatement("INSERT INTO settings (name,value) VALUES ('font_method','1')");
    }

    protected function tearDown(): void
    {
        if ($this->database !== null && $this->created) {
            $this->database->executeStatement('DROP DATABASE `' . $this->schema . '`');
            $this->database->close();
        }
    }

    public function testStoredEmptyAndZeroGroupValuesHaveTheirOwnPresence(): void
    {
        foreach (['hide_disabled' => '', 'min_tree_width' => '0', 'thumbnail_section_preview' => '', 'thumbnail_section_tree_2' => 'on'] as $name => $value) {
            $this->database->insert('settings_user_group', ['group_id' => 5, 'name' => $name, 'value' => $value]);
        }
        $fields = $this->render();
        foreach (['hide_disabled' => '', 'min_tree_width' => '0'] as $name => $value) {
            self::assertSame($value, $fields[$name]['value']);
            self::assertSame(1, $fields[$name]['form_id'] ?? null, $name);
        }
        foreach (['thumbnail_section_preview' => '', 'thumbnail_section_tree_2' => 'on'] as $name => $value) {
            self::assertSame($value, $fields['thumbnail_sections']['items'][$name]['value']);
            self::assertSame(1, $fields['thumbnail_sections']['items'][$name]['form_id'] ?? null, $name);
        }
    }

    public function testUnrelatedUserSettingsCannotMakeAbsentGroupValuesPresent(): void
    {
        foreach (['hide_disabled', 'min_tree_width', 'thumbnail_section_preview', 'thumbnail_section_tree_2'] as $name) {
            $this->database->insert('settings_user', ['user_id' => 5, 'name' => $name, 'value' => 'on']);
            $this->database->insert('settings_user_group', ['group_id' => 6, 'name' => $name, 'value' => 'on']);
        }
        $fields = $this->render();
        foreach (['hide_disabled', 'min_tree_width'] as $name) {
            self::assertArrayNotHasKey('form_id', $fields[$name], $name);
        }
        foreach ($fields['thumbnail_sections']['items'] as $field) {
            self::assertArrayNotHasKey('form_id', $field);
        }
    }

    /** @return array<string,mixed> */
    private function render(): array
    {
        $parameters = $this->database->getParams();
        $dsn = 'mysql:dbname=' . $this->schema . ';charset=utf8mb4';
        foreach (['host', 'port', 'unix_socket'] as $name) {
            if (isset($parameters[$name])) $dsn .= ';' . $name . '=' . $parameters[$name];
        }
        $child = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__) . '/Fixtures/group-settings-presence-native.php'], env: [
            'KADUPUL_GROUP_SETTINGS_DSN' => $dsn,
            'KADUPUL_GROUP_SETTINGS_USER' => $parameters['user'],
            'KADUPUL_GROUP_SETTINGS_PASSWORD' => $parameters['password'],
        ], timeout: 10);
        $child->run();
        self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        self::assertSame('', $child->getErrorOutput());
        $fields = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fields);
        return $fields;
    }
}
