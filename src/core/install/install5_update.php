<?php

/*
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
    // If we're impersonating, then the "stop impersonating" menu item won't work and we'd be stuck.
    // So stop impersonating right now.
    if (LoginSession::IsImpersonating())
    {
        LoginSession::Impersonate(NULL);
        WebApp::Reload();
    }

    $htmlTitle = L("{{L//Database tables update required}}");
    WholePage::EmitHeader($htmlTitle);
    $oHTML->openPage($htmlTitle, FALSE);

    $oHTML->append(L(<<<EOD
<p>{{L//An update has been installed on this server, but the %DOREEN% database has not been updated yet.}}</p>
EOD
                  ));

    if (!LoginSession::IsCurrentUserAdmin())
        $oHTML->append(L(<<<EOD
<p>{{L//Please contact the administrator of this site to have the upgrade completed, or sign into an administrator account, if you have one.}}
</p>
EOD
                      ));
    else
    {
        GlobalConfig::$databaseVersion = GlobalConfig::Get('database-version');
        require INCLUDE_PATH_PREFIX.'/core/install/install5_update-data.php';

        $oHTML->addLine("<ol>");
        foreach (Install::GetRoutines() as $htmlDescription => $sql)
            $oHTML->addLine("<li>$htmlDescription</li>");
        $oHTML->addLine("</ol>");

        $oHTML->append(L(<<<EOD
<p>{{L//Press %{Proceed}% to complete the database update.}}
</p>

<form>
  <input type="hidden" name="execute" value="yes">
  <button type="submit" class="btn btn-primary">{{L//Proceed}}</button>
</form>

EOD
                      ));
    }
} // if (!($execute = getRequestArg('execute')))
else
{
    $htmlTitle = L("Installing database tables update");
    WholePage::EmitHeader($htmlTitle);
    $oHTML->openPage($htmlTitle, FALSE);

    $oHTML->flush();

    GlobalConfig::$databaseVersion = GlobalConfig::Get('database-version');
    require INCLUDE_PATH_PREFIX.'/core/install/install5_update-data.php';

    # Refresh the database-version in config to the latest after having done all the updates.
    $version = DATABASE_VERSION;
    GlobalConfig::AddInstall("Store database version $version", function()
    {
        GlobalConfig::Set('database-version', DATABASE_VERSION);
        GlobalConfig::Save();
    });
    GlobalConfig::AddInstall("Clear thumbnails cache", function()
    {
        DrnThumbnailer::ClearCache();
    });

    # GO!
    Install::ExecuteRoutines();
}

$oHTML->close();    # page
$oHTML->flush();
WholePage::EmitFooter();

exit;
