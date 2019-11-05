<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  ApiHelp class
 *
 ********************************************************************/

/**
 *  Static class that combines ticket API handler implementations.
 *  Using this class enables us to use the ApiHelp class and have
 *  our autoloader take care of finding the include file.
 */
class ApiHelp
{
    /**
     *  Implementation for the GET /help REST API.
     */
    public static function Get($topic)
    {
        $a = NULL;

        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_HELPTOPICS) as $oImpl)
        {
            /** @var IHelpTopicsPlugin $oImpl */
            if ($a = $oImpl->getHelpForTopic($topic))
                break;
        }

        if (!$a)
        {
            switch ($topic)
            {
                case 'searchbar':
                    $a = [
                            L("{{L//%DOREEN% fulltext search}}"),
                            L(<<<HTML
{{L/HELPSEARCHBAR/<p>%DOREEN% has powerful full-text search with many of the features you have come to expect from the well-known internet
search engines.</p>

<p>If you enter ordinary words, %DOREEN% will perform a similarity match with ranking. Exact matches will be sorted
to the top, but words that are merely similar will be found as well (which helps with spelling and typos). 
If you enter <b>multiple words,</b> %DOREEN% will look for combinations of them as well as individual occurences of
every one of the words.</p>

<p>This behavior can also be changed with the following <b>special characters:</b></p>

<ul>
<li>A <code>+</code> (plus) character in front of a word makes it <b>mandatory,</b> meaning that the word <i>must</i> occur
in a document to be considered a match.</li>

<li>Inversely, a <code>-</code> (minus) character <b>negates</b> the word, meaning that the words <i>must not</i> occur in
a document.</li>

<li>If you enclose one word or multiple words with <b>quotes</b> (<code>"</code>%HELLIP%<code>"</code>), 
%DOREEN% will only return results if the entire phrase was found exactly. (In other words, this will no longer find
merely similar words, nor will it find occurences of only one of multiple words.)</li>

<li><code>#</code> (the <b>hash sign</b>) followed by a number will not perform a search, but go directly
to the given ticket number (e.g. <code>#123</code> will go to ticket number 123).</li>

</ul>

<p>%DOREEN% applies an internal <b>boost</b> to certain data fields. For example, matches in titles will result in a
higher ranking compared to matches in other fields or comments.</p>

<p>Finally, note that %DOREEN% will also perform a full-text search in <b>attached files</b> (e.g. PDF, ODT, DOC
documents) if their contents are searchable (i.e. not in bitmap scans).</p>}}
HTML
                            ) ];
                break;

                case 'autostart':
                    $a = [
                        L("{{L//Auto-starting %DOREEN% services}}"),
                        L(<<<HTML
{{L/HELPAUTOSTART/%DOREEN% provides services, which are PHP processes that run on the server in the background for
extended periods of time (or permanently) even if the server is not currently processing HTTP requests. Which services
are available depends on the plugins that you have installed. With <code>cli.php autostart-services</code>,
all services that provide a checkbox in this column and whose checkbox is enabled will be started if they are not
yet running. Additionally, you can use <code>cli.php autostart-systemd</code> once to have a systemd file created
that will run that command once during the server boot process.}}
HTML
                        ) ];
                break;

                case 'mainpageitems':
                    $a = [
                        L("{{L//Title page items}}"),
                        L("This shows what is currently configured to be visible on %DOREEN%'s title page. Please use <code>cli.php titlepage</code> on the command line to modify.")
                    ];
                break;

                case 'mainpagewiki':
                    $a = [
                        L("{{L//Title page wiki #}}"),
                        L("If set, %DOREEN%'s title page will display the body of this wiki ticket on the title page in addition to what is configured above. Please use <code>cli.php titlepageticket</code> on the command line to modify.")
                    ];
                break;

                case 'settings-ticketmail':
                    $a = [
                        L("{{L//Ticket mail}}"),
                        L("{{L//Ticket mail is the mail that %DOREEN% sends out whenever tickets are created or modified. Disable this checkbox to suppress ticket mail completely.}}")
                    ];
                break;
            }
        }

        if (!$a)
            throw new DrnException("No help available for topic ".Format::UTF8Quote($topic));

        return [ 'topic' => $topic,
                 'htmlHeading' => replaceSpecials($a[0]),
                 'htmlBody' => replaceSpecials($a[1])
               ];
    }
}
