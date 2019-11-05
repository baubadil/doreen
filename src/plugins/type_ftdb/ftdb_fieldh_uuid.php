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
class FTArticleVariantUUIDHandler extends FieldHandler
{
    public $label = '{{L//Unique identifier}}';
    public $help  = '{{L//In the FTDB every kit or part must have a unique identifier (in UUID format), which has been generated automatically here.}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        FieldHandler::__construct(FIELD_FT_ARTICLEVARIANT_UUID);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    public function getInitialValue(TicketContext $oContext)
    {
        return UUID::NormalizeLong(UUID::Create());
    }
}


