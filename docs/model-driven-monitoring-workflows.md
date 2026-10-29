# Model-driven monitoring workflows

Two Symfony state machines in `config/packages/workflow.yaml` state the
lifecycles of capabilities and metric bindings. They mirror rules the domain
already enforces; `tests/Symfony/ModelDrivenWorkflowTest.php` compares them with
the domain for every pair of states and with these diagrams. Regenerate a
diagram with `bin/console workflow:dump <name> --dump-format=mermaid` and run
the test.

## Capability

Changes a rediscovery stores (`DiscoverDeviceCapabilities`). A verified,
partial or unsupported outcome is read from the device's model and is replaced
only by another such outcome, so nothing returns to advertised or unknown once a
model has been read. [ADR 0001](adr/0001-model-driven-monitoring.md) names the
five states and the first step from advertised; the other moves are what the
merge in `DiscoverDeviceCapabilities` stores today.

```mermaid
graph LR
place0(["advertised"])
place1(("verified"))
place2(("partial"))
place3(("unsupported"))
place4(("unknown"))
place0-->|"verify"|place1
place4-->|"verify"|place1
place2-->|"verify"|place1
place3-->|"verify"|place1
place0-->|"mark_partial"|place2
place4-->|"mark_partial"|place2
place1-->|"mark_partial"|place2
place3-->|"mark_partial"|place2
place0-->|"mark_unsupported"|place3
place4-->|"mark_unsupported"|place3
place1-->|"mark_unsupported"|place3
place2-->|"mark_unsupported"|place3
place0-->|"mark_unknown"|place4
place4-->|"advertise"|place0
```

## Metric binding

The moves `BindingStatus::canMoveTo` allows, per
[ADR 0008](adr/0008-data-source-ownership-and-bindings.md). `approve` is guarded:
the guard asks IdentityAccess for the signed-in actor and the binding-approval
permission, denies by default, and audits every decision. A binding is stored
only after the guard allows the move; the stored status is the domain's, not the
workflow's.

```mermaid
graph LR
place0(["proposed"])
place1(("approved"))
place2(("verified"))
place3(("rejected"))
place4(("stale"))
place0-->|"approve"|place1
place1-->|"verify"|place2
place1-->|"mark_stale"|place4
place2-->|"mark_stale"|place4
place0-->|"reject"|place3
place1-->|"reject"|place3
place4-->|"reject"|place3
```
