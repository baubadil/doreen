<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  ViewMainPage
 *
 ********************************************************************/

/**
 *  Abstract class that implements the main page view.
 */
abstract class ViewMainPage
{
    /**
     *
     */
    public static function CheckSystem(HTMLChunk $oHTML)
    {
        Globals::CheckRequiredPHPExtensions();

        if (!function_exists("gettext"))
            throw new DrnException("Doreen requires the GNU gettext extension to be installed.");

        if (strtoupper($charset = ini_get("default_charset")) != "UTF-8")
            throw new DrnException("Doreen requires PHP's default charset to be utf-8 (currently \"$charset\"), please fix your php.ini.");

        if (ini_get('magic_quotes'))
            throw new DrnException("Doreen requires PHP's magic_quotes to be turned off, please fix your php.ini.");

        # TODO check PHP.INI: session.cookie_httponly = 1
        # session.use_only_cookies = 1
        # session.cookie_secure = 1

        if (Plugins::GetFailed())
        {
            /** @noinspection PhpUnusedLocalVariableInspection */
            list($htmlPlugins, $htmlFailedPlugins) = ViewGlobalSettings::DescribePlugins('alert-warning');
            $oHTML->addLine($htmlFailedPlugins);
        }

        # PHP >= 5.5 must not have always_populate_raw_post_data enabled.
        if (    defined(PHP_VERSION_ID)
             && (PHP_VERSION_ID >= 50500)
             && ($v = ini_get('always_populate_raw_post_data') != -1)
           )
            $oHTML->addAdminAlert("The php.ini on this server has <code>always_populate_raw_post_data</code> set to $v, which causes problems with HTTP POST requests.",
                                  "<p>Please fix it and set it to -1. "
                                  ."See <a href=\"https://www.bram.us/2014/10/26/php-5-6-automatically-populating-http_raw_post_data-is-deprecated-and-will-be-removed-in-a-future-version/\">this article</a> for details.</p>");

        if (!(ini_get('date.timezone')))     # NULL or FALSE if not not exist:
            $oHTML->addAdminAlert("The php.ini on this server does not have <code>date.timezone</code> set.",
                                  "<p>This will trigger all kinds of weird PHP errors, so please fix that.</p>");

        if ($oSearch = Plugins::GetSearchInstance())
        {
            // Check if a reindex is currently in progress.
            if (LongTask::IsReindexerRunning())
            {
                $oHTML->addAlert(L(<<<HTML
{{L/REINDEXINGMESSAGE/<b>Warning:</b> The search engine is currently rebuilding its index, which can take a few hours.
During that time, this message will be shown, and %DOREEN% will produce incomplete search results. If you cannot find
what you are looking for, please check again later. We apologize for the inconvenience!}}
HTML
                                 ),NULL, 'alert-danger');
            }
            else switch ($oSearch->getStatus(TRUE))
            {
                case SearchEngineBase::RUNNING:
                    if (GlobalConfig::GetBoolean(GlobalConfig::KEY_REINDEX_SEARCH, FALSE))
                    {
                        $url = Globals::$rootpage.'/settings#reindex-all';
                        $oHTML->addAdminAlert("The search engine index is outdated, search results may be unrelated to queries and filters may be broken.",
                                              "<p>Please see the <a href=\"$url\">System settings</a> for details.</p>");
                    }
                break;

                case SearchEngineBase::NOINDEX:
                    $url = Globals::$rootpage.'/settings';
                    $oHTML->addAdminAlert("The search engine process is running, but apparently the index has not yet been created.",
                                          "<p>Please see the <a href=\"$url\">System settings</a> for details.</p>");
                break;

                default:
                {
                    $url = Globals::$rootpage.'/settings';
                    $oHTML->addAdminAlert("The search engine process does not seem to be running. Functionality will be severely limited.",
                                          "<p>Please see the <a href=\"$url\">System settings</a> for details.</p>");
                }
            }
        }
    }

    /** @var $oHtmlFirst HTMLChunk */
    public static $oHtmlFirst = NULL;       // Optionally to be filled by plugins regardless of which order the blurbs are processed.

    public static function Emit()
    {
        $htmlTitle = L("{{L//Welcome to %DOREEN%!}}");

        $llBlurbs = Blurb::GetAll(TRUE);      // enabled only
        $oHTMLBlurbs = new HTMLChunk(4);

        foreach ($llBlurbs as $oBlurb)
            $oBlurb->addUserAlerts($oHTMLBlurbs);

        self::CheckSystem($oHTMLBlurbs);      # this dies on errors

        $c = 0;
        foreach ($llBlurbs as $oBlurb)
        {
            if ($c++ > 0)
                $oHTMLBlurbs->addLine("\n      <hr>\n");
            $id = $oBlurb->getID()[0];
            $oHTMLBlurbs->addLine("<!-- begin $id -->");
            $oBlurb->emit($htmlTitle, $oHTMLBlurbs);
            $oHTMLBlurbs->addLine("<!-- end $id -->");
        }

        if (!$c)
            if (LoginSession::IsCurrentUserAdmin())
            {
                $href = Globals::$rootpage.'/tickets';
                $oHTMLBlurbs->addAlert("If you weren't an admin, this page would be blank: no main page items are configured. Use the "
                                 .Format::UTF8Quote("titlepage")
                                 ." command line mode to list and configure them. You can <a href=\"$href\">show all tickets</a> for now.");
            }

        $oHTMLPage = new HTMLChunk(2);
        $oHTMLPage->openPage($htmlTitle, FALSE);
        if (self::$oHtmlFirst)
            $oHTMLPage->appendChunk(self::$oHtmlFirst);
        $oHTMLPage->appendChunk($oHTMLBlurbs);
        $oHTMLPage->close();    # page

        // Only on the main page we want to set focus to the search bar.
        WholePage::Enable(WholePage::FEAT_JS_AUTOFOCUS_SEARCH_BAR);
        WholePage::Emit($htmlTitle,
                        $oHTMLPage);
    }
}
