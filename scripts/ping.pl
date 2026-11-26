#!/usr/bin/env perl
use strict;
use warnings;

delete @ENV{qw(PATH)};
$ENV{PATH} = '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin:/usr/local/sbin';

# take care for tcp:hostname or TCP:ip@
my $host = defined $ARGV[0] ? $ARGV[0] : '';
$host =~ s/(?:tcp|udp)6?://gis;

# leave IPv6 intact
if (($host =~ tr/://) <= 1) {
	$host =~ s/:[0-9]{1,5}//gis;
}

# Reject empty values, shell metacharacters, and leading dashes
if ($host =~ /^([A-Za-z0-9][\w.:-]*)$/) {
	$host = $1;
} else {
	print 'U';
	exit 0;
}

# always have the language in english
$ENV{LANG} = 'en_US.UTF-8';

my $osname = "$^O";
my @pingcmd = ($osname =~ /solaris/i)
	? ('ping', '-s', '--', $host, '64', '1')
	: ('ping', '-c', '1', '--', $host);

my $ping = '';
open(my $olderr, '>&', \*STDERR);
open(STDERR, '>', '/dev/null');
if (open(my $fh, '-|', @pingcmd)) {
	while (my $line = <$fh>) {
		if ($line =~ /(icmp_[sr]eq.*time|unknown host|Unknown host|not supported|Name or service not known|No address associated with name)/) {
			chomp($line);
			$ping = $line;
			last;
		}
	}
	close($fh);
}
open(STDERR, '>&', $olderr);
close($olderr);

if ($ping eq '' || $ping =~ /(?:unknown host|Unknown host|not supported|Name or service not known|No address associated with name)/) {
	if ((-f '/bin/ping6') || (-f '/sbin/ping6')) {
		open(my $olderr6, '>&', \*STDERR);
		open(STDERR, '>', '/dev/null');
		if (open(my $fh6, '-|', 'ping6', '-c', '1', '--', $host)) {
			while (my $line = <$fh6>) {
				if ($line =~ /icmp_[sr]eq.*time/) {
					chomp($line);
					$ping = $line;
					last;
				}
			}
			close($fh6);
		}
		open(STDERR, '>&', $olderr6);
		close($olderr6);
	}
}

if ($ping =~ m/(.*time=)([0-9.]+) (ms|usec)/) {
	my ($val, $unit) = ($2, $3);
	if ($val == 0) {
		print 'U'; 		# avoid cacti errors, but do not fake rrdtool stats
	} elsif ($unit eq 'usec') {
		print $val / 1000;	# re-calculate in units of 'ms'
	} else {
		print $val;
	}
} else {
	print 'U';
}
