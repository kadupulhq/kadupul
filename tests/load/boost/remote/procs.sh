#!/usr/bin/env bash
# Samples every Boost-related process twice a second and prints CSV:
# epoch,pid,class,rss_kb,hwm_kb. VmHWM is the kernel's peak RSS for the
# process, so a process sampled at least once reports its true peak up to
# that sample.
set -euo pipefail

echo 'epoch,pid,class,rss_kb,hwm_kb'
while true; do
    now="$(date +%s.%N)"
    ps -eo pid=,args= | while read -r pid args; do
        case "$args" in
            *'poller_boost.php --child='*) class=child ;;
            *'poller_boost.php'*) class=master ;;
            *'legacy-boost-partition.php'*) class=partition ;;
            *'messenger:consume boost'*) class=consumer ;;
            'rrdtool -'*|*'/rrdtool -'*) class=rrdtool ;;
            *) continue ;;
        esac
        case "$args" in
            runuser*|pgrep*|pkill*|*' sh -c '*) continue ;;
        esac
        status="/proc/$pid/status"
        [ -r "$status" ] || continue
        rss="$(awk '/^VmRSS:/ {print $2}' "$status" 2>/dev/null || true)"
        hwm="$(awk '/^VmHWM:/ {print $2}' "$status" 2>/dev/null || true)"
        [ -n "$hwm" ] || continue
        printf '%s,%s,%s,%s,%s\n' "$now" "$pid" "$class" "${rss:-0}" "$hwm"
    done
    sleep 0.5
done
