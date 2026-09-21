# PHPMailer provenance and compatibility

Updated from 6.10.0 to **7.1.1** on 2026-09-19. Upstream still declares
PHP >=5.5.0, so this does not raise Kadupul's PHP >=8.0 application floor or
change the runtime floor at the time. Main now requires PHP 8.4.

- Release: https://github.com/PHPMailer/PHPMailer/releases/tag/v7.1.1
- Archive: https://codeload.github.com/PHPMailer/PHPMailer/zip/refs/tags/v7.1.1
- Archive SHA-256: `c45a1ad58cb835921827b9ec2e86a55f5abdbdb6b17345fd1ab55a7dfd31119f`
- Source commit: `1bc1716a507a65e039d4ac9d9adebbbd0d346e15`

The old `src` files exactly matched upstream 6.10.0. The new `src`, language
files and top-level release documents were copied from the verified archive;
the local runtime patches are described below. Kadupul's `index.php` directory
guards and pre-existing Amharic/Chamorro translations are retained. Existing
`include/vendor/phpmailer/src` and `language` paths remain unchanged.

The nested autoloader is regenerated with the existing Composer toolchain:

```sh
COMPOSER_ROOT_VERSION=7.1.1 mise exec php@8.1.34 -- php /opt/homebrew/bin/composer update --working-dir=include/vendor/phpmailer --no-dev --no-scripts --no-plugins --no-interaction
```

Use the appropriate Composer executable path on other systems. Only the empty
production dependency set is installed. Upstream disables lockfile creation;
the stale nested development lockfile was removed (recoverable from Git).
Upstream's development-only dependencies are not shipped or application runtime
requirements. This manually vendored package is not covered by root Composer
audit, so check its upstream releases/advisories independently.

PHPMailer 7 makes language state static. Kadupul now invokes its static language
API for every message, including English and unavailable locales, preventing
translations from a previous message leaking into subsequent mail errors.
Transport initialization also now precedes setting the configured sendmail
executable; otherwise PHPMailer overwrites that setting with PHP's default.
An empty configured path deliberately retains PHP's default sendmail program.

`PhpMailerCompatibilityTest.php` executes the real application mailer with a
temporary stdin-only sendmail capture and an independently rejecting PHP-default
sendmail program. A second case leaves the configured path empty and verifies
fallback to PHP's default, also pointed at the inert capture. No test recipient is a deliverable domain and no real mail
transport is used. It checks multipart body/attachment generation and German →
English → unavailable-locale fallback in one process. Separate MIME-only tests
cover uppercase Base64, invalid encoding, and injected header-line removal.
The native child contributes actual executed-line coverage to Sonar.

## Local upstream compatibility patches

In `src/SMTP.php`, the long-token XOAUTH2 continuation branch in upstream 7.1.1
returns false when its final `AUTH End` command succeeds, and true when that
command fails. The local patch negates that result in the failure check. Eight
no-network protocol cases cover direct success, continuation success/rejection,
initial/token rejection, short tokens and empty tokens. Both continuation cases
failed before the patch and pass afterward. Preserve this patch until an upstream
release incorporates the correction; do not overwrite it during a vendor refresh.

The supported non-IMAP address parser no longer emits an unconditional advisory
notice during normal use. Genuine validation errors and deprecation diagnostics
remain unchanged. A real address-parsing regression test checks this behavior.

Header decoding retains its original string if optional adjacent-word whitespace
normalization returns a PCRE error. A native-child fault-injection test exercises
this defensive guard on PHP 8.3+; this is not a naturally reproduced PCRE failure.
The PHP 8.1 decoding path remains unchanged. Preserve these patches during refreshes.

Three additional no-network tests run the real SMTP DATA generator, covering
the first header, mixed line endings, dot-stuffing, empty bodies and headerless
bodies. They verify that probing the generator with `current()` before `foreach`
does not lose the first line or trigger a rewind exception on supported PHP.
