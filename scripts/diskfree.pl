#!/usr/bin/env perl
use strict;
use warnings;

delete @ENV{qw(PATH)};
$ENV{PATH} = '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin:/usr/local/sbin';

my $partition = $ARGV[0];
if (!defined $partition || $partition eq '' || $partition =~ /^-/ || $partition =~ /[\x00-\x1f\x7f]/) {
	exit 0;
}

open(my $fh, '-|', 'df', '-k', '-P', '--', $partition) or exit 0;
while (my $line = <$fh>) {
	next if $line =~ /^Filesystem\b/;
	if ($line =~ /(\Q$partition\E)(.* )(.*[0-9])(.* )(.*[0-9])(.* )(.*[0-9])(.* )(.*[0-9])%(.* )/) {
		print "megabytes:$7 percent:$9";
	}
}
close($fh);
