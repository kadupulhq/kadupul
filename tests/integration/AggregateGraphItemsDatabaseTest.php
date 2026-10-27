<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__, 2) . '/lib/database.php';
require_once dirname(__DIR__, 2) . '/lib/api_aggregate.php';

define('POLLER_VERBOSITY_DEBUG', 6);

function cacti_log($message, $output = false, $category = '', $verbosity = 0) {
}

function cacti_count($value) {
	return count($value);
}

function clean_up_lines($value) {
	return str_replace(array("\r", "\n"), ' ', $value);
}

beforeEach(function () {
	$port = getenv('KADUPUL_AGGREGATE_TEST_PORT');
	if ($port === false) {
		$this->markTestSkipped('Set KADUPUL_AGGREGATE_TEST_PORT for an isolated local MySQL/MariaDB server.');
	}
	if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
		throw new RuntimeException('Invalid test database port.');
	}
	$connection = new PDO('mysql:host=127.0.0.1;port=' . $port,
		getenv('KADUPUL_AGGREGATE_TEST_USER') ?: 'root',
		getenv('KADUPUL_AGGREGATE_TEST_PASSWORD') ?: '',
		array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false));
	$name = 'kadupul_test_aggregate_' . bin2hex(random_bytes(8));
	$connection->exec('CREATE DATABASE ' . $name);
	$GLOBALS['aggregate_test_database'] = $name;
	$GLOBALS['aggregate_test_connection'] = $connection;
	$connection->exec('USE ' . $name);
	$schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
	if (preg_match('/CREATE TABLE `aggregate_graphs_graph_item` \(.*?;\R/s', $schema, $matches) !== 1) {
		throw new RuntimeException('Aggregate table schema not found.');
	}
	$connection->exec($matches[0]);
	$connection->exec("INSERT INTO aggregate_graphs_graph_item
		(aggregate_graph_id, graph_templates_item_id, color_template, item_skip, item_total)
		VALUES (1, 10, 0, '', ''), (2, 20, 0, '', '')");
	$GLOBALS['database_hostname'] = '127.0.0.1';
	$GLOBALS['database_port'] = $port;
	$GLOBALS['database_default'] = $name;
	$GLOBALS['database_sessions'] = array('127.0.0.1:' . $port . ':' . $name => $connection);
	$GLOBALS['database_total_queries'] = 0;
	$GLOBALS['config'] = array();
});

afterEach(function () {
	$connection = $GLOBALS['aggregate_test_connection'] ?? null;
	$name = $GLOBALS['aggregate_test_database'] ?? '';
	if ($connection instanceof PDO && preg_match('/^kadupul_test_aggregate_[a-f0-9]{16}$/D', $name)) {
		if ($connection->inTransaction()) {
			$connection->rollBack();
		}
		$connection->exec('DROP DATABASE ' . $name);
	}
	unset($GLOBALS['aggregate_test_connection'], $GLOBALS['aggregate_test_database']);
});

function aggregateDatabaseRows() {
	return $GLOBALS['aggregate_test_connection']->query('SELECT * FROM aggregate_graphs_graph_item
		ORDER BY aggregate_graph_id, graph_templates_item_id')->fetchAll(PDO::FETCH_ASSOC);
}

function aggregateDatabaseItems($child = 11) {
	return array(array('aggregate_graph_id' => 1, 'graph_templates_item_id' => $child));
}

it('replaces only the bound parent using native prepared statements', function () {
	expect(aggregate_graph_items_save(aggregateDatabaseItems(), 'aggregate_graphs_graph_item'))->toBeTrue();
	$rows = aggregateDatabaseRows();
	expect((int) $rows[0]['graph_templates_item_id'])->toBe(11);
	expect((int) $rows[1]['graph_templates_item_id'])->toBe(20);
});

it('rejects malformed parents before executing any mutation', function () {
	$before = aggregateDatabaseRows();
	$items = array(array('aggregate_graph_id' => '1 OR 1=1', 'graph_templates_item_id' => 11));
	expect(aggregate_graph_items_save($items, 'aggregate_graphs_graph_item'))->toBeFalse();
	expect($GLOBALS['database_total_queries'])->toBe(0);
	expect(aggregateDatabaseRows())->toBe($before);
});

it('restores old items after a real duplicate-key failure', function () {
	$before = aggregateDatabaseRows();
	$items = array_merge(aggregateDatabaseItems(), aggregateDatabaseItems());
	expect(aggregate_graph_items_save($items, 'aggregate_graphs_graph_item'))->toBeFalse();
	expect(aggregateDatabaseRows())->toBe($before);
	expect($GLOBALS['aggregate_test_connection']->inTransaction())->toBeFalse();
});

it('preserves caller work after a replacement fails inside its transaction', function () {
	$connection = $GLOBALS['aggregate_test_connection'];
	$connection->beginTransaction();
	$connection->exec("INSERT INTO aggregate_graphs_graph_item
		(aggregate_graph_id, graph_templates_item_id, color_template, item_skip, item_total)
		VALUES (3, 30, 0, '', '')");
	$before = aggregateDatabaseRows();
	expect(aggregate_graph_items_save(array_merge(aggregateDatabaseItems(), aggregateDatabaseItems()),
		'aggregate_graphs_graph_item'))->toBeFalse();
	expect($connection->inTransaction())->toBeTrue();
	expect(aggregateDatabaseRows())->toBe($before);
});

it('leaves successful caller-owned work uncommitted', function () {
	$connection = $GLOBALS['aggregate_test_connection'];
	$before = aggregateDatabaseRows();
	$connection->beginTransaction();
	expect(aggregate_graph_items_save(aggregateDatabaseItems(), 'aggregate_graphs_graph_item'))->toBeTrue();
	expect($connection->inTransaction())->toBeTrue();
	$connection->rollBack();
	expect(aggregateDatabaseRows())->toBe($before);
});

it('batches more than the native parameter limit without losing other parents', function () {
	$items = array();
	for ($id = 100; $id < 7100; $id++) {
		$items[] = array('aggregate_graph_id' => 1, 'graph_templates_item_id' => $id);
	}
	expect(aggregate_graph_items_save($items, 'aggregate_graphs_graph_item'))->toBeTrue();
	expect(aggregateDatabaseRows())->toHaveCount(7001);
	expect($GLOBALS['database_total_queries'])->toBe(8);
});

it('rejects a nontransactional table without deleting its existing items', function () {
	$GLOBALS['aggregate_test_connection']->exec("ALTER TABLE aggregate_graphs_graph_item
		ENGINE=MyISAM COMMENT='Misleading metadata\n) ENGINE=InnoDB'");
	$before = aggregateDatabaseRows();
	expect(aggregate_graph_items_save(aggregateDatabaseItems(), 'aggregate_graphs_graph_item'))->toBeFalse();
	expect(aggregateDatabaseRows())->toBe($before);
	expect($GLOBALS['database_total_queries'])->toBe(0);
});

it('rejects a temporary table shadow on the mutation connection', function () {
	$connection = $GLOBALS['aggregate_test_connection'];
	$definition = $connection->query('SHOW CREATE TABLE aggregate_graphs_graph_item')->fetch(PDO::FETCH_NUM);
	$connection->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $definition[1]));
	expect(aggregate_graph_items_save(aggregateDatabaseItems(), 'aggregate_graphs_graph_item'))->toBeFalse();
	expect($GLOBALS['database_total_queries'])->toBe(0);
	$connection->exec('DROP TEMPORARY TABLE aggregate_graphs_graph_item');
	expect(aggregateDatabaseRows())->toHaveCount(2);
});

it('holds the checked engine stable until transaction completion', function () {
	$connection = $GLOBALS['aggregate_test_connection'];
	$connection->beginTransaction();
	expect(aggregate_graph_items_transactional_table($connection, 'aggregate_graphs_graph_item'))->toBeTrue();
	$other = new PDO('mysql:host=127.0.0.1;port=' . $GLOBALS['database_port']
		. ';dbname=' . $GLOBALS['aggregate_test_database'],
		getenv('KADUPUL_AGGREGATE_TEST_USER') ?: 'root',
		getenv('KADUPUL_AGGREGATE_TEST_PASSWORD') ?: '',
		array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
	$other->exec('SET SESSION lock_wait_timeout=1');
	$blocked = false;
	try {
		$other->exec('ALTER TABLE aggregate_graphs_graph_item ENGINE=MyISAM');
	} catch (PDOException $error) {
		$blocked = ($error->errorInfo[1] ?? null) === 1205;
	}
	expect($blocked)->toBeTrue();
	$connection->rollBack();
	expect($other->exec('ALTER TABLE aggregate_graphs_graph_item ENGINE=MyISAM'))->not->toBeFalse();
});

it('uses the template parent column and restores its items on a failed replacement', function () {
	$connection = $GLOBALS['aggregate_test_connection'];
	$schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
	expect(preg_match('/CREATE TABLE `aggregate_graph_templates_item` \(.*?;\R/s', $schema, $matches))->toBe(1);
	$connection->exec($matches[0]);
	$connection->exec("INSERT INTO aggregate_graph_templates_item
		(aggregate_template_id, graph_templates_item_id, color_template, item_skip, item_total)
		VALUES (1, 10, 0, '', ''), (2, 20, 0, '', '')");
	$item = array('aggregate_template_id' => '01', 'graph_templates_item_id' => 11);
	expect(aggregate_graph_items_save(array($item), 'aggregate_graph_templates_item'))->toBeTrue();
	$select = 'SELECT * FROM aggregate_graph_templates_item ORDER BY aggregate_template_id, graph_templates_item_id';
	$before = $connection->query($select)->fetchAll(PDO::FETCH_ASSOC);
	expect(array_map('intval', array_column($before, 'graph_templates_item_id')))->toBe(array(11, 20));
	expect(aggregate_graph_items_save(array($item, $item), 'aggregate_graph_templates_item'))->toBeFalse();
	expect($connection->query($select)->fetchAll(PDO::FETCH_ASSOC))->toBe($before);
	$item['aggregate_template_id'] = '1 OR 1=1';
	expect(aggregate_graph_items_save(array($item), 'aggregate_graph_templates_item'))->toBeFalse();
	expect($connection->query($select)->fetchAll(PDO::FETCH_ASSOC))->toBe($before);
});
