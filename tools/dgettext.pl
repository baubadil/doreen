#!/usr/bin/env perl

# See \page drn_nls in docygen.pages.php for documentation
# or look up "National Language Support" under "Pages" after
# building the documentation with phoxygen (see README).
#
# Copyright 2015-17 Baubadil GmbH. All rights reserved.
#

use strict;
use warnings;
use Data::Dumper;

my @llPaths = qw( htdocs/ src/core/ src/plugins/ );
my $strPaths = join(' ', @llPaths);
my @llInputFiles;
my @llExtensions = qw( *.php *.xml );

foreach my $ext (@llExtensions)
{
    push @llInputFiles, findFiles($strPaths, $ext);
}

my $g_outdir = "out/pot";
print STDERR "Creating $g_outdir\n";
`mkdir -p $g_outdir`;

my %g_aOutfilesByDomain;    # file handles by domain names
my %g_aPairs;
my %g_aOccurences;      # Values are references to a g_aPairs pair.

# Sort the input files list! That way we get src/core before src/plugins
# and can eliminate duplicate strings from plugin domains.
foreach my $file (sort @llInputFiles)
{
    my ($ext) = $file =~ /\.([^.]*)$/;
    open(SOURCE, "< $file")
        or die("failed to open $file for reading: $!. Stopped");
    my $lineno = 0;
    my $linenoOpening = 0;
    my $multiline;
    my $idMultiline;
    my $domain;

    my $descrDomain = "";

    if ( (my $plugin) = $file =~ /src\/plugins\/([^\/]+)\//)
    {
        if (	($plugin ne 'config_changelog')
             && ($plugin ne 'user_hardcoded')
           )
        {
            $domain = $plugin;
            $descrDomain = " (domain: $domain)";
        }
    }

    print STDERR "Parsing file: $file$descrDomain\n";

    while (<SOURCE>)
    {
        # Escaping rules. If we're in a PHP source file and the string is in double quotes, then
        # it is already escaped like a C string, so we can copy it verbatim into the PO file, which
        # uses C-string-style escaping as well. Otherwise we need to escape.

        ++$lineno;
        my $prevChar;
        if ( (my $domain0) = /\/* !!DGETTEXT DOMAIN:\s+(\S+)/ )
        {
            # Ignore. We base the domain entirely on plugins now.
            # die ("Too many DGETTEXT DOMAIN tags at line $linenoOpening of file $file. Stopped")
            #     if ($domain);
            # $domain = $domain0;
        }
        # Single-line definition with a plural. String IDs must be same as message ID.
        elsif ( ($prevChar, my $str1, my $strPlural) = /(.)?\{\{Ln\/\/(.*)\/\/(.*)\}\}/ )
        {
            my $fCString = (($ext eq "php") && ($prevChar) && ($prevChar eq '"'));
            my $id = $str1;
            addString($file, $lineno, $domain, $id, $str1, $fCString, $strPlural);
        }
        # Single-line definition with or without a string ID after {{L/.
        elsif ( ($prevChar, my $id, my $str) = /(.)?\{\{L\/([a-zA-Z0-9-_)]*)\/(.*)\}\}/ )
        {
            my $fCString = (($ext eq "php") && ($prevChar) && ($prevChar eq '"'));
            # print "$str -- prevChar: \"$prevChar\" ($ext) ==> $fCString\n";
            $id = $str if (!$id);
            addString($file, $lineno, $domain, $id, $str, $fCString);
        }
        elsif ( (my $id2, my $str2) = (/\{\{L\/([a-zA-Z0-9-_)]*)\/(.*)/) )
        {
            # Opening multi-line comment:
            die "Unterminated {{ in line $linenoOpening of file $file. Stopped"
                if ($linenoOpening);
            $linenoOpening = $lineno;
            $multiline = "$str2\\n\n";
            $idMultiline = $id2;
            die "Missing required explicit ID in multiline string starting at line $linenoOpening of file $file. Stopped"
                if (!$id2);
        }
        elsif (    $linenoOpening
                && ( (my $str3) = /(.*)\}\}/ )
              )
        {
            # Closing multi-line comment:
            $multiline .= $str3;
            addString($file, $linenoOpening, $domain, $idMultiline, $multiline);
            $linenoOpening = 0;
            $multiline = undef;
        }
        elsif ($linenoOpening)
        {
            # Contintuing multi-line comment:
            chomp;
            $multiline .= "$_\\n\n";
        }
    }
    close SOURCE;
}

#
# OUTPUT .POT FILES
#

my $defaultDomainFile = "$g_outdir/doreen.pot";
my $fhDefaultDomain = openPOT($defaultDomainFile);

foreach my $id (sort keys %g_aOccurences)
{
    my $pllOccurrences = $g_aOccurences{$id};

    # The original string is introduced by the keyword msgid, and the translation, by msgstr.
    my $id2 = $id;

    # Extract domain, if any.
    my $fh = undef;
    if ( my ($domain, $rest) = $id2 =~ /^\{\{\{(.+)\}\}\}(.*)/)
    {
        if (!($fh = $g_aOutfilesByDomain{$domain}))
        {
            my $domainFile = "$g_outdir/$domain.pot";
            $fh = $g_aOutfilesByDomain{$domain} = openPOT($domainFile);
        }
        $id2 = $rest;
    }
    else
    {
        $fh = $fhDefaultDomain;
    }

    foreach my $occ (@$pllOccurrences)
    {
        print $fh "#: $occ\n";
    }

    # Our special marker for plural forms (resulting from Ln macros)?
    if ( (my $singular, my $plural) = $id2 =~ /\{!\{!\{PLURAL---(.*)---(.*)\}!\}!\}/)
    {
        print $fh "msgid  \"$singular\"\n";
        print $fh "msgid_plural  \"$plural\"\n";
        print $fh "msgstr[0] \"$singular\"\n";
        print $fh "msgstr[1] \"$plural\"\n";
    }
    else
    {
        print $fh "msgid  \"$id2\"\n";

        my $str = $g_aPairs{$id};
        # $str =~ s/"/\\"/g;
        if (index($str, "\n") != -1)
        {
            print $fh "msgstr \"\"\n";
            my @llLines = split("\n", $str);
            foreach my $line2 (@llLines)
            {
                print $fh "\"$line2\"\n";
            }
        }
        else
        {
            print $fh "msgstr \"$str\"\n";
        }
    }

    print $fh "\n";
}

#
# MERGE .POT FILES ON .PO FILES UNDER /src
#

my @llLocales = qw( de_DE );

for my $potfileIn (findFiles($g_outdir, '*.pot'))
{
    my $stem = $potfileIn;
    $stem =~ s/^.*\/([^\/]+)\.pot$/$1/;
    my $targetbasedir = ($stem eq 'doreen') ? "src/core/po" : "src/plugins/$stem/po";

    die "Required output directory \"$targetbasedir\" for merging file \"$potfileIn\" does not exist"
        if (!(-d $targetbasedir));

    my $c = 0;
    foreach my $locale (@llLocales)
    {
        my $pofileOut = "$targetbasedir/$locale.po";
        if (!(-f $pofileOut))
        {
            print "Creating new \"$pofileOut\" from \"$potfileIn\"...\n";
            my $cmd = "msginit -i \"$potfileIn\" -o \"$pofileOut\" -l $locale --no-translator";
            `$cmd`;
        }
        else
        {
            my $cmd = "msgmerge \"$pofileOut\" \"$potfileIn\" > /tmp/dgettext$c.tmp && mv /tmp/dgettext$c.tmp \"$pofileOut\"";
            print "Merging \"$potfileIn\" into existing \"$pofileOut\":\n$cmd\n";
            `$cmd`;
        }
        ++$c;
    }
}

exit(0);

######################################################################################

sub addString
{
    my ($file,
        $lineno,
        $domain,         # gettext domain or NULL if main
        $id0,            # string ID (or text)
        $str,            # English text ($id0 can be the same as this if not multiline)
        $fCString,       # in: TRUE if input string comes from a C string and has already been escaped
        $strPlural       # plural form with Ln case (optional)
       ) = @_;

    die "Identifier on line $lineno of file $file is too long. Stopped"
        if (length($id0) > 500);

    my $id = $id0;

    $id = "{!{!{PLURAL---$id0---$strPlural}!}!}"
        if ($strPlural);

    $id = "{{{$domain}}}$id"
        if ($domain);

    if (!$fCString)
    {
        $id  =~ s/"/\\"/g;
        $str =~ s/\\/\\\\/g;        # Escape literal backslashes.
        $str =~ s/\\\\n/\\n/g;      # Fix \\n back to \n.
        $str =~ s/"/\\"/g;          # Escape quots with \".
    }

    # If this ID is for a plugin and exists already in the core, then ignore it.
    # Otherwise we'd have duplicate work for the translators for no reason.
    if (	($domain)
         && ($g_aPairs{$id0})       # note, check for id0 == msgid without domain name!
       )
    {
    }
    elsif (!$g_aPairs{$id})
    {
        # First occurence:
        $g_aPairs{$id} = $str;
        my @ll = ( "$file:$lineno" );
        $g_aOccurences{$id} = \@ll;
    }
    else
    {
        my $pll = $g_aOccurences{$id};
        push @$pll, "$file:$lineno";
    }
}

sub openPOT
{
    my ($fn) = @_;

    my $fh = undef;
    open($fh, "> $fn") or die("Cannot write to $fn: $!. Stopped");

    print $fh '# Doreen .'."\n";
    print $fh '# Copyright (C) Baubadil GmbH. All rights reserved'."\n";
    print $fh 'msgid ""'."\n";
    print $fh 'msgstr ""'."\n";
    print $fh '"Project-Id-Version: 0.1.0\n"'."\n";
    print $fh '"Report-Msgid-Bugs-To: bugs@baubadil.de\n"'."\n";
    # print '"POT-Creation-Date: 2015-09-28 21:05+0200\\n"'."\n";
    # print '"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"'."\n";
    print $fh '"Last-Translator: Baubadil <bugs@baubadil.de>\\n"'."\n";
    # print '"Language-Team: LANGUAGE <LL@li.org>\\n"'."\n";
    print $fh '"Language: en_US\\n"'."\n";
    print $fh '"MIME-Version: 1.0\\n"'."\n";
    print $fh '"Content-Type: text/plain; charset=UTF-8\\n"'."\n";
    print $fh '"Content-Transfer-Encoding: 8bit\\n"'."\n\n";

    return $fh;
}

sub findFiles
{
    my ($strPaths, $ext) = @_;

    my @llInputFiles;
    my $cmd = 'find -L '.$strPaths.' -type f -name "'.$ext.'"';
    # print STDERR "Executing $cmd\n";
    open(FIND, "$cmd |")
        or die("failed to run \"$cmd\": $!. Stopped");
    while (<FIND>)
    {
        chomp;
        push @llInputFiles, $_;
    }
    close FIND;

    return @llInputFiles;
}
