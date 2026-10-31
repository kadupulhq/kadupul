# Installer Scan Mode

The Scan Mode part of #1116 affects both supported branches. The browser submits
an unchecked checkbox as boolean false, while the CLI supplies the same
AutomationMode parameter as a string. The private setter ignored false, zero and
empty strings through a loose null comparison. The getter could then treat a
stored empty value as missing and restore the enabled network default.

Only an absent setting now falls back to the network row. Only null skips the
setter. Existing setTrueFalse validation and stored representations are preserved,
including all five accepted false values and true values. Null/omitted parameters
retain their defaults. No existing network is changed just by deploying this code.
The correction applies when the normal installer later saves its selected network.

InstallerAutomationModeTest runs the full original Installer class in native PHP
children. Reflection bypasses construction, then invokes actual parameter mapping,
settings reload and installServer. Actual read_config_option/default handling and
set_install_config_option execute against private SQLite settings/profile/network
tables through a PDO database adapter. The tested settings/network column names,
widths and null/default semantics follow cacti.sql; this is not a MySQL engine test.
Main uses its supported pre-Composer settings fallback. Logger and translation
functions are fixtures. A caught sentinel stops immediately after the actual
network UPDATE, before unrelated device creation, cache work or key generation.

PHP8.1.34/LTS and PHP8.4.25/main each pass22 cases and150 assertions. The unchanged
regression file fails six original assertions with16 controls passing. It checks
runtime mode, actual stored setting bytes, forced reload despite stale web/CLI
caches, persisted network enablement, subnet retention and errors. No browser,
HTTP, installed DBAL/MySQL, full installation or production discovery is claimed.
Main registers the suite in required spikekill CI; the LTS complete isolated unit
runner discovers every Unit file and explicitly supplies pdo_sqlite on PHP8.1–8.4.

No schema, dependency, runtime, translation or existing stored-data migration is
needed. Reverting restores the original null comparisons, without undoing settings
or network rows already saved. This does not repair an installation that already
enabled discovery; review its network setting normally. The independent stale-CSRF
transport/retry defect remains open under#1116. No release/deployment is claimed.
