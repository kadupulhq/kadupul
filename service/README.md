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
