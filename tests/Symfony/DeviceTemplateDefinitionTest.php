<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceTemplateDefinition;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceTemplateFilters;
use PHPUnit\Framework\TestCase;

final class DeviceTemplateDefinitionTest extends TestCase
{
    public function testRevisionBindsParentFieldsAndBothAssociationSets(): void
    {
        $original = new DeviceTemplateDefinition(1, '<router>', 'router', [1, 2], [3]);
        foreach ([new DeviceTemplateDefinition(1, 'changed', 'router', [1, 2], [3]), new DeviceTemplateDefinition(1, '<router>', 'switch', [1, 2], [3]), new DeviceTemplateDefinition(1, '<router>', 'router', [1], [3]), new DeviceTemplateDefinition(1, '<router>', 'router', [1, 2], [4])] as $changed) {
            self::assertNotSame($original->revision(), $changed->revision());
        }
        self::assertSame($original->revision(), (new DeviceTemplateDefinition(1, '<router>', 'router', [1, 2], [3]))->revision());
        self::assertSame(['name' => ' raw name ', 'class' => 'router'], DeviceTemplateDefinition::validate(['name' => ' raw name ', 'class' => 'router']));
    }
    public function testNameBoundsMatchTheDatabaseAndDuplicateExpansion(): void
    {
        foreach ([str_repeat('x', 100), str_repeat('é', 100)] as $name) {
            self::assertSame($name, DeviceTemplateDefinition::validate(['name' => $name, 'class' => 'router'])['name']);
            self::assertSame($name, DeviceTemplateDefinition::duplicateName($name, '<template_title>'));
        }
        foreach ([str_repeat('x', 101), str_repeat('é', 101), "\xff"] as $name) {
            try {
                DeviceTemplateDefinition::validate(['name' => $name, 'class' => 'router']);
                self::fail('Invalid database name accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach (['<template_title>x', "\xff", "a\0"] as $format) {
            try {
                DeviceTemplateDefinition::duplicateName(str_repeat('x', 100), $format);
                self::fail('Invalid expanded duplicate name accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testInvalidFieldsAndFiltersFailBeforeCasting(): void
    {
        foreach ([['name' => [], 'class' => 'router'], ['name' => '', 'class' => 'router'], ['name' => 'ok', 'class' => 'bogus'], ['name' => "a\0", 'class' => 'router'], ['name' => str_repeat('x', 256), 'class' => 'router']] as $data) {
            try {
                DeviceTemplateDefinition::validate($data);
                self::fail('Invalid fields accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([['q' => []], ['page' => '0'], ['size' => '1'], ['sort' => 'name; DROP TABLE host'], ['direction' => 'bogus'], ['class' => 'bogus'], ['graph' => []], ['q' => "a\0"]] as $query) {
            try {
                DeviceTemplateFilters::parse($query);
                self::fail('Invalid filter accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(25, DeviceTemplateFilters::parse([])['size']);
    }
}
