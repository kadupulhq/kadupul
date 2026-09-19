<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Exercise PDO transaction ownership without an engine-specific SQL probe. */

require_once dirname(__DIR__, 3) . '/lib/database.php';

class FakeDbCommitConn {
    public $active;
    public $checked = false;
    public $commit_called = false;
    public $result = true;
    public function __construct($active) { $this->active = (bool) $active; }
    public function inTransaction() { $this->checked = true; return $this->active; }
    public function commit() { $this->commit_called = true; return $this->result; }
}

beforeEach(function () {
	global $database_hostname, $database_port, $database_default, $database_sessions;

	$database_hostname = 'default-host';
	$database_port     = '3306';
	$database_default  = 'cacti';

	// The pooled default connection: no active transaction (active false),
	// so it must never be committed by this test.
	$this->default_conn = new FakeDbCommitConn(0);
	$database_sessions["$database_hostname:$database_port:$database_default"] = $this->default_conn;

	// The connection explicitly passed to db_commit_transaction(): has an
	// active transaction (active true) and must be the one queried/committed.
	$this->passed_conn = new FakeDbCommitConn(1);
});

test('db_commit_transaction checks the passed connection, not the default one', function () {
	db_commit_transaction($this->passed_conn);

	expect($this->passed_conn->checked)->toBeTrue();
	expect($this->default_conn->checked)->toBeFalse();
});

test('db_commit_transaction commits the passed connection when it has an active transaction', function () {
	db_commit_transaction($this->passed_conn);

	expect($this->passed_conn->commit_called)->toBeTrue();
	expect($this->default_conn->commit_called)->toBeFalse();
});

test('db_commit_transaction does not commit when the passed connection has no active transaction', function () {
	$idle_conn = new FakeDbCommitConn(0);

	db_commit_transaction($idle_conn);

	expect($idle_conn->checked)->toBeTrue();
	expect($idle_conn->commit_called)->toBeFalse();
});

test('db_commit_transaction preserves a failed commit result', function () {
    $this->passed_conn->result = false;
    expect(db_commit_transaction($this->passed_conn))->toBeFalse();
});
