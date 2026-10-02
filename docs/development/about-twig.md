# About presentation

`about.php` forwards to the Symfony compatibility route, which redirects eligible signed-in users to `/app.php/about`. The page uses the original realm -1 policy: no console or administrator realm is required. Anonymous, revoked, guest, disabled, locked and mandatory-password-change sessions cannot view it. POST is rejected without interpreting legacy fields.

Platform owns ProductVersion, the release read model, controllers and Twig view. The adapter reads `include/cacti_version` and parses the optional top-level literal `CACTI_VERSION_BETA` definition in `include/global.php` without executing that file. It does not read configuration or database versions. Twig escapes both release fields. GPL v2-or-later redistribution and warranty text, project independence and fixed support links remain available in English and French.

Run `mise exec php@8.4.25 -- php include/vendor/bin/phpunit -c phpunit-symfony.xml` and `mise exec python@3.12.12 -- python tests/Symfony/about_review_http.py`. The HTTP scenarios also run in shared-session coverage for file and database sessions, with source hashes verified before coverage merge.

## First authenticated visit

About restores a first visit only on its two named routes. Web-server authentication requires an authoritative native `REMOTE_USER` or `REDIRECT_REMOTE_USER`; an ordinary `Authorization: Basic` header or `PHP_AUTH_USER` alone is insufficient. A present PHP Basic username must agree with the authenticated server principal. Remembered credentials use the native cookie and exact client-address token contract, consume the original token and rotate its replacement. Existing authenticated session behavior remains scoped to About.

New session and remembered cookies are published only after checked persistence, transaction commit and success audit. Failed transitions refuse access and independently attempt rollback and credential cleanup; uncertain cleanup is reported as a failure. Database sessions reject configured IDs longer than the existing 32-character column before establishment. File sessions retain supported longer native ID configuration.

Run `mise exec php@8.4.25 python@3.12.12 -- python tests/Symfony/about_native_authentication.py` for real kernel/session failure boundaries. `about_authentication_review_http.py` verifies installed Apache protected first visits, unprotected Basic refusal, remembered-token rotation and revocation with each session handler. Those installed scenarios also run in `session_bridge.py`; the merger requires their source hashes, checks and measured adapter/session paths.
