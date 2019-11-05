<?php

/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;

/**
 *  Extends FindResults to contain all data to display an ordered list of tickets
 *  as result, especially before actual ticket data is populated in the
 *  FindResults. Once populated, FindResults should contain all the information
 *  needed.
 */
class SearchResults extends FindResults
{
    /**
     *  Ordered list of found tickets.
     *  @var int[] $aTicketIDs
     */
    public $aTicketIDs = [];
    /**
     *  ID - binary ID map of found binaries.
     *  @var array $aBinaries
     */
    public $aBinaries = [];
    /**
     *  Terms to highlight in ticket. ID -> highlights[] array.
     *  @var string[][] $aHighlights
     */
    public $aHighlights = [];

    /**
     *  ID -> value map of scores.
     *  @var array $aScores
     */
    public $aScores = [];
}
