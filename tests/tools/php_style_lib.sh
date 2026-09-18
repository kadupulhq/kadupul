# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Shared by check_php_style.sh and convert_php_style.sh; sourced, not run.
# shellcheck shell=bash

# Paths the config's Finder covers, one per line and relative to the current
# directory. list-files quotes each path like escapeshellarg(), so an embedded
# quote is unescaped after the outer quotes are removed.
php_style_list_files() {
	"$1" list-files --config="$2" | sed -e "s/^'//" -e "s/'$//" -e 's#^\./##' -e "s/'[\\\\]''/'/g"
}

# True when two PHP files hold the same tokens apart from whitespace, so a
# change between them only reformats. String and heredoc contents are tokens,
# so a changed literal does not count as whitespace.
# shellcheck disable=SC2016 # the single-quoted program is PHP
same_tokens() {
	php -r '
		$strip = function ($file) {
			$out = array();
			foreach (token_get_all(file_get_contents($file)) as $t) {
				if (is_array($t)) {
					if ($t[0] === T_WHITESPACE) {
						continue;
					}
					// The opening tag token carries the whitespace that follows it.
					if ($t[0] === T_OPEN_TAG || $t[0] === T_OPEN_TAG_WITH_ECHO) {
						$t[1] = rtrim($t[1]);
					}
					// Formatting can re-indent a docblock or respace a comment.
					if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
						$t[1] = preg_replace("/\\s+/", "", $t[1]);
					}
					$out[] = array($t[0], $t[1]);
				} else {
					$out[] = $t;
				}
			}
			return $out;
		};
		exit($strip($argv[1]) === $strip($argv[2]) ? 0 : 1);
	' -- "$1" "$2"
}
