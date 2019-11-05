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
 *  Field handler for dates.
 */
class DateHandler extends FieldHandler
{

    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct(int $field_id,
                                string $lstrDescription)
    {
        parent::__construct($field_id);
        $this->label = $lstrDescription;
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *
     *  Since we might be getting a database timestamp string, make sure we strip the date.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        if ($value)
            return Format::DateFromDateTimeString($value);
    }

    /**
     *  This gets called by \ref writeToDatabase() for every field before the
     *  new value gets written into the database. This must return a value that
     *  fits into the value column of the field's database table, or, in the
     *  case of FIELDFL_ARRAY, an arra of those values. It can also throw a
     *  APIException with the field name if validation fails.
     *
     * @return mixed
     */
    public function validateBeforeWrite(TicketContext $oContext,    //!< in: TicketContext instance
                                        $oldValue,                  //!< in: current value (or NULL during create ticket)
                                        $newValue)                  //!< in: suggest new value from form field
    {
        if ($newValue)
        {
            // This throws if given a bad date.
            $oDate = Date::CreateFromString($newValue);
            $oContext->oTicket->onDateFieldChanged($this->field_id,
                                                   $oDate);
        }

        return parent::validateBeforeWrite($oContext, $oldValue, $newValue);
    }


}
