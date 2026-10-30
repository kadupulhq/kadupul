# LTS enterprise readiness

Statuses describe the stated requirement only; they do not certify the release.

| Requirement and acceptance criteria | Subsystem | Status | Evidence | Gaps and risks | Release gate |
|---|---|---|---|---|---|
| POSIX RRDtool one-shot and proxy failures terminate under their deadlines, preserve failure results, and never parse a failed fetch as measured zero. Partial proxy writes are completed and failed sockets are closed. | Collection and graphing | implemented | [Maintenance scope and regression evidence](testing/rrd-command-failures.md): 133 LTS tests on PHP 8.1.34, including 46 new transport/caller cases. | Measured coverage, deployed proxy/browser/database workflows and native Windows proxy execution remain unverified. Windows one-shot I/O remains open in #782. A remote timeout cannot roll back an executed mutation. | Require branch CI and independent review before release. No release is claimed. |
| Windows graph and fetch responses retain payload bytes and remove only a complete final RRDtool success trailer. | Graphing response parsing | implemented | [Trailer regression evidence](testing/rrd-output-trailers.md): 58 LTS cases pass on PHP 8.1.34/8.4.25; 49 fail on the old parser. Main's equivalent 58 cases pass on PHP 8.4.25. | Native Windows CI and deployed graph/fetch workflows remain pending; Windows one-shot I/O is separately tracked in #782. | Require native Windows CI and branch checks before release. |
