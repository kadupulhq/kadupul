#!/usr/bin/env perl
use strict;
use warnings;
use Cwd qw(abs_path);

delete @ENV{qw(PATH)};
$ENV{PATH} = '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin:/usr/local/sbin';

my $log_path = (defined $ARGV[0] && $ARGV[0] ne '')
	? $ARGV[0]
	: '/var/log/httpd/access_log';

# Reject leading dash, traversal segments, and unexpected characters.
if ($log_path =~ /^-/ || $log_path !~ m{\A/[A-Za-z0-9_./-]+\z} || $log_path =~ m{(?:^|/)\.\.(?:/|$)}) {
	exit 0;
}

my $allowed_root = abs_path('/var/log');
if (!defined $allowed_root || $allowed_root eq '') {
	exit 0;
}
$allowed_root =~ s{/+$}{};

if (-l $log_path || ! -f $log_path) {
	exit 0;
}

my $resolved = abs_path($log_path);
if (!defined $resolved || index($resolved, $allowed_root . '/') != 0) {
	exit 0;
}

open(my $fh, '<', $resolved) or exit 0;
my $lines = 0;
while (<$fh>) {
	$lines++ if /\n\z/;
}
close($fh);

print $lines;
