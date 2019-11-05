<?php

/*
 *  Copyright 2018 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Date class
 *
 ********************************************************************/

/**
 *  Simple Date class for date stamps without a time.
 */
class Date
{
    public $year;       # e.g. 2018
    public $month;      # 1-12
    public $day;        # 1-31

    private $strDateConstruct;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    /**
     *  Constructor made private. Use factory methods instead.
     */
    private function __construct()
    {
    }


    /********************************************************************
     *
     *  Static factory methods
     *
     ********************************************************************/

    /**
     *  Creates a new Timestamp from the given string in database format.
     *  This accepts both YYYY-MM-DD HH:MM:SS (without time) and
     *  YYYY-MM-DD HH:MM:SS (with time) formats; in the second case, the
     *  time string will simply be ignored.
     */
    public static function CreateFromString($strDate,           //!< in: string in YYYY-MM-DD format
                                            $fValidate = TRUE)
        : self
    {
        if (!$strDate)
            throw new DrnException("Error: empty string handed to ".__METHOD__);
        if (!preg_match('/^(\d\d\d\d)-(\d\d)-(\d\d)(?: \d\d:\d\d:\d\d)?$/', $strDate, $aMatches))
            throw new DrnException("Error: invalid string \"$strDate\" handed to ".__METHOD__);
        $o = new self;
        $o->strDateConstruct = $strDate;
        $o->year = $aMatches[1];
        $o->month = $aMatches[2];
        $o->day = $aMatches[3];

        if ($fValidate)
            $o->validate();

        return $o;
    }

    public function validate()
    {
        if ($this->year < 1900)
            throw new DrnException("Error: strange year $o->year in date object from string \"$this->strDateConstruct\"");
    }

    public function toString()
        : string
    {
        return $this->year.'-'.$this->month.'-'.$this->day;
    }

}
