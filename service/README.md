<!--
SPDX-FileCopyrightText: 2004-2026 The Cacti Group
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-2.0-or-later
-->

## cactid.service

Run the Kadupul collector as a managed system service.

### Installing on Linux (systemd based systems)

Just about all Linux variants that are out there today use systemd.  The
systemd model makes creating and installing a service very convenient.
There are only a few steps.  For this service here they are.

1. Copy the cactid.service file to /usr/lib/systemd/system
2. Modify the file to point to the real path of the cactid.php file located
   in the application directory by default
3. Modify the file to use the user and group that your Kadupul installation is installed
   with, on RedHat variants, it usually: apache:apache, for Debian variants,
   its generally www-run:www
4. Create the file /etc/sysconfig/cactid, even though it's not used today,
   we'll keep it there for as a future path to over write certain settings
5. Install the service using 'systemctl enable cactid'
6. Reload systemd using 'systemctl daemon-reload'
7. Comment out the cacti crontab file or simply remove the /etc/cron.d/cacti
   file in place today
8. Start the service using 'systemctl start cactid'

### Debugging

If you are interested in seeing what the cactid service is doing, you can
place it into debug mode using the following instructions:

1. Stop the cactid service 'systemctl stop cactid'
2. Switch directories to the application directory, by default /var/www/html/cacti
3. Run the cactid service in foreground mode: ./cactid.php --foreground --debug

What it does is pretty simple.

### Windows support

Today, the cactid service does not include Windows support, though the
PHP Windows project does provide a PHP module to install and enable a PHP
based Windows services.  We would be glad to accept a pull request and
additional instructions for supporting this service on Windows.

## kadupul-boost-worker.service

Run a Boost queue worker. It consumes the `boost` Messenger transport when
Boost Processing Mode is set to Message queue workers. It does nothing for
installations that use the default forked processes.

Install it the same way as cactid.service: copy the file to
/usr/lib/systemd/system, set User, Group and WorkingDirectory to match the
installation, and change the `php` path in ExecStart if yours is not /usr/bin/php. The unit must run as the same account as the poller so the Boost child
it starts can write the RRD files. `Restart=always` is required: the worker
exits after `--time-limit` or `--memory-limit` and systemd starts it again.
See "Boost queue workers" in docs/symfony-migration.md for setup, scaling and
the checks to run before enabling queue mode.

To let Messenger own the Boost cycle instead of poller.php, set
`KADUPUL_BOOST_SCHEDULER=1` in the environment of both processes:

- The poller. In its cron file, add the line `KADUPUL_BOOST_SCHEDULER=1` above
  the poller entry, which passes it to every command below it, or put it in
  front of the command, as in
  `*/5 * * * * apache KADUPUL_BOOST_SCHEDULER=1 php /var/www/html/cacti/poller.php > /dev/null 2>&1`.
  With cactid.service, add it to /etc/sysconfig/cactid, which the unit reads
  through `EnvironmentFile=`.
- The scheduler_boost consumer. Add `Environment=KADUPUL_BOOST_SCHEDULER=1` to
  the `[Service]` section of its systemd unit.

Kadupul does not load `.env` files. config/bootstrap.php reads only the process
environment and poller.php checks `getenv()`, so the variable set only in a
Symfony `.env.local` file reaches neither the poller nor the consumer.

Run
`php bin/console messenger:consume scheduler_boost --time-limit=3600 --memory-limit=128M --failure-limit=1`
alongside the row-cache consumer (`scheduler_row_cache`), under systemd or
another process supervisor configured to restart the worker even after a
successful time-limit exit. `--failure-limit=1` makes the worker exit on a
failed message; its supervisor must restart it so a dropped database connection
is replaced. Enabling the scheduler stops poller.php from launching Boost. That environment setting is what
prevents two masters per cycle; the master's own process registration is only a
best-effort backstop and can let two near-simultaneous masters both start.
The scheduler also purges the Boost image cache hourly while `boost_png_cache_enable` is on; `bin/console kadupul:boost:purge-image-cache` does the same on demand.

Stop the scheduler_boost consumer before running `bin/console kadupul:boost:run`
manually; it holds the boost lock for its whole life, so the command exits 2
while it runs.
