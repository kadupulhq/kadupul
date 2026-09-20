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

Platform owns the health controller. IdentityAccess owns the current-actor query,
its public Actor DTO and the legacy authenticated-session adapter. Other
features remain legacy code until migrated. New modules are introduced with a
working use case, rather than empty entity/repository scaffolding.

## Inside a module

```text
src/
  Kernel.php                         Symfony composition root
  Inventory/                         Example next module
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

The next vertical slice is Inventory device listing, followed by editing. The
read-only shared-session bridge establishes identity while legacy code continues
to own authentication. Inventory must add explicit device authorization before
exposing its routes; console access alone does not grant device access.
