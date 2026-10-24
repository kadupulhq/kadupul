# 0006. YANG processing strategy

## Status

Proposed. Decision: libyang is not adopted yet. It must be assessed before
anything requires it.

## Context

Resolving YANG imports, augments, deviations and types correctly is complex.
libyang is the widely used C implementation. No maintained pure-PHP YANG
compiler of comparable coverage is assumed to exist; this has not been
surveyed.

## Decision

Kadupul does not implement a YANG compiler. YANG handling is delegated to a
library behind a port (for example `php-yang`, see
[0003](0003-standalone-protocol-libraries.md)). The first milestones need only
module name, revision and namespace from capability advertisements (gNMI
capabilities, NETCONF hello) and do not need schema compilation. libyang is
therefore not a dependency, and no deployment requirement is added for it.

Before any feature requires schema compilation, the options below are assessed
against these criteria: ABI stability across libyang releases, packaging on the
supported platforms, behaviour in minimal containers and on the offline
install path, memory and process model, licence compatibility with
GPL-3.0-or-later distribution, security update path, and testability in CI.

| Option | Notes to evaluate |
| --- | --- |
| PHP FFI binding to libyang | Needs the FFI extension enabled, which many hardened PHP builds disable; couples PHP to the libyang ABI; shared library must be present on every host. |
| Sidecar process (a small tool that wraps libyang) | Isolates crashes and the ABI; adds a process, a protocol and a binary to package and sign; fits the independent-collector direction. |
| Pre-compiled JSON | Compile models at build or publish time and ship JSON; no native code at runtime; cannot handle models the build did not include, and the build tool still needs libyang. |

libyang is BSD-3-Clause licensed; compatibility with the project's licence for
linking or bundling has to be confirmed in the assessment, not assumed.

## Consequences

- No new native dependency now.
- Features that need type or constraint knowledge (validating values, deriving
  paths from a module) wait for the assessment.
- The assessment result will be recorded as a new ADR that supersedes this one.

## Alternatives

- Adopt libyang via FFI now. Rejected: deployment impact before a requirement.
- Write a PHP YANG parser. Rejected: large surface, high correctness risk.
- Ignore YANG and use OpenConfig paths as opaque strings. Acceptable for the
  first milestones and what this decision implies.
