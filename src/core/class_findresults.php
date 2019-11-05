<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FindResults class
 *
 ********************************************************************/

/**
 *  Results instance returned by \ref Ticket::FindMany() and thus by all the find methods that
 *  depend on it that contains both the tickets set and the types used. This avoids having
 *  to use references.
 *
 *  If pagination is used, then $aTickets contains instantiated tickets only for the selected
 *  page, and $cTotal contains the total no. of tickets available on all pages.
 */
class FindResults
{
    public $cTotal;                 //!< Total number of tickets found. This is >= count($aTickets) if a page was given to FindMany().

    /** @var  Ticket[] */
    public $aTickets;               //!< Tickets found in ticket_id => oTicket format

    /** @var  TicketType[] */
    public $aTypes;                 //!< Ticket types used in the tickets set (on all pages), in type_id => oType format.

    public $aDrillDownCounts;       //!< All drill-down counts (for all pages) broken down by field ID, in field_id => [ value => count, ... ] format.

    public $llHighlights;           //!< With full-text searches, receives a list of words to highlight when displaying results.

    # Details for fetchChunk()
    public $cLeft = -1;
    public $aChunks;
    public $aLastChunk = NULL;

    public function __construct($cTotal,
                                $aTickets,
                                $aTypes,
                                $aDrillDownCounts)
    {
        initObject($this,
                   [ 'cTotal', 'aTickets', 'aTypes', 'aDrillDownCounts'],
                   func_get_args());
    }

    public function add($oTicket)
    {
        $this->aTickets[$oTicket->id] = $oTicket;
        $this->aTypes[$oTicket->oType->id] = $oTicket->oType;

        if (!isset($this->aDrillDownCounts[FIELD_TYPE][$oTicket->oType->id]))
            $this->aDrillDownCounts[FIELD_TYPE][$oTicket->oType->id] = 1;
        else
            ++$this->aDrillDownCounts[FIELD_TYPE][$oTicket->oType->id];

        ++$this->cTotal;
    }

    /**
     *  Convenience helper to fetch a chunk of tickets from the member search results.
     *  This is useful when the search result contains hundreds of thousands of tickets
     *  and you don't want to call Ticket::PopulateMany() on all of them in order not to
     *  bring the server down. Instead, keep calling this method to get a slice from
     *  the members array, call Ticket::PopulateMany() on the slice and exit the loop
     *  when this method returns NULL.
     *
     *  This either returns an array with up to $max ticket_id => oTicket pairs, or
     *  NULL when there's nothing left to fetch, but never an empty array.
     *
     *  NOTE: this only works with a newly created FindResults instance. After this
     *  has gone through the find results and has returned NULL, there is no way to
     *  rewind and start over currently.
     *
     * @return Ticket[]
     */
    public function fetchChunk($max = NULL)
    {
        if (!$max)
            $max = Globals::$cDefaultTicketsChunk;

        if ($this->cLeft == -1)
        {
            # First call:
            $this->aChunks = array_chunk($this->aTickets,
                                         $max,
                                         TRUE); # preserve keys
            $this->cLeft = $this->cTotal;
        }

        if ($this->cLeft > 0)
        {
            $a = array_shift($this->aChunks);
            $this->cLeft -= count($a);

            if ($this->aLastChunk !== NULL)
            {
                # Unset all ticket refs from the last chunk or else a long-running task like reindex-all
                # will consume gigabytes of main memory that are never used.
                /** @var Ticket $oTicket */
                foreach ($this->aLastChunk as $id => $oTicket)
                    $oTicket->release();

                $this->aLastChunk = NULL;
            }

            $this->aLastChunk = $a;

            return $a;
        }

        return NULL;
    }

    /**
     *  Makes an array of HTML chunks with filters for $this results set.
     *
     *  Each array key is a field ID (e.g. FIELD_TYPE), each value is an HTML string with buttons that
     *  contain the aggregations from the FindResults, formatted by the field's field handler.
     *
     *  This goes into each field handler's formatDrillDownResults() method, which can be complex processing.
     *
     * @return HTMLChunk[] | null
     */
    public function makeFiltersHtml(int $mode,              //!< in: MODE_READONLY_LIST or MODE_READONLY_GRID
                                    string $baseCommand,    //!< in: base for generated URLs (e.g. 'tickets' or 'board')
                                    array $aParticles)      //!< in: array of URL key/value pairs to build sub-URLs correctly
    {
        return self::BuildFiltersHtml($mode,
                                     $baseCommand,
                                     $aParticles,
                                     $this->aDrillDownCounts);
    }

    public static function BuildFiltersHtml(int $mode,              //!< in: MODE_READONLY_LIST or MODE_READONLY_GRID
                                            string $baseCommand,            //!< in: base for generated URLs (e.g. 'tickets' or 'board')
                                            array $aParticles,              //!< in: array of URL key/value pairs to build sub-URLs correctly
                                            array $aDrillDownCounts = NULL, //!< in: optional ID -> result count map for filters
                                            bool $fShowAllFilters = FALSE)  //!< in: flag to show all active filters
    {
        Debug::FuncEnter(Debug::FL_TICKETFIND, __METHOD__, "baseCommand=".Format::UTF8Quote($baseCommand));
        Debug::Log(Debug::FL_TICKETFIND, print_r($aParticles, TRUE));

        $aFilterHTML = [];      # key = filter class, value = html with buttons for aggregations of that class

        /*  Drill-down filters have two components: a filter key (e.g. 'type') and a filter value (e.g. 5).
         *  Initially, all filters are unset, and we print one checkbox for each filter.
         *  When a filter checkbox gets clicked, we add a "filterkey=value1,!value2,!value3" to the URL,
         *  where each filter that should be ACTIVE has no prefix, and other filters have a "!" prefix.
         *  For each filter that is active, we need to list the INACTIVE filters with the "!" prefix because
         *  once we start filtering, we cannot build a filter list from the FindResults instance because we
         *  will no longer see tickets for the other filters in the search results.
         */
        $aFilterValuesUsed = [];
        foreach ($aParticles as $key => $values)
            if (    (preg_match('/drill_(.*)/', $key, $aMatches))
                 && ($filterFieldName = $aMatches[1])
               )
            {
                foreach (explode(',', $values) as $filterValue)
                {
                    if ($filterValue === NULL)
                        throw new DrnException("Invalid drill-down filter syntax \"$key=$values\"");
                    $aFilterValuesUsed[$filterFieldName][$filterValue] = 1;
                }
            }

    //     Debug::Log("aFilterValuesUsed: ".print_r($aFilterValuesUsed, TRUE));

        # Create a special TicketContext for the filters list so we can retrieve value names from field handlers.
        $oContext = new TicketContext(LoginSession::$ouserCurrent,
                                      NULL,        # No ticket here, this is for the filters list
                                      MODE_READONLY_FILTERLIST);        // Needed by some field handlers for value formatting.
        $oContext->filterListMode = $mode;

        if ($aDrillDownCounts === NULL)
            $aDrillDownCounts = [];
        $aDrillDownFieldIDs = TicketField::GetDrillDownIDs();
        $aDrillDownFieldIDs[] = FIELD_TYPE;
        foreach ($aDrillDownFieldIDs as $fieldID)
        {
            if ($fieldID !== FIELD_TYPE)
                $fieldName = TicketField::FindOrThrow($fieldID)->name;
            else
                $fieldName = 'type';
            if (array_key_exists($fieldName, $aFilterValuesUsed))
            {
                if (!array_key_exists($fieldID, $aDrillDownCounts))
                    $aDrillDownCounts[$fieldID] = [];
                foreach ($aFilterValuesUsed[$fieldName] as $key => $value)
                    if (!array_key_exists($key, $aDrillDownCounts[$fieldID]))
                        $aDrillDownCounts[$fieldID][$key] = 0;
            }
        }

        /* Examine the drill-down counts from the search results, if any. Aggregations have the following
           hierarchy:

            1) for each drill-downable field ID (e.g. FIELD_TYPE, FIELD_ASSIGNEE etc.), the backend has
               performed an aggregation and returns

            2) a list of integer values that have occured in the result set (e.g. TYPE_WIKI and TYPE_TASK); these
               are the "buckets" for this aggregation; for each of these, we get

            3) a value COUNT (i.e. the no. of tickets that were found for this value in the result set) and

            4) we also ask the field handler for the field id to format the value as a string. */

        foreach ($aDrillDownCounts as $filterFieldID => $aValueCounts)
        {
            # Only display filter buttons if we have more than one value for it.
            if (count($aValueCounts) > 1 || $fShowAllFilters)
            {
                $oHandler = FieldHandler::Find($filterFieldID);
                if ($html = $oHandler->formatDrillDownResults($oContext,
                                                              $aValueCounts,
                                                              $baseCommand,
                                                              $aParticles,
                                                              $aFilterValuesUsed))
                    $aFilterHTML[$filterFieldID] = $html;
            }
        }

        Debug::FuncLeave();

        return count($aFilterHTML) ? $aFilterHTML : NULL;
    }
}
