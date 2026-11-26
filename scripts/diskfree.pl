#!/usr/bin/env perl

use strict;
use warnings;

delete @ENV{qw(PATH)};
$ENV{PATH} = '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin:/usr/local/sbin';

my $osname = "$^O";
my $arg = defined($ARGV[0]) ? $ARGV[0] : '';

# Strip a single matching pair of outer quotes for legacy Data Source compatibility
if ($arg =~ /^"(.*)"\z/s || $arg =~ /^'(.*)'\z/s) {
	$arg = $1;
}

# Refuse shell metacharacters, NUL bytes, and option-like arguments
if ($arg =~ /[`\$;&|<>\r\n\0]/ || $arg =~ /^-/) {
	exit;
}

my @cmd;
if ($osname =~ /freebsd/) {
	@cmd = ('df', '-k', '-P');
} else {
	@cmd = ('df', '--block-size=1024', '-P');
}

if ($arg ne '') {
	push @cmd, '--', $arg;
}

open(my $fh, '-|', @cmd) or exit;
while (my $line = <$fh>) {
	next if $line =~ /^Filesystem\b/;
	if ($line =~ /(\Q$arg\E)(.* )(.*[0-9])(.* )(.*[0-9])(.* )(.*[0-9])(.* )(.*[0-9])%(.* )/) {
		print "megabytes:$7 percent:$9";
	}
}
close($fh);
