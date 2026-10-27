<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/ProfileDeletionContract.php';

final class ProfileDeletionNativeTest extends ProfileDeletionContract
{
    public function testCollectorCopiesWholeTableGroupsThroughTheNativeEntryPoint(): void
    {
        foreach (array('all', 'auth', 'plugins', 'data') as $class) {
            $state = $this->runNative(array('collector' => 'bulk', 'entrypoint' => true, 'class' => $class));
            self::assertTrue($state['result']);
            $queries = array_column($state['calls'], 1);
            foreach (array('automation_graph_rule_items' => 'all', 'plugin_config' => 'plugins', 'user_auth' => 'auth') as $table => $group) {
                self::assertSame($class === 'all' || $class === $group, in_array('SELECT * FROM ' . $table, $queries, true), $class . ':' . $table);
            }
        }
        $failed = $this->runNative(array('collector' => 'bulk', 'entrypoint' => true, 'class' => 'plugins', 'failure' => 'read-plugin_config'));
        self::assertFalse($failed['result']);
        self::assertContains('on', $failed['sync']);
    }
}
