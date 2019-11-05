<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 * @page intro_parent_child Parent/child ticket relationships
 *
 *  Doreen provides the \ref ticket_parents table and the ParentsHandler and ChildrenHandler
 *  to represent arbitrary relationships between tickets.
 *
 *  Essentially, a row in ticket_parents with ticket_id X and a value of Y says that Y
 *  is the parent ticket of X. Consequently, this makes X a child of Y without needing
 *  a separate specification of that. This is truly an m:n relation: any ticket can have
 *  an arbitrary number of children and parents.
 *
 *  As a result, parent/child relations are not properties of a SINGLE ticket, but rather
 *  define relations between several tickets. As a result, changing a parent of a ticket
 *  logically makes that ticket a child of the other ticket, altering the children list
 *  of that ticket, and vice versa.
 *
 *  If used with a bugtracker, the "parent" would be the "milestone" (and there would
 *  only be a single parent), and the children would be the "blockers" that another
 *  ticket "depends on".
 *
 *  For Doreen's ticket field magic and field handlers to work with this, the following
 *  needs to be established:
 *
 *   --  FIELD_PARENTS must have the FIELDFL_STD_DATA_OLD_NEW, FIELDFL_ARRAY and FIELDFL_ARRAY_HAS_REVERSE flags set,
 *       and its table must be ticket_parents. FIELDFL_ARRAY means that ticket_parents
 *       can have multiple rows (parents) per ticket ID, and that they should be combined
 *       into an array when fetching ticket data.
 *
 *   --  FIELD_CHILDREN must have an ID that is FIELD_PARENTS plus 1, and it must have
 *       FIELDFL_STD_DATA_OLD_NEW and FIELDFL_ARRAY_REVERSE set. If this is set up
 *       correctly, then Doreen can build a ticket's children list from ticket_parents
 *       as well.
 */


/********************************************************************
 *
 *  ParentsHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_PARENTS. See FieldHandler for how these work.
 *
 *  ParentsHandler implements an m:n structure between tickets to represent
 *  arbitrary parent/child structures. This has a companion field handler,
 *  the ChildrenHandler. See \ref intro_parent_child for an explanation how this
 *  works, which is not trivial.
 */
class ParentsHandler extends FieldHandler
{
    public $label = '{{L//Milestones (parents)}}';
    public $help  = '{{L//Here you can specify other tickets that depend on the completion of this ticket.}}';

    public $graphBlocks = '{{L//blocks}}';
    public $direction = 'LR';       # Graph formatting for details view. One of UD, DU, LR, RL. If empty, we display a text list.

    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($field_id = FIELD_PARENTS)
    {
        FieldHandler::__construct($field_id);
        $this->flSelf |= self::FL_GRID_PREFIX_FIELD;
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Called for ticket details to add a row to the dialog form which
     *  displays the field data for the ticket and allows for editing it.
     *  Only gets called in MODE_CREATE or MODE_EDIT, because otherwise there is
     *  no editable dialog. (In MODE_READONLY_DETAILS, appendReadOnlyRow() gets called instead.)
     *
     *  We override the parent only to call \ref Ticket::getInfo() with the
     *  INFO_CREATE_SHOW_PARENTS_AND_CHILDREN flag to allow Ticket subclasses
     *  to suppress this field. Since ChildrenHandler derives from this, it
     *  can be suppressed too.
     */
    public function appendFormRow(TicketPageBase $oPage)   # in: TicketPageBase instance with ticket dialog information
    {
        if ($oPage->oTicket->getInfo(Ticket::INFO_CREATE_SHOW_PARENTS_AND_CHILDREN))
            parent::appendFormRow($oPage);
    }

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  We override this to be able to pick up the 'parents' argument in the URL bar,
     *  if we have been called as a result from the 'add another' button.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,          //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        if ($oPage->mode == MODE_CREATE)
            $value = WebApp::FetchParam($this->oField->name,
                                        FALSE); # not required
                    # will be NULL unless we're in the 'create ticket' form as a result of
                    # the "add child" button
        else
            $value = $this->getValue($oPage);

        if (is_array($value))
            $value = implode(',', $value);

        $oPage->oDlg->addInput('text',
                               $idControl,
                               '',
                               toHTML($value));
    }

    /**
     *  This method gets called in MODE_READONLY_LIST and MODE_READONLY_CARDS to allow field handlers to
     *  preload data before they get called again for the actual tickets.
     *  See FieldHandler::prepareDisplay() for details.
     *
     *  We override the empty FieldHandler implementation to add parent and child tickets to the list of
     *  tickets for which stage-2 data should be fetched. The caller will then fetch all stage-2 data for
     *  all involved tickets at once, saving possibly many SQL round trips.
     *
     * @return void
     */
    public function preloadDisplay($aVisibleTickets,
                                   &$aFetchStage2DataFor)    //!< in/out: result ticket set (keys must be ticket IDs to be loaded, values are ignored)
    {
        foreach ($aVisibleTickets as $ticket_id => $oTicket)
            if ($value = getArrayItem($oTicket->aFieldData, $this->field_id))
                foreach (explode(',', $value) as $ticket_id)
                    $aFetchStage2DataFor[$ticket_id] = 1;
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     *
     *  We override this to display a hierarchy graph in ticket details or alternatively
     *  a list of ticket links.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        $oHtml = new HTMLChunk();

        if (    ($oContext->mode == MODE_READONLY_DETAILS)
             && ($this->direction)
           )
            $this->formatHierarchy($oHtml,
                                   $oContext->oTicket);
        else if ($value)
            $this->formatTicketsList($oHtml,
                                     $oContext,
                                     $value,
                                     5);
        return $oHtml;
    }

    /**
     *  Called by \ref Ticket::toArray() to give each field handler a chance for add meaningful
     *  values to the JSON array returned from there.
     *
     *  If the $paFetchSubtickets reference is not NULL, it points to an array in
     *  \ref Ticket::GetManyAsArray(), and this function can add key/subkey/value pairs to that
     *  array to instruct that function to fetch additional subticket data.
     *  The format is: $paFetchSubtickets[idParentTicket][stringKey] = list of sub-ticket IDs.
     *  \ref Ticket::GetManyAsArray() will then fetch JSON for the sub-ticket IDs and add their JSON
     *  data to $aJSON in one go.
     *
     *  This default FieldHandler implementation appents a simple key => value pair to the
     *  array. If the key is an integer we also call formatValue.
     *
     * @return void
     */
    public function serializeToArray(TicketContext $oContext,
                                     &$aReturn,
                                     $fl,
                                     &$paFetchSubtickets)
    {
        # Exclude parents and children unless JSON_LEVEL_PARENTS_CHILDREN is set.
        if ($fl & Ticket::JSON_LEVEL_PARENTS_CHILDREN)
            parent::serializeToArray($oContext, $aReturn, $fl, $paFetchSubtickets);
    }

    /**
     *  This gets called by \ref writeToDatabase() for every field before the
     *  new value gets written into the database. This must return a value that
     *  fits into the value column of the field's database table, or, in the
     *  case of FIELDFL_ARRAY, an arra of those values. It can also throw a
     *  APIException with the field name if validation fails.
     *
     *  TODO Validate the parent/child relations here. Make sure the user doesn't accidentally
     *  set a ticket as its own child or parent, and prohibit circular references.
     *
     * @return mixed
     */
    public function validateBeforeWrite(TicketContext $oContext,    //!< in: TicketContext instance
                                        $oldValue,                  //!< in: current value (or NULL during create ticket)
                                        $newValue)                  //!< in: suggest new value from form field
    {
        if (!$newValue)
            return NULL;
        return $newValue;
    }

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     *
     *  Override this to display parent/child ticket information in changelog items.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        $aTicketIDs = [];
        foreach (explode(',', $oRow->value_str) as $str)
            if (preg_match('/([+-])(\d+)/', $str, $aMatches))
                $aTicketIDs[$aMatches[2]] = $aMatches[1];           # value = '+' or '-'

        if ($fr = Ticket::FindManyByID(array_keys($aTicketIDs)))                # this can throw
        {
            $aEntries = [];

            foreach ($fr->aTickets as $idTicket => $oTicket)
            {
                $htmlTicket = $oTicket->makeLink();

                if ($aTicketIDs[$idTicket] == '+')
                    $aEntries[] = L("{{L//added %TITLE%}}", [ '%TITLE%' => $htmlTicket ] );
                else
                    $aEntries[] = L("{{L//removed %TITLE%}}", [ '%TITLE%' => $htmlTicket ] );
            }

            return L("{{L//%FIELD% changed: %LIST%}}",
                     [ '%FIELD%' => L($this->label),
                       '%LIST%' => implode(', ', $aEntries)
                     ]);
        }

        return NULL;
    }


    /********************************************************************
     *
     *  Newly introduced protected methods
     *
     ********************************************************************/

    protected function explodeTicketsString($str)
        : array
    {
        $aReturn = [];
        if ($str)
        {
            $oUser = LoginSession::GetCurrentUserOrGuest();
            $llTicketIds = explode(',', $str);
            # TODO this is too slow -- combine the queries from two calls into one.
            if ($fr = Ticket::FindManyByID($llTicketIds))
                foreach ($fr->aTickets as $oTicket)
                {
                    if ($oTicket->canRead($oUser))
                        $aReturn[] = $oTicket->id;
                }
        }

        return $aReturn;
    }

    /**
     *  Creates a ticket hierarchy graph in the given HTMLChunk.
     *
     * @return void
     */
    protected function formatHierarchy(HTMLChunk $oHtml,
                                       Ticket $oTicket)
    {
        $parents = $oTicket->aFieldData[$this->field_id] ?? NULL;          # e.g. FIELD_PARENTS
        $children = $oTicket->aFieldData[$this->field_id + 1] ?? NULL;     # reverse array field ID, e.g. FIELD_CHILDREN

        $aParents = $aChildren = [];
        $defaultColor = '#a0a0a0';

        if ($children || $parents)
        {
            WholePage::Enable(WholePage::FEAT_JS_JAVASCRIPT_VISJS);
            # view_ticketpage.ts is always added in ticket details view, which has JavaScript for this.

            # Get the stage-2 data for all children and parents as efficiently as possible.
            $llParents = $this->explodeTicketsString($parents);
            $llChildren = $this->explodeTicketsString($children);
            $llBoth = array_merge($llParents, $llChildren);
            if (    $llBoth
                 && ($fr = Ticket::FindManyByID($llBoth))
               )
            {
                Ticket::PopulateMany($fr->aTickets,
                                     Ticket::POPULATE_LIST);   # details

                $aParents = [];
                foreach ($llParents as $idTicket)
                {
                    $oTicket = $fr->aTickets[$idTicket];
                    if (isset($aParents[$idTicket]))
                    {
                        $oHtml->append(Format::MakeYellow("Error: parent #$idTicket listed more than once (parents list: ".implode(', ', $llParents).')'));
                        return;
                    }
                    $aParents[$idTicket] = $oTicket->makeGraphData($this->field_id, $defaultColor);
                }
                $aChildren = [];
                foreach ($llChildren as $idTicket)
                {
                    $oTicket = $fr->aTickets[$idTicket];
                    $aChildren[] = $oTicket->makeGraphData($this->field_id, $defaultColor);
                }
            }
        }

        # Set the height both in the CSS for the canvas and pass it to visjs
        # to avoid flicker.
        $cMax = min(10, max(count($aParents), count($aChildren)));

        if ($cMax)
        {
            $aConfig = [ 'edgeColor' => '#c0c0c0',
                         'defaultColor' => $defaultColor,
                         'nlsBlocks' => L($this->graphBlocks),
                         'nlsCurrentTicketFlyOver' => L("You are currently viewing this"),
                         'distanceToParent' => 230,
                         'distanceToSibling' => 80,
                       ];
            if ($this->direction == 'free')
            {
                $aConfig['layout'] = 'free';
                $aConfig['height'] = max(($cMax * 100), 400);
            }
            else
            {
                $aConfig['layout'] = 'hierarchical';
                $aConfig['direction'] = $this->direction;
                $aConfig['height'] = max(($cMax * 100), 100);
            }

            $jsonGraphColors = json_encode(
                [
                    # Omit the 'back' field everywhere because we use statusColor in every node.
                    'parents'   => [ 'fore' => 'white', 'border' => '#B46804' ],
                    'this'      => [ 'fore' => 'white', 'border' => 'white' ],
                    'children'  => [ 'fore' => 'white', 'border' => '#B46804' ],
                ]
            );

            $height = $aConfig['height'];
            $oHtml->openDiv('div-ticket-hierarchy');
            $oHtml->openDiv('ticket-hierarchy-canvas',
                            NULL,
                            NULL,
                            'div',
                            [ 'style' => "height: $height" ] );
            $oHtml->close();
            $oHtml->close();

            WholePage::AddScript(<<<JS
var g_oGraphColors = $jsonGraphColors;
JS
            );
            WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initHierarchyGraph',
                                                 [ 'ticket-hierarchy-canvas',
                                                   array_values($aParents),
                                                   array_values($aChildren),
                                                   $aConfig ], TRUE);
        }
    }

    /**
     *  Helper that takes the given comma-separated string list of ticket IDs
     *  and returns a string with ticket titles.
     *
     *  If $limit != NULL, we initially display only $limit items and expand
     *  to the full list on click only.
     *
     *  In that case, if $limit is a POSITIVE integer, we initially display
     *  $limit items always and add a "and X more" link, which expands to the
     *  full list on click.
     *
     *  If $limit is a NEGATIVE integer, we display the list only if it is
     *  at most -$limit items and otherwise show a link to the ticket details
     *  page.
     *
     *  Only for that case you can specify $htmlTotalL for an NLS string (in L format)
     *  which can contain a %COUNT% string to be replaced with the item count.
     *
     * @return void
     */
    protected function formatTicketsList(HTMLChunk $oHtml,
                                         TicketContext $oContext,
                                         $value,
                                         $limit = NULL,
                                         $htmlTotalL = NULL)
    {
        if ($value)
        {
            if (!is_array($value))
                $value = explode(',', $value);

            if ($findResults = Ticket::FindManyByID($value))
            {
                $aLinks = [];
                $aLinksHidden = [];
                $c = 0;
                $limit2 = abs($limit);
                foreach ($findResults->aTickets as $ticket_id => $oTicket)
                {
                    $htmlLink = $oTicket->makeLink();

                    if ((!$limit2) || ($c < $limit2))
                        $aLinks[] = $htmlLink;
                    else
                        $aLinksHidden[] = $htmlLink;

                    ++$c;
                }

                if (($limit === NULL) || ($limit > 0))
                {
                    $oHtml->html = implode(', ', $aLinks);

                    if ($cMore = count($aLinksHidden))
                    {
                        $strAndMore = ' <b>'.L("{{L//and %COUNT% more}}", [ '%COUNT%' => Format::Number($cMore) ] ).'</b>';
                        $oHtml->append(' '.HTMLChunk::AddMoreLink($strAndMore,
                                                                  ', '.implode(', ', $aLinksHidden)));
                    }
                }
                else
                {
                    # $limit < 0
                    if (!$htmlTotalL)
                        $htmlTotalL = "{{L//%COUNT% items}}";
                    $oHtml->html = L($htmlTotalL, [ '%COUNT%' => Format::Number(count($findResults->aTickets)) ] );
                    $oHtml->html = HTMLChunk::MakeTooltip($oHtml->html,
                                                          L("{{L//Go to details to view complete list}}"),
                                                          Globals::$rootpage."/ticket/".$oContext->oTicket->id);
                }
            }
        }
    }

}


/********************************************************************
 *
 *  ChildrenHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_CHILDREN. See FieldHandler for how these work.
 *
 *  This is the reverse to ParentsHandler, which displays the children of a ticket.
 *  We can inherit from ParentsHandler since the formatting for ticket lists is
 *  identical. The logic which data arrives here sits in the Tickets class, and it
 *  is based on the fact that the TicketField for FIELD_CHILDREN has
 *  FIELDFL_ARRAY_REVERSE set.
 *
 *  Note that if you derive children from this with different field IDs, the magic
 *  between ParentsHandler and ChildrenHandler will only works if the
 *  FIELDID_(CHILDREN) = FIELDID_(PARENTS) + 1. For example,
 *  FIELD_CHILDREN = -30, FIELDID_PARENTS = -31.
 */
class ChildrenHandler extends ParentsHandler
{
    public $label = '{{L//Blockers (children)}}';
    public $help  = '{{L//Here you can specify other tickets that must be completed before this ticket is done.}}';

    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct($field_id = FIELD_CHILDREN)
    {
        # We intentionally do not call the parent, but FieldHandler directly.
        FieldHandler::__construct($field_id);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Called for ticket details in MODE_READONLY_DETAILS to add a Bootstrap row div
     *  to the page's HTML to display the ticket data.
     *
     *  We override the FieldHandler default to simply do nothing for the children
     *  list in details view, since we have a pretty graph in ParentsHandler::appendReadOnlyRow()
     *  that displays the children as well.
     *
     * @return void
     */
    public function appendReadOnlyRow(TicketPageBase $oPage)               //!< in: TicketPageBase instance with ticket dialog information
    {
    }
}
