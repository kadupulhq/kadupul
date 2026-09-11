# Fork import

Everything in this repository so far is scaffolding around an empty tree, and
none of it is validated until real code lands. This is the plan for landing it.

## Preserve the history

Import Cacti's full history. Do not squash.

Three reasons. The licence requires preserving authorship and copyright, and the
commit history is the cleanest record of both. `git blame` is how anyone debugs
poller logic written in 2009 by someone who left a decade ago, and a squash
destroys that permanently for no recoverable gain. And a fork that discards the
history of the project it forked reads as a land grab rather than a
continuation.

The cost is real: a large repository, and twenty-three years of someone else's
commit conventions that our own rules would reject. Accept both. They are paid
once.

| | |
|---|---|
| Commits on develop | 15,989 |
| Oldest commit | 18 May 2002 |
| Tags | 87 |
| Repository size | about 306 MB |

The working checkout at hand is marked shallow. Take a full clone before
importing, or the graft point lands in the middle of the history.

## Rename the product, not the interface

This is the decision everything else hangs on.

The README promises plugins and templates keep working. That promise and a
thorough rename cannot both hold. Every existing plugin calls `cacti_*`
functions and registers against `api_plugin_hook` names, so renaming those
breaks every plugin on the first release, which is the population we most need.

So the rename stops at the plugin API boundary.

### What changes

- The product name everywhere a human reads it: interface, page titles, logos,
  documentation, the user agent.
- The version file, `include/cacti_version`.
- Default database name and user, for new installs only. An upgrade keeps what
  it has.
- Session and cookie names, with the old ones read once and migrated so nobody
  is logged out by upgrading.
- The default URL path, currently `/cacti/`.
- Log file names and the paths in the packaging.

### What must not change

| Surface | Count | Why it stays |
|---|---|---|
| `cacti_*` functions | 89 defined, 4,814 call sites | Plugins call them directly |
| `api_plugin_*` functions | 47 | The registration surface itself |
| Hook names | 137 distinct | A renamed hook silently never fires |
| `CACTI_*` constants | 63 | Plugins read them |
| Database table and column names | all | Plugins query them, and reports read them |
| `$config` keys | all | Plugins index into it |

A silently renamed hook is the worst failure mode here. The plugin loads, the
hook never fires, and nothing logs an error. If any hook name ever has to move,
it needs an alias and a deprecation notice, not a rename.

### Session keys

There are 67 distinct session keys, and `cacti_remembers` is a cookie as well as
a session value. Renaming the session without a migration logs out every user
and silently breaks remember-me. Read the old names once, write the new, delete
the old.

## Then the migration tool

Nobody adopts a monitoring system by starting over. A credible path from a
running Cacti install is worth more than every piece of infrastructure in this
repository, and it is the only thing here that is actually the fork's
proposition rather than its packaging.

It has to handle the database, the RRD files, the configuration, and the
installed plugins, and it has to be reversible, because an operator will not run
a one-way tool against a production monitoring system.

## Order

1. Full clone, import with history, confirm the tree builds and the tests run.
2. Rename the product surface. Leave the plugin API alone.
3. Dockerfile, decided as its own thing: carry Cacti's over, or rewrite it with
   a non-root user and a pinned base.
4. Migration tool.
