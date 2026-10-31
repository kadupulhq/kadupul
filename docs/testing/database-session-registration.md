# Native database-session registration

The six-callback form of `session_set_save_handler` is deprecated in PHP 8.4.
Deprecation output can precede session startup or plugin headers. Both LTS and
main use that registration. This reliability fix registers a
`CactiDatabaseSessionHandler` object that delegates to the same six callbacks.
It keeps the explicit shutdown callback and disables automatic shutdown
registration on the object API to avoid adding a second registration.

The callback functions remain public. Their SQL, transaction ownership, error
handling, return values and session serialization are unchanged. The GC
callback's historical `true` acknowledgement still converts to integer 1 at
the PHP handler boundary. This does not change session storage, cookies,
authentication policy or collector roles.

The regression starts a disposable PHP web server, includes the complete
production `include/session.php`, and uses the shipped `sessions` DDL in an
owned random MariaDB schema. It exercises write, read, garbage collection,
destroy and an empty read through PHP's actual session engine. Every PHP
warning/deprecation fails the fixture. No production session or database is
used; the schema and server are cleaned in `finally`.

PHP 8.1.34 native execution and a PHP 8.4.25 child web server each pass one test
with 28 assertions. The old registration fails the same PHP 8.4 test at the
registration call with E_DEPRECATED (8192). LTS's locked Pest runner uses PHP
8.1; `KADUPUL_TEST_SESSION_PHP` selects the explicit child executable when
checking PHP 8.4 without upgrading the runner dependencies.

```sh
cd tests
mise exec php@8.1.34 -- php vendor/bin/pest --configuration=phpunit-unit.xml \
  Unit/Security/Session/DatabaseSessionRegistrationTest.php --test-directory=. \
  --fail-on-empty-test-suite
```

Set `KADUPUL_TEST_MYSQL_DSN` and the existing configured database credential
environment variables to a disposable service that permits owned schema
creation. CI runs the case in the database matrix. No upgrade or data repair
is required. Reverting restores the old registration and its deprecation;
it does not require restoring session data. Hosted CI and installed plugin
workflows remain release gates.
