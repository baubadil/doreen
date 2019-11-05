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
 */
class SchoolClassHandler extends CategoryHandlerBase
{
    public $label = "{{L//Class}}";

    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_SCHOOL_CATEGORY_CLASS);
    }
}
