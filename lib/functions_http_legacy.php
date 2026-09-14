<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

/**
 * cacti_http_fetch_legacy - performs the cacti_http() request and reads the
 * response headers via the predefined $http_response_header variable.
 *
 * Kept out of lib/functions.php on purpose: PHP 8.5 deprecates referencing
 * $http_response_header, and it raises that notice for any function whose
 * compiled body mentions the variable at all, whether or not the reference
 * actually runs. Isolating it in its own file means it is only required,
 * and only compiled, when cacti_http() cannot use its 8.5 replacement,
 * http_get_last_response_headers().
 *
 * @param string   $url - the request URL
 * @param resource $ctx - the stream context built by cacti_http()
 *
 * @return array - [response body or false, response header lines]
 */
function cacti_http_fetch_legacy($url, $ctx) {
	$body = @file_get_contents($url, false, $ctx);

	$response_headers = isset($http_response_header) && is_array($http_response_header)
		? $http_response_header
		: array();

	return array($body, $response_headers);
}
