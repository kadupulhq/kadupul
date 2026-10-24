# 0003. Standalone protocol libraries

## Status

Proposed.

## Context

Kadupul needs clients and parsers for Redfish, gNMI, YANG, NETCONF, RESTCONF
and OpenConfig. These are useful outside Kadupul, have their own release
cadence and security surface, and should be testable without the application.

## Decision

Each protocol is intended to be a standalone Composer package: `php-redfish`,
`php-gnmi`, `php-yang`, `php-netconf`, `php-restconf` and `php-openconfig`.
None of these packages exists yet. Names are provisional and no repository has
been created. Until they exist, Kadupul defines ports for what it needs and
provides adapters only where a real use case requires one; it does not add
placeholder clients.

Rules for the packages:

- Dependency direction is one way. Kadupul depends on a library through an
  adapter in `Infrastructure`. A library never depends on Kadupul, Symfony
  application services or Kadupul vocabulary.
- Domain and Application code in Kadupul depend on ports, never on a library
  type.
- A generic gNMI or NETCONF client must not require OpenConfig. OpenConfig
  models are one consumer of those protocols. `php-openconfig` may depend on
  `php-yang` and, optionally, on client packages; the reverse is not allowed.
- Credentials are passed in by the caller. Libraries do not read Kadupul
  configuration or secret stores.
- TLS verification is on by default in every client.

## Consequences

- Libraries can be reviewed, versioned and fuzzed on their own and reused.
- Kadupul pins versions and carries adapter code for each.
- Releasing a fix touches two repositories. Until the packages exist, the
  ports are the only commitment.
- Whether some packages should be merged (for example NETCONF and RESTCONF
  share YANG handling) is open and is not decided here.

## Alternatives

- Implement protocols inside Kadupul modules. Rejected: couples protocol
  releases to the application and prevents reuse.
- One combined protocol library. Rejected: forces users of one protocol to
  take the dependencies of all.
- Depend on existing third-party PHP libraries. Not ruled out; each candidate
  needs the same review for maintenance, licence and TLS behaviour before an
  adapter targets it.
