#!/usr/bin/env perl

use strict;
use warnings;

delete @ENV{qw(PATH)};
$ENV{PATH} = '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin:/usr/local/sbin';

my $log_path;
if (!defined($ARGV[0]) || $ARGV[0] eq '') {
	$log_path = '/var/log/httpd/access_log';
} else {
	$log_path = $ARGV[0];
}

# Strip a single matching pair of outer quotes for legacy Data Source compatibility
if ($log_path =~ /^"(.*)"\z/s || $log_path =~ /^'(.*)'\z/s) {
	$log_path = $1;
}

# Refuse shell metacharacters, NUL bytes, leading hyphens, and path traversal
if ($log_path =~ /[`\$;&|<>\r\n\0]/ || $log_path =~ /^-/ || $log_path =~ /(?:^|\/)\.\.(?:\/|\z)/) {
	exit;
}

# Only read existing regular non-symlink files natively without invoking a shell
exit if (-l $log_path || ! -f $log_path || ! -r $log_path);

open(my $fh, '<', $log_path) or exit;
my $count = 0;
$count++ while (<$fh>);
close($fh);

print $count;
