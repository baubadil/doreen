<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;

/********************************************************************
 *
 *  AcctContractorHandler
 *
 ********************************************************************/

/**
 *  Field handler for FIELD_OFFICE_CONTACTS ('ofc_contacts').
 *
 *  This uses the \ref ticket_vcards table to link N contacts (VCard tickets) to the
 *  owning ticket.
 *
 *  Spec:
 *
 *   -- In Ticket instance: OfcContactsList instance, containing an array
 *      of OfcContact instances.
 *
 *   -- GET/POST/PUT JSON data: JSON array of OfcContact serialization.
 *
 *   -- Database: rows in ofc_contacts.
 *
 *   -- Search engine: TODO.
 */
class OfcContactsHandler extends FieldHandler
{
    public $label = '{{L//Associated contacts}}';
    public $help  = '{{L//This has all the contacts associated with this file.}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_OFFICE_CONTACTS);
    }


    /********************************************************************
     *
     *  Field data serialization
     *
     ********************************************************************/

    /**
     *  Gets called by Ticket::PopulateMany() for all fields that have the FIELDFL_CUSTOM_SERIALIZATION
     *  flag set. This must then produce the SQL needed to retrieve stage-2 data from the database for
     *  multiple tickets.
     *
     *  We must override the empty default implementation because our field does have
     *  FIELDFL_CUSTOM_SERIALIZATION enabled and needs a complicated query.
     *
     * @return string
     */
    public function makeFetchSql(&$columnNames,
                                 &$aGroupBy)
    {
        $tblname = $this->oField->tblname;

        $columnNames .= ",\n    -- FIELD_OFFICE_CONTACTS";

        $sub = $this->fieldname."_sub";
        $fields = implode(', ', [ 'i', 'vcard_id', 'contact_type', 'fileno', 'parent_id' ]);
        $columnNames .= "\n    (SELECT JSON_AGG($sub.*) FROM (SELECT $fields FROM $tblname WHERE ticket_id = tickets.i) AS $sub) AS $this->fieldname";

        return NULL;
    }

    /**
     *  The companion to \ref makeFetchSql(). This gets called for every row arriving from the database
     *  and must fill the instance data of the given Ticket instance with the data decoded from the given
     *  database row.
     */
    public function decodeDatabaseRow(Ticket $oTicket,
                                      array $dbrow)
    {
        if ($json = $dbrow[$this->fieldname] ?? NULL)
        {
            Debug::LogR(0, "contact json: ", $json);

            $llContacts = json_decode($json, TRUE);

            $oContactsList = NULL;

            $aIds = [];

            foreach ($llContacts as $aContactData)
            {
                $rowid = $aContactData['i'];

                $oContact = new OfcContact($rowid,
                                           $oTicket,
                                           $aContactData['vcard_id'],
                                           $aContactData['contact_type'],
                                           $aContactData['fileno'],
                                           $aContactData['parent_id']);
                if (!$oContactsList)
                    $oContactsList = new OfcContactsList();

                $oContactsList->addContact($oContact);

                $aIds[] = $rowid;
            }

            Debug::LogR(0, "contact json: ", $oContactsList);

            $oTicket->aFieldData[$this->field_id] = $oContactsList;
            $oTicket->aFieldDataRowIDs[$this->field_id] = implode(',', $aIds);
        }
    }

    /**
     *  This gets called by \ref onCreateOrUpdate() to determine whether the new value
     *  for the field is different from the old. Subclasses may override this if they have
     *  a special data format.
     *
     *  $oldValue will be NULL with MODE_CREATE. It can be NULL with MODE_EDIT if there was
     *  no value previously.
     *
     *  This only gets called if newValue was not NULL in the first place, so it will never be
     *  NULL (but could be an empty string).
     */
    public function isNewValueDifferent(TicketContext $oContext,
                                        $oldValue,          //!< in: old value (can be NULL)
                                        $newValue)          //!< in: new value (never NULL)
        : bool
    {
        return parent::isNewValueDifferent($oContext, $oldValue, $newValue);
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
     *  We need to override this because the ticket field data is an instance
     *  of OfcContactsList which needs to be serialized specially to the database.
     */
    public function writeToDatabase(TicketContext $oContext,    //!< in: TicketContext instance
                                    Ticket $oTicket,            //!< in: new or existing ticket instance
                                    $oldValue,                  //!< in: existing value (NULL if not present or in MODE_CREATE)
                                    $newValue,                  //!< in: new value to be written out
                                    $fWriteChangelog)
    {
        Debug::LogR(0, "newValue: ", $newValue);

        if ($newValue)
            if (!($newValue instanceof OfcContactsList))
                throw new DrnException("error handling data for FIELD_OFFICE_CONTACTS: expected OfcContactsList instance");

        if ($newValue)
        {
            /** @var OfcContactsList $newValue */
            $newValue->toDatabase($oTicket,
                                  ($oContext->mode != MODE_CREATE));
        }
    }

    public function serializeToArray(TicketContext $oContext, &$aReturn, $fl, &$paFetchSubtickets)
    {
//        parent::serializeToArray($oContext, $aJSON, $fl, $paFetchSubtickets); // TODO: Change the autogenerated stub
    }


    /********************************************************************
     *
     *  Field data formatting
     *
     ********************************************************************/

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
        if ($value = $this->getValue($oPage))
        {
            if (!($value instanceof OfcContactsList))
                throw new DrnException("expected OfcContactsList as field value");

            /** @var OfcContactsList $oContactsList */
            $oContactsList = $value;

            $aVCardHtmls = [];

            foreach ($oContactsList->aContacts as $oContact)
                $aVCardHtmls[] = $oContact->formatHtml(LoginSession::GetCurrentUserOrGuest(),
                                                       FALSE);

            if ($c = count($aVCardHtmls))
            {
                if ($c == 1)
                    $oHtml = $aVCardHtmls[0];
                else
                {
                    $oHtml = new HTMLChunk();
                    $oHtml->openList(HTMLChunk::LIST_OL);
                    foreach ($aVCardHtmls as $oChunk)
                        $oHtml->addListItem(NULL, NULL, $oChunk);
                    $oHtml->close();    // LIST_OL
                }

                $oPage->oDlg->appendChunk($oHtml);
            }
        }
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     */
    public function formatValuePlain(TicketContext $oContext,
                                     $value)
    {
        $o = $this->formatValueHTML($oContext, $value);
        return Format::HtmlStrip($o->html, TRUE);
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     *
     *  @return HTMLChunk
     */
    public function formatValueHTML(TicketContext $oContext,
                                    $value)
        : HTMLChunk
    {
        $oHtml = new HTMLChunk();

        if ($value)
        {
            if (!($value instanceof OfcContactsList))
                throw new DrnException("expected OfcContactsList as field value");

            /** @var OfcContactsList $oContactsList */
            $oContactsList = $value;

            $aVCardHtmls = [];

            $fDetails = ($oContext->mode == MODE_READONLY_DETAILS);

            foreach ($oContactsList->aContacts as $oContact)
                $aVCardHtmls[] = $oContact->formatHtml(LoginSession::GetCurrentUserOrGuest(),
                                                       $fDetails);

            if ($c = count($aVCardHtmls))
            {
                if ($c == 1)
                    $oHtml = $aVCardHtmls[0];
                else
                {
                    $oHtml->openList(HTMLChunk::LIST_OL);
                    foreach ($aVCardHtmls as $oChunk)
                        $oHtml->addListItem(NULL, NULL, $oChunk);
                    $oHtml->close();    // LIST_OL
                }
            }
        }

        return $oHtml;
    }


}
