#!/usr/bin/env perl

#
#  Used by the build system to increase the Doreen version number on every push.
#  Copyright 2017 Baubadil GmbH. All rights reserved.
#

use strict;
use warnings;

my $filename = shift(@ARGV) or die "Missing argument";
open FILE, "< $filename" or die "Couldn't open $filename for reading: $!";
chomp(my $version = <FILE>);
close FILE;

die("File $filename has no version contents") if (!$version);

(my $major, my $minor, my $revision, my $build) = $version =~ /(\d+)\.(\d+)\.(\d+)\.(\d+)/;
++$build;

my $newversion = "$major.$minor.$revision.$build";
open FILE, "> $filename" or die "Couldn't open $filename for writing: $!";
print FILE "$newversion\n";
close FILE;

print "Bumped version number to $newversion\n";
