# Upgrade and rollback rehearsal

`tests/Support/Behavior/release_readiness.py` builds the actual baseline source,
installs its schema in a disposable MariaDB container, creates and polls RRD data,
then upgrades that database using the candidate installer. It preserves an enabled
compatibility plugin and a custom watermark. Before another poll changes samples,
it compares the RRD byte hashes and database records against the snapshot.

The rehearsal checks login, the production graph renderer's PNG output, plugin
callbacks and polling. It repeats the upgrade both before and after completed-wizard settings are cleared
to check idempotence. Rollback
restores the baseline image **and** its matching database and RRD snapshot, checks
the restored records and hashes, then verifies login, graphing, plugins and polling
again. It never points at an existing installation.

```sh
git fetch https://github.com/Cacti/cacti.git 6482af547c204199e829b7a0df0b7a13db3e0a58
PHP_VERSION=8.2 mise exec python@3.12.12 -- python tests/Support/Behavior/release_readiness.py \
  --baseline 6482af547c204199e829b7a0df0b7a13db3e0a58
```

The pinned baseline is release 1.2.30. Evidence goes to
`tests/behavior/results/release-readiness/<project>/observations.json`; an exception or
failed invariant leaves `complete: false` and returns a nonzero status. The
manifest records source revisions, runtime provenance, RRD hashes and observed
commands. Database dumps and raw snapshots live only in a temporary directory.
The unique `kadupul-release-<uuid>` Docker project is removed after the run.

This verifies the fixture upgrade path and compatibility plugin. It does not
establish compatibility with every external plugin or every historical schema.
A production rollout still needs a quiesced, restorable backup from that specific
installation; replacing application files alone is not a database rollback.

Each invocation uses a unique Compose project, image tags and lock. Cleanup
only removes that invocation’s resources. Invalid baseline revisions and
unavailable Docker still produce `complete: false` observations. Default evidence paths include the unique project name. If overriding
`--output`, use distinct paths for concurrent rehearsals.
