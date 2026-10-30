#!/bin/sh
set -eu
# SPDX-FileCopyrightText: 2004-2025 The Cacti Group
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-2.0-or-later

# get script name
SCRIPT_NAME=`basename ${0}`

# locate the application base directory
if ! REALPATH_BIN=`command -v realpath`
then
	echo "ERROR: unable to locate realpath"
	echo
	echo "Linux: Confirm coreutils installed"
	echo "Mac: Brew install coreutils"
	echo
	exit 1
fi
BASE_PATH=`${REALPATH_BIN} ${0} | sed s#/locales/${SCRIPT_NAME}##`

# locate the gettext tools used for processing
for TOOL in xgettext msgmerge msgattrib msgfmt
do
	if ! command -v "${TOOL}" > /dev/null
	then
		echo "ERROR: Unable to locate ${TOOL}"
		echo
		echo "Linux: Install GNU gettext"
		echo "Mac: Brew install GNU gettext"
		echo
		exit 1
	fi
done
XGETTEXT_BIN=`command -v xgettext`

# Update main gettext POT file with application strings
echo "Updating Kadupul language gettext language file..."
cd ${BASE_PATH}

${XGETTEXT_BIN} --from-code=UTF-8 --no-wrap --copyright-holder="The Cacti Group" --package-name="Kadupul" --package-version=`cat include/cacti_version` --msgid-bugs-address="https://github.com/kadupulhq/kadupul/issues" -F -k__gettext -k__ -k__n:1,2 -k__x:1c,2 -k__xn:1c,2,3 -k__esc -k__esc_n:1,2 -k__esc_x:1c,2 -k__esc_xn:1c,2,3 -k__date -o locales/po/cacti.pot `find . -maxdepth 2 -name \*.php` `find src -type f -name \*.php`


# Keep the migrated console identities in the shared gettext catalogue.
if [ -f templates/platform/console_home_body.html.twig ]; then
    CONSOLE_POT_DIR=`mktemp -d` || exit 1
    trap 'rm -rf "$CONSOLE_POT_DIR"' EXIT HUP INT TERM
    if ! php tools/translations/extract-console.php > "$CONSOLE_POT_DIR/console.pot"; then
        echo "ERROR: Console Twig extraction failed; translation artifacts remain incomplete." >&2
        exit 1
    fi
    msgcat --no-wrap locales/po/cacti.pot "$CONSOLE_POT_DIR/console.pot" -o "$CONSOLE_POT_DIR/merged.pot" || exit 1
    cp "$CONSOLE_POT_DIR/merged.pot" locales/po/cacti.pot || exit 1
fi

# Merge any changes to POT file into language files
echo "Merging updates to language files..."

for file in locales/po/*.po; do
	[ -f "$file" ] || continue
	echo "Updating $file from cacti.pot"
	msgmerge --backup off --no-wrap --no-fuzzy-matching --update -F "$file" locales/po/cacti.pot
done

for file in locales/po/*.po; do
  [ -f "$file" ] || continue
  ofile="${file##*/}"
  ofile="${ofile%.po}"
  echo "Converting $file to LC_MESSAGES/${ofile}.mo"
  msgattrib --no-obsolete --no-wrap "$file" -o "$file"
  msgfmt --check-format "$file" -o "locales/LC_MESSAGES/${ofile}.mo"
done

if mise exec python@3.12.12 -- python locales/merge_historical_compatibility.py; then
	:
else
	status=$?
	echo "ERROR: historical compatibility merge failed; extracted catalog artifacts remain incomplete" >&2
	exit "$status"
fi

exit 0
