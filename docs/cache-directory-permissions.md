# Real-time and Boost cache directory permissions

Cacti 1.2 writes two kinds of short-lived cache files that are shared between
the web server account and the poller account:

| Cache | Setting | Written by | Removed by |
|---|---|---|---|
| Real-time RRDfiles and images | Settings > Visual > Real-time Graphs > Cache Directory (`realtime_cache_path`, default `<path_cacti>/cache/realtime/`) | the web server account: `graph_realtime.php` runs `poller_realtime.php` with the configured PHP binary, and then renders the image | the poller account: `poller_maintenance.php` removes `.rrd` and `.png` files that have not changed for two hours |
| Boost image cache | Settings > Performance > Image Caching > Location for Image Files (`boost_png_cache_directory`, default `<path_cacti>/cache/boost/`) | whichever account renders a graph with the image cache enabled: normally the web server account, but a command-line process running as the poller account can write there too | the poller account: `poller_boost.php` removes cached images older than one hour, but only those it can write itself (see below) |

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
- For the **Real-time cache**, the poller account removes files, which needs
  write and search permission on the directory, not on the files. When the
  directory is not writable by the poller account, `poller_maintenance.php`
  skips the purge and the directory grows until it is cleaned by hand.
- For the **Boost image cache**, the poller account also needs write
  permission on each file. `boost_purge_cached_png_files()` in
  `poller_boost.php` checks `is_writable()` on every cached image and skips
  any image the poller account cannot write. Boost publishes each image with
  an explicit `chmod(0644)`, so an image written by the web server account is
  not writable by the poller account, even with the shared group below. The
  Boost purge therefore removes only images the poller account wrote itself.
  Images rendered by the web server stay until another job removes them; see
  [Purging web server images from the Boost cache](#purging-web-server-images-from-the-boost-cache).

A umask or a default ACL on the directory does not change this. The explicit
`chmod(0644)` runs after the file is created and overrides the umask, and on a
file with an ACL it sets the ACL mask to read-only, which also masks any
group write entry the default ACL inherited.

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

## Purging web server images from the Boost cache

Until the Boost purge guard is changed in code, remove old images written by
the web server account with a scheduled job that runs as that account. This
matches the Boost purge: `.png` and `.jpg` files older than one hour. For
example, in the web server account's crontab:

```sh
*/15 * * * * find /usr/share/cacti/cache/boost -maxdepth 1 -type f \( -name '*.png' -o -name '*.jpg' \) -mmin +60 -delete
```

The Real-time cache needs no such job.

## Checking the setup

Have the web server account create a file and the poller account remove it,
which is what the purges do. For the Real-time cache, directory permission is
enough:

```sh
sudo -u www-data touch /usr/share/cacti/cache/realtime/.probe.png
sudo -u cacti    rm /usr/share/cacti/cache/realtime/.probe.png
```

If `rm` fails, the poller account lacks write or search permission on the
directory.

For the Boost cache, create the file with the mode Boost uses and test file
write the way the Boost purge does, then remove the probe as the web server
account:

```sh
sudo -u www-data sh -c 'touch /usr/share/cacti/cache/boost/.probe.png && chmod 0644 /usr/share/cacti/cache/boost/.probe.png'
sudo -u cacti    test -w /usr/share/cacti/cache/boost/.probe.png && echo 'Boost purge can remove web server images' || echo 'Boost purge skips web server images: schedule the job above'
sudo -u www-data rm /usr/share/cacti/cache/boost/.probe.png
```

With the setup above, the second command reports that the Boost purge skips
web server images. Then open a real-time graph. If the Real-time page reports
that the Image Cache Directory is not writable, the web server account is
still missing write permission on the directory.

## SELinux and AppArmor

On systems with SELinux or AppArmor, the web server process also needs a
policy that allows writes to these directories, for example the
`httpd_sys_rw_content_t` file context on SELinux. Group permissions alone are
not enough there.
