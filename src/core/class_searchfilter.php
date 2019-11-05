<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  SearchFilter class
 *
 ********************************************************************/

/**
 *  A search filter, an array of which can be passed into Ticket::FindMany(), which will then
 *  convert these into appropriate queries for the database backends. These classes free the
 *  FindMany() callers from having to know about table or SQL internals.
 *
 *  Calling FindMany() without search filters will return all tickets on the system and provide
 *  statistics about them. This is almost never a good idea, so the following filters can be used:
 *
 *   -- TYPE_FULLTEXT; see \ref Fulltext();
 *
 *   -- TYPE_STRICTMATCH, a fulltext query which must match literally, without advanced syntax;
 *      see \ref StrictMatch();
 *
 *   -- TYPE_ACLIDS: a ticket must have one of the given list of ACL IDs as its own ACL ID;
 *      see \ref FromACLIDs();
 *
 *   -- TYPE_TICKETIDS: a ticket must have one of the given list of ticket IDs; see \ref FromTicketIDs();
 *
 *   -- TYPE_TICKETTYPES: a ticket must be of one of the types specified by the given list of
 *      ticket type IDs; see \ref FromTicketTypes();
 *
 *   -- TYPE_FIELDVALUELIST, to allow only tickets who match the given enumeration of field values;
 *      see \ref FromFieldValueList();
 *
 *   -- TYPE_FIELDVALUERANGE; similar to TYPE_FIELDVALUELIST, but instead of an enumeration of
 *      valid values, this takes a range of permitted values; see \ref FromFieldValueRange().
 *
 *   -- TYPE_EXCLUDETICKETIDS: do not include the given ticket IDs in the results. This is for
 *      filtering out system tickets like the "main page" wiki ticket.
 *
 *  All of the above can return both templates and non-templates, so you might want to combine
 *  the above filters with one of these:
 *
 *   -- TYPE_TEMPLATES: return templates only; see \ref Templates()
 *
 *   -- TYPE_NONTEMPLATES: return non-templates (actual tickets with data) only; see \ref NonTemplates().
 *
 *  Multiple SearchFilter instances in the array act cumulatively, i.e. as AND condition, so
 *  tickets will only be returned if they fulfill all of them.
 */
class SearchFilter
{
    const TYPE_TEMPLATES        = 1;
    const TYPE_NONTEMPLATES     = 2;
    const TYPE_FULLTEXT         = 3;
    const TYPE_STRICTMATCH      = 4;
    const TYPE_ACLIDS           = 5;
    const TYPE_TICKETIDS        = 6;
    const TYPE_TICKETTYPEIDS    = 7;
    const TYPE_FIELDVALUELIST   = 8;
    const TYPE_FIELDVALUERANGE  = 9;
    const TYPE_EXCLUDETICKETIDS = 10;

    // Parameters
    const TYPE_IGNORECHANGELOG  = 10;
    const TYPE_LIMIT            = 11;

    public $type;

    public $fulltext;               # for TYPE_FULLTEXT and TYPE_STRICTMATCH only
    public $llFieldIDs;             # for TYPE_STRICTMATCH only

    public $field_id;               # for TYPE_FIELDVALUES only
    public $llValues;               # for TYPE_ACLIDS, TYPE_TICKETIDS, TYPE_TICKETTYPES, TYPE_FIELDVALUES, TYPE_EXCLUDETICKETIDS
    public $min;                    # for TYPE_FIELDVALUERANGE
    public $max;                    # for TYPE_FIELDVALUERANGE

    /** @var bool $fStrings */
    public $fStrings = FALSE;       # for TYPE_FIELDVALUELIST

    public $sql;


    /********************************************************************
     *
     *  Constructors
     *
     ********************************************************************/

    private function __construct($type)
    {
        $this->type = $type;
    }

    public function describe()
    {
        switch ($this->type)
        {
            case self::TYPE_TEMPLATES:
                return "TYPE_TEMPLATES";

            case self::TYPE_NONTEMPLATES;
                return "TYPE_NONTEMPLATES";

            case self::TYPE_FULLTEXT:
                return "TYPE_FULLTEXT(\"$this->fulltext\")";

            case self::TYPE_STRICTMATCH:
                return "TYPE_STRICTMATCH";

            case self::TYPE_ACLIDS:
                return "TYPE_ACLIDS";

            case self::TYPE_TICKETIDS:
                return "TYPE_TICKETIDS";

            case self::TYPE_TICKETTYPEIDS:
                return "TYPE_TICKETTYPEIDS";

            case self::TYPE_FIELDVALUELIST:
                return "TYPE_FIELDVALUELIST";

            case self::TYPE_FIELDVALUERANGE:
                return "TYPE_FIELDVALUERANGE";

            case self::TYPE_EXCLUDETICKETIDS:
                return "TYPE_EXCLUDETICKETIDS";
        }

        return NULL;
    }

    /**
     *  Factory method to create a SearchFilter that returns only template tickets.
     *
     *  @return self
     */
    public static function Templates()
        : SearchFilter
    {
        return new self(self::TYPE_TEMPLATES);
    }

    /**
     *  Factory method to create a SearchFilter for full-text search with advanced syntax
     *  features, using the configured search engine.
     *  This corresponds to a `?fulltext=TERM` query in the GUI.
     *
     *  Combining this with \ref Templates() or \ref NonTemplates() makes no sense since
     *  templates are never pushed to the search engine, as they have no data.
     *
     *  @return self
     */
    public static function Fulltext($fulltext)
        : SearchFilter
    {
        $fCooked = FALSE;
        # Run through all "precook search" plugins to allow them to modify the search query.
        foreach (Plugins::GetWithCaps(IUserPlugin::CAPSFL_PRECOOK_SEARCH) as $oImpl)
        {
            /** @var IPrecookSearchPlugin $oImpl */
            if ($modified = $oImpl->precookSearch($fulltext))
            {
                $fulltext = $modified;
                $fCooked = TRUE;
                break;
            }
        }

        if (!$fCooked)
            if (strpos($fulltext, '"') === FALSE)
                if (preg_match('/.+@.+\..+/', $fulltext))
                    $fulltext = '"'.$fulltext.'"';

        $o = new self(self::TYPE_FULLTEXT);
        $o->fulltext = $fulltext;
        return $o;
    }

    /**
     *  Factory method to create a SearchFilter for a strict text match, using the configured search engine.
     *
     *  Whereas Fulltext() produces a query with fuzzyness and advanced syntax as provided by the search engine,
     *  this query is for a literal search within specific fields, which should also have FIELDFL_TYPE_TEXT_LITERAL
     *  so that the indexing has been run accordingly.
     *
     *  This is presently only used internally by plugins to search for phone numbers and emails.
     *
     *  Combining this with \ref Templates() or \ref NonTemplates() makes no sense since
     *  templates are never pushed to the search engine, as they have no data.
     *
     *  @return self
     */
    public static function StrictMatch($llFieldIDs,
                                       $text)
        : SearchFilter
    {
        Debug::Log(Debug::FL_TICKETFIND, __METHOD__."(\"$text\")");
        $o = new self(self::TYPE_STRICTMATCH);
        $o->llFieldIDs = $llFieldIDs;
        $o->fulltext = $text;
        return $o;
    }

    /**
     *  Factory method to create a SearchFilter that returns only non-template (actual data) tickets.
     *
     *  @return self
     */
    public static function NonTemplates()
        : SearchFilter
    {
        return new self(self::TYPE_NONTEMPLATES);
    }

    /**
     *  Factory method to create a SearchFilter that returns only non-template (actual data) tickets.
     *
     *  @return self
     */
    public static function ExcludeTicketIds(array $llTicketIds)
        : SearchFilter
    {
        $o = new self(self::TYPE_EXCLUDETICKETIDS);
        $o->llValues = $llTicketIds;
        return $o;
    }

    /**
     *  Factory method to create a SearchFilter that returns only tickets that have one of the given ACL IDs.
     *
     *  A SearchFilter of this type is automatically added if you use one of the search methods in Access,
     *  like \ref Access::findTickets(), \ref Access::findTemplates(), \ref Access::findByFulltextQuery().
     *
     *  Alternatively, you can construct your own SearchFilter with \ref Access::getACLsForAccess() to
     *  find tickets that a given user is allowed to see (or modify or delete or create tickets from).
     *  Note that if that method returns NULL, there are no ACLs for that user and access method, and
     *  as a result no tickets either, so it will not be necessary to hit the database.
     *
     *  There is no GUI query for this as this is done automatically do show only tickets that the current
     *  user is allowed to see.
     *
     *  This should at least be combined with \ref Templates() or \ref NonTemplates() for meaningful results.
     *
     *  @return self
     */
    public static function FromACLIDs($llAIDs)
        : SearchFilter
    {
        $o = new self(self::TYPE_ACLIDS);
        $o->llValues = $llAIDs;
        return $o;
    }

    /**
     *  Factory method to create a SearchFilter that returns only tickets that have one of the given ticket IDs.
     *
     *  This is used by \ref Ticket::FindManyByID() and \ref Ticket::FindOne(), both of which are used all
     *  over the place.
     *
     *  If you know your ticket IDs already, there's also no point to combine the query with
     *  \ref Templates() or \ref NonTemplates().
     */
    public static function FromTicketIDs($llTicketIDs)
    {
        $o = new self(self::TYPE_TICKETIDS);
        $o->llValues = $llTicketIDs;
        return $o;
    }

    /**
     *  Factory method to create a SearchFilter that returns only tickets that have one of the given type IDs
     *  (see also the TicketType class).
     *
     *  This should at least be combined with \ref Templates() or \ref NonTemplates() for meaningful results.
     *
     *  @return self
     */
    public static function FromTicketTypes($llTypeIDs)
        : SearchFilter
    {
        $o = new self(self::TYPE_TICKETTYPEIDS);
        $o->llValues = $llTypeIDs;
        return $o;
    }

    /**
     *  Factory method to create a SearchFilter that returns only tickets that have one of the given field values.
     *
     *  This type has two arguments, a ticket field ID (e.g. FIELD_STATUS) and a list of permitted values, and
     *  the filter will return only tickets that have one of those values in the field.
     *
     *  It will NOT return tickets that do not have that field at all according to their ticket type, or whose
     *  value in that field is NULL.
     *
     *  @return self
     */
    public static function FromFieldValueList($field_id,       //!< in: field ID (e.g. FIELD_STATUS)
                                              $llValues,       //!< in: flat list of permitted values
                                              bool $fStrings = FALSE) //!< in: if the values are strings
        : SearchFilter
    {
        $o = new self(self::TYPE_FIELDVALUELIST);
        $o->field_id = $field_id;
        $o->llValues = $llValues;
        $o->fStrings = $fStrings;
        return $o;
    }

    /**
     *  Factory method to create a SearchFilter that returns only tickets whose value of the given field is
     *  within the given range.
     *
     *  This is similar to \ref FromFieldValueList() but it takes a value range instead of an enumeration.
     *
     *  This works best for dates.
     *
     *  @return self
     */
    public static function FromFieldValueRange($field_id,       //!< in: field ID (e.g. FIELD_STATUS)
                                               $min,            //!< in: minimum value (inclusive) or NULL for no limit
                                               $max)            //!< in: maximum value (inclusive) or NULL for no limit
        : SearchFilter
    {
        $o = new self(self::TYPE_FIELDVALUERANGE);
        $o->field_id = $field_id;
        $o->min = $min;
        $o->max = $max;
        return $o;
    }

    /**
     *  Factory method to create an option SearchFilter that makes the search ignore comments and attachments, for speed.
     *
     *  @return self
     */
    public static function IgnoreChangelog()
        : SearchFilter
    {
        $o = new self(self::TYPE_IGNORECHANGELOG);
        return $o;
    }

    /**
     *  Factory method to create an option SearchFilter that limits the no. of results to the given maximum, for speed.
     *
     *  Note that this is *not* used for paging search results in the GUI, since we need to get *all* the results from
     *  the search engine to be able to tell how many pages would need to be displayed. Pagination normally comes as a
     *  second step implemented by \ref Ticket::FindMany() before hitting the SQL database and instantiating Ticket
     *  instances.
     *
     *  Instead, use this SearchFilter type only if you are sure you don't need to know how many results there might
     *  be because you will never be interested in more than the $cMax given here.
     *
     *  @return self
     */
    public static function Limit($cMax)
        : SearchFilter
    {
        $o = new self(self::TYPE_LIMIT);
        $o->max = $cMax;
        return $o;
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  Called from Ticket::FindMany() when the SQL for finding tickets gets built. This can return
     *  a WHERE clause component to filter the search.
     */
    public function makeSQL(JoinsList &$oJoinsList,
                            &$pstrFulltextForSQL,
                            &$pfResultsPossibleAtAll)        //!< out: set to FALSE if there cannot possibly be any results
    {
        switch ($this->type)
        {
            case self::TYPE_TEMPLATES:
                return 'tickets.template IS NOT NULL';
            break;

            case self::TYPE_NONTEMPLATES:
                return 'tickets.template IS NULL';
            break;

            case self::TYPE_FULLTEXT:
                $pstrFulltextForSQL = $this->fulltext;
            break;

            case self::TYPE_ACLIDS:
                if ($this->llValues)
                    return 'tickets.aid IN ('.Database::MakeInIntList($this->llValues).')';
                else
                    // If there are no ACLs in the list, then this user cannot see any tickets at all.
                    // This happens for the guest account if there are no public tickets, for example.
                    $pfResultsPossibleAtAll = FALSE;
            break;

            case self::TYPE_TICKETIDS:
                return 'tickets.i IN ('.Database::MakeInIntList($this->llValues).')';
            break;

            case self::TYPE_TICKETTYPEIDS:
                return 'tickets.type_id IN ('.Database::MakeInIntList($this->llValues).')';
            break;

            case self::TYPE_FIELDVALUELIST:
            case self::TYPE_FIELDVALUERANGE:
                if (!($oField = TicketField::Find($this->field_id)))
                    throw new DrnException("Internal error: invalid field ID $this->field_id specified in search");

                if ($oField->fl & FIELDFL_STD_CORE)
                    $colname = 'tickets.'.$oField->name.'_id';      // TODO works only for project...
                else
                {
                    $oLJ = new LeftJoin($oField);
                    $oJoinsList->add($oLJ);
                    $colname = "$oLJ->tblAlias.value";
                }

                if ($this->type == self::TYPE_FIELDVALUELIST)
                {
                    if ($this->fStrings)
                    {
                        $ids = '';
                        foreach ($this->llValues as $strThis)
                            $ids .= ($ids ? ', ' : '').Database::GetDefault()->escapeString($strThis);
                    }
                    else
                        $ids = Database::MakeInIntList($this->llValues);
                    return "$colname IN (".$ids.')';
                }
                else
                {
                    # range: both mysql and postgres support between()
                    $str = "";
                    if ($this->min !== NULL)
                        $str = "$colname >= '$this->min'";
                    if ($this->max !== NULL)
                    {
                        if ($str)
                            $str .= " AND ";
                        $str .= "$colname <= '$this->max'";
                    }

                    return $str;
                }
            break;

            case self::TYPE_EXCLUDETICKETIDS:
                return 'tickets.i NOT IN ('.Database::MakeInIntList($this->llValues).')';
            break;
        }

        return NULL;
    }
}
