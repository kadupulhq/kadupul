# Console home and help

The console home uses a Symfony controller, an application query and Twig templates. `index.php` forwards to `/app.php/console`; `/public/index.php/console` serves the same use case. Old login POSTs receive a 307 redirect to `login.php`, which runs native authentication and returns to the console. The home still requires realm 8.

`/app.php/help` adds an authenticated documentation overview using the existing documentation map. Local Page Help Only suppresses remote links when the local document is absent. `help.php` retains its JSON lookup and error-reporting contracts.

## Compatibility boundary

Installed PHP extensions run in a separate native PHP process after Symfony admits the account. The worker resumes and rechecks the actual native session, refreshes permission caches, runs page and console hooks, renders permitted external content, and persists session changes. Twig owns the core introduction and page structure. Existing gettext catalogs supply the translated console text; the gettext tools now extract its Twig messages too.

The worker uses the configured PHP interpreter, a private input pipe, a 60-second timeout and an 8 MiB output limit. Failures return a generic 503 and log bounded diagnostic fields without exception messages or extension output. Invalid request encoding or oversized handoff data returns 400. This process boundary does not sandbox trusted PHP plugins or reduce their installation privileges. Arbitrary plugin response headers, cookie emission, process termination and checks for the CLI SAPI require separate compatibility assessment. The verified contract covers HTML hooks, native form protection and session mutations.

Remote collectors retain the native identity-store selection: primary while online, local while recovering or offline. Their status and queued-record count remain visible. These pages do not add a second domain write path; native authentication, session updates and plugin effects retain their existing owners.

## Deployment and verification

Deploy the PHP files, templates, JavaScript and generated translation catalogs together. Rebuild assets using the established build and clear the Symfony production cache, including compiled Twig and translation caches. The change requires no schema, dependency or minimum-runtime upgrade. Reverting the change restores the previous home entry point; it does not undo writes performed by installed plugins.

Focused Symfony tests cover authorization ordering, escaped values, selected French and German catalogs and help routing. `tests/Symfony/console_home_http.py` exercises native login, PHP content permissions, hook order, session persistence, CSRF rewriting, default and nonce CSP in Chromium, legacy menu navigation, Apache subdirectory URLs, controlled failures, collector states and the existing help JSON contract. The existing session bridge runs these scenarios for file and database sessions and records their PHP and browser coverage. Current-head CI and required review remain necessary before merge. Arbitrary third-party plugins need installed acceptance evidence before claiming compatibility beyond the tested contract.
