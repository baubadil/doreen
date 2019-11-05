<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  SelectFromSetHandlerBase class
 *
 ********************************************************************/

/**
 *  "Mezzanine", abstract class in between FieldHandler and the implementation of many field
 *  handlers which use a SELECT/OPTION form to pick a value from a finite set of int => string pairs.
 *
 *  A lot of non-abstract field handler classes derive from this:
 *
 *   -- AssigneeHandler for FIELD_UIDASSIGN (displays a list of users)
 *
 *   -- CategoryHandlerBase, for ticket categories
 *
 *   -- PriorityHandler, for simple integer priorities
 *
 *   -- StatusHandler, for integer status values, in coordination with WorkflowHandler
 *
 *   -- TicketPickerHandlerBase, to select a single ticket (e.g. for parents)
 *
 *  This adds a single new abstract method, getValidValues(), which subclassees must implement.
 *  The rest gets handled automatically.
 */
abstract class SelectFromSetHandlerBase extends FieldHandler
{
    // This can be overwritten by subclasses to change the selection style.
    const MODE_ALWAYS_USE_DROP_DOWN = 1;            # Always use a <select>...<option> group.
    const MODE_ALWAYS_USE_RADIO     = 2;            # Always use a Doreen radio group.
    const MODE_USERADIO_IF_SMALL    = 3;            # Use a radio group if the no. of items is small.

    protected $eDisplayStyle = self::MODE_ALWAYS_USE_DROP_DOWN;

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
     *  SelectFromSetHandlerBase overrides this to add a SELECT/OPTION drop-down,
     *  which is filled with values from getValidValues(). That is an abstract
     *  method newly introduced by SelectFromSetHandlerBase and must be implemented
     *  by subclasses to actually return the values that can be picked from.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,       //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)                  //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        $current = NULL;
        if ($oPage->mode == MODE_CREATE)
            $select = $this->getInitialValue($oPage);         # default value for new tickets
        else
        {
            $current = $this->getValue($oPage);
//            Debug::Log(Debug::FL_STATUS, "current: $current");
            $select = $current;
        }

        $aValues = $this->getValidValues($oPage,
                                         $current);        # NULL for 'MODE_CREATE'

        if ($aValues)
        {
            if (    ($this->eDisplayStyle== self::MODE_ALWAYS_USE_RADIO)
                 || (    ($this->eDisplayStyle == self::MODE_USERADIO_IF_SMALL)
                      && (count($aValues) <= 3)
                    )
               )
            {
                $oPage->oDlg->openDiv($idControl, 'drn-radio-group');
                foreach ($aValues as $val => $str)
                    $oPage->oDlg->addRadio($this->formatRadioValue($val, $str), NULL, $idControl, $val, ($val == $select));
                $oPage->oDlg->close();    // div
            }
            else
                $oPage->oDlg->addSelect($idControl,
                                        $aValues,
                                        $select);
        }
    }

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     *
     *  We override this to return an HTML-formatted column for a changelog row
     *  which describes the actual change.
     *
     *  This is used by all subclasses except StatusHandler (FIELD_STATUS),
     *  which overrides this for special casing.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        return L('{{L//%FIELD% changed from %OLD% to %NEW%}}',
                 [ '%FIELD%' => $this->getLabel($oPage)->html,
                   '%OLD%' => Format::HtmlQuotes($this->formatValueHTML($oPage, $oRow->int_old)->html),
                   '%NEW%' => Format::HtmlQuotes($this->formatValueHTML($oPage, $oRow->int_new)->html) ]);
    }

    /**
     *  This gets called by \ref writeToDatabase() for every field before the
     *  new value gets written into the database. This must return a value that
     *  fits into the value column of the field's database table, or, in the
     *  case of FIELDFL_ARRAY, an arra of those values. It can also throw a
     *  APIException with the field name if validation fails.
     *
     *  Here we validate that the new value is part of the permitted set of values.
     *  We also throw if a NULL or otherwise empty value is given.
     *
     * @return mixed
     */
    public function validateBeforeWrite(TicketContext $oContext,    //!< in: TicketContext instance
                                        $oldValue,                  //!< in: current value (or NULL during create ticket)
                                        $newValue)                  //!< in: suggest new value from form field
    {
        if (    ($newValue === NULL)
             || ($newValue === '')
           )
            throw new APIMissingDataException($this->oField, L($this->label));

        if ($aPairs = $this->getValidValues($oContext, $oldValue))
            if (!(isset($aPairs[$newValue])))
                # Illegal value:
                if (!Globals::$fImportingTickets)
                    throw new APIException($this->fieldname,
                                           L('{{L//The value %VALUE% is invalid for field %FIELDNAME%}}',
                                             [ '%VALUE%' => Format::UTF8Quote($newValue),
                                               '%FIELDNAME%' => L($this->label)
                                             ]));

        return $newValue;
    }


    /********************************************************************
     *
     *  Newly introduced instance methods
     *
     ********************************************************************/

    /**
     *  Abstract method newly introduced by SelectFromSetHandlerBase to be overridden
     *  by subclasses to return an array of id => HTML string pairs representing the
     *  set of valid values that this field can have. The strings will be HTML-escaped.
     *  The returned array must include the current value!
     *
     *  Typically the returned set will be invariant, but for things like
     *  FIELD_STATUS, the returned array can vary according to the ticket's
     *  current field value, which is given in $currentValue.
     *
     *  This gets called from:
     *
     *   -- SelectFromSetHandlerBase::addDialogField(), in MODE_CREATE and MODE_EDIT, to fill the
     *      SELECT/OPTION field with the users from which the user can pick a value;
     *
     *   -- SelectFromSetHandlerBase::processFieldValue(), in MODE_CREATE and MODE_EDIT, which in
     *      turn gets called from \ref FieldHandler::writeToDatabase() when the database needs to
     *      be written to for a ticket.
     *
     *  This control should not allow NULL values. If you want to provide for an "undefined"
     *  value, define one with a non-null integer.
     */
    abstract public function getValidValues(TicketContext $oContext,    //!< in: TicketContext instance
                                            $currentValue);

    /**
     *  Method newly introduced by SelectFromSetHandlerBase, called by addDialogField() only if
     *  $this->eDisplayStyle calls for formatting radio buttons. In that case, this gets called
     *  for each value to be displayed to retrieve a formatted HTML string to be submitted to HTMLChunk::addRadio().
     *
     *  This default can be overridden if more sophisticated HTML is required.
     */
    protected function formatRadioValue($val, $str)
        : HTMLChunk
    {
        return HTMLChunk::FromString($str);
    }

}
