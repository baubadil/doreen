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
 *  Field handler for FIELD_STUDENT ('school_student').
 *
 *  This inherits from VCardHandler and uses its exact data format, it just
 *  renames some display labels and forces "simple format" on the
 *  VCard dialog.
 */
class SchoolStudentHandler extends VCardHandler
{
    // Override fields of parent.
    public $flDisplay = self::FL_DEFAULT_SIMPLE_FIELDS;
    protected $lstrFirstName = '{{L//Student first name}}';
    protected $lstrLastName = '{{L//Student last name}}';
    protected $lstrAddress = '{{L//Home address}}';
    protected $lstrPhone = '{{L//Home phone}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_STUDENT);
    }
}
