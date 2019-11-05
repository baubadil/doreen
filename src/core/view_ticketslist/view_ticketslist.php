<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  Static class implementing the list view for ticket search results (/tickets GUI,
 *  in the plural).
 *
 *  The entry point is \ref Emit(), which directly parses the URL, fires
 *  the search command and displays the results.
 */
abstract class ViewTicketsList
{
    protected static $fulltext          = NULL;
    protected static $baseCommand;
    protected static $aParticles        = [];          # Processed list of URL articles. The 'sortby' key is guaranteed to be set even if not originally present.
    protected static $iCurrentPage      = NULL;      # NULL = graph mode; otherwise current page
    protected static $format            = NULL;            # 'list' or 'grid'
    protected static $aDrillDownFilters = [];               # field_id => [ permitted, ... ], ....

    protected static $fGraph = FALSE;

    protected static function ParseArgs()
    {
        self::$fulltext = WebApp::FetchParam('fulltext', FALSE);

        Globals::Profile("Entering printSearchResults()");

        WebApp::ParseUrl(Globals::GetRequestOnly(),
                         self::$baseCommand,
                         self::$aParticles);

        if (self::$fulltext)
        {
            self::$fulltext = trim(self::$fulltext);
            $defaultSortby = new SearchOrder(SearchOrder::TYPE_SCORE);
        }
        else
            $defaultSortby = new SearchOrder(SearchOrder::TYPE_FIELD, FIELD_CREATED_DT);

        if (!getArrayItem(self::$aParticles, 'sortby'))
            self::$aParticles['sortby'] = $defaultSortby->getFormattedParam();

        self::$aDrillDownFilters = ApiTicket::FetchDrillDownParams();

        if ($page = WebApp::FetchParam('page', FALSE))
        {
            if (!isPositiveInteger($page))
                throw new InvalidPageException($page);
            self::$iCurrentPage = $page;
        }
        else
            self::$iCurrentPage = 1;

        if (!(self::$format = WebApp::FetchParam('format', FALSE)))
            self::$format = Globals::$defaultTicketsView;
        else if (    (self::$format != 'list')
                  && (self::$format != 'grid')
                )
            throw new DrnException("Invalid format ".Format::UTF8Quote(self::$format));
    }

    public static function MakeSwitchFormatButton(bool $fActive,
                                                  string $format,
                                                  string $hover,
                                                  string $htmlIcon)
        : HTMLChunk
    {
        $aParticles2 = self::$aParticles;
        $aParticles2['format'] = $format;
        if (empty($aParticles2['fulltext']))
            unset($aParticles2['fulltext']);
        $hrefThis = Globals::BuildURL(Globals::$rootpage.'/tickets',
                                      $aParticles2,
                                      Globals::URL_URLENCODE);

        $cls = 'btn-default';
        if ($fActive)
            $cls = 'btn-primary';
        $aAttrs = [ 'class' => "btn $cls",
                    'title' => $hover,
                    'rel' => 'alternate'
                  ];
        if ($fActive)
            $aAttrs['disabled'] = 'disabled';
        else
            $aAttrs['href'] = $hrefThis;

        return HTMLChunk::MakeElement('a',
                                      $aAttrs,
                                      Icon::GetH($htmlIcon));
    }

    /**
     *  Called from the /tickets GUI request handler to output the tickets table,
     *  for all kinds of search results, including fulltext queries.
     *
     *  This builds the query, executes it, and then displays the results.
     */
    public static function Emit()
    {
        /*
         *  Parse arguments (URL)
         */
        self::ParseArgs();

        // Ticket ID was entered, go directly to ticket.
        if (self::$fulltext !== NULL && preg_match('/^#(\d+)$/', self::$fulltext, $aMatches))
        {
            WebApp::Reload('ticket/'.$aMatches[1]);
            return;
        }

        switch (self::$format)
        {
            case 'grid':
                ViewTicketsGrid::EmitGrid();
            break;

            default:
                self::EmitList();
        }
    }

    public static function EmitList()
    {
        Globals::Profile("Calling Access::GetForUser()");

        /*
         *  Run the query
         */
        $oAccess = Access::GetForUser(LoginSession::$ouserCurrent);

        $findResults = NULL;

        if (self::$fulltext)
        {
            Globals::Profile("Calling Access::findByFulltextQuery()");
            $findResults = $oAccess->findByFulltextQuery(self::$fulltext,
                                                         self::$aParticles['sortby'],
                                                         self::$iCurrentPage,
                                                         self::$aDrillDownFilters);
            Globals::Profile("Returned from Access::findByFulltextQuery()");
        }
        else
        {
            Globals::Profile("Calling Access::findNonTemplates()");
            $findResults = $oAccess->findTickets([ SearchFilter::NonTemplates() ],
                                                 ACCESS_READ,
                                                 self::$aParticles['sortby'],
                                                 self::$iCurrentPage,
                                                 self::$aDrillDownFilters);
            Globals::Profile("Returned from Access::findNonTemplates()");
        }

        # If we found exactly one ticket, then go into its details view directly.
    //    if (    $findResults
    //         && ($findResults->cTotal == 1)
    //       )
    //    {
    //        $oTicket = array_shift($findResults->aTickets);
    //        WebApp::Reload('ticket/'.$oTicket->id);
    //        # does not return
    //    }

        /*
         *
         *  Make drill-down filters box
         *
         */

        $aFilterHTML = NULL;
        if ($findResults)
            $aFilterHTML = $findResults->makeFiltersHtml(MODE_READONLY_LIST, self::$baseCommand, self::$aParticles);
        else
            $aFilterHTML = FindResults::BuildFiltersHtml(MODE_READONLY_LIST, self::$baseCommand, self::$aParticles, NULL, TRUE);

        /*
         *
         *  Call subroutine for results table / cards
         *
         */
        $oHTML2 = NULL;
        if ($findResults)
        {
            $oHTML2 = new HTMLChunk();

            if (self::$iCurrentPage)
            {
                # Table mode:
                Globals::Profile("Calling PrintTicketsTable()");
                self::PrintTicketsTable($oHTML2,
                                        $findResults);
                Globals::Profile("Returned from PrintTicketsTable()");
            }
        }

        /* Create another HTMLChunk for the entire page so we can insert the "time take"
           string as late as possible, to get an accurate measurement of not only the
           time taken of the main query, but also the subsequent queries triggered by
           the field handlers invoked. */
        $oHTMLPage = new HTMLChunk();

        $htmlTitle = L('{{L//Search results}}');
        $oButtons = HTMLChunk::MakeElement('span',
                                           [ 'class' => 'btn-group',
                                             'role' => 'group' ])
            ->appendChunk(self::MakeSwitchFormatButton(TRUE,'list', L("{{L//Show results as list}}"), 'list'))
            ->appendChunk(self::MakeSwitchFormatButton(FALSE,'grid', L("{{L//Show results as grid}}"), 'table'));
        $oHTMLPage->openPage($htmlTitle,
                             TRUE,
                             NULL,
                             $oButtons->html);


        $helpFilters = L("{{L//Toggle filters}}");
        $oHTMLPage->addLine("<button id=\"filtersbutton\" type=\"button\" aria-pressed=\"false\" class=\"pull-right btn btn-default btn-sm visible-xs-inline\" title='$helpFilters'>".Icon::Get('filter')."</button>");
        WholePage::AddJSAction('core', 'onTicketTableReady', [], TRUE);

        $oHTMLPage->addLine('<p>'.ApiTicket::MakeResultsString($findResults).'</p>');

        if ($aFilterHTML)
        {
            $oHTMLPage->openGridRow(NULL, 'table-drill-down hidden-xs');
            foreach ($aFilterHTML as $fieldidFilter => $strFilterHTML)
            {
                $oHTMLPage->openGridColumn([ 'col-lg-2', 'col-sm-4' ]);

                $oHTMLPage->openDiv();

                $oHTMLPage->append(L("<h4 style=\"display: inline-block\">{{L//Filter by %FILTER%:}}</h4>",
                                     [ '%FILTER%' => TicketField::GetDrillDownFilterName($fieldidFilter) ] ));


                if (    ($oFieldHandler = FieldHandler::Find($fieldidFilter))
                     && ($oFieldHandler->shouldShowMultipleToggle())
                   )
                    $oHTMLPage->appendChunk($oFieldHandler->makeDrillMultipleToggle(self::$baseCommand,
                                                                               self::$aParticles));

                $oHTMLPage->close();

                $oHTMLPage->append($strFilterHTML);

                $oHTMLPage->close();  # grid column
            }
            $oHTMLPage->close();

            WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initShowMoreFilters',
                                                 [ 'drn-show-hidden-filters', 'drn-hidden-filter' ], true);
        }

        if ($oHTML2)
            $oHTMLPage->html .= $oHTML2->html;

        $oHTMLPage->close();    # page

        WholePage::Emit($htmlTitle, $oHTMLPage);
    }

    /**
     *  Creates a column title. If the colum matches the current "sortby" criterion, we'll add an icon.
     *  If not, we'll make it a link to reload with the sortby criterion.
     */
    protected static function MakeColumnTitle($htmlTitle,
                                              $sortby,
                                              $fDefaultDescending,           //!< in: if TRUE, default to descending sort, otherwise ascending
                                              $iconstem = 'sort_amount')     //!< in: either sort_amount or sort_alpha (without _asc or _desc)
    {
        $baseCommand = Globals::$rootpage.WebApp::$command;

        $fAscending = $fDefaultDescending;

        if ($oldSortby = getArrayItem(self::$aParticles, 'sortby'))
        {
            $oldSortby = Ticket::ParseSortBy($oldSortby, $fAscending);

            if ($oldSortby == $sortby)
                $htmlTitle .= Format::NBSP.Icon::Get($iconstem.($fAscending ? '_asc' : '_desc'), TRUE);
            else
                $fAscending = $fDefaultDescending;
        }

        $oSort = SearchOrder::FromParam($sortby);
        // Inverse the current direction
        $oSort->direction = $fAscending ? SearchOrder::DIR_DESC : SearchOrder::DIR_ASC;
        return HTMLChunk::MakeTooltip($htmlTitle,
                                      L('{{L//Click here to have the results sorted by this column.}}'),
                                      Globals::BuildURL($baseCommand, array_merge(self::$aParticles, [ 'sortby' => $oSort->getFormattedParam() ] )));
    }

    /**
     *  Prints a table of ticket results with columns (called the "list view").
     *
     *  For the columns, we look through the types of ALL the tickets returned and check
     *  which data fields should be visible in list view; this allows the columns to be
     *  constant when the user clicks through several pages of data, even though we only
     *  retrieve ticket data for the current page for speed.
     */
    protected static function PrintTicketsTable(HTMLChunk $oHTML,
                                                FindResults $findResults)
    {
        $cPages = floor(($findResults->cTotal + Globals::$cPerPage - 1) / Globals::$cPerPage);

        WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_TOOLTIPS);

        # Pagination!
        $oHTML->addPagination(Globals::$rootpage.WebApp::$command,
                              self::$aParticles,
                              self::$iCurrentPage,
                              $cPages);

        $oHTML->openTable();

        # Make the TABLE HEADINGS for the visible columns. Display columns for all the types in the entire result
        # set, not only for the types used on the current page.
        Globals::Profile("Calling TicketType::GetManyVisibleFields()");
        $aVisibleFields = TicketType::GetManyVisibleFields($findResults->aTypes,
                                                           TicketType::FL_INCLUDE_CORE);      # flags: include neither children nor hidden fields nor details
        Globals::Profile("Returned from TicketType::GetManyVisibleFields()");

        /* Merge certain fields together. If mapToOtherColumn returns something,
           use that in the array of columns to display. */
        $aMergedColumnNames = [];             # field_id => name of merged column
        $aMergeMaps = [];                     # field_id => merged column field ID
        $aMergedFieldIDs = [];                # merged column field ID => 1
        foreach ($aVisibleFields as $field_id => $oField)
        {
            $htmlColumnName = NULL;
            $fieldidUse = $field_id;
            # Initialize the field handler, either from a plugin or built-in.
            if ($oHandler = FieldHandler::Find($field_id,
                                               FALSE))
            {
                if ($a = $oHandler->mapToOtherColumn())
                {
                    list($fieldidMapped, $htmlColumnName) = $a;
                    $aMergedFieldIDs[$fieldidMapped] = 1;
                    $fieldidUse = $fieldidMapped;
                    $aMergeMaps[$field_id] = $fieldidMapped;
                }
                else
                    $htmlColumnName = $oHandler->getLabel(NULL)->html;      # for FIELD_STATUS, use the generic description, no process handlers.
            }
            else
                $htmlColumnName = Format::MakeYellow(toHTML($oField->name));

            $aMergedColumnNames[$fieldidUse] = $htmlColumnName;
        }

        $oHTML->openTableHeadAndRow();
        foreach ($aMergedColumnNames as $field_id => $htmlColumnTitle)
        {
            if (isset($aMergedFieldIDs[$field_id]))
                $oHTML->addTableHeading($htmlColumnTitle);
            else
            {
                # "Real" field, not mapped:
                $oField = $aVisibleFields[$field_id];

                if ($oField->fl & FIELDFL_SORTABLE)
                    $oHTML->addTableHeading(self::MakeColumnTitle($htmlColumnTitle,
                                                                  $oField->name,
                                                                  ($oField->fl & FIELDFL_DESCENDING) != 0),
                                            $oField->getTableColumnAttributes());
                else
                    $oHTML->addTableHeading($htmlColumnTitle);
            }
        }

        # Add a search score column if this was a full-text search.
        if (self::$fulltext)
            $oHTML->addTableHeading(self::MakeColumnTitle(Icon::Get('thumbsup'),
                                                          'score',
                                                          TRUE));

        $oHTML->close(); # table head and row
        $oHTML->openTableBody();

        # Now prepare the pages as a window into the whole result set.

        # Fetch the full (stage-2) ticket data only for the visible window of rows.
        if (count($findResults->aTickets))
        {
            $strHighlightWords = NULL;
            if ($findResults->llHighlights && count($findResults->llHighlights))
                # We have search results:
                $strHighlightWords = implode(' ', $findResults->llHighlights);

            Ticket::PreloadDisplay($findResults->aTickets,
                                   $aVisibleFields,
                                   Ticket::POPULATE_LIST);

            Globals::Profile("Done handling prepareDisplay(), now printing cells");

            $oPage = new TicketPageBase(LoginSession::$ouserCurrent,
                                        NULL,       # ticket, to be ste later
                                        MODE_READONLY_LIST);

            foreach ($findResults->aTickets as $ticket_id => $oTicket)
            {
    //            Debug::FuncEnter("Printing ticket $ticket_id");
                $oHTML->openTableRow();

                # first cell is ID
                $oPage->hrefTicket = Globals::$rootpage."/ticket/$ticket_id";
                $oPage->llHighlightWords = $findResults->llHighlights;
    //            throw new DrnException(print_r($oPage->llHighlightWords, TRUE));

                if ($strHighlightWords)
                {
                    # We have search results:
                    $oPage->hrefTicket .= "?highlight=".toHTML($strHighlightWords);
                    if ($oTicket->aBinariesFound)
                        $oPage->hrefTicket .= '&binariesFound='.implode(',', $oTicket->aBinariesFound);
                }

                $oPage->oTicket = $oTicket;
                $oPage->oType = $oTicket->oType;

                /* For the columns of the row, first go through the fields reported in aVisibleFields
                   and make an array of field_id => HTML pairs; then, go through the list of merged
                   columns and output the fields in that order. */
                $c = 1;
                $aFieldChunks = [];
                foreach ($aVisibleFields as $field_id => $oField)
                {
                    /* Check again that the field is visible for THIS type, because aVisibleFields
                       contains the combined fields from all visible types. Let's not call field
                       handlers unnecessarily. */
                    $oHTMLThis = NULL;
                    if ($oField->isVisibleInListView($oTicket))
                    {
    //                    Debug::FuncEnter("Printing field $field_id (".$oField->name.") of #$ticket_id");
                        if ($oHandler = FieldHandler::Find($field_id, FALSE))
                            $oHTMLThis = $oHandler->formatValueHTML($oPage,
                                                                    $oHandler->getValue($oPage));
                        else
                            $oHTMLThis = HTMLChunk::FromString(getArrayItem($oTicket->aFieldData, $field_id));
    //                    Debug::FuncLeave();
                    }

                    if ($oHTMLThis)
                    {
                        if ($c++ == 1)
                            if ($oIcon = $oTicket->getIcon())
                                $oHTMLThis->append("<div class=\"drn-icon-list\">$oIcon->html</div>");

                        if (!($fieldidUse = getArrayItem($aMergeMaps, $field_id)))
                            $fieldidUse = $field_id;
                        $aFieldChunks[$fieldidUse] = $oHTMLThis;
                    }
                }

                foreach ($aMergedColumnNames as $fieldidMerged => $htmlColumnTitle)
                {
                    $oChunk = $aFieldChunks[$fieldidMerged] ?? NULL;

                    $attrs = NULL;
                    if ($oField = TicketField::Find($fieldidMerged))
                        $attrs = $oField->getTableColumnAttributes();
                    $oHTML->addTableCell($oChunk ? $oChunk->html : Format::NBSP,
                                         $attrs);
                }

                # On full-text search, add an extra column for search score.
                if (self::$fulltext)
                    $oHTML->addTableCell(Format::Number($oTicket->score, 2));

                $oHTML->close(); # table row
    //            Debug::FuncLeave();
            }

            Globals::Profile("Done printing cells");
        }

        $oHTML->close(); # table-body
        $oHTML->close(); # table

        $oHTML->addPagination(Globals::$rootpage.WebApp::$command,
                              self::$aParticles,
                              self::$iCurrentPage,
                              $cPages);
    }

    /**
     *  An attempt to print tickets as a VisJS animation. Currently unused.
     */
    protected static function PrintTicketsGraph(HTMLChunk $oHTML,
                                                $findResults)
    {
        $aTicketsFound = $findResults->aTickets;

        WholePage::Enable(WholePage::FEAT_JS_JAVASCRIPT_VISJS);

        # Fetch ONLY the parent information from ticket_ints. We might have thousands of tickets
        # in the array and we don't want to fetch all stage-2 data for them.
        $ids = implode(',', array_keys($aTicketsFound));
        $res = Database::DefaultExec(<<<EOD
SELECT
    ticket_parents.ticket_id AS child_id,
    ticket_parents.value AS parent_id
FROM ticket_parents
WHERE ticket_parents.ticket_id IN ($ids)
EOD
                                 );
        $aParents = [];
        $aHasChildren = [];
        $aLoadParents = [];
        while ($row = Database::GetDefault()->fetchNextRow($res))
        {
            $child_id = $row['child_id'];
            $parent_id = $row['parent_id'];
            if (!array_key_exists($child_id, $aParents))
                $aParents[$child_id] = [ $parent_id ];
            else
                $aParents[$child_id][] = $parent_id;
            $aHasChildren[$parent_id] = 1;

            # If the parent ticket is not in the result set, load it later.
            if (!array_key_exists($parent_id, $aTicketsFound))
                $aLoadParents[$parent_id] = 1;
        }

        if (count($aLoadParents))
            if ($findResults3 = Ticket::FindManyByID(array_keys($aLoadParents)))
                foreach ($findResults3->aTickets as $id => $oTicket)
                    $aTicketsFound[$id] = $oTicket;

        $oHTML->openDiv('graph-canvas');
        $oHTML->close();

        $aNodes = [];
        $aEdges = [];

        $c = 0;
        $cSkipped = 0;

        foreach ($aTicketsFound as $id => $oTicket)
        {
            /** @var Ticket $oTicket */
            # Add 300 tickets max, unless the node has children.
            if (    ($c < 100)
                 || (array_key_exists($id, $aHasChildren))
               )
            {
                $aNodes[$id] = [ 'id' => "#$id",
                                 'label' => "#$id: ".$oTicket->getTitle(),
                                 'group' => 'default'
                               ];
                if (array_key_exists($id, $aParents))
                    foreach ($aParents[$id] as $parent_id)
                        $aEdges[] = [   'from' => "#$parent_id",
                                        'to' => "#$id"
                                    ];
            }
            else
                ++$cSkipped;

            ++$c;
        }

        if ($cSkipped)
            $oHTML->addLine('<p>'
                           .L('{{L//Too many nodes for graph, %SKIPPED% tickets were skipped.}}',
                              [ '%SKIPPED%' => Format::Number($cSkipped) ] )
                           .'</p>');

        $jsonNodes = json_encode(array_values($aNodes));
        $jsonEdges = json_encode($aEdges);

        WholePage::AddScript(<<<EOD
var g_ticketData =
{
    nodes: $jsonNodes,
    edges: $jsonEdges
};
EOD
                        );
        WholePage::AddJSAction('core', 'onTicketResultsGraphDocumentReady', [], TRUE);

    }
}
