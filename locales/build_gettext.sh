#!/bin/sh
set -eu
# SPDX-FileCopyrightText: 2004-2025 The Cacti Group
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-2.0-or-later

# get script name
SCRIPT_NAME=`basename ${0}`

# locate the application base directory
REALPATH_BIN=`which realpath 2>/dev/null`
if [ $? -gt 0 ]
then
	echo "ERROR: unable to locate realpath"
	echo
	echo "Linux: Confirm coreutils installed"
	echo "Mac: Brew install coreutils"
	echo
	exit 1
fi
BASE_PATH=`${REALPATH_BIN} ${0} | sed s#/locales/${SCRIPT_NAME}##`

# locate xgettext for processing
XGETTEXT_BIN=`which xgettext 2>/dev/null`
if [ $? -gt 0 ]
then
	echo "ERROR: Unable to locate xgettext"
	echo
	echo "Linux: Install GNU gettext"
	echo "Mac: Brew install GNU gettext"
	echo
	exit 1
fi

# Update main gettext POT file with application strings
echo "Updating Kadupul language gettext language file..."
cd ${BASE_PATH}

${XGETTEXT_BIN} --from-code=UTF-8 --no-wrap --copyright-holder="The Cacti Group" --package-name="Kadupul" --package-version=`cat include/cacti_version` --msgid-bugs-address="https://github.com/kadupulhq/kadupul/issues" -F -k__gettext -k__ -k__n:1,2 -k__x:1c,2 -k__xn:1c,2,3 -k__esc -k__esc_n:1,2 -k__esc_x:1c,2 -k__esc_xn:1c,2,3 -k__date -o locales/po/cacti.pot `find . -maxdepth 2 -name \*.php`

# Merge any changes to POT file into language files
echo "Merging updates to language files..."

for file in `ls -1 locales/po/*.po`;do
	echo "Updating $file from cacti.pot"
	msgmerge --backup off --no-wrap --no-fuzzy-matching --update -F $file locales/po/cacti.pot
done

for file in `ls -1 locales/po/*.po`;do
  ofile="${file##*/}"
  ofile="${ofile%.po}"
  echo "Converting $file to LC_MESSAGES/${ofile}.mo"
  msgattrib --no-obsolete --no-wrap "$file" -o "$file"
  msgfmt --check-format ${file} -o locales/LC_MESSAGES/${ofile}.mo
done

exit 0
