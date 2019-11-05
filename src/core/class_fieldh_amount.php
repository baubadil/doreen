<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  MonetaryAmountHandlerBase
 *
 ********************************************************************/

/**
 *  Base class for field handlers that deal with monetary amounts.
 *
 *  This can operate as a simple entry field, which will be modified to only accept monetary
 *  amounts, or even sub-amounts that sum up to a total amount. This is automatic: if
 *  categories exist for the field ID given to the constructor, then sub-amounts will be
 *  added with those category names, otherwise not. Sub-amounts will then be written into
 *  the \ref subamounts table. FIELDFL_ARRAY must NOT be set because there's still only
 *  a single row in ticket_amounts.
 */
class MonetaryAmountHandlerBase extends FieldHandler
{
    public $label = '{{L//Total amount paid or received}}';

    protected $aSubAmountCategories = NULL;

    # Flags for inherited flSelf.
    const FL_CANNOT_BE_NEGATIVE = (1 << 10);
    const FL_ALIGN_LEFT         = (1 << 11);
    const FL_BIGGERMARGIN_TOP   = (1 << 12);        # Make a little space above this row.
    const FL_LINE_ABOVE_TOTAL   = (1 << 13);        # Draw a "subtotal" line above this row's amount.


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($field_id,
                                $flSelf)            //<! in: 0 or FL_CANNOT_BE_NEGATIVE
    {
        parent::__construct($field_id);

        if ($this->aSubAmountCategories = Category::GetAllForField($field_id))
            if ($this->oField->fl & FIELDFL_ARRAY)
                throw new DrnException("Internal error in MonetaryAmountHandlerBase: field $field_id must not have FIELDFL_ARRAY set");

        $this->flSelf = $flSelf | self::FL_GRID_PREFIX_FIELD;
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Called for ticket details in MODE_READONLY_DETAILS to add a Bootstrap row div
     *  to the page's HTML to display the ticket data.
     *
     *  The FieldHandler parent cannot print subcategories, so add that functionality
     *  here.
     *
     * @return void
     */
    public function appendReadOnlyRow(TicketPageBase $oPage)               //!< in: TicketPageBase instance with ticket dialog information
    {
        $oLabel = $this->getLabel($oPage);

        // Have at least one row with the label and the total amount.
        $oPage->oHTMLPage->openGridRow(NULL,
                                       ($this->flSelf & self::FL_BIGGERMARGIN_TOP) ? 'drn-biggermargin-top' : NULL);
        $oPage->oHTMLPage->addGridColumn(self::C_LABEL_COLUMNS, "<b>$oLabel->html</b>");

        $cSubAmounts = 0;

        if (    ($this->aSubAmountCategories)
             && ($aSubAmounts = $this->getSubamounts($oPage->oTicket))
           )
        {
            $cSubAmounts = count($aSubAmounts);
            foreach ($aSubAmounts as $cat => $subAmount)
            {
                $catname = '';
                if ($oCat = Category::FindByID($cat))
                    $catname = "<div class='text-center'>".toHTML($oCat->name).'</div>';
                $oPage->oHTMLPage->addGridColumn(self::C_MONETARY_SUBLABEL_COLUMNS, $catname);

                // If there's more than one subamount, show a subtotal.
                if ($cSubAmounts > 1)
                {
                    $oPage->oHTMLPage->addGridColumn(self::C_MONETARY_AMOUNT_COLUMNS,
                                                     Format::MonetaryAmountAligned($subAmount), NULL);

                    // Now the row is full. Close it, open another row with an empty column under the label.
                    $oPage->oHTMLPage->close(); // grid row
                    $oPage->oHTMLPage->openGridRow();
                    $oPage->oHTMLPage->addGridColumn(self::C_LABEL_COLUMNS, '');
                }
            }
        }

        $fl0 = ($this->flSelf & self::FL_LINE_ABOVE_TOTAL) ? Format::FL_LINE_ABOVE_TOTAL : 0;
        if ($this->flSelf & self::FL_ALIGN_LEFT)
            $fl0 |= Format::FL_ALIGN_LEFT;

        // Now the final amount.
        $amount = (float)$this->getValue($oPage);
        if ($cSubAmounts > 0)
        {
            $oPage->oHTMLPage->addGridColumn(self::C_MONETARY_SUBLABEL_COLUMNS,
                                             '');       // sub-label
            $arrow = '';
            if ($cSubAmounts > 1)
            {
                $fl = Format::FL_LINE_ABOVE_TOTAL;
                if ($this->flSelf & self::FL_ALIGN_LEFT)
                    $fl |= Format::FL_ALIGN_LEFT;
                $oPage->oHTMLPage->addGridColumn(self::C_MONETARY_AMOUNT_COLUMNS,
                                                 Format::MonetaryAmountAligned($amount, $fl));
                $arrow = Icon::Get('arrow-right').Format::NBSP;
            }

            $amount2 = ($this->makeSubtotalNegative($oPage)) ? -$amount : $amount;
            $oPage->oHTMLPage->addGridColumn(self::C_MONETARY_AMOUNT_COLUMNS,
                                             $arrow.Format::MonetaryAmountAligned($amount2, $fl0));
        }
        else if (!($this->flSelf & self::FL_ALIGN_LEFT))
        {
            $oPage->oHTMLPage->addGridColumn(self::C_MONETARY_SUBLABEL_COLUMNS + self::C_MONETARY_AMOUNT_COLUMNS, '');
            $oPage->oHTMLPage->addGridColumn(self::C_MONETARY_AMOUNT_COLUMNS,
                                             Format::MonetaryAmountAligned($amount, $fl0));
        }
        else
            // Left-align single column:
            $oPage->oHTMLPage->addGridColumn(12 - self::C_LABEL_COLUMNS,
                                             Format::MonetaryAmount($amount, 2));

        $oPage->oHTMLPage->close(); // grid row
    }

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  We override this to add several entry fields if this amount has sub-amounts
     *  defined by the categories of its field ID. Otherwise we call the parent.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,   //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)              //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        if ($this->aSubAmountCategories)
        {
            $aSubAmounts = NULL;
            if ($oPage->mode == MODE_EDIT)
                $aSubAmounts = $this->getSubamounts($oPage->oTicket);      // can still be NULL

            foreach ($this->aSubAmountCategories as $id => $oSubAmountCategory)
            {
                $oPage->oDlg->openGridRow();
                $oPage->oDlg->openGridColumn(5, NULL, 'text-right');
                $oPage->oDlg->addLabel(HTMLChunk::FromString($oSubAmountCategory->name));
                $oPage->oDlg->close(); // grid column
                $oPage->oDlg->openGridColumn(7);
                $sub = ($aSubAmounts) ? getArrayItem($aSubAmounts, $id) : NULL;
                $oPage->oDlg->addInput('text',
                                       $idControl."-$id",
                                       '',
                                       $sub, // works as NULL for MODE_CREATE
                                       HTMLChunk::INPUT_PULLRIGHT,
                                       'euro');

                $oPage->oDlg->close(); // grid column
                $oPage->oDlg->close(); // grid ros
            }

            $oPage->oDlg->addInput('text',
                                   $idControl.'-sum',
                                   '',
                                   $this->getValue($oPage),
                                   HTMLChunk::INPUT_DISABLED | HTMLChunk::INPUT_PULLRIGHT,
                                   'euro');

            WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initSubAmountHandler',
                                                 [ $idControl,
                                                   array_keys($this->aSubAmountCategories)
                                                 ]);
        }
        else
            // The parent will add the euro icon because the table is ticket_amounts.
            parent::addDialogField($oPage, $idControl);
    }

    /**
     *  Second function that gets called after appendFormRow() in MODE_CREATE or MODE_EDIT
     *  modes to add the field name to list of fields to be submitted as JSON in the body
     *  of a ticket POST or PUT request (for create or update, respectively).
     *
     *  If we have sub-amounts, then we need to add the dlg IDs of the sub-amount entry fields
     *  to the array instead of the one total, which shouldn't be submitted at all. Otherwise
     *  we call the parent.
     *
     * @return void
     */
    public function addFieldToJSDialog(TicketPageBase $oPage)
    {
        if ($this->aSubAmountCategories)
        {
            foreach ($this->aSubAmountCategories as $id => $oSubAmountCategory)
            {
                $key = $this->fieldname."-$id";
                $oPage->aFieldsForDialog[] = $key;
            }
        }
        else
            parent::addFieldToJSDialog($oPage);
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *
     *  This only works for the main amount, not sub-amounts.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        $v = NULL;
        if ($value !== NULL)
            $v = Format::MonetaryAmount($value, 2);

        return $v;
    }

    /**
     *  This gets called from Ticket::createAnother() and Ticket::update() for every field that might
     *  have field data to update. This method then peeks into the update data (in $oContext->aVariableData),
     *  either from the PUT request or from whoever called Ticket::update() directly. If the data has changed,
     *  it then calls FieldHandler::writeToDatabase().
     *  Must return TRUE if the field was changed, or FALSE otherwise.
     *
     *  We override the parent because we need to poke around the input data array for sub-amounts if there
     *  are any.
     *
     *  On input, we get something like [ fieldname-cat1 => 123.12, fieldname-cat2 => 123.13 ] without a sum.
     *  We must compute the sum, write that into ticket_amounts, and write the sub-amounts into the subamounts
     *  table.
     */
    public function onCreateOrUpdate(TicketContext $oContext,
                                     Ticket $oTicket,            //!< in: new or existing ticket instance
                                     int $fl = 0)                //!< in: combination of Ticket::CREATEFL_NOCHANGELOG and Ticket::CREATEFL_IGNOREMISSING
        : bool
    {
        if ($this->aSubAmountCategories)
        {
            // Build old array (sum and sub-amounts).
            $aOld = NULL;                       // This is either NULL for no value, or an array with value and sub-amounts.
            if ($oContext->mode == MODE_EDIT)
            {
                if ($oldValue = getArrayItem($oTicket->aFieldData, $this->field_id))
                {
                    $aSubamounts = $this->getSubamounts($oTicket);
                    $aSubamounts[0] = $oldValue;
                    $aOld = $aSubamounts;
                }
            }

            // Build new array (sum and sub-amounts).
            $aNew = [];
            $newTotal = 0;
            foreach ($this->aSubAmountCategories as $id => $oSubAmountCategory)
            {
                $key = $this->fieldname."-$id";
                $subAmount = getArrayItem($oContext->aVariableData, $key);
                $aNew[$id] = $subAmount;
                if ($subAmount)
                    $newTotal += $subAmount;
            }

            if ($newTotal)
                $aNew[0] = $newTotal;
            else
                $aNew = NULL;

            // Compare.
            if (    !$aOld
                 || (!arrayEqualValues($aOld, $aNew))
               )
            {
                // Something changed:
                $this->writeToDatabase($oContext,
                                       $oTicket,
                                       $aOld,
                                       $aNew,
                                       ($fl & Ticket::CREATEFL_NOCHANGELOG) == 0);
                return TRUE;
            }

            return FALSE;
        }

        // No sub-amounts:
        return parent::onCreateOrUpdate($oContext,
                                        $oTicket,
                                        $fl);
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
     *  For non-array cases (simple values without sub-amounts), we call the
     *  parent.
     *
     *  With sub-amounts, both $oldValue and $newValue must be PHP arrays
     *  with the 0-key having the total amounts and other keys the category ID
     *  => subamount pairs.
     *
     * @return void
     */
    public function writeToDatabase(TicketContext $oContext,    //!< in: TicketContext instance
                                    Ticket $oTicket,            //!< in: new or existing ticket instance
                                    $oldValue,                  //!< in: existing value (NULL if not present or in MODE_CREATE)
                                    $newValue,                  //!< in: new value to be written out
                                    $fWriteChangelog)           //!< in: TRUE for MODE_EDIT (except in special cases), FALSE for MODE_CREATE
    {
        if ($this->aSubAmountCategories)
        {
            $oldSum = $newSum = NULL;
            if (    ($oldValue !== NULL)
                 && (is_array($oldValue))
               )
                $oldSum = getArrayItem($oldValue, 0);
            if (    ($newValue !== NULL)
                 && (is_array($newValue))
               )
                $newSum = getArrayItem($newValue, 0);

            // Write out a row into ticket_amounts as the parent for the sub-amounts.
            $newRowId = $this->insertRowWithChangelogAndMail($oContext,
                                                             $oTicket,
                                                             $oldSum,
                                                             $newSum,
                                                             $fWriteChangelog);

            // Use the newRowID as the amount_id for the sub-amounts.
            if (is_array($newValue))
            {
                $aValues = [];
                foreach ($this->aSubAmountCategories as $id => $oSubAmountCategory)
                {
                    if ($v = getArrayItem($newValue, $id))
                    {
                        $aValues[] = $id;
                        $aValues[] = $newRowId;     // amount_id
                        $aValues[] = $v;
                    }
                }
                if ($aValues)
                    Database::GetDefault()->insertMany('subamounts',
                                                       [ 'cat', 'amount_id', 'value' ],
                                                       $aValues);
            }
        }
        else
            parent::writeToDatabase($oContext,
                                    $oTicket,
                                    $oldValue,
                                    $newValue,
                                    $fWriteChangelog);
    }

    /**
     *  This gets called by \ref writeToDatabase() for every field before the
     *  new value gets written into the database. This must return a value that
     *  fits into the value column of the field's database table, or, in the
     *  case of FIELDFL_ARRAY, an arra of those values. It can also throw a
     *  APIException with the field name if validation fails.
     *
     *  We ensure the value is a valid monetary amount here.
     *
     * @return mixed
     */
    public function validateBeforeWrite(TicketContext $oContext,    //!< in: TicketContext instance
                                        $oldValue,                  //!< in: current value (or NULL during create ticket)
                                        $newValue)                  //!< in: suggest new value from form field
    {
        // This is for derived classes as well.
        if (!$newValue)
            return NULL;

        $a = DrnLocale::ValidateMonetaryAmount($newValue, $this->oField->name);
        $flt = $a['number'];    // float

        if (!Globals::$fImportingTickets)
            if (    ($this->flSelf & self::FL_CANNOT_BE_NEGATIVE)
                 && ($flt < 0)
               )
                if ($newValue < 0)
                    throw new APIException($this->fieldname, Format::UTF8Quote(L($this->label))." cannot be negative");

        return $flt;
    }


    /********************************************************************
     *
     *  Newly introduced instance methods
     *
     ********************************************************************/

    /**
     *  Returns the sub-amounts for the ticket_amounts row with the field ID from $this
     *  in the given ticket, or NULL if there are none.
     */
    public function getSubamounts(Ticket $oTicket)
    {
        $aSubAmounts = [];
        // This can be NULL if the ticket has a NULL amount in the first place.
        if ($rowidAmount = getArrayItem($oTicket->aFieldDataRowIDs, $this->field_id))
        {
            if ($res = Database::DefaultExec(<<<SQL
SELECT cat, value
FROM subamounts WHERE amount_id = $1
SQL
                              , [ $rowidAmount ]))
            {
                while ($row = Database::GetDefault()->fetchNextRow($res))
                {
                    $cat = $row['cat'];
                    $value = $row['value'];
                    $aSubAmounts[$cat] = $value;
                }
            }
        }

        return count($aSubAmounts) ? $aSubAmounts : NULL;
    }

    /**
     *  Newly introduced instance method that indicates if the value should
     *  be prefixed with a minus sign in the rightmost column of the sub-amounts
     *  total.
     *
     *  To be overridden by subclasses which can return TRUE if appropriate.
     */
    public function makeSubtotalNegative(TicketContext $oContext)
    {
        return FALSE;
    }

}
