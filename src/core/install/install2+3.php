<?php

/*
 *  install2+3.php gets called after the Doreen database has been created, but
 *  is still empty and needs to be filled with tables.
 *
 *  We get here only if doreen-install-vars.inc.php EXISTS but the config table
 *  does NOT exist.
 *
 *  We DO have a connection to the database at this point.
 *
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Active included code
 *
 ********************************************************************/

$oHTML = new HTMLChunk();

if (!($execute = getRequestArg('execute')))
{
    $htmlTitle = L("{{L//Doreen installation step 2}}");
    WholePage::EmitHeader($htmlTitle);
    $oHTML->openPage($htmlTitle, FALSE);

    $db = Database::$defaultDBName;
    $dirOfInstallVars = dirname(Globals::$fnameInstallVars);
    $oHTML->append(L(<<<EOD
<p><b>That worked well.</b> The %DATABASE% database has been created (although it is still empty at this time), and the file <code>%INSTALLVARS%</code> has been written to disk with the configuration data you have provided.</p>
<p>It would probably be a good idea to make this file and its directory read-only now. On a Linux server, use the following commands:</p>
<pre>
chmod 440 %INSTALLVARS%
chmod 750 %DIROFINSTALLVARS%
</pre>
<p>It is now time to fill the database with the tables that Doreen needs for its operation.</p>
<p>Please press %{Proceed}% to continue.</p>

<form>
  <input type="hidden" name="execute" value="yes">
  <button type="submit" class="btn btn-primary">Proceed</button>
</form>

EOD
    , [ '%DATABASE%' => Format::HtmlQuotes(toHTML($db)),
        '%INSTALLVARS%' => toHTML(Globals::$fnameInstallVars),
        '%DIROFINSTALLVARS%' => toHTML($dirOfInstallVars)
      ] ));

    $oHTML->close();    # page
    echo $oHTML->html;
    WholePage::EmitFooter();
}
else if ($execute == 'yes')
{
    $htmlTitle = L("{{L//Doreen installation step 2}}");
    WholePage::EmitHeader($htmlTitle);
    $oHTML->openPage($htmlTitle, FALSE);

    Install::LoadAllRoutines($oHTML);

    Install::ExecuteRoutines();

    $oHTML->close();    # page
    $oHTML->flush();
    WholePage::EmitFooter();
}

exit;


