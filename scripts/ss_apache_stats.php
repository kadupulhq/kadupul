<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*

Moved from the Apache_Webserver package into the repository so it can be tested.
Original Template by 	ApacheStats 0.8.2 by RCK - http://forums.cacti.net/about25227.html
Tested and packged for Cacti 1.2.x  by Sean Mancini - bmfmancini - www.seanmancini.com


*/

$no_http_headers = true;

/* display No errors */
error_reporting(0);

/* Seconds to wait for server-status; the poller's script server must not stall on one web server. */
const SS_APACHE_STATS_TIMEOUT = 5;

if (!isset($called_by_script_server)) {
    include_once(dirname(__FILE__) . "/../include/cli_check.php");
    include_once(dirname(__FILE__) . "/../lib/snmp.php");

    array_shift($_SERVER["argv"]);

    print call_user_func_array("ss_apache_stats", $_SERVER["argv"]);
}

function ss_apache_stats($host = "", $section = "all")
{
    /* perform initial error checking */
    if (empty($host)) {
        cacti_log("ERROR: ApacheStats08 - Host parameter missing, can not continue");

        return ss_apache_stats_report(false, $section);
    }

    // A host name, IPv4 or bracketed IPv6 address and optional port; anything else could steer the URL.
    if (!preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9.-]*|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/', $host)) {
        cacti_log("ERROR: ApacheStats08 - Host parameter is not a host name or address");

        return ss_apache_stats_report(false, $section);
    }

    return ss_apache_stats_report(cacti_http("http://$host/server-status?auto", SS_APACHE_STATS_TIMEOUT), $section);
}

/* A server-status value, or U when the page holds something other than a number. */
function ss_apache_stats_value($text)
{
    $value = trim($text);

    return preg_match('/^-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?$/', $value) ? $value : 'U';
}

/* Formats a server-status?auto page for the section; every requested field is U when there is no page. */
function ss_apache_stats_report($result, $section)
{
    $variables = array('apache_total_hits', 'apache_total_kbytes', 'apache_busy_workers', 'apache_idle_workers', 'apache_cpuload');
    $threads = array('_W' => 0,'S' => 0,'R' => 0,'W' => 0,'K' => 0,'D' => 0,'C' => 0,'L' => 0,'G' => 0,'I' => 0,'_O' => 0);
    /*	***Types of Threads***
        _W --- waiting
        S  --- starting up
        R  --- reading request
        W  --- sending reply
        K  --- keepalive
        D  --- DNS lookup
        C  --- closing connection
        L  --- logging
        G  --- graceful finishing
        I  --- idle cleanup
        _O --- open slot
    */
    if ($result === false) {
        return ss_apache_stats_unknown($variables, array_keys($threads), $section);
    }

    $array_result = array();
    $array_result =  explode("\n", $result);
    $i = 0;
    $output = "";
    $line = array();
    $scoreboard = false;
    foreach ($array_result as $newline) {
        $line = explode(":", $newline);

        switch ($line[0]) {
            case "Total Accesses":
                if (($section == "hits") or ($section == "all")) {
                    $output .= $variables[0] . ":" . ss_apache_stats_value($line[1]) . " ";
                }
                break;
            case "Total kBytes":
                if (($section == "kbytes") or ($section == "all")) {
                    $output .= $variables[1] . ":" . ss_apache_stats_value($line[1]) . " ";
                }
                break;
            case "BusyServers":
            case "BusyWorkers":
                if (($section == "workers") or ($section == "all")) {
                    $output .= $variables[2] . ":" . ss_apache_stats_value($line[1]) . " ";
                }
                break;
            case "IdleServers":
            case "IdleWorkers":
                if (($section == "workers") or ($section == "all")) {
                    $output .= $variables[3] . ":" . ss_apache_stats_value($line[1]) . " ";
                }
                break;
            case "CPULoad":
                if (($section == "cpuload") or ($section == "all")) {
                    $output .= $variables[4] . ":" . ss_apache_stats_value($line[1]) . " ";
                }
                break;
            case "Scoreboard":
                $scoreboard = true;
                $string = trim($line[1]);
                $length = strlen($string);
                for ($j = 0; $j < $length ; $j++) {
                    $char = $string[$j];
                    if ($char == '_') {
                        $threads['_W']++;
                    } elseif ($char == '.') {
                        $threads['_O']++;
                    } elseif (isset($threads[$char])) {
                        // Only Apache's documented states; any other character would add an output field.
                        $threads[$char]++;
                    }
                }
                break;
        }//end switch
    }//end foreach

    if (($section == "threads") or ($section == "all")) {
        foreach ($threads as $type => $num_threads) {
            // Without a scoreboard the counts are unknown, not zero.
            $output .= "thread" . $type . ":" . ($scoreboard ? $num_threads : 'U') . " ";
        }
    }

    return trim(str_replace(array("\r","\n"), '', $output));

}

/* Every field the section asks for, as U. */
function ss_apache_stats_unknown($variables, $thread_types, $section)
{
    $fields = array(
        'hits'    => array($variables[0]),
        'kbytes'  => array($variables[1]),
        'workers' => array($variables[2], $variables[3]),
        'cpuload' => array($variables[4]),
        'threads' => array_map(fn($type) => 'thread' . $type, $thread_types),
    );
    $requested = $section == 'all' ? array_merge(...array_values($fields)) : ($fields[$section] ?? array());

    return implode(' ', array_map(fn($field) => $field . ':U', $requested));
}

?>
