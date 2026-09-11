# Versioning

Kadupul follows [Semantic Versioning 2.0.0](https://semver.org/). Releases are
tagged `vMAJOR.MINOR.PATCH`, and the tag is the only source of truth for what a
release contains.

## What the version number covers

Semantic versioning only means something once you say what the public interface
is. For Kadupul it is these five things, and nothing else:

1. **The plugin API.** Hook names, their arguments, and the functions a plugin
   is expected to call.
2. **The database schema**, as far as a plugin or an external report reads it.
3. **The command line interface**: script names, their flags, exit codes, and
   the shape of what they print to stdout.
4. **The configuration file** and the settings stored in the database.
5. **The HTTP API**, once there is one.

The web interface, internal functions, file layout, and anything marked
experimental are not covered. They can change in a patch release.

## What forces a major release

- Removing or renaming a plugin hook, or changing what it is passed.
- A schema migration a plugin cannot survive without a code change.
- Removing a CLI flag, or changing what an existing one does.
- Raising the minimum PHP, MySQL or RRDtool version.
- Changing a default in a way that alters what gets polled or stored.

## What forces a minor release

New hooks, new CLI flags, new settings, new graph or data source types, and
anything else additive. A plugin written against the previous minor keeps
working.

## What is a patch

Bug fixes and security fixes that keep the interface identical.

## Security releases

A security fix ships as a patch on every supported branch. It is never bundled
with a feature, so an operator can take the fix without taking anything else.

## Where the numbering starts

Kadupul continues Cacti's version line rather than restarting at zero. The code
is twenty-three years old and carries a compatibility promise from its first
release, and `v0.1.0` would say the opposite of both.

The fork point is Cacti 1.2.31, so the first Kadupul release is **`v1.3.0`**.
That is the number an operator running 1.2.31 would expect to see next, and the
continuity is the point: a fork that resets its version number asks every user
to work out where they are.

1.2.32 is not available: Cacti's 1.2.x branch already declares that version and
is building it now, with security work in it. Two releases of a monitoring tool
sharing a patch number means nobody can answer "do you have that fix" from a
version string, which is the one place a collision does real harm.

Cacti's development branch also carries 1.3.0, but 1.3 has never been released.
If Cacti ships it later, two 1.3.0 releases exist. That is a cost accepted
deliberately, and a much smaller one than colliding on a security patch line.

## The fork point and the security line

The split is Cacti 1.2.31, the release, deliberately. It is reproducible and it
is a version operators actually run.

That is separate from what gets carried forward. Cacti's 1.2.x branch has 107
commits since that release, 28 of them security work. Those land here as
reviewed cherry-picks on top of the fork point, not as a merge of the branch.
Taking the security work without the other 79 changes is the point: it halves
the footprint, 291 files rather than 630, and every one arrives as a deliberate
decision.

## Commits and releases

Commits follow [Conventional Commits](https://www.conventionalcommits.org/).
`feat:` implies a minor, `fix:` a patch, and a `!` or a `BREAKING CHANGE:`
footer implies a major. The mapping is a guide for the maintainer cutting the
release, not an automation that tags on its own. Releases are deliberate.
