<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Blurb
 *
 ********************************************************************/

/**
 *  The Blurb class encapsulates functionality of the Doreen main page. Every
 *  Blurb represents a potential bit of HTML that a plugin can provide to be
 *  displayed on the main page. Administrators can then configure which bits
 *  do get displayed.
 *
 *  When the main page is about to be displayed, Doreen calls the makeBlurbs()
 *  function of all plugins that implement the IMainPagePlugin interface.
 *  Plugins wishing to add something to the main page should derive a class
 *  from Blurb and create an instance of that derivative. The main page then
 *  calls Blurb::emit() with the HTMLChunk instance of the main page, to which
 *  the derivative can add HTML.
 */
abstract class Blurb
{
    public $fEnabled = FALSE;       // Do not set manually. This is set by GetAll().

    const DISPLAYWIKITICKET = 'displaywikiticket';

    /**
     *  Must return a pair of strings with ID and descriptive name for the blurb.
     *  The ID is stored in GlobalConfig, the descriptive name is displayed
     *  in the global settings.
     */
    abstract public function getID();

    /**
     *  Called by the Doreen main page code to give plugins a chance to call HTMLChunk::addUserAlerts()
     *  before \ref emit() gets called, in case any alerts need to be displayed. This default
     *  implementation does nothing, but plugins can override this method.
     */
    public function addUserAlerts(HTMLChunk $oHTML)
    {
    }

    /**
     *  Called by the Doreen main page code to give plugins a chance to add HTML to the main page.
     *  To do so, the plugin can add to the given HTMLChunk instance.
     */
    abstract public function emit(&$htmlTitle,
                                  HTMLChunk $oHTML);

    /**
     *  Returns a list of all Blurb objects provided by all plugins advertising CAPSFL_MAINPAGE capability.
     *
     * @return Blurb[]
     */
    public static function GetAll($fEnabledOnly = FALSE)
    {
        $strEnabled = GlobalConfig::Get(GlobalConfig::KEY_MAINPAGEITEMS);
        $aEnabled = [];
        foreach (explode(',', $strEnabled) as $enabled)
            $aEnabled[$enabled] = 1;

        $llBlurbsToAdd = [ new DisplayWikiTicketBlurb ];
        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_MAINPAGE) as $oImpl)
            /** @var IMainPagePlugin $oImpl */
            if ($ll = $oImpl->makeBlurbs())
                foreach ($ll as $new2)
                    $llBlurbsToAdd[] = $new2;


        /** @var Blurb[] $aBlurbsByID */
        $aBlurbsByID = [];
        foreach ($llBlurbsToAdd as $oBlurb)
        {
            /** @noinspection PhpUnusedLocalVariableInspection */
            list($id, $name) = $oBlurb->getID();
            $oBlurb->fEnabled = isset($aEnabled[$id]);
            $aBlurbsByID[$id] = $oBlurb;
        }

        if (!$fEnabledOnly)
            return array_values($aBlurbsByID);

        /* If caller wants only enabled, then return the objects in the order of the GlobalConfig
           setting because order is relevant for the MainPage output. */
        $llReturn = [];
        foreach ($aEnabled as $id => $dummy)
            if ($o = getArrayItem($aBlurbsByID, $id))        // might not be active
                $llReturn[] = $o;
        return $llReturn;
    }

    /**
     *  Sets a new list of title page items. $llNew must be a flat list of blurb IDs. The order
     *  is important as it will determine the output order on the title page if several blurbs
     *  are enabled.
     */
    public static function SetAll(array $llNew)
    {
        $aAvailableBlurbs = [];
        foreach (Blurb::GetAll() as $oBlurb)
        {
            /** @noinspection PhpUnusedLocalVariableInspection */
            list($id, $name) = $oBlurb->getID();
            $aAvailableBlurbs[$id] = 1;
        }
        foreach ($llNew as $new)
            if (!isset($aAvailableBlurbs[$new]))
                throw new DrnException(Format::UTF8Quote($new)." is not a valid main page item ID");

        GlobalConfig::Set(GlobalConfig::KEY_MAINPAGEITEMS, implode(',', $llNew));
        GlobalConfig::Save();
    }

    /**
     *  Appends $new to the list of title page blurbs if it's not on the list yet.
     *  Returns TRUE if it was added.
     */
    public static function Add($new)
        : bool
    {
        $ll = [];
        foreach (Blurb::GetAll(TRUE) as $oBlurb)
        {
            /** @noinspection PhpUnusedLocalVariableInspection */
            list($id, $name) = $oBlurb->getID();
            if ($id == $new)
                return FALSE;
            $ll[] = $id;
        }

        $ll[] = $new;
        self::SetAll($ll);
        return TRUE;
    }

    /**
     *  Returns the integer ticket no. of what is configured to be the title page wiki ticket,
     *  whose contents are displayed on the title page in addition to what is displayed as blurbs,
     *  or NULL if nothing has been configured yet.
     *
     * @return int | null;
     */
    public static function GetTitlePageWikiTicket()
    {
        if ($s = GlobalConfig::Get(GlobalConfig::KEY_MAINWIKITICKET))
            if (isInteger($s))
                return (int)$s;

        return NULL;
    }

    public static function SetTitlePageWikiTicket(int $id)
    {
        self::Add(self::DISPLAYWIKITICKET);
        GlobalConfig::Set(GlobalConfig::KEY_MAINWIKITICKET, $id);
        GlobalConfig::Save();
    }
}

/**
 *  A built-in blurb (title page item) that displays the contents of a ticket on the Doreen title
 *  page. This allows for flexibility on what is added.
 *
 *  Use Blurb::SetTitlePageWikiTicket() to set the ticket ID that should be displayed. The following
 *  rules apply:
 *
 *   -- If the currently logged in user does not have read access to that ticket, nothing is displayed
 *      on the title page for this blurb at all. (Additional blurbs can still display other things.)
 *
 *   -- If the currently logged in user has update permission on that ticket, an edit link is also
 *      displayed as a shortcut to having to find that ticket with the search bar first since the
 *      ticket ID may not be visible.
 */
class DisplayWikiTicketBlurb extends Blurb
{
    public function getID()
    {
        return [ self::DISPLAYWIKITICKET, 'Display contents of a wiki ticket' ];
    }

    public function emit(&$htmlTitle,
                         HTMLChunk $oHTML)
    {
        if (!($id = self::GetTitlePageWikiTicket()))
            $oHTML->append("Error: no title page wiki ticket configured, use \"titlepageticket --set X\" CLI command");
        else if (!($oTicket = Ticket::FindOne($id, Ticket::POPULATE_DETAILS)))
            $oHTML->append("Error: invalid ticket ID $id configured with titlepageticket CLI command");
        else
        {
            $flAccess = $oTicket->getUserAccess(LoginSession::GetCurrentUserOrGuest());
            if ($flAccess & ACCESS_READ)
            {
                $htmlTitle = $oTicket->getTitle();

                if ($flAccess & ACCESS_UPDATE)
                    $oHTML->appendChunk(HTMLChunk::MakeElement('div',
                                                               [ 'class' => 'pull-right' ],
                                                               $oTicket->makeEditLink()));

                $oHTML->append($oTicket->getFieldValue(FIELD_DESCRIPTION));
            }
        }
    }
}
