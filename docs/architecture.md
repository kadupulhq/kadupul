# Application architecture

Main adopts domain-driven design for module boundaries, hexagonal architecture
inside each module, and Symfony 7.4 as the application framework. LTS is unchanged.

## Module boundaries

These are initial boundaries to validate against real use cases during migration,
not a claim that the procedural application has already been decomposed.

| Module | Owns | Does not own |
| --- | --- | --- |
| Inventory | Device identity, sites, device groups and desired configuration | Collection scheduling or measurement storage |
| Collection | Polling schedules, collector ownership, collection runs and sample acceptance | Device administration or graph presentation |
| Graphing | Graph definitions, render/export requests and RRD integration | User identity or collector scheduling |
| IdentityAccess | Identity, role assignments, authorization policy and audit attribution | Device or graph business rules |
| Alerting | Alert rules, evaluation, incidents and notification intent | Mail transport or device configuration |
| Platform | Operational health and application integration infrastructure | Business rules shared merely for convenience |

Symfony owns the application lifecycle and composition root. Platform owns
health, response security headers and installation configuration adapters.
IdentityAccess owns the current-actor query and public Actor/ConsoleAccess
contracts. Inventory owns device-list criteria, its ListDevices use case,
read models and DeviceCatalog port, plus the Device aggregate, EditDevice command
and DeviceEditor port. Site administration reads use the ListSites query and
SiteCatalog port; site device counts share Inventory’s device visibility adapter. Other
features remain legacy code until migrated. New modules are introduced with a
working use case, rather than empty entity/repository scaffolding.

## Inside a module

```text
src/
  Kernel.php                         Symfony composition root
  Inventory/                         Device administration module
    Domain/                          Entities, value objects, invariants, events
    Application/
      UseCase/                       Commands/queries and orchestration
      Port/                          Required persistence/integration interfaces
    Contract/                        Public module API and integration event DTOs
    Infrastructure/
      Symfony/Controller/            HTTP adapters
      Console/                       CLI adapters
      Persistence/                   Database adapters
      Legacy/                        Explicit transitional legacy adapters
  Platform/
    Infrastructure/Symfony/Controller/HealthController.php
```

Dependencies point inward: Infrastructure may depend on Application and Domain;
Application may depend on Domain and its ports; Domain is independent of Symfony,
Doctrine, HTTP requests, the service container, the database and legacy globals.
Framework attributes belong on adapters, not domain entities or use cases.

Controllers translate input into use-case arguments and translate results into
responses. Application services own orchestration and transaction boundaries.
Domain objects enforce business rules. Infrastructure implements outbound ports
for SQL, SNMP, RRDtool, mail, clocks and other external capabilities as needed.
Do not add interfaces solely to wrap every class; ports describe real external
dependencies or module boundaries.

Symfony wires adapters to ports explicitly in configuration. Domain entities and
contract DTOs are not automatically registered as services. The initial service
configuration registers infrastructure and explicitly selected application services.

## Between modules

A module calls another module's public `Contract` API or consumes its published
integration events. It must not import another module's domain entities,
persistence adapters or internal use cases, and must not write another module's
tables directly. Use stable IDs and explicit DTOs across boundaries.

Keep synchronous calls for operations that need an immediate result. Introduce
asynchronous delivery only with explicit retry, idempotency and failure behavior;
use an outbox when a committed state change must reliably publish an event.
Distributed transactions and event sourcing are not prerequisites.

The existing schema and database remain in place initially. Ownership can be
made explicit without creating a separate database or service for each module.
Avoid a catch-all Shared domain. Share only small, stable concepts whose meaning
is agreed by the participating modules.

## Legacy migration

An `Infrastructure/Legacy` adapter may call the existing application while a
use case is migrated. Those dependencies cannot leak into Domain or Application.
Do not include a legacy page script inside a controller; its bootstrap, output,
session and termination behavior require a deliberate boundary.

Validate domain invariants with fast unit tests, use cases against fake ports,
and real adapters with integration tests. Keep the behavioral harness as the
external compatibility check for authentication, plugins, devices, collection
and graphing. A feature is migrated when its legacy entry point can be removed
without losing those contracts.

The Inventory list is the first migrated read slice. Symfony owns its routes,
controller and Twig rendering. Its application use case depends on a catalog port
and IdentityAccess's public access contract. Native session and legacy-schema SQL
are confined to adapters; new routes do not bootstrap the procedural application.
Platform's PDO/configuration contracts are technical integration APIs used only
by infrastructure, never domain/application services.

Device editing now covers name, address, location, external ID, notes and polling state through a Device aggregate,
EditDevice command, DeviceEditor port and Symfony Form/CSRF adapters. The write
adapter isolates the legacy save API in a CLI process, rechecks authorization and
revision under a row lock, and preserves graph/poller/plugin effects. The local
transaction cannot roll back arbitrary external effects. This is a transitional
adapter to retire as owning modules acquire explicit integration contracts.
Remaining device settings and bulk actions still use the legacy editor.

## Cutover and retirement criteria

- Inventory list: migrate required filters, saved preferences, exports, plugin
  contributions and navigation; compare behavior before replacing `host.php`.
- Inventory writes: move edit/bulk commands, validation, resource authorization,
  transactions and audit effects into the module; retire corresponding legacy
  dispatch actions only after regression coverage passes.
- IdentityAccess: migrate credential issuance, external providers, remember-me,
  logout and CSRF to Symfony security integration before removing native-session
  compatibility. Symfony already owns new HTTP requests and access decisions.
- Persistence: replace legacy-schema adapters as owning modules evolve their
  schema and contracts. Temporary read projections may join the old shared
  schema; they must not write another module's data.

`tests/Symfony/ArchitectureTest.php` checks inward dependencies, cross-module
contract usage, framework isolation and entry points. Behavioral HTTP tests cover
the adapters against a disposable database. Both are required CI checks.
