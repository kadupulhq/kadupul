# Installer SNMP override values

`Installer::setAutomationOverride()` compared its input loosely with null. PHP
therefore treated integer zero, boolean false and an empty string as an omitted
value. An existing enabled override remained enabled after those submissions.
The native `setTrueFalse()` helper already accepts these disabled values.

The correction uses a strict null comparison. It changes future installer
submissions only and leaves existing settings untouched. Omitted input still
retains the current value. This is the LTS maintenance correction for #1300;
the equivalent main correction is included in the 1.3 installer work (#1293).
Neither correction is released merely because its tests pass or its PR merges.

Run the focused regression from `tests/` using the locked test dependencies:

```sh
mise exec php@8.1.34 -- php vendor/bin/pest --test-directory=. --bootstrap=bootstrap-unit.php Unit/Installer/InstallerSnmpOverrideTest.php --colors=never
```

The fixture loads the production installer class without running its constructor
and extracts the complete production `set_install_config_option()` through
`tests/Helpers/PhpSource.php`. The actual setter, boolean normalizer and
persistence SQL run against an isolated in-memory SQLite settings table.
Logging is suppressed at the fixture boundary. The test compares persisted
values and the native object's effective override flag.

On PHP 8.1.34, three of seven cases fail before correction: integer zero,
boolean false and empty string. All seven pass afterward with 21 assertions.
The controls cover string zero, the false string, enabled input and omission.
The test is registered in the existing unit inventory and discovered by the
complete LTS unit runner. No packages, schema, minimum runtime or frontend
contracts change. No installed LTS browser, remote collector or MySQL result is
claimed. Reverting the one-line correction restores the former handling of
future submissions; it does not rewrite saved settings.
