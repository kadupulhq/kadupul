# Interface catalog validation

The interface catalog makes the existing interface query and template identities reviewable as build inputs. It adds no monitoring capabilities or runtime configuration. Only the existing IF-MIB interface family is included.

`resource/catalogs/interface.json` records the ordered query properties, all 42 existing fields, the existing interface query, traffic graph and traffic data-template hashes, and their direct native dependency references. `interface.schema.json` validates the document with JSON Schema 2020-12. The schema rejects unknown properties; semantic checks reject duplicate fields, missing dependencies, changed RRD semantics and mismatches between metrics and native query bindings.

The renderer reconstructs `resource/snmp_queries/interface.xml` from the ordered properties, including its current formatting. Validation requires byte equality. The signed `Generic_SNMP_Device.xml.gz` package and its embedded query have separate fingerprints: the embedded query already differs from the standalone resource. Neither artifact is rewritten or repacked. Display names do not determine template identities.

Install the pinned build dependencies and run validation using the commands below. The Python dependencies are needed only for development and CI; Cacti-compatible PHP polling, Spine, database schemas, importers and installers continue to use their existing files and protocols.

```sh
mise exec -- python -m pip install --require-hashes -r .github/scripts/catalog/requirements.txt
mise exec -- python .github/scripts/catalog/interface_catalog.py
mise exec -- python .github/scripts/catalog/interface_catalog.py --render > /tmp/interface-catalog-rendered.xml
cmp resource/snmp_queries/interface.xml /tmp/interface-catalog-rendered.xml
```

The first catalog records existing traffic counters in octets, including 32-bit and high-capacity 64-bit query bindings. It preserves the shipped `COUNTER` data-source type, minimum, heartbeat and `|query_ifSpeed|` maximum expression. The standards references describe object semantics, not a claim that a particular device implements every query field: [IF-MIB](https://www.rfc-editor.org/rfc/rfc2863) and [IP-MIB](https://www.rfc-editor.org/rfc/rfc4293).

## Regression coverage

`InterfaceCatalogCounterTest` reads the actual bundled traffic template, calls the production `rrdtool_create_ds()` helper in a PHP subprocess and updates a temporary RRD using real RRDtool. It checks small differences between counters above 2^53, the existing 32-bit wrap correction, configured maximum handling, and unknown samples followed by a new counter baseline. Existing `COUNTER` behavior cannot distinguish every reset from a wrap. A low-value reset can produce a positive rate below the configured maximum; the test preserves and documents that ambiguity.

The interface cases in `ImportParentRollbackDatabaseTest` execute the production query importer against tables created from `cacti.sql` in a disposable database. Reimport keeps the query and association IDs and does not duplicate rows. Additional operator suggested values survive the default import and are removed when replacement is explicitly requested. This characterizes the existing import policy; imported template names and matching suggested values can still be replaced. Dependency IDs use the test's existing controlled database adapters; these cases do not establish a full installed graph-creation or upgrade rehearsal.

The RRD test is registered in the existing RRDtool CI suite. The import cases run in the existing database-contract matrix. Schema and mutation tests run with the pinned build dependencies. No live devices are involved. There is no installation or rollback step for operators because the shipped runtime artifacts are unchanged.
