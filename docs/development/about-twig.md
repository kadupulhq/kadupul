# About presentation

`about.php` forwards to the Symfony compatibility route, which redirects eligible signed-in users to `/app.php/about`. The page uses the original realm -1 policy: no console or administrator realm is required. Anonymous, revoked, guest, disabled, locked and mandatory-password-change sessions cannot view it. POST is rejected without interpreting legacy fields.

Platform owns ProductVersion, the release read model, controllers and Twig view. The adapter reads `include/cacti_version` and parses the optional top-level literal `CACTI_VERSION_BETA` definition in `include/global.php` without executing that file. It does not read configuration or database versions. Twig escapes both release fields. GPL v2-or-later redistribution and warranty text, project independence and fixed support links remain available in English and French.

Run `mise exec php@8.4.25 -- php include/vendor/bin/phpunit -c phpunit-symfony.xml` and `mise exec python@3.12.12 -- python tests/Symfony/about_review_http.py`. The HTTP scenarios also run in shared-session coverage for file and database sessions, with source hashes verified before coverage merge.
