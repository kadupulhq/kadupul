<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function cacti_trim_dir_separator($dir, $separator = DIRECTORY_SEPARATOR)
{
    if ($dir === '') {
        return $dir;
    }

    /* Windows accepts both slash styles as a separator, so both must be
       trimmed regardless of which one the configured path used */
    $trim_chars = ($separator === '\\') ? '/\\' : $separator;

    $trimmed = rtrim($dir, $trim_chars);

    if ($trimmed === $dir) {
        /* nothing was trimmed; a drive-relative path like 'C:' must not
           gain a root separator it never had, or it silently becomes the
           drive root instead of the current directory on that drive */
        return $dir;
    }

    if ($trimmed === '') {
        /* bare root: preserve whichever separator the input actually used */
        return substr($dir, -1);
    }

    /* a Windows drive root ('C:\' or 'C:/') must keep its separator, or the
       result ('C:') means the current directory on that drive instead of its
       root */
    if ($separator === '\\' && preg_match('/^[A-Za-z]:$/', $trimmed)) {
        return $trimmed . substr($dir, strlen($trimmed), 1);
    }

    return $trimmed;
}

function cacti_join_dir_child($dir, $name, $separator = DIRECTORY_SEPARATOR)
{
    if ($dir === '') {
        return $name;
    }

    if ($separator === '\\' && preg_match('/^[A-Za-z]:$/', $dir)) {
        return $dir . $name;
    }

    $last = substr($dir, -1);

    if ($last === '/' || ($separator === '\\' && $last === '\\')) {
        return $dir . $name;
    }

    return $dir . $separator . $name;
}
