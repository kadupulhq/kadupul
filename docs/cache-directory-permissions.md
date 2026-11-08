# Real-time and Boost cache directory permissions

Cacti 1.2 writes two kinds of short-lived cache files that are shared between
the web server account and the poller account:

| Cache | Setting | Written by | Removed by |
|---|---|---|---|
| Real-time RRDfiles and images | Settings > Visual > Real-time Graphs > Cache Directory (`realtime_cache_path`, default `<path_cacti>/cache/realtime/`) | the web server account: `graph_realtime.php` runs `poller_realtime.php` with the configured PHP binary, and then renders the image | the poller account: `poller_maintenance.php` removes `.rrd` and `.png` files that have not changed for two hours |
| Boost image cache | Settings > Performance > Image Caching > Location for Image Files (`boost_png_cache_directory`, default `<path_cacti>/cache/boost/`) | whichever account renders a graph with the image cache enabled: normally the web server account, but a command-line process running as the poller account can write there too | the poller account: `poller_boost.php` removes cached images older than one hour |

Both kinds of file are created with mode `0644`, as in 1.2.31. That mode is
kept on 1.2.x. A cached image written by one account must stay readable by
the other, and installs where the two accounts share no group depend on the
"other" read bit for that. The files hold rendered graphs and short-lived
real-time samples, not credentials.

## What each account needs

- The **web server account** creates files in both directories, so it needs
  write and search (`wx`) permission on each directory. `graph_realtime.php`
  and `poller_realtime.php` refuse to run, and Boost skips writing its cache,
  when the directory is not writable.
- The **poller account** removes files, which needs write and search
  permission on the directory, not on the files. When the real-time directory
  is not writable by the poller account, `poller_maintenance.php` skips the
  purge and the directory grows until it is cleaned by hand.

## Recommended setup: a shared group

Put both accounts in one group, give that group the directories, and set the
set-group-ID bit so new files inherit the group. With `cacti` as the poller
account, `www-data` as the web server account and `cacti` as the shared group
(substitute your own names and paths):

```sh
usermod -a -G cacti www-data

chgrp cacti /usr/share/cacti/cache/realtime /usr/share/cacti/cache/boost
chmod 2770 /usr/share/cacti/cache/realtime /usr/share/cacti/cache/boost
```

Restart the web server or PHP-FPM so it picks up the new group.

Mode `2770` on the directory keeps other local accounts out. They cannot
list or open anything inside, so the `0644` mode of each file no longer lets
them read it. If another account must read the cache, for example a
monitoring agent, add it to the group rather than opening the directory to
everyone.

## Checking the setup

As each account, create and remove a file in each directory:

```sh
sudo -u www-data sh -c 'touch /usr/share/cacti/cache/realtime/.probe && rm /usr/share/cacti/cache/realtime/.probe'
sudo -u cacti    sh -c 'touch /usr/share/cacti/cache/realtime/.probe && rm /usr/share/cacti/cache/realtime/.probe'
```

Repeat for `cache/boost`. Then open a real-time graph. If the Real-time page
reports that the Image Cache Directory is not writable, the web server
account is still missing write permission on the directory.

## SELinux and AppArmor

On systems with SELinux or AppArmor, the web server process also needs a
policy that allows writes to these directories, for example the
`httpd_sys_rw_content_t` file context on SELinux. Group permissions alone are
not enough there.
