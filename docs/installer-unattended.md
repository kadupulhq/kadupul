# Unattended installer configuration

Use the existing PHP 8.4 and Symfony 7.4 stack. Prepare the database and eligible
installation operator, and provide the shared application secret as described in
[the installer workflow](installer-1.3.md). Configuration validation does not
provision an administrator or replace installation readiness checks.

Store a JSON object in a protected configuration file outside the document root:

```json
{
  "Eula": true,
  "AutomationMode": "0",
  "Profile": "1",
  "SnmpOptions": {
    "SnmpCommunity": {"env": "INSTALLER_SNMP_COMMUNITY"},
    "SnmpPassword": {"env": "INSTALLER_SNMP_PASSWORD"},
    "SnmpPrivPassphrase": {"env": "INSTALLER_SNMP_PRIVACY_PASSWORD"}
  }
}
```

Supply those environment variables through the automation platform's secret
store. An explicit `{"env":"NAME"}` reference is supported only for the three
secret fields above, only in JSON configuration. Names must use letters, digits
and underscores, starting with a letter or underscore, with at most 255 bytes.
Missing variables, additional reference properties and references in nonsecret
fields are invalid. There is no fallback, interpolation, file access or shell
execution. Ordinary strings, including strings containing `${NAME}`, remain
literal. An existing variable containing an empty string explicitly clears the
secret; an omitted field retains its stored value. Normal installer limits also
apply to resolved values. Legacy INI and command-line inputs remain literal.
Explicit command-line options override configuration-file values.

Validate without starting a native worker or changing installation settings:

```sh
php bin/console kadupul:install --validate --configuration=/protected/install.json --output=json
```

This checks parsing, references and the shared option validation rules. It does
not check installed profiles, executable paths, authorization, database state or
other runtime prerequisites. Success returns `{"ok":true,"valid":true}` without
configuration values. `--validate` accepts the default action or
`--action=configure`; execution, status and recovery combinations are rejected.

Review runtime prerequisites separately, then explicitly authorize execution:

```sh
php bin/console kadupul:install --output=json
php bin/console kadupul:install --install --configuration=/protected/install.json --output=json
```

Discovery remains disabled in this example. Enabling it additionally requires
`--confirm-discovery`. Save backups before execution and follow the documented
recovery procedure after a failed or unconfirmed attempt.

With `--output=json`, command validation errors have this stable envelope:

```json
{"ok":false,"error":{"code":"invalid_configuration","message":"Accept the license before saving the installation plan."}}
```

Invalid action combinations use `invalid_arguments` instead. Messages do not
include secret values or environment-variable names. Exit status `0` means the
requested validation or operation succeeded; `2` means command/configuration
validation failed; `1` means a native operation failed, was blocked, or did not
confirm completion. Existing native JSON result shapes are unchanged. Errors
raised by Symfony before command execution, such as unknown flags, retain the
standard Console behavior.
