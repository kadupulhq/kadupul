#!/bin/sh
# Role switch for the Kadupul image. Runs as www-data; nothing here needs root.
set -eu

APP_DIR=/var/www/html

wait_for_database() {
	# The poller and the interface both fail confusingly against a database that
	# is not up yet. Fail slowly and clearly instead.
	[ -n "${KADUPUL_DB_HOST:-}" ] || return 0
	attempt=0
	until mysqladmin ping \
		--host="${KADUPUL_DB_HOST}" \
		--port="${KADUPUL_DB_PORT:-3306}" \
		--user="${KADUPUL_DB_USER:-kadupul}" \
		--password="${KADUPUL_DB_PASSWORD:-}" --silent 2>/dev/null
	do
		attempt=$((attempt + 1))
		if [ "$attempt" -ge "${KADUPUL_DB_WAIT:-60}" ]; then
			echo "database at ${KADUPUL_DB_HOST} did not answer after ${attempt}s" >&2
			return 1
		fi
		sleep 1
	done
}

case "${1:-web}" in
	web)
		wait_for_database
		exec php-fpm --nodaemonize
		;;
	poller)
		wait_for_database
		# Supercronic logs to stdout and reaps its children, which the system
		# cron daemon does neither of in a container.
		exec supercronic -passthrough-logs /etc/kadupul/crontab
		;;
	poller-once)
		# One collection cycle, then exit. For a Kubernetes CronJob, or for
		# debugging a cycle without waiting for the minute to come round.
		wait_for_database
		exec php "${APP_DIR}/poller.php" --force
		;;
	healthcheck)
		# cgi-fcgi is not in this image, so check the master process instead.
		pgrep -x php-fpm >/dev/null 2>&1 || pgrep -x supercronic >/dev/null 2>&1
		;;
	*)
		exec "$@"
		;;
esac
