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

require_once dirname(__DIR__, 4) . '/lib/path_helpers.php';

/*
 * cli/removespikes.php and cli/batchgapfix.php run lib/spikekill.php's
 * remove_spikes() as root. It copies the RRD being processed to a backup
 * name under cache/spikekill/ (or the configured spikekill_backupdir), a
 * directory the web user's poller can write to. The prior code picked the
 * name with file_exists() and then called copy(), which follows symlinks:
 * a symlink planted at that name, dangling or not, made copy() write
 * through it as root instead of to the intended backup file.
 *
 * copyFileSafely() replaces that check-then-act pair with an exclusive
 * create (refusing a name already taken, including by a symlink) and a
 * unique-name fallback, so root never follows or clobbers a pre-planted
 * link. Both call sites in remove_spikes() (the requested-backup copy and
 * backupRRDFile()) now go through it.
 *
 * These tests load the real class with the constructor bypassed (it reads
 * config options this test has no database for) and invoke the private
 * methods via reflection, so the exact shipped code runs against a real
 * temp directory rather than a text-extracted copy of it.
 */

/* spikekill::normalizeDir() delegates to cacti_trim_dir_separator()
   (lib/functions.php); lib/functions.php as a whole is never require'd
   here because it would define the real read_config_option(), cacti_log()
   and cacti_sizeof() ahead of every other test file's function_exists()
   guard in this same Pest process, so only this one pure function is
   extracted by source, the same technique the eval() blocks elsewhere in
   this suite use */
require_once dirname(__DIR__, 4) . '/lib/path_helpers.php';

if (!function_exists('cacti_trim_dir_separator')) {
    $spikekill_functions_source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

    $trim_start = strpos($spikekill_functions_source, 'function cacti_trim_dir_separator(');
    expect($trim_start)->not->toBeFalse();

    $trim_end = strpos($spikekill_functions_source, "\n}\n", $trim_start);
    $trim_body = substr($spikekill_functions_source, $trim_start, $trim_end - $trim_start + 2);

    eval($trim_body); // nosemgrep: php.lang.security.eval-use.eval-use
}

require_once dirname(__DIR__, 3) . '/Helpers/SpikekillPathFunctions.php';

require_once dirname(__DIR__, 4) . '/lib/spikekill.php';

/* read_config_option() is guarded with function_exists() because
   PurgeSpikeBackupsWritableCheckTest.php, SpikekillXmlDumpFileSafetyTest.php
   and HeadersSecureTest.php stub the same global and all four files run in
   the same Pest process. Every one of those stubs reads and writes
   $GLOBALS['__test_config_options'], the store HeadersSecureTest.php
   already used, so whichever file's guarded definition wins the race still
   honors this file's stubbed values instead of silently falling back to
   another file's. */

function spikekill_backup_test_stub_config($values)
{
    $GLOBALS['__test_config_options'] = $values;
}

if (!function_exists('read_config_option')) {
    function read_config_option($option)
    {
        return $GLOBALS['__test_config_options'][$option] ?? '';
    }
}

function invoke_spikekill_private(string $method, array $args)
{
    $reflection = new ReflectionClass('spikekill');
    $instance   = $reflection->newInstanceWithoutConstructor();

    return invoke_spikekill_private_on($instance, $method, $args);
}

/* like invoke_spikekill_private(), but reuses a caller-supplied instance so
   its per-instance canonicalDir() cache carries over between calls, the way
   one remove_spikes() call reuses $this across its two copyFileSafely()
   backups */
function invoke_spikekill_private_on($instance, string $method, array $args)
{
    $reflection = new ReflectionClass($instance);
    $m          = $reflection->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($instance, $args);
}

/* backupRRDFile() re-checks its argument against $rrdfile_stat, the
   identity initialize_spikekill() would have captured; these tests build
   instances via newInstanceWithoutConstructor() and call backupRRDFile()
   directly, so that capture has to be primed by hand the same way
   remove_spikes() would have left it */
function spikekill_prime_rrdfile_stat($instance, $stat)
{
    $reflection = new ReflectionClass($instance);
    $prop       = $reflection->getProperty('rrdfile_stat');
    $prop->setAccessible(true);
    $prop->setValue($instance, $stat);
}

/* copyFileSafely() returns array('path' => ..., 'stat' => ...) on success
   so the success-path cleanup can remove the backup by its creation
   identity instead of by name; tests that only care about the path use
   this to unwrap it. */
function spikekill_copy_path($result)
{
    return $result === false ? false : $result['path'];
}

/* a minimal read-only stream wrapper whose declared size (stream_stat)
   exceeds the bytes it actually yields, standing in for a short copy: a
   destination write that stops early (a full disk, a quota) hands
   stream_copy_to_stream() fewer bytes than fstat() on the source reported
   before the copy started */
class SpikekillShortSourceStream
{
    public $context;

    private $data     = 'short-content';
    private $position = 0;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->position = 0;

        return true;
    }

    public function stream_read($count)
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof()
    {
        return $this->position >= strlen($this->data);
    }

    public function stream_stat()
    {
        return array('size' => strlen($this->data) + 1000, 'mode' => 0100600, 'dev' => 1, 'ino' => 1);
    }

    public function url_stat($path, $flags)
    {
        return $this->stream_stat();
    }

    public function stream_close() {}
}

if (!in_array('spikekillshortsource', stream_get_wrappers())) {
    stream_wrapper_register('spikekillshortsource', 'SpikekillShortSourceStream');
}

class SpikekillSwappedSourceStream extends SpikekillShortSourceStream
{
    public static $reads = 0;

    public function url_stat($path, $flags)
    {
        return parent::stream_stat();
    }

    public function stream_stat()
    {
        $stat = parent::stream_stat();
        $stat['ino'] = 2;
        return $stat;
    }

    public function stream_read($count)
    {
        self::$reads++;
        return parent::stream_read($count);
    }
}

if (!in_array('spikekillswappedsource', stream_get_wrappers())) {
    stream_wrapper_register('spikekillswappedsource', 'SpikekillSwappedSourceStream');
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/spikekill_test_' . uniqid();
    mkdir($this->dir, 0700, true);

    $this->rrdfile = $this->dir . '/source.rrd';
    file_put_contents($this->rrdfile, 'rrd-bytes');
});

afterEach(function () {
    foreach (glob($this->dir . '/*') as $item) {
        unlink($item);
    }

    rmdir($this->dir);
});

test('a normal backup is created with the expected content and name', function () {
    $desired = $this->dir . '/backup.rrd';

    $result  = invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired]);
    $written = spikekill_copy_path($result);

    expect($written)->toBe($desired)
        ->and($result['stat'])->toBeArray()
        ->and(is_link($desired))->toBeFalse()
        ->and(file_get_contents($desired))->toBe('rrd-bytes')
        ->and(fileperms($desired) & 0777)->toBe(0600);
});

test('a changed source identity is refused before copying into a backup', function () {
    $expected = lstat($this->rrdfile);
    rename($this->rrdfile, $this->dir . '/original.rrd');
    file_put_contents($this->rrdfile, 'replacement-bytes');
    $desired = $this->dir . '/backup.rrd';

    expect(invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired, null, $expected]))->toBeFalse()
        ->and(file_exists($desired))->toBeFalse();
});

test('a source symlink is refused even when its target is the expected file', function () {
    $expected = lstat($this->rrdfile);
    rename($this->rrdfile, $this->dir . '/original.rrd');
    symlink($this->dir . '/original.rrd', $this->rrdfile);
    $desired = $this->dir . '/backup.rrd';

    expect(invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired, null, $expected]))->toBeFalse()
        ->and(file_exists($desired))->toBeFalse();
});

test('a source replaced between stat and open is rejected without reading its bytes', function () {
    $desired = $this->dir . '/backup.rrd';
    SpikekillSwappedSourceStream::$reads = 0;

    expect(invoke_spikekill_private('copyFileSafely', ['spikekillswappedsource://source', $desired]))->toBeFalse()
        ->and(SpikekillSwappedSourceStream::$reads)->toBe(0)
        ->and(file_exists($desired))->toBeFalse();
});

test('a planted symlink at the backup name is never written to', function () {
    $evil_target = $this->dir . '/evil-target';
    $desired     = $this->dir . '/backup.rrd';

    symlink($evil_target, $desired);

    $written = spikekill_copy_path(invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired]));

    expect($written)->not->toBe($desired)
        ->and(is_link($desired))->toBeTrue()
        ->and(file_exists($evil_target))->toBeFalse()
        ->and(file_get_contents($written))->toBe('rrd-bytes');

    unlink($written);
    unlink($desired);
});

test('a dangling symlink at the backup name is not followed', function () {
    $desired = $this->dir . '/backup.rrd';

    symlink($this->dir . '/does-not-exist', $desired);

    $written = spikekill_copy_path(invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired]));

    expect($written)->not->toBe($desired)
        ->and(is_link($desired))->toBeTrue()
        ->and(file_exists($this->dir . '/does-not-exist'))->toBeFalse();

    unlink($written);
    unlink($desired);
});

test('an existing regular file at the backup name is not overwritten', function () {
    $desired = $this->dir . '/backup.rrd';
    file_put_contents($desired, 'do-not-touch');

    $written = spikekill_copy_path(invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired]));

    expect($written)->not->toBe($desired)
        ->and(file_get_contents($desired))->toBe('do-not-touch')
        ->and(file_get_contents($written))->toBe('rrd-bytes');

    unlink($written);
    unlink($desired);
});

test('the fallback name is created in the same directory, not the system temp directory', function () {
    $desired = $this->dir . '/backup.rrd';
    file_put_contents($desired, 'taken');

    $written = spikekill_copy_path(invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired]));

    expect($written)->not->toBeFalse()
        ->and(dirname($written))->toBe($this->dir)
        ->and(dirname($written))->not->toBe(sys_get_temp_dir());

    unlink($written);
    unlink($desired);
});

test('an unwritable backup directory fails instead of falling back to the system temp directory', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('directory permissions have no effect running as root');
    }

    $desired = $this->dir . '/backup.rrd';
    file_put_contents($desired, 'taken');

    chmod($this->dir, 0500);

    /* the exclusive opens inside copyFileSafely are error-suppressed with
       '@' for the caller they report to (a false return); swallow the
       underlying E_WARNING here too so the permission-denied noise from
       this deliberately-unwritable directory doesn't fail the test run */
    set_error_handler(function () {
        return true;
    });

    $written = invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired]);

    restore_error_handler();

    chmod($this->dir, 0700);

    expect($written)->toBeFalse();

    unlink($desired);
});

test('a failed source open closes the destination handle before removing it', function () {
    /* Windows refuses to delete a file while a handle to it is still
       open, so the destination handle has to be closed before
       unlinkOwnedFile() runs against it, not after; the fstat() the
       identity check needs has to be captured before that close too,
       since fstat() needs a live handle. Isolated to the
       '$source_handle === false' branch specifically (not the function as
       a whole), because the later short-copy-failure branch already
       captures fstat() before fclose() and would make a position search
       across the whole function pass regardless of this branch's order. */
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/spikekill.php');

    $branch_start = strpos($source, 'if ($source_handle === false) {');
    expect($branch_start)->not->toBeFalse();

    $branch_end = strpos($source, "\n\t\t}\n", $branch_start);
    $branch     = substr($source, $branch_start, $branch_end - $branch_start);

    $fclose_pos = strpos($branch, 'fclose($handle)');
    $unlink_pos = strpos($branch, 'unlinkOwnedFile($desired_path,');

    expect($fclose_pos)->not->toBeFalse()
        ->and($unlink_pos)->not->toBeFalse()
        ->and($fclose_pos)->toBeLessThan($unlink_pos);
});

test('no chmod or by-name reopen happens after the file is created', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/spikekill.php');

    $start = strpos($source, 'private function copyFileSafely');
    $end   = strpos($source, 'private function unlinkOwnedFile', $start);
    $body  = substr($source, $start, $end - $start);

    expect($body)->not->toContain('chmod(')
        ->and($body)->not->toContain("'wb'");
});

test('unlinkOwnedFile refuses to remove a name swapped for a symlink', function () {
    $path = $this->dir . '/owned.rrd';
    $fh   = fopen($path, 'xb');
    $fstat = fstat($fh);
    fclose($fh);

    unlink($path);
    symlink($this->dir . '/source.rrd', $path);

    invoke_spikekill_private('unlinkOwnedFile', [$path, $fstat]);

    expect(is_link($path))->toBeTrue()
        ->and(file_exists($this->dir . '/source.rrd'))->toBeTrue();

    unlink($path);
});

test('a symlinked backup directory is refused', function () {
    $real_backupdir = $this->dir . '/real-backupdir';
    mkdir($real_backupdir, 0700, true);

    $backupdir_link = $this->dir . '/backupdir';
    symlink($real_backupdir, $backupdir_link);

    $desired = $backupdir_link . '/backup.rrd';

    $written = spikekill_copy_path(invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired, $backupdir_link]));

    expect($written)->toBeFalse()
        ->and(glob($real_backupdir . '/*'))->toBe([]);

    unlink($backupdir_link);
    rmdir($real_backupdir);
});

test('a directory resolved once through a symlinked ancestor refuses a later ancestor swap on the same instance', function () {
    $real_a = $this->dir . '/real-a';
    mkdir($real_a . '/spikekill', 0700, true);

    $parent = $this->dir . '/parent';
    symlink($real_a, $parent);

    $configured = $parent . '/spikekill';

    /* one instance backs both copyFileSafely() calls in a single
       remove_spikes() run (the requested backup, then backupRRDFile()),
       so its canonicalDir() cache is primed with the legitimate
       resolution here, the same as the first of those two calls would */
    $reflection = new ReflectionClass('spikekill');
    $instance   = $reflection->newInstanceWithoutConstructor();

    $primed = spikekill_copy_path(invoke_spikekill_private_on($instance, 'copyFileSafely', [$this->rrdfile, $configured . '/backup1.rrd', $configured]));
    expect($primed)->toBe($configured . '/backup1.rrd');

    /* an attacker with write access to the parent directory repoints it
       at a different real directory after that first, legitimate
       resolution; the leaf directory name is unchanged and is not itself
       a symlink, so only the cached canonical path catches this */
    $real_b = $this->dir . '/real-b';
    mkdir($real_b . '/spikekill', 0700, true);

    unlink($parent);
    symlink($real_b, $parent);

    $written = spikekill_copy_path(invoke_spikekill_private_on($instance, 'copyFileSafely', [$this->rrdfile, $configured . '/backup2.rrd', $configured]));

    expect($written)->toBeFalse()
        ->and(glob($real_b . '/spikekill/*'))->toBe([]);

    unlink($real_a . '/spikekill/backup1.rrd');
    rmdir($real_a . '/spikekill');
    rmdir($real_a);
    unlink($parent);
    rmdir($real_b . '/spikekill');
    rmdir($real_b);
});

test('a short copy is treated as failure and the partial backup is removed', function () {
    $desired = $this->dir . '/backup.rrd';

    $written = invoke_spikekill_private('copyFileSafely', ['spikekillshortsource://source', $desired]);

    expect($written)->toBeFalse()
        ->and(file_exists($desired))->toBeFalse();
});

test('normalizeDir strips a trailing slash but keeps a bare root or empty value', function () {
    expect(invoke_spikekill_private('normalizeDir', ['/var/lib/cacti/backups/']))->toBe('/var/lib/cacti/backups')
        ->and(invoke_spikekill_private('normalizeDir', ['/var/lib/cacti/backups']))->toBe('/var/lib/cacti/backups')
        ->and(invoke_spikekill_private('normalizeDir', ['/']))->toBe('/')
        ->and(invoke_spikekill_private('normalizeDir', ['///']))->toBe('/')
        ->and(invoke_spikekill_private('normalizeDir', ['']))->toBe('');
});

test('backupRRDFile refuses a symlinked spikekill_backupdir configured with its default trailing slash', function () {
    /* spikekill_backupdir defaults to a path with a trailing slash
       (include/global_settings.php); is_link('dir/') follows the final
       symlink to stat what it points at instead of the link itself, so a
       symlinked backup directory would pass an is_link() check made
       against the unnormalized configured value */
    $real_backupdir = $this->dir . '/real-backupdir';
    mkdir($real_backupdir, 0700, true);

    $backupdir_link = $this->dir . '/backupdir';
    symlink($real_backupdir, $backupdir_link);

    expect(is_link($backupdir_link . '/'))->toBeFalse()
        ->and(is_link($backupdir_link))->toBeTrue();

    spikekill_backup_test_stub_config(array('spikekill_backupdir' => $backupdir_link . '/'));

    $reflection = new ReflectionClass('spikekill');
    $instance   = $reflection->newInstanceWithoutConstructor();

    spikekill_prime_rrdfile_stat($instance, lstat($this->rrdfile));

    $ok = invoke_spikekill_private_on($instance, 'backupRRDFile', [$this->rrdfile]);

    expect($ok)->toBeFalse()
        ->and(glob($real_backupdir . '/*'))->toBe([]);

    unlink($backupdir_link);
    rmdir($real_backupdir);
});

test('backupRRDFile refuses when the source identity no longer matches what was captured', function () {
    $backupdir = $this->dir . '/backupdir';
    mkdir($backupdir, 0700, true);

    /* a stat taken from a different file, standing in for $rrdfile having
       been swapped for a symlink or a different file since
       initialize_spikekill() captured its identity */
    $other = $this->dir . '/other.rrd';
    file_put_contents($other, 'other-bytes');
    $other_stat = lstat($other);
    unlink($other);

    spikekill_backup_test_stub_config(array('spikekill_backupdir' => $backupdir));

    $reflection = new ReflectionClass('spikekill');
    $instance   = $reflection->newInstanceWithoutConstructor();

    spikekill_prime_rrdfile_stat($instance, $other_stat);

    $ok = invoke_spikekill_private_on($instance, 'backupRRDFile', [$this->rrdfile]);

    expect($ok)->toBeFalse()
        ->and(glob($backupdir . '/*'))->toBe([]);

    rmdir($backupdir);
});

/* PHP caches the last stat() and lstat() result per path, and on 8.3+
   clears that cache on any plain stream read, write or flush.  The swap
   below therefore runs in a child process with no pipes and is waited on
   with proc_get_status() alone, the same as an attacker's own process
   would act, so nothing in this process refreshes the cache before the
   method under test reads it. */
if (!function_exists('spikekill_swap_externally')) {
    function spikekill_swap_externally($script)
    {
        $process = proc_open(array('sh', '-c', $script), array(), $pipes);

        do {
            usleep(10000);
            $status = proc_get_status($process);
        } while ($status['running']);

        return array('process' => $process, 'exit' => $status['exitcode']);
    }
}

test('unlinkOwnedFile does not trust a cached lstat from before the name was swapped', function () {
    $path  = $this->dir . '/owned.rrd';
    $fh    = fopen($path, 'xb');
    $fstat = fstat($fh);
    fclose($fh);

    $replacement = $this->dir . '/replacement.rrd';
    file_put_contents($replacement, 'not ours');

    lstat($path);

    $swap = spikekill_swap_externally('mv ' . escapeshellarg($replacement) . ' ' . escapeshellarg($path));

    invoke_spikekill_private('unlinkOwnedFile', [$path, $fstat]);

    proc_close($swap['process']);

    expect($swap['exit'])->toBe(0)
        ->and(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toBe('not ours');
});

test('copyFileSafely does not trust a cached is_link() from before its directory was swapped for a symlink', function () {
    $dir = $this->dir . '/backups';
    mkdir($dir, 0700);

    $elsewhere = $this->dir . '/elsewhere';
    mkdir($elsewhere, 0700);

    is_link($dir);

    $swap = spikekill_swap_externally('rmdir ' . escapeshellarg($dir) . ' && ln -s ' . escapeshellarg($elsewhere) . ' ' . escapeshellarg($dir));

    $written = invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $dir . '/backup.rrd']);

    proc_close($swap['process']);

    $leaked = glob($elsewhere . '/*');

    array_map('unlink', $leaked);
    unlink($dir);
    rmdir($elsewhere);

    expect($swap['exit'])->toBe(0)
        ->and($written)->toBeFalse()
        ->and($leaked)->toBe([]);
});

test('copyFileSafely does not trust a cached realpath() after another process swaps an ancestor directory', function () {
    $real_a = $this->dir . '/real-a';
    mkdir($real_a . '/spikekill', 0700, true);

    $real_b = $this->dir . '/real-b';
    mkdir($real_b . '/spikekill', 0700, true);

    $parent = $this->dir . '/parent';
    symlink($real_a, $parent);

    $configured = $parent . '/spikekill';

    $reflection = new ReflectionClass('spikekill');
    $instance   = $reflection->newInstanceWithoutConstructor();

    $primed = spikekill_copy_path(invoke_spikekill_private_on($instance, 'copyFileSafely', [$this->rrdfile, $configured . '/backup1.rrd', $configured]));

    /* unlike the in-process swap above, unlink() and symlink() are not
       called here, so PHP's realpath cache still maps the configured
       directory to real-a when the second copy runs */
    $swap = spikekill_swap_externally('rm ' . escapeshellarg($parent) . ' && ln -s ' . escapeshellarg($real_b) . ' ' . escapeshellarg($parent));

    $written = spikekill_copy_path(invoke_spikekill_private_on($instance, 'copyFileSafely', [$this->rrdfile, $configured . '/backup2.rrd', $configured]));

    proc_close($swap['process']);

    $leaked = glob($real_b . '/spikekill/*');

    array_map('unlink', array_merge($leaked, glob($real_a . '/spikekill/*')));
    rmdir($real_a . '/spikekill');
    rmdir($real_a);
    rmdir($real_b . '/spikekill');
    rmdir($real_b);
    unlink($parent);

    expect($primed)->toBe($configured . '/backup1.rrd')
        ->and($swap['exit'])->toBe(0)
        ->and($written)->toBeFalse()
        ->and($leaked)->toBe([]);
});
