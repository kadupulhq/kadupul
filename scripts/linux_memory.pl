#!/usr/bin/env perl
use strict;
use warnings;

delete @ENV{qw(PATH)};
$ENV{PATH} = '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin:/usr/local/sbin';

my $raw = defined $ARGV[0] ? $ARGV[0] : '';
my ($val1) = $raw =~ /^([\w.:]+)$/;
if (!defined $val1 || $val1 eq '') {
	exit 0;
}

open(my $fh, '<', '/proc/meminfo') or exit 0;
while (my $line = <$fh>) {
	if ($line =~ /(?<![A-Za-z0-9_])\Q$val1\E(?![A-Za-z0-9_])/ && $line =~ /(\Q$val1\E.*\s)(.*[0-9])( kB)/) {
		print $2;
	}
}
close($fh);
