<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  TicketPickerHandlerBase
 *
 ********************************************************************/

/**
 *  Abstract class descending from the abstract SelectFromSetHandlerBase class.
 *  Instead of a regular select/option control provided by the parent, this
 *  calls \ref HTMLChunk::addTicketPicker() with a full-blow ticket selector
 *  using the GET /api/tickets REST API for live searching.
 *
 *  This does implement the abstract \ref getValidValues() method prescribed
 *  by the parent, but introduces another new abstract method,
 *  \ref getTypesFilter(), which subclasses must implement. But that is simple.
 */
abstract class TicketPickerHandlerBase extends SelectFromSetHandlerBase
{
    protected $fUseTicketPicker = TRUE;

    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($field_id)
    {
        parent::__construct($field_id);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  We override the FieldHandler parent to add a ticket picker control
     *  instead of the parent's plain-text entry field. The ticket picker
     *  will only display tickets of the types returned by the newly introduced
     *  abstract \ref getTypesFilter() method.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,        //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)                   //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        if ($this->fUseTicketPicker)
        {
            $llTickets = NULL;
            if ($idTicket = $this->getValue($oPage))
                $llTickets = [ Ticket::FindForUser((int)$idTicket,
                                                   LoginSession::$ouserCurrent,
                                                   ACCESS_READ)
                             ];
            $oPage->oDlg->addTicketPicker($idControl,
                                          $llTickets,
                                          [ FIELD_TYPE => $this->getTypesFilter() ],
                                          FALSE);
        }
        else
            parent::addDialogField($oPage, $idControl);
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        if ($value)
        {
            if (!($oTicket = Ticket::FindOne($value)))
                return "Invalid ticket #$value";

            return $oTicket->getTitle();
        }

        return NULL;
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        $o = new HTMLChunk();

        if ($value)
        {
            if (is_array($value))
                $o->html = "Error: value is an array (".print_r($value, TRUE).")";
            else if (!($oTicket = Ticket::FindOne($value)))
                $o->html = "Invalid ticket #$value";
            else
                if (    ($oContext->mode == MODE_READONLY_DETAILS)
                     || ($oContext->mode == MODE_READONLY_LIST)
                     || ($oContext->mode == MODE_READONLY_GRID)
                   )
                $o->html = $oTicket->makeLink(TRUE);
            else
                $o->html = toHTML($oTicket->getTitle());
        }

        return $o;
    }

    /**
     *  Function that is used when building the filters list for ticket search results. This
     *  must return an array of value -> HTMLChunk pairs for each item in the list.
     *
     *  Since our values are tickets, we preload them so we don't have a thousand SQL round-drips.
     */
    public function formatManyHTML(TicketContext $oContext,
                                   $llValues)
    {
        Ticket::FindManyByID($llValues, Ticket::POPULATE_LIST);
        return parent::formatManyHTML($oContext, $llValues);
    }

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  We override this to be able to get readable ticket links from the
     *  changelog row IDs pointing into ticket_parents.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        $old = $new = L("{{L//unknown}}");

        $llRowIDs = [];
        if ($rowOld = $oRow->value_1)
            $llRowIDs[] = $rowOld;
        if ($rowNew = $oRow->value_2)
            $llRowIDs[] = $rowNew;

        if (count($llRowIDs))
        {
            $idTicketOld = $idTicketNew = NULL;

            $rows = Database::MakeInIntList($llRowIDs);
            if ($res = Database::DefaultExec(<<<SQL
SELECT i, value FROM ticket_parents WHERE i IN ($rows)
SQL
                ))
            {
                while ($row = Database::GetDefault()->fetchNextRow($res))
                {
                    $i = $row['i'];
                    if ($i == $rowOld)
                        $idTicketOld = $row['value'];
                    else
                        $idTicketNew = $row['value'];
                }

                if ($fr = Ticket::FindManyByID( [ $idTicketOld, $idTicketNew ], Ticket::POPULATE_LIST))
                {
                    /** @var Ticket $o */
                    if ($o = getArrayItem($fr->aTickets, $idTicketOld))
                        $old = $o->makeLink(FALSE);
                    if ($o = getArrayItem($fr->aTickets, $idTicketNew))
                        $new = $o->makeLink(FALSE);
                }
            }
        }

        return L('{{L//%FIELD% changed from %OLD% to %NEW%}}',
                 [ '%FIELD%' => $this->getLabel($oPage)->html,
                   '%OLD%' => Format::HtmlQuotes($old),
                   '%NEW%' => Format::HtmlQuotes($new) ]);
    }

    /**
     *  This gets called by \ref onCreateOrUpdate() to determine whether the new value
     *  for the field is different from the old. Subclasses may override this if they have
     *  a special data format.
     *
     *  On input, the ticket ID comes in with a "#" prefix, but the value in memory doesn't
     *  have that, so we need to strip the prefix before comparing.
     */
    public function isNewValueDifferent(TicketContext $oContext,
                                        $oldValue,          //!< in: old value (can be NULL)
                                        $newValue)          //!< in: new value (never NULL)
        : bool
    {
        // Strip the "#" from the ticket ID before comparing.
        if ($newValue)
            $newValue = $this->validateBeforeWrite($oContext, $oldValue, $newValue);

        return parent::isNewValueDifferent($oContext, $oldValue, $newValue);
    }

    /**
     *  This gets called by \ref writeToDatabase() for every field before the
     *  new value gets written into the database. This must return a value that
     *  fits into the value column of the field's database table, or, in the
     *  case of FIELDFL_ARRAY, an arra of those values. It can also throw a
     *  APIException with the field name if validation fails.
     *
     *  Here we require that ticket references must start with # and be followed
     *  by a numerical ID.
     *
     * @return mixed
     */
    public function validateBeforeWrite(TicketContext $oContext,    //!< in: TicketContext instance
                                        $oldValue,                  //!< in: current value (or NULL during create ticket)
                                        $newValue)                  //!< in: suggest new value from form field
    {
        if (!$newValue)
            throw new APIMissingDataException($this->oField, L($this->label));

        if (!(preg_match('/^#(-?\d+)$/', $newValue, $aMatches)))
            throw new APIException($this->fieldname, L('{{L//Invalid data format in value %VAL% for %FIELDNAME%}}',
                                                       [ '%VAL%' => Format::UTF8Quote($newValue),
                                                         '%FIELDNAME%' => Format::UTF8Quote(L($this->label)) ]));
        return (int)$aMatches[1];
    }

    /**
     *  Abstract method newly introduced by SelectFromSetHandlerBase to be overridden
     *  by subclasses to return an array of id => HTML string pairs representing the
     *  set of valid values that this field can have. The strings will be HTML-escaped.
     *  The returned array must include the current value!
     *
     *  The permitted values are all tickets of the types returned by the newly introduced
     *  abstract \ref getTypesFilter() method. TODO this will be inefficient for many tickets
     */
    public function getValidValues(TicketContext $oContext,    //!< in: TicketContext instance
                                   $currentValue)
    {
        $aValues = [];
        if ($fr = Ticket::FindMany( [ SearchFilter::FromTicketTypes( $this->getTypesFilter() ),
                                      SearchFilter::NonTemplates() ],
                                   'title' ))
            foreach ($fr->aTickets as $id => $oTicket)
                $aValues[$id] = $oTicket->getTitle();

        return $aValues;
    }


    /********************************************************************
     *
     *  Newly introduced instance methods
     *
     ********************************************************************/

    /**
     *  Abstract method newly introduced by TicketPickerHandlerBase to be overridden by subclasses
     *  to supply the ticket types that the ticket picker should be limited to. This must return
     *  a flat PHP list of integer ticket type IDs.
     *
     * @return int[]
     */
    abstract protected function getTypesFilter();
}
