<!-- SPDX-FileCopyrightText: 2026 The Kadupul project and contributors -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Native session persistence

Database sessions keep the existing sessions table and native PHP serialization
formats (`php`, `php_binary` and `php_serialize`). Other configured serializers
remain unsupported by this adapter and fail explicitly. Each operation confirms the selected PDO and schema, persistent InnoDB
storage and driver outcomes. Existing caller transactions retain ownership;
a callback cannot acknowledge their uncommitted work as durable. Activity fields
retain their later value while other session bytes retain the submitted state.
Logout updates existing rows, including their stored account metadata.

The legacy callback facade delegates to a native store. Its deferred selection
callback uses the same live connection registry as the database helpers, including
bootstrap invoked from another scope. Authentication transitions retain their
existing separate fresh-session insertion boundary. Display metadata fits the
existing address and UTF-8 user-agent columns; it does not replace the full
request identity used for authentication or address policy.

Defensive regressions use actual native serializers and isolated shipped-schema
MariaDB/MySQL tables. They cover concurrent requests, caller-owned transactions,
failed reads/writes/readback/commit, table identity, selection changes and native
web registration. Subprocess coverage binds measured sources and completion
markers to the actual parent PHPUnit stack; missing or stale evidence fails.
Retained address diagnostics test their persistence argument boundary separately
from the actual database-session engine cases.

Deploy the facade, three helper libraries and authentication metadata adapter
together. No dependency, minimum-runtime or schema upgrade is introduced. Existing
startup repair of optional session columns retains its compatibility role and
requires the existing database privileges. Unsupported storage or unconfirmed
operations fail explicitly rather than reporting a completed save.

Drain in-flight requests and long-running workers before switching versions.
Restart the usual PHP workers and clear application caches through the established
deployment procedure. A code rollback requires the same drain and complete-file
replacement; the stored native formats remain unchanged. Code rollback does not
reverse completed session changes. Current-head CI, review and supported-branch
release coordination remain required before deployment.
