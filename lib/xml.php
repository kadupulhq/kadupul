<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function xml2array($data) {
	/* mvo voncken@mailandnews.com
	original ripped from  on the php-manual:gdemartini@bol.com.br
	to be used for data retrieval(result-structure is Data oriented) */
	$p = xml_parser_create();
	$vals = array();
	$index = array();
	xml_parser_set_option($p, XML_OPTION_SKIP_WHITE, 1);
	xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
	xml_parse_into_struct($p, $data, $vals, $index);

	$tree = array();
	$i = 0;
	$tree = get_children($vals, $i);

	return $tree;
}

function get_children($vals, &$i) {
	$children = array();

	if (isset($vals[$i]['value'])) {
		if ($vals[$i]['value']) array_push($children, $vals[$i]['value']);
	}

	$prevtag = ''; $j = 0;

	while (++$i < cacti_count($vals)) {
		switch ($vals[$i]['type']) {
		case 'cdata':
			array_push($children, $vals[$i]['value']);
			break;
		case 'complete':
			/* if the value is an empty string, php doesn't include the 'value' key
			in its array, so we need to check for this first */
			if (isset($vals[$i]['value'])) {
				$children[$vals[$i]['tag']] = $vals[$i]['value'];
			} else {
				$children[$vals[$i]['tag']] = '';
			}

			break;
		case 'open':
			$j++;

			if ($prevtag <> $vals[$i]['tag']) {
				$j = 0;
				$prevtag = $vals[$i]['tag'];
			}

			$children[$vals[$i]['tag']] = get_children($vals,$i);
			break;
		case 'close':
			return $children;
		}
	}
}

function rrdxport2array($data) {
	// Bug force encoding to UTF-8
	$data = str_replace(array('US-ASCII', 'ISO-8859-1'), 'UTF-8', $data);

	/* bug #1436 */
	/* scan XML for bad data RRDtool 1.2.30 */
	$array = explode("\n", $data);

	if (cacti_sizeof($array)){
		if ((substr(trim($array[0]),0,1)) == '<') {
			/* continue */
		} else {
			$new_array = array();
			foreach($array as $element) {
				if ((substr(trim($element),0,1)) == '<') {
					$new_array[] = $element;
				}
			}

			$array = $new_array;

			$data = implode("\n", $array);
		}
	}

	/* mvo voncken@mailandnews.com
	original ripped from  on the php-manual:gdemartini@bol.com.br
	to be used for data retrieval(result-structure is Data oriented) */
	$p = xml_parser_create('UTF-8');
	$vals = array();
	$index = array();
	xml_parser_set_option($p, XML_OPTION_SKIP_WHITE, 1);
	xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
	xml_parser_set_option($p, XML_OPTION_TARGET_ENCODING, 'UTF-8');
	xml_parse_into_struct($p, $data, $vals, $index);

	$tree = array();
	$i = 0;
	$column = 0;
	$row = 0;
	$tree = get_rrd_children($vals, $i, $column, $row);

	return $tree;
}

function get_rrd_children($vals, &$i, &$column, &$row) {
	$children = array();

	if (isset($vals[$i]['value'])) {
		if ($vals[$i]['value']) array_push($children, $vals[$i]['value']);
	}

	$prevtag = ''; $j = 0;

	while (++$i < cacti_count($vals)) {
		switch ($vals[$i]['type']) {
		case 'cdata':
			array_push($children, $vals[$i]['value']);
			break;
		case 'complete':
			/* if the value is an empty string, php doesn't include the 'value' key
			in its array, so we need to check for this first */
			if (isset($vals[$i]['value'])) {
				switch($vals[$i]['tag']) {
					case 'entry':
						$column++;
						$children['col' . $column] = $vals[$i]['value'];
						break;
					case 't':
						$children['timestamp'] = $vals[$i]['value'];
						break;
					case 'v':
						$column++;
						$children['col' . $column] = $vals[$i]['value'];
						break;
					default:
						$children[$vals[$i]['tag']] = $vals[$i]['value'];
				}
			} else {
				$children[$vals[$i]['tag']] = '';
			}

			break;
		case 'open':
			$j++;

			if ($prevtag <> $vals[$i]['tag']) {
				$j = 0;
				$prevtag = $vals[$i]['tag'];
			}

			switch($vals[$i]['tag']) {
				case 'meta':
				case 'xport':
				case 'legend':
					$children[$vals[$i]['tag']] = get_rrd_children($vals,$i,$column,$row);
					break;
				case 'data':
					break;
				case 'row':
					$row++;
					$column=0;
					$children['data'][$row] = get_rrd_children($vals,$i,$column,$row);

					break;
			}

			break;
		case 'close':
			return $children;
		}
	}
}

