<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  Static class implementing the board view for ticket search results (/board GUI).
 *
 *  This inherits from ViewTickets (for the tickets list, /tickets GUI) for code reuse.
 *
 *  The backend does very little except for outputting an HTML template.
 *
 *  The TicketsGrid TypeScript class then calls the GET /tickets REST API, which doees the
 *  real work; see \ref ApiTicket::GetMany(). That calls \ref Ticket::MakeApiResult()
 *  in turn with $format = 'grid', which ends up calling \ref FormatOne() here for each
 *  ticket (below).
 *
 *  The entry point is the parent's \ref Emit(), which calls \ref EmitGrid() here.
 */
abstract class ViewTicketsGrid extends ViewTicketsList
{
    const C_GRID_COLUMNS_FILTERS = 3;       // out of 12

    const C_CARDS_PER_ROW = 3;

    public static function EmitGrid()
    {
        /*
         *  Parse arguments (URL)
         */
        self::ParseArgs();

        $idDialog = 'ticketsgrid';

        /*
         *  Emit page
         */
        $oPage = new HTMLChunk();

        $htmlTitle = L('{{L//Search results}}');
        $formatButtons = "<span id=\"$idDialog-formats\"></span>";
        $oPage->openPage($htmlTitle,
                         TRUE,
                         NULL,
                         $formatButtons);

        $oPage->openDiv("$idDialog");

        $oPage->addHiddenErrorBox("$idDialog-error");

        // Template
        $oPage->openGridRow("$idDialog-template-row", 'hide');
        $colclass = "col-md-".(12 / self::C_CARDS_PER_ROW);
        $oPage->openGridColumn([ $colclass ], "$idDialog-template-column");
        $oPage->openDiv(NULL, 'panel panel-default', NULL, 'div', [ 'style' => 'z-order: 1' ]);
        $oPage->openDiv(NULL, 'drn-icon-grid', NULL, 'span');
        $oPage->close(); // icon span
        $oPage->openDiv(NULL, 'panel-heading');
        $oPage->close(); // panel-heading
        $oPage->openDiv(NULL, 'panel-body');
        $oPage->close(); // panel-body
        $oPage->close(); // panel-default
        $oPage->close(); // column
        $oPage->close(); // grid row

        $oPage->openGridRow();

        $oPage->openGridColumn(12);
        // Drop-down for the sort order.
        $oPage->openDiv("$idDialog-sortby", 'pull-right hidden');
        $oPage->append(L("{{L//Sort by}}").Format::NBSP);
        $oPage->addSelect("$idDialog-sortby-select");
        $oPage->addButton(Icon::Get('sort-amount-asc'),
                          "$idDialog-sortby-direction",
                          'hidden',
                          'btn-sm btn-default',
                          'title="'.L("{{L//Reverse sort order}}").'"');
        $helpFilters = L("{{L//Toggle filters}}");
        $oPage->addLine("<button id=\"$idDialog-filtersbutton\" type=\"button\" class=\"btn btn-default btn-sm visible-xs-inline\" title='$helpFilters'>".Icon::Get('filter')."</button>");
        $oPage->close();
        // "X tickets found, search took X seconds".
        $oPage->openDiv("$idDialog-totalResults");
        $oPage->close();
        $oPage->close();

        // Target div for filters
        $colclass = "col-md-".self::C_GRID_COLUMNS_FILTERS;
        $oPage->openDiv("$idDialog-filters-collapsed", "hidden-xs");      // outer DIV for hiding on mobile
        $oPage->openGridColumn( [ $colclass ], "$idDialog-filters", 'hide');
        $oPage->addHeading(3, L("{{L//Filter by%HELLIP%}}"));
        $oPage->openDiv("$idDialog-filters-list");
        $oPage->close();
        $oPage->close(); // filters column
        $oPage->close(); // outer div

        // Target div for cards
        $oPage->openGridColumn(12 - self::C_GRID_COLUMNS_FILTERS);
        $oPage->openDiv("$idDialog-results");
        $oPage->close();
        $oPage->close(); // outer column

        $oPage->close(); // row

        $oPage->close();        // -outer div

        $oPage->close();    # page

        $cCardsPerRow = self::C_CARDS_PER_ROW;

        $page = self::$iCurrentPage;
        $oSearchOrder = SearchOrder::FromParam(self::$aParticles['sortby']);
        $sortBy = $oSearchOrder->getParam();
        $direction = $oSearchOrder->direction == SearchOrder::DIR_ASC;
        $aGridState = [
            'fulltext' => self::$fulltext,
            'types' => NULL,
            'page' => $page,
            'sortby' => $sortBy,
            'sortAscending' => $direction
        ];
        $aDrillMultiple = ApiTicket::FetchDrillMultipleParams();
        if ($aDrillParams = ApiTicket::FetchDrillDownParams())
        {
            $aDrill = [];
            foreach ($aDrillParams as $field_id => $aValues)
            {
                $aDrill[] = [ 'fieldname' => TicketField::GetName($field_id),
                              'values' => $aValues,
                              'multiple' => array_key_exists($field_id, $aDrillMultiple) ];
                unset($aDrillMultiple[$field_id]);
            }
            $aGridState['drill'] = $aDrill;
        }
        // Ensure multiple params are also written to state when no drill filter is active.
        if ($aDrillMultiple)
        {
            foreach ($aDrillMultiple as $field_id => $fieldname)
            {
                $aGridState['drill'][] = [ 'fieldname' => $fieldname,
                                           'values' => [],
                                           'multiple' => true ];
            }
        }

        WholePage::AddNLSStrings([
            'drillMultiple' => L('{{L//Multiple}}'),
            'drillMultipleFlyover' => L('{{L//Enable this to be able to search for more than one status value at once.}}'),
        ]);
        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initTicketsGrid',
                                             [ $idDialog,
                                               $cCardsPerRow,
                                               $aGridState ] );

        WholePage::Emit($htmlTitle, $oPage);
    }

    /**
     *  Formats one ticket for the grid. This gets called in the context of the AJAX request
     *  triggered by the TypeScript generated by EmitGrid().
     *
     *  The call stack is:
     *
     *   1. GET /tickets REST API
     *
     *   2. ApiTicket::GetMany()
     *
     *   3. Ticket::MakeApiResult()
     *
     *   4. \ref Ticket::GetManyAsArray(), which first calls getJSON() and then this.
     *
     *  @return HTMLChunk
     */
    public static function FormatOne(Ticket $oTicket)
        : HTMLChunk
    {
        $oContextGrid = new TicketContext(LoginSession::$ouserCurrent,
                                          $oTicket,
                                          MODE_READONLY_GRID);

        $aFields = $oTicket->oType->getVisibleFields(0);

        $oHTML = new HTMLChunk();

        /** @var HTMLChunk[] $llChunks */
        $llChunks = [];

        $oTicket->getHtmlForGrid($oContextGrid,
                                 $aFields,
                                 $oHTML,
                                 $llChunks);

        $oHTML->appendChunk(HTMLChunk::Implode('<br>', $llChunks));

        return $oHTML;
    }
}
