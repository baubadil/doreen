<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  KeywordsHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_KEYWORDS.
 *
 *  Keywords differ from all the other fields (FIELD_TITLE, FIELD_DESCRIPTION,
 *  FIELD_PRIORITY, FIELD_UIDASSIGN and all properties such as FIELD_CATEGORY)
 *  in that they have an m:n relation with tickets.
 *
 *  In other words, a keyword can be attached to an arbitrary number of tickets,
 *  and a ticket can have an arbitrary number of keywords.
 *
 *  To map this in the database and ticket data, the following has been done:
 *
 *   -- The 'keyword_defs' table contains the keywords which have been used so
 *      far, in any ticket, as (int) idKeyword => string pairs. Other tables only
 *      use the integer idKeyword.
 *
 *   -- To map the m:n relation between tickets and keywords, we can use the
 *      'ticket_ints' table as well, as with all the other ticket fields. The
 *      difference here is, however, that a 1:1 relation between 'tickets' and
 *      'ticket_ints' is not enforced: there can be several rows in ticket_ints
 *      with the same ticket_id and field_id = FIELD_KEYWORDS.
 *
 *   -- When a keyword is added to a ticket, it is first added to 'ticket_keywords'
 *      if it doesn't exist yet. Then, a row is added to ticket_ints with
 *      ticket_id = ticket ID, field_id = FIELD_KEYWORDS, and value = primary index
 *      from the keyword_defs table.
 */
class KeywordsHandler extends FieldHandler
{
    public $label = '{{L//Keywords}}';
    public $help  = '{{L//Use keywords to tag tickets to be able to find similar tickets quickly. Use spaces with or without commas to separate multiple keywords.}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_KEYWORDS);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Method override, since for keywords we don't just have a scalar value.
     *  Instead, we return an array of the keyword strings, or NULL if there
     *  are none.
     */
    public function getValue(TicketContext $oContext)
    {
        if ($oContext->mode == MODE_CREATE)
            return $this->getInitialValue($oContext);

        # MODE_READONLY_LIST or MODE_READONLY_CARDS or MODE_EDIT or MODE_READONLY_DETAILS:
        if (    (isset( $oContext->oTicket->aFieldData[$this->field_id]))
             && ( $oContext->oTicket->aFieldData[$this->field_id])
           )
            return explode(',', $oContext->oTicket->aFieldData[$this->field_id]);

        return NULL;
    }

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,          //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        $value = '';
        if ($v = $this->getValue($oPage))
            $value = implode(', ', $v);
        $oPage->oDlg->addInput('text',
                               $idControl,
                               '',
                               $value);
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        if (is_array($value))
            return implode(', ', $value);

        return NULL;
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     *
     *  This default implementation calls \ref formatValuePlain(), HTML-escapes the
     *  result and puts it into a new HTMLChunk.
     *
     *  If you need to override these methods to display human-readable strings for
     *  internal codes or enumeration values, you need to at least override
     *  formatValuePlain().
     *
     *  Whether you also need to override this method depends on whether you want
     *  to use fancy HTML like links in your values. If not, this default
     *  implementation looks at $fLinkifyValue, $fShowNoData and $fHighlightSearchTerms
     *  in $this. So it may be enough to override formatValuePlain() and set those
     *  variables to FALSE in your FieldHandler subclass.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        $o = parent::formatValueHTML($oContext, $value);

        if ($oContext->mode == MODE_READONLY_GRID)
            if ($o->html)
                $o->prepend(Icon::Get('tags').Format::NBSP);

        return $o;
    }


    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     *
     *  Keywords have a special format in value_str.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        $str = $oRow->value_str;
        $aAdded = $aRemoved = [];
        foreach (explode(',', $str) as $change)
        {
            $aMatches = [];
            if (preg_match('/([-+])(\d+)/', $change, $aMatches))
            {
                if ($aMatches[1] == '+')
                    $aAdded[] = $aMatches[2];
                else
                    $aRemoved[] = $aMatches[2];
            }
        }

        $str = L('{{L//Keywords}}').': ';

        if ($aAdded)
        {
            $str2 = NULL;
            foreach ($aAdded as $id)
                if ($oKeyword = Keyword::Get($id))
                    $str2 .= (($str2) ? ', ' : '').$oKeyword->formatHTML()->html;
            $str .= L('{{L//added %KEY%}}', array( '%KEY%' => $str2 ));
        }
        if ($aRemoved)
        {
            $str2 = NULL;
            foreach ($aRemoved as $id)
                if ($oKeyword = Keyword::Get($id))
                    $str2 .= (($str2) ? ', ' : '').$oKeyword->formatHTML()->html;

            if ($str)
                $str .= "; ";
            $str .= L('{{L//removed %KEY%}}', array( '%KEY%' => $str2 ));
        }

        return $str;
    }

    /**
     *  This gets called from \ref onCreateOrUpdate() for each ticket
     *  field whose data needs to be written to (or updated in) the database.
     *  Note: In MODE_CREATE, $oContext->oTicket contains the template from
     *  which the new ticket was created, whereas $oTicket has the newly created
     *  ticket. In MODE_EDIT, both are set to the ticket being changed.
     *  In MODE_EDIT, this only gets called if the new value is different from
     *  the old.
     *  This also generates changelog entries, unless $fWriteChangelog is FALSE. See
     *  \ref Changelog::AddTicketChange() for details about the format.
     *  See \ref data_serialization for an overview of the different
     *  data representations.
     *
     *  We override this for FIELD_KEYWORDS. As input in $new, we get a space-separated
     *  string list of keywords. This needs to be converted into multiple integer
     *  keyword IDs to be inserted into one row each in ticket_ints. This translation
     *  is done here.
     *
     * @return void
     */
    public function writeToDatabase(TicketContext $oContext,           //!< in: TicketContext instance
                                    Ticket $oTicket,            //!< in: new or existing ticket instance
                                    $oldValue,           //!< in: existing value (NULL if not present or in MODE_CREATE)
                                    $newValue,           //!< in: new value to be written out
                                    $fWriteChangelog)    //!< in: TRUE for MODE_EDIT (except in special cases), FALSE for MODE_CREATE
    {
        $aOld = KeywordsHandler::StringToKeywords($oldValue);
        $aNew = KeywordsHandler::StringToKeywords($newValue);

        $oField = $this->oField;
        $field_id = $oField->id;
        $tblname = $oField->tblname;

        $aRowIDs = [];
        $fields = ($aOld) ? $oTicket->aFieldDataRowIDs[$field_id] : NULL;
        $llRowIDs = ($fields) ? explode(',', $fields) : NULL;

        $aToRemove = $aToAdd = [];
        foreach ($aOld as $idKeyword => $oKeyword)
        {
            if (!(isset($aNew[$idKeyword])))
                # in old but not in new:
                $aToRemove[$idKeyword] = $oKeyword;

            # And while we're at it, build an index of row IDs in ticket_ints for this.
            $aRowIDs[$idKeyword] = array_shift($llRowIDs);
        }

        foreach ($aNew as $idKeyword => $oKeyword)
            if (!(isset($aOld[$idKeyword])))
                # in new but not in old:
                $aToAdd[$idKeyword] = $oKeyword;

        $strChangelog = '';

        # Handle newly added keywords.
        foreach ($aToAdd as $idKeyword => $oKeyword)
        {
            Database::DefaultExec(<<<EOD
INSERT INTO $tblname
    ( ticket_id,     field_id,   value) VALUES
    ( $1,            $2,         $3)
EOD
  , [ $oTicket->id,  $field_id,  $idKeyword ] );

            # Return the new row ID so parent can insert it into changelog.
            $newRowId = Database::GetDefault()->getLastInsertID($tblname, 'i'); // TODO this is unused!

            if ($strChangelog)
                $strChangelog .= ',';
            $strChangelog .= '+'.$idKeyword;
        }

        # Handle deleted added keywords.
        if ($aToRemove)
        {
            # To remove a keyword from a ticket, we need to remove the ticket_id / value (== idKeyword)
            # combo from ticket_ints. We have all the row IDs for that in $oTicket->aFieldDataRowIDs.

            foreach ($aToRemove as $idKeyword => $oKeyword)
            {
                Database::DefaultExec(<<<EOD
DELETE FROM $tblname WHERE i = $1
EOD
                           , [ $aRowIDs[$idKeyword] ] );

                if ($strChangelog)
                    $strChangelog .= ',';
                $strChangelog .= '-'.$idKeyword;
            }
        }

        if ($fWriteChangelog)
            if ($strChangelog)
                $oTicket->addChangelogEntry($field_id,
                                            $oContext->lastmod_uid,
                                            $oContext->dtNow,
                                            NULL,
                                            NULL,
                                            $strChangelog);

        $oTicket->aFieldData[$field_id] = $newValue;     # string
    }


    /********************************************************************
     *
     *  Newly introduced public static methods
     *
     ********************************************************************/

    /**
     *  Helper to splice up the given space/comma-separated string and return
     *  a matching list of Keyword objects, which are created as necessary.
     */
    public static function StringToKeywords($str)         //!< in: suggest new value from form field, out: value to be written to DB
    {
        $aKeywordIDs = [];

        if ($str)
        {
            $aKeywordStrings = preg_split("/[\s,]+/", $str);
            foreach ($aKeywordStrings as $strKeyword)
            {
                # Find the keyword if it has been used before, or create a new one in the database.
                $oKeyword = Keyword::CreateOrGet($strKeyword);
                $aKeywordIDs[$oKeyword->id] = $oKeyword;
            }
        }

        # Replace the input strings with the array of keyword IDs.
        return $aKeywordIDs;
    }
}
