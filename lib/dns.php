<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

/** Decode a bounded DNS hostname, including backward compression pointers. */
function cacti_dns_read_name($packet, &$offset) {
	$cursor = $offset;
	$jumped = false;
	$labels = array();
	$expanded = 1;
	$seen = array();
	while ($cursor < strlen($packet)) {
		if (isset($seen[$cursor])) return false;
		$seen[$cursor] = true;
		$size = ord($packet[$cursor]);
		if (($size & 192) === 192) {
			if ($cursor + 1 >= strlen($packet)) return false;
			$target = (($size & 63) << 8) | ord($packet[$cursor + 1]);
			if ($target < 12 || $target >= $cursor) return false;
			if (!$jumped) $offset = $cursor + 2;
			$jumped = true;
			$cursor = $target;
			continue;
		}
		if ($size > 63 || $cursor + 1 + $size > strlen($packet)) return false;
		$cursor++;
		if ($size === 0) {
			if (!$jumped) $offset = $cursor;
			return implode('.', $labels);
		}
		$label = substr($packet, $cursor, $size);
		// PTR results are hostnames, not arbitrary binary DNS owner labels.
		if (!preg_match('/^[a-z0-9_-]+$/iD', $label)) return false;
		$expanded += $size + 1;
		if ($expanded > 255) return false;
		$labels[] = $label;
		$cursor += $size;
	}
	return false;
}

/** Match a complete PTR response to its request; never return unrelated records. */
function cacti_dns_parse_ptr($packet, $id, $question_name) {
	if (!is_string($packet) || strlen($packet) < 12 || substr($packet, 0, 2) !== $id) return false;
	$header = unpack('nflags/nquestions/nanswers/nauthorities/nadditional', substr($packet, 2, 10));
	// Require a standard, successful, untruncated response with exactly one question.
	if (($header['flags'] & 0xFA4F) !== 0x8000 || $header['questions'] !== 1 || $header['answers'] === 0) return false;
	$offset = 12;
	$name = cacti_dns_read_name($packet, $offset);
	if ($name === false || strcasecmp($name, $question_name) !== 0 || substr($packet, $offset, 4) !== "\0\x0c\0\1") return false;
	$offset += 4;
	$aliases = array();
	$results = array();
	$total = $header['answers'] + $header['authorities'] + $header['additional'];
	for ($record = 0; $record < $total; $record++) {
		$owner = cacti_dns_read_name($packet, $offset);
		if ($owner === false || $offset + 10 > strlen($packet)) return false;
		$rr = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
		$offset += 10;
		$end = $offset + $rr['length'];
		if ($end > strlen($packet)) return false;
		if ($record < $header['answers'] && $rr['class'] === 1 && in_array($rr['type'], array(5, 12), true)) {
			$target = cacti_dns_read_name($packet, $offset);
			if ($target === false || $target === '' || $offset !== $end) return false;
			$owner = strtolower($owner);
			if ($rr['type'] === 5) {
				if (isset($aliases[$owner]) && strcasecmp($aliases[$owner], $target) !== 0) return false;
				$aliases[$owner] = $target;
			} elseif (!isset($results[$owner])) {
				$results[$owner] = $target;
			}
		}
		$offset = $end;
	}
	if ($offset !== strlen($packet)) return false;
	$name = strtolower($question_name);
	$seen = array();
	while (isset($aliases[$name])) {
		if (isset($seen[$name]) || isset($results[$name])) return false;
		$seen[$name] = true;
		$name = strtolower($aliases[$name]);
	}
	return isset($results[$name]) ? strtoupper($results[$name]) : false;
}

/** Legacy IPv4 reverse lookup contract: hostname, input IP, ERROR or timed_out. */
function cacti_dns_reverse_lookup($ip, $dns, $timeout = 1000) {
	if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return 'ERROR';
	$labels = array_merge(array_reverse(explode('.', $ip)), array('in-addr', 'arpa'));
	$question_name = implode('.', $labels);
	try {
		$id = random_bytes(2);
	} catch (\Exception $e) {
		return $ip;
	}
	$request = $id . "\1\0\0\1\0\0\0\0\0\0";
	foreach ($labels as $label) $request .= chr(strlen($label)) . $label;
	$request .= "\0\0\x0c\0\1";
	$timeout = max(1, (int)$timeout);
	// Connected UDP pins replies to the configured peer; no global resolver override.
	$handle = @fsockopen("udp://$dns", 53, $errno, $error, $timeout / 1000);
	if ($handle === false) return $ip;
	try {
		if (!stream_set_timeout($handle, intdiv($timeout, 1000), ($timeout % 1000) * 1000) ||
			!stream_set_blocking($handle, true) || @fwrite($handle, $request) !== strlen($request)) return $ip;
		$response = @fread($handle, 65535);
		$info = stream_get_meta_data($handle);
		if (!empty($info['timed_out'])) return 'timed_out';
		$result = cacti_dns_parse_ptr($response, $id, $question_name);
		return $result === false ? $ip : $result;
	} finally {
		fclose($handle);
	}
}
