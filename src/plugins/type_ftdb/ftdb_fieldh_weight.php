<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FTArticleVariantUUIDHandler class
 *
 ********************************************************************/

/**
 *
 */
class FTWeightHandler extends FieldHandler
{
    public $label = '{{L//Weight in g}}';
    public $help  = '{{L//If this is a single part, you can specify the weight in grams here.}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        FieldHandler::__construct(FIELD_FT_WEIGHT);
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        return Format::Number($value, 2);
    }
}


