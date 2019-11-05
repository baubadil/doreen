<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  Implements the "System settings" admin interface.
 */
abstract class ViewGlobalSettings
{
    /**
     *  Returns a list of two strings describing all loaded plugins and, if any,
     *  plugins that failed to load. This is also used in the index.html main
     *  page as a warning ('/' GUI URL).
     */
    public static function DescribePlugins($class = 'alert-danger')
    {
        $aFailedPlugins = [];
        $aFailed = Plugins::GetFailed();

        $aAllPlugins = Install::GetAllPlugins();

        $oHTML = new HTMLChunk();
        $oHTML->openTable();
        $oHTML->addTableHeadings( [ L('{{L//Name}}'),
                                    L('{{L//Enabled}}'),
                                    L('{{L//Author}}'),
                                    L('{{L//Features}}') ] );
        foreach ($aAllPlugins as $plugin => $aPluginData)
        {
            $htmlEnabled = $bgClass = '';
            if (    $aFailed
                 && ($path = getArrayItem($aFailed, $plugin))
               )
            {
                $aFailedPlugins[] = "<b>$plugin</b> ($path)";
                $bgClass = 'danger';
                $extra = "<b>Failed to load</b>";
            }
            else
            {
                /** @@var IPlugin $o */
                list($o, $path) = Plugins::Get($plugin);

                $extra = '';
                if ($o)
                {
                    $fl = $o->getCapabilities();
                    $extra = Plugins::DescribeFlags($fl);
                }

                $htmlEnabled = Icon::Get($aPluginData['enabled'] ? 'checkbox_checked' : 'checkbox_unchecked');
            }

            $oHTML->addTableRow( [ $plugin,
                                   $htmlEnabled,
                                   toHTML($aPluginData['author']),
                                   $extra ],
                                 $bgClass );
        }
        $oHTML->close();    # table

        $plugins = $oHTML->html;

        $failedPlugins = '';
        if (count($aFailedPlugins))
        {
            $oAlert = new HTMLChunk();
            $oAlert->addAlert("<b>Warning:</b> The following plugins are enabled but failed to load:<br>".implode("<br>", $aFailedPlugins), NULL, $class);
            $failedPlugins = $oAlert->html;
        }

        return [$plugins, $failedPlugins];
    }

    private static function MakeBool(string $settingsKey,       //!< global config settings key and dialog item ID
                                     string $lstr,
                                     bool $fCurrentValue)       //!< in: current value of the setting from GlobalConfig
        : HTMLChunk
    {
        $oHtmlSub = new HTMLChunk();
        $oHtmlSub->addCheckbox(L($lstr),
                               $settingsKey,
                               NULL,
                               NULL,
                               $fCurrentValue);

        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initGlobalSettingBool',
                                             [ $settingsKey ]);

        return $oHtmlSub;
    }

    /**
     *  Implementation for the GET /settings GUI URL.
     */
    public static function Emit()
    {
        if (!LoginSession::IsCurrentUserAdminOrGuru())
            myDie(L('{{L//You are not authorized to be here}}'));

        $oHTML = new HTMLChunk();

        $htmlTitle = L('{{L//System settings}}');
        $oHTML->openPage($htmlTitle, FALSE, 'cog');

        /*
         *  Prepare "Title page" section
         */

        $htmlTitlePageBlurbs = "";
        $c = 0;
        foreach (Blurb::GetAll(TRUE) as $oBlurb)
        {
            list($id, $name) = $oBlurb->getID();
            if ($c++ > 0)
                $htmlTitlePageBlurbs .= "<br>";
            $htmlTitlePageBlurbs .= "$name (ID: $id)\n";
        }

        $htmlMainPageHelp = Format::NBSP.HTMLChunk::MakeHelpLink('mainpageitems')->html;

        $htmlMainWikiPageTicket = '';
        if ($id = Blurb::GetTitlePageWikiTicket())
            if ($oTicket = Ticket::FindOne($id))
                $htmlMainWikiPageTicket = "#$id ".$oTicket->makeLink(NULL)." ".$oTicket->makeEditLink()->html;

        $htmlTitlePageWikiHelp = Format::NBSP.HTMLChunk::MakeHelpLink('mainpagewiki')->html;


        /*
         *  Prepare "System" section
         */

        list($htmlPlugins2, $htmlFailedPlugins) = self::DescribePlugins();
        $oHTMLSub = new HTMLChunk();
        $idSettingsPlugins = 'settings-plugins';
        if ($htmlFailedPlugins)
            $oHTMLSub->addLine($htmlFailedPlugins);
        $oHTMLSub->addShowHideButton($idSettingsPlugins, L("{{L//Show details}}"));
        $oHTMLSub->openDiv($idSettingsPlugins, 'hidden');
        $oHTMLSub->addLine($htmlPlugins2);
        $oHTMLSub->close();     # div
        $htmlPlugins = $oHTMLSub->html;

        $htmlTicketMail = self::MakeBool(GlobalConfig::KEY_TICKET_MAIL_ENABLED,
                                         "{{L//Enable ticket mail}}",
                                         GlobalConfig::IsTicketMailEnabled())->html;
        if ($oHost = SMTPHost::CreateFromDefines())
            $htmlTicketMail .= L("{{L//All mail is sent via SMTP host <b>%HOST%</b> with login <b>%LOGIN%</b>}}",  [ '%HOST%' => $oHost->Host, '%LOGIN%' => $oHost->Username ]);
        else
            $htmlTicketMail .= L("{{L//All mail is sent via local sendmail without validation}}");

        /*
         *  Prepare "Appearance" section
         */

        $aThemes = WholePage::GetThemes();

        $idDialogTheme = 'set-theme';
        $ohtmlThemes = new HTMLChunk();
        $ohtmlThemes->openDiv($idDialogTheme);
        $ohtmlThemes->addHiddenErrorBox("$idDialogTheme-error");
        $ohtmlThemes->addSelect("$idDialogTheme-select",
                                $aThemes,
                                WholePage::GetTheme());
        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initThemeSelector',
                                             [ $idDialogTheme ]);
        $ohtmlThemes->close();      # div
        $htmlThemes = $ohtmlThemes->html;

        $htmlNavbarFixedTop = self::MakeBool(GlobalConfig::KEY_TOP_NAVBAR_FIXED,
                                             "{{L//Keep fixed at top of screen}}",
                                             GlobalConfig::IsTopNavbarFixedEnabled())->html;


        /*
         *  Prepare "SQL database" section
         */

        $htmlDatabase = L('{{L//%DBSERVER% on host %DBHOST%}}',
                          [ '%DBSERVER%' => Database::$defaultDBType.' '.Database::GetDefault()->getVersion(),
                            '%DBHOST%' => Format::HtmlQuotes(DBHOST)
                          ] );

        $cTickets = Ticket::CountAll();

        $clearbutton = '';
        $rootpage = Globals::$rootpage;
        if (LoginSession::IsCurrentUserAdmin())
        {
            $delall = L("{{L//Delete all tickets}}").Format::HELLIP;

            $clearbutton = <<<HTML
        <form method="POST" action="$rootpage/nuke">
        <button type="submit" class="btn btn-danger" type="submit">$delall</button>
        </form>
HTML;
        }

        $bytesDB = Database::GetDefault()->getTotalSize(Database::$defaultDBName);
        $bytesAttach = FileHelpers::FolderSize(DOREEN_ATTACHMENTS_DIR);


        /*
         *  Prepare "Services" section
         */

        $llAllServices = ServiceLongtask::GetAll();

        $oHTMLServices = new HTMLChunk();
        $cServices = 0;
        $idDialog = 'drn-services';
        $oHTMLServices->addLine(L(<<<EOD
{{L/SERVICESINTRO/%DOREEN% services are processes that keep running in the background regardless of the HTTP requests
that %DOREEN% may be serving. Some services can be configured to start automatically when the server starts up.
Which services are available depends on the plugins you have activated.}}
EOD
        ));
        $oHTMLServices->openDiv("$idDialog");
        $oHTMLServices->addHiddenErrorBox("$idDialog-error");

//        Debug::Log(0, print_r($llAllServices, TRUE));

        foreach ($llAllServices as $oService)
        {
            if (!$cServices)
            {
                $oHTMLServices->openTable("$idDialog-table");
                $oHTMLServices->addTableHeadings( [ L("{{L//Name}}"),
                                                    L("{{L//Plugin}}"),
                                                    L("{{L//Status}}"),
                                                    L("{{L//Autostart}}").Format::NBSP.HTMLChunk::MakeHelpLink('autostart')->html,
                                                    L("{{L//Actions}}"),
                                                  ] );
            }

            $oHTMLServices->openTableRow();

            $oHTMLServices->addTableCell($oService->nlsDescription);

            if (!($plug = $oService->getPluginName()))
                $plug = '&mdash;';
            $oHTMLServices->addTableCell($plug);

            $oHTMLServices->openTableCell();
            if ($oLongtask = $oService->isRunning())
                $oHTMLServices->addLine(Format::HtmlPill(L("{{L//Running}}"), 'bg-success'));
            else
                $oHTMLServices->addLine(Format::HtmlPill(L("{{L//Not running}}"), 'bg-warning'));
            $oHTMLServices->close(); # cell

            $oHTMLServices->openTableCell();
            if ($oService->canAutostart())
            {
                $checked = $oService->hasAutostart() ? ' checked' : '';
                $htmlService = toHTML($oService->serviceAPIID);
                $attrs = "type=\"checkbox\"$checked";
                $oHTMLServices->addLine("<input data-service=\"$htmlService\" $attrs/>");
            }
            $oHTMLServices->close();

            $oHTMLServices->openTableCell();
            if ($aActions = $oService->getActionLinks())
            {
                $cActions = 0;
                foreach ($aActions as $html => $url)
                {
                    if ($cActions++)
                        $oHTMLServices->addLine("<br>");
                    $link = Globals::$rootpage."/$url";
                    $oHTMLServices->addLine("<a href=\"$link\">$html</a>");
                }
            }
            $oHTMLServices->close();

            $oHTMLServices->close();    # row

            ++$cServices;
        }
        $oHTMLServices->close(); # div

        if ($cServices)
        {
            $oHTMLServices->close();     # form

            WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initAutostarts',
                                                 [ $idDialog ] );
        }

        $htmlTicketMailHelp = Format::NBSP.HTMLChunk::MakeHelpLink('settings-ticketmail')->html;

        /*
         *  Put it all together
         */

        $aRows = [ '{{L//Appearance}}' => 2,# heading
                   '{{L//Branding}}' => Globals::$doreenName,
                   '{{L//Theme}}' => $htmlThemes,
                   '{{L//Navigation bar}}' => $htmlNavbarFixedTop,
                   '{{L//Title page items}}'.$htmlMainPageHelp => $htmlTitlePageBlurbs,
                   '{{L//Title page wiki #}}'.$htmlTitlePageWikiHelp => $htmlMainWikiPageTicket,

                   '{{L//System}}' => 2,# heading
                   '{{L//PHP version}}' => phpversion(),
                   '{{L//Program version}}' => Globals::GetVersion(),
                   '{{L//Plugins}}' => $htmlPlugins,
                   '{{L//Ticket mail}}'.$htmlTicketMailHelp => $htmlTicketMail,

                   '{{L//SQL database}}' => 2,# heading
                   '{{L//Server}}' => $htmlDatabase,
                   '{{L//Database name}}' => Format::HtmlQuotes(Database::$defaultDBName),
                   '{{L//Connecting as user}}' => Format::HtmlQuotes(Database::$defaultDBUser),
                   '{{L//%DOREEN% database tables version}}' => GlobalConfig::Get('database-version'),
                   '{{L//Tickets in database}}' => Format::Number($cTickets).$clearbutton,
                   '{{L//Allocated database size}}' => L('%DB% in database<br>%AT% as files in attachments directory',
                                                 [ '%DB%' => Format::Bytes($bytesDB),       // 1000-based, not 1024
                                                   '%AT%' => Format::Bytes($bytesAttach)    // 1000-based, not 1024
                                                 ]),

                   '{{L//%DOREEN% services}}' => 2,# heading
                   '{{L//Available services}}' => $oHTMLServices->html,
                 ];

        # Now go through all plugins to allow them to change the table.
        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_GLOBALSETTINGS) as $oImpl)
        {
            /** @var IGlobalSettingsPlugin $oImpl */
            $oImpl->addToSettingsTable($aRows);
        }

        foreach ($aRows as $key => $value)
        {
            $oHTML->openGridRow(NULL, 'drn-margin-top drn-margin-bottom');
            $l = L($key);
            if (is_integer($value))
            {
                if ($value)
                    $str = "<h$value>$l</h$value>";
                else
                    $str = $value;

                $oHTML->addGridColumns( [$str], [12]);
            }
            else
            {
                $oHTML->openGridColumn(2);
                $oHTML->addLine($l);
                $oHTML->close();
                $oHTML->openGridColumn(10);
                $oHTML->addLine($value);
                $oHTML->close();
            }
            $oHTML->close();    # grid row
        }

        $oHTML->close();    # page

        WholePage::SilenceFooter();

        WholePage::Emit($htmlTitle, $oHTML);
    }

    /**
     *  Implementation for the POST /nuke GUI request that comes from the "Delete all tickets"
     *  button on the settings page.
     */
    public static function PostNuke()
    {
        $dlgid = 'nuke';
        $nlsNuking = L('{{L//Deleting all tickets}}');
        $spinner = Icon::Get('spinner');

        $oHTML = new HTMLChunk();

        $htmlTitle = L('{{L//Really delete all tickets in database?}}');
        $oHTML->openPage($htmlTitle, TRUE);

        $oHTML->addAlert(L(<<<EOD
    {{L/NUKEALLHELP/<p>There are currently %COUNT% tickets in the database. If you press the button below, they will <b>all be deleted</b> without a trace, including
    all changelogs and other associated data. User accounts, groups and ticket types will be preserved, however.</p>
    <p>Since this deletes every ticket individually, preserving database integrity along the way, this can take a <b>very long time,</b> possibly hours.</p>
    <p>Are you sure you want to do this?}}
EOD
                              ,
                              [ '%COUNT%' => Format::Number(Ticket::CountAll()) ]
                        ), NULL, 'alert-danger' );

        $oHTML->openForm('nuke');
        $oHTML->addButton(L('{{L//Delete all tickets}}').'! '.$spinner, 'nuke-save');
        $oHTML->close();    # form

        $oHTML->addLine("<br>");
        $oHTML->openDiv("$dlgid-div", "hidden");
        $oHTML->addAlert(L('{{L//Deleted %DELETED% tickets out of %TOTAL% %REMAIN%}}',
                           [ '%DELETED%' => "<span id=\"$dlgid-deleted\">0</span>",
                             '%TOTAL%' => "<span id=\"$dlgid-total\">0</span>",
                             '%REMAIN%' => "<span id=\"$dlgid-remain\"></span>"
                           ]),
                         $dlgid.'-result',
                         'alert-warning');
        $oHTML->addHiddenErrorBox($dlgid.'-error');
        $oHTML->addProgressBar($dlgid.'-progress', 'drn-margin-above');
        $oHTML->close();    # DIV

        $oHTML->close();    # page

        WholePage::AddNLSStrings([
            'nuking' => L('{{L//Deleting all tickets}}')
        ]);
        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */'core_initNukeDialog', []);

        WholePage::Emit($htmlTitle, $oHTML);
    }

    /**
     *  Implementation for the /mail-queue GUI request.
     */
    public static function EmitMailQueue()
    {
        if (!LoginSession::IsCurrentUserAdminOrGuru())
            myDie(L('{{L//You are not authorized to be here}}'));

        $oHTML = new HTMLChunk();

        $htmlTitle = L('{{L//Recently sent mails}}');
        $oHTML->openPage($htmlTitle, FALSE);

        if ($aMailStatus = Email::GetQueue())
        {
            $oHTML->openTable();
            $oHTML->addTableHeadings( [ 'i', 'Date', 'status', 'To&nbsp;/ BCC', 'Subject' ] );
            foreach ($aMailStatus as $aRow)
            {
                list($i, $insert_dt, $iStatus, $jsonData, $error) = $aRow;

                list($str, $colorClass) = Email::DescribeStatus($iStatus);
                if ($error)
                    $str = "Error: ".toHTML($error);
                $htmlStatus = Format::HtmlPill($str,
                                               $colorClass);

                $aData = json_decode($jsonData, TRUE);
                $toAndBcc = '';
                if ($aTo = $aData['to'] ?? NULL)
                    $toAndBcc = implode(', ', $aTo);
                if ($aBCC = $aData['bcc'] ?? NULL)
                    $toAndBcc .= implode(', ', $aBCC);
                $subject = getArrayItem($aData, 'subject');
                $oHTML->addTableRow( [ $i, $insert_dt, $htmlStatus, $toAndBcc, $subject] );
            }
            $oHTML->close(); # table
        }
        else
            $oHTML->addLine(L("{{L//No data found in email queue}}"));

        $oHTML->close(); # page

        WholePage::Emit($htmlTitle, $oHTML);
    }

    const ID_REINDEX_DIALOG = 'reindex-all';

    /**
     *  Helper method that can be called by search engine plugins to produce the "reindex all" button.
     *  This is here so we can add TypeScript funcionality more easily.
     */
    public static function MakeReindexButton(Dialog &$oDlg, $htmlButton)
    {
        $oDlg->openForm('reindex-all');
        $oDlg->addLine("<br>");
        if (GlobalConfig::GetBoolean(GlobalConfig::KEY_REINDEX_SEARCH, FALSE))
            $oDlg->addAdminAlert(L("{{L//Search index is outdated. Please trigger a re-index via the button below or use <code>cli.php reindex-all</code>.}}"));
        $oDlg->addButton($htmlButton, self::ID_REINDEX_DIALOG.'-save');
        $oDlg->addHiddenErrorBox('reindex-all-error');
        $oDlg->addProgressBar('reindex-all-progress', 'hidden drn-margin-above');
        $oDlg->close(); # form

        $nlsReindexing = L("{{L//Reindexing}}").Format::HELLIP;

        $idDialog = self::ID_REINDEX_DIALOG;
        $channelReindexing = Longtask::CHANNEL_SEARCH_REINDEX_ALL;

        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initReindexAllButton',
                                             [ $idDialog, $channelReindexing ]);
    }

}
