/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as $ from 'jquery';
import FormBase from '../../js/inc-formbase';
import {
    drnFindByID,
    myEscapeHtml,
    drnEnablePopovers,
    drnShowAndFadeIn,
} from '../../js/shared';
import { drnGetUrlParameters } from '../../js/inc-globals';
import {
    GetTicketsApiResult,
    SortDirection,
    SortType,
    TicketListFormat
} from '../../js/inc-api-types';
import APIHandler from '../../js/inc-apihandler';
import { activateShowMoreButton } from '../../js/main';
import _ from '../../js/nls';
import getIcon from '../../js/icon';

interface DrillDownFilter
{
    fieldname: string;
    values: number[];
    multiple: boolean;
}

/**
 *  An instance of this is stored is given to the TicketsGrid constructor
 *  and then stored in its instance data.
 */
export interface GridState
{
    fulltext: string;
    types: string;
    drill: DrillDownFilter[];
    page: number;
    sortby: string;
    sortAscending: boolean;
}

/**
 *  Created by the file's entry point to build the complete tickets grid.
 *
 *  The load() method fires a ticket search API request and then displays
 *  the results. This gets called by the constructor and later again if
 *  the user clicks on filters or a page in the pages list.
 */
export class TicketsGrid extends FormBase
{
    protected readonly jqDialog: JQuery;
    protected readonly jqTemplateRow: JQuery;
    protected readonly jqTemplateColumn: JQuery;
    protected readonly jqTotals: JQuery;
    protected readonly jqSortBy: JQuery;
    protected readonly jqResults: JQuery;
    protected readonly jqFilters: JQuery;
    protected readonly jqFiltersList: JQuery;
    protected fRestoringState: boolean;

    constructor(idDialog: string,                   //!< in: 'ticketsgrid' ID stem
                protected readonly cCardsPerRow: number,     //!< in: from back-end, currently hard-coded as 3
                protected oState: GridState,        //!< in: initial grid state, produced by back-end
                protected readonly format = 'grid')
    {
        super(idDialog);

        this.jqDialog = drnFindByID(idDialog);
        this.jqTemplateRow = this.findDialogItem('template-row');
        this.jqTemplateColumn = this.findDialogItem('template-column');
        this.jqTotals = this.findDialogItem('totalResults');
        this.jqSortBy = this.findDialogItem('sortby');
        this.jqResults = this.findDialogItem('results');
        this.jqFilters = this.findDialogItem('filters');
        this.jqFiltersList = this.findDialogItem('filters-list');

        this.fRestoringState = true;

        this.findDialogItem('filtersbutton').on('click', (e) =>
        {
            const jqButton = $(e.target);
            let jq = this.findDialogItem('filters-collapsed');
            if (jq.hasClass('hidden-xs'))
            {
                jq.removeClass('hidden-xs');
                jqButton.attr('aria-pressed', 'true');
                jqButton.addClass('active');
            }
            else
            {
                jq.addClass('hidden-xs');
                jqButton.attr('aria-pressed', 'false');
                jqButton.removeClass('active');
            }
        });

        this.load(true);

        // The popstate event is fired when the active history entry changes.
        window.addEventListener("popstate", (event) => {
            let { state } = event;
            if (!state)
                state = this.makeStateFromUrl();
            this.restoreState(state);
        }, false);
    }

    protected getDrillFromState(fieldname: string,
                                oState: GridState = this.oState)
        : DrillDownFilter | undefined
    {
        if (oState.drill)
        {
            for (const drillInfo of oState.drill)
            {
                if(drillInfo.fieldname === fieldname)
                    return drillInfo;
            }
        }
    }

    protected makeStateFromUrl()
        : GridState
    {
        const urlState: any = {};
        const params = drnGetUrlParameters();
        for (const name in params)
        {
            const value = params[name];
            if (name === 'sortby')
            {
                if (value[0] === '!')
                {
                    urlState.sortAscending = false;
                    urlState.sortby = value.substr(1);
                }
                else {
                    urlState.sortAscending = true;
                    urlState.sortby = value;
                }
            }
            else if (name.substr(0, 6) === 'drill_')
            {
                if (!urlState.drill)
                    urlState.drill = [];

                const drillName = name.substr(6);
                const values = value.split(',').map((v) => parseInt(v, 10));

                const drillInfo = this.getDrillFromState(drillName, urlState);
                if (drillInfo)
                    drillInfo.values = values;
                else
                    urlState.drill.push({
                        fieldname: drillName,
                        values,
                        multiple: false
                    });
            }
            else if (name.substr(0, 9) === 'multiple_')
            {
                if (!urlState.drill)
                    urlState.drill = [];

                const drillName = name.substr(9);

                const drillInfo = this.getDrillFromState(drillName, urlState);
                if (drillInfo)
                    drillInfo.multiple = true;
                else
                    urlState.drill.push({
                        fieldname: drillName,
                        values: [],
                        multiple: true
                    });
            }
            else if (name === 'fulltext')
                urlState[name] = value.replace(/\+/g, ' ');
            else if (name === 'page')
                urlState[name] = parseInt(value, 10);
        }
        if (!urlState.page)
            urlState.page = 1;

        return urlState;
    }

    /**
     *  Produces the whole URL except for the page= parameter, which caller must append.
     *  If format is null, the currently displayed format is used; otherwise the link
     *  will change the current display format.
     */
    protected makeUrlExceptPage(format: string = null, oState: GridState = this.oState)
        : string
    {
        if (!format)
            format = this.format;

        let url = `/tickets?base=board&format=${format}`;
        if (oState.fulltext)
        {
            const fulltext = encodeURIComponent(oState.fulltext);
            url += `&fulltext=${fulltext}`;
        }
        if (oState.sortby)
            url += `&sortby=${oState.sortAscending ? '' : '!'}${oState.sortby}`;
        if (oState.drill)
        {
            for (let filter of oState.drill)
            {
                if (filter.values.length)
                {
                    let values = filter.values.join(',');
                    url += `&drill_${filter.fieldname}=${values}`;
                }
                if (filter.multiple)
                    url += `&multiple_${filter.fieldname}=1`;
            }
        }

        return url;
    }

    /**
     *  Produces the full API request URL with all particles.
     */
    protected makeFullUrl(format: string = null, oState: GridState = this.oState)
        : string
    {
        return this.makeUrlExceptPage(format, oState) + `&page=${oState.page}`;
    }

    protected restoreState(state: GridState)
    {
        this.oState = state;
        this.fRestoringState = true;
        this.load(true);
    }

    /**
     *  This fires off the GET /tickets/ request with the complete
     *  complex URL. On the first call, when called from the constructor,
     *  fCompleteReload is true; on subsequent loads (additional pages,
     *  sort order change), it is false.
     *
     *  On success this calls the onDataLoaded() handler method.
     */
    protected load(fCompleteReload: boolean)
    {
        if (fCompleteReload)
            this.jqTotals.html(getIcon('spinner'));
        else
        {
            let jqLoading = this.jqResults.find('.drn-loading-overlay');
            jqLoading.html(getIcon('spinner'));
            jqLoading.removeClass('hide');
        }

        this.execGET(this.makeFullUrl(),
                     (json: GetTicketsApiResult) => {
                        this.onDataLoaded(json, fCompleteReload);
                     });
    }

    /**
     *  This loads another page of the result set after the initial page had been
     *  loaded, e.g. because the user clicked in the pagination bar or the sort
     *  order was changed (in which case page should be 1 again).
     */
    protected loadSubsequentPage(page: number)
    {
        this.oState.page = page;
        this.load(false);
    }

    protected onError(jqXHR)
    {
        this.jqTotals.html("");
        this.handleError(this.jqDialog, jqXHR);
    }

    private getSortIcon(direction: SortDirection,
                        type: SortType)
    {
        const iconType = type === SortType.NUMBER ? 'amount' : 'alpha';
        const iconDirection = direction === SortDirection.ASCENDING ? 'asc' : 'desc';
        return getIcon(`sort_${iconType}_${iconDirection}`);
    }

    /**
     *  Produces one of the buttons in the top bar to switch the display format.
     */
    private makeFormatButtonLink(formatActive: string,
                                 oFormat: TicketListFormat)
        : string
    {
        let cls = 'btn-default';
        let disabled = '';
        let href = '';
        if (oFormat.format == formatActive)
        {
            cls = 'btn-primary';
            disabled = ' disabled="disabled"';
        }
        else
            // Use the API request URL with the rootpage prefix, assuming that the format is the same.
            href = ' href="' + g_rootpage + this.makeFullUrl(oFormat.format) + '"';

        return ` <a${disabled} class="btn ${cls}" title="${oFormat.hover}"${href}" rel="alternate">${oFormat.htmlIcon}</a> `;
    }

    private toggleDrillMultiple(fieldname: string, oState: GridState = this.oState)
        : boolean
    {
        const oDrillThis = this.getDrillFromState(fieldname, oState);
        const checked = oDrillThis && oDrillThis.multiple;
        if (oDrillThis)
        {
            oDrillThis.multiple = !checked;
            if (checked && oDrillThis.values.length)
                oDrillThis.values.splice(1, oDrillThis.values.length - 1);
        }
        else
        {
            if (!oState.drill)
                oState.drill = [];
            oState.drill.push({
                fieldname,
                values: [],
                multiple: !checked
            });
        }
        return checked;
    }

    private makeDrillMultipleToggle(fieldname: string)
        : string
    {
        // Copy state and toggle the multiple flag for the given filter
        const oStateCopy = this.makeStateFromUrl();
        const checked = this.toggleDrillMultiple(fieldname, oStateCopy);
        oStateCopy.page = 1;
        // Use the API request URL with the rootpage prefix, assuming that the format is the same.
        const href = g_rootpage + this.makeFullUrl(null, oStateCopy);
        const icon = `checkbox_${checked ? '' : 'un'}checked`;
        return ` &nbsp;<a href="${myEscapeHtml(href)}" title="${_('drillMultipleFlyover')}">${getIcon(icon)}&nbsp;${_('drillMultiple')}</a>`;
    }

    /**
     *  Called by the load() success closure. This fills the results DIV
     *  with the results set.
     */
    protected onDataLoaded(json: GetTicketsApiResult,
                           fWasCompleteReload: boolean)
    {
        if (!this.fRestoringState)
        {
            // Use the API request URL with the rootpage prefix, assuming that the format is the same.
            const stateUrl = g_rootpage + this.makeFullUrl();
            window.history.pushState(this.oState, stateUrl, stateUrl);
        }
        else
            this.fRestoringState = false;

        // Always update the load time.
        this.jqTotals.html(`<p>${json.nlsFoundMessage}</p>`);

        let htmlPagination2 = '';
        if (json.htmlPagination)
            htmlPagination2 = json.htmlPagination;

        /*
         *  Fill results DIV with cards
         */
        this.jqResults.html(`<p>${htmlPagination2}</p>`);

        this.jqResults.append('<div>'); // for 100% of the overlay
        this.jqResults.append(`<div class="drn-loading-overlay hide"></div>`);

        this.hideError(this.jqDialog);

        const highlights = json.hasOwnProperty('llHighlights') ? json.llHighlights.join(" ") : [];

        let cThisRow = 0;
        let cTotal = 0;
        let jqCurrentRow: JQuery = null;
        if (json.hasOwnProperty('results') && json.results)
            for (let oTicket of json.results)
            {
                let jqCurrentColumn = this.jqTemplateColumn.clone();
                if (highlights.length)
                    oTicket.href += `?highlight=${highlights}`;

                let htmlInnerPanel = '';
                let htmlEdit: string;
                if ((htmlEdit = APIHandler.MakeTicketLink(oTicket,
                                                          getIcon('edit'),
                                                          false,
                                                          true,
                                                          [ 'pull-right' ])))
                    htmlInnerPanel += ` &nbsp;${htmlEdit}`;
                htmlInnerPanel += APIHandler.MakeTicketLink(oTicket, oTicket.htmlLongerTitle);

                jqCurrentColumn.find('.panel-heading').html(htmlInnerPanel);
                jqCurrentColumn.find('.panel-body').html(oTicket.format_grid);
                if (oTicket.icon)
                    jqCurrentColumn.find('.drn-icon-grid').html(oTicket.icon);

                // Clone the row (without the children) if we need a new one.
                if (jqCurrentRow === null)
                    jqCurrentRow = this.jqTemplateRow.clone().removeClass('hide').empty();

                jqCurrentRow.append(jqCurrentColumn);

                ++cThisRow;
                if (cThisRow >= this.cCardsPerRow)
                {
                    this.jqResults.append(jqCurrentRow);
                    jqCurrentRow = null;
                    cThisRow = 0;
                }
                ++cTotal;
            }

        if (jqCurrentRow)
            this.jqResults.append(jqCurrentRow);

        this.jqResults.append('</div>');

        this.jqResults.append(`<p>${htmlPagination2}</p>`);

        drnEnablePopovers(this.jqResults);

        let jqSelect = this.jqSortBy.find('select');
        jqSelect.find('option').remove();
        if (    (cTotal > 1)
             && (json.hasOwnProperty('sortby'))
           )
        {
            let currentSortby = /^!?([^"]+)$/.exec(json.sortby)[1];
            for (let oSort of json.aSortbys)
            {
                let selected = (currentSortby == oSort.param) ? ' selected="selected"' : '';
                jqSelect.append(`<option value="${oSort.param}"${selected} data-direction="${oSort.direction}" data-type="${oSort.type}">${oSort.name}</option>`);
            }

            jqSelect.off();
            // Attach handler to drop-down to change sort order.
            jqSelect.on('change', () => {
                this.oState.sortby = jqSelect.val();
                this.oState.sortAscending = jqSelect.find(":selected").data('direction') == SortDirection.ASCENDING;
                this.loadSubsequentPage(1);
            });

            if (fWasCompleteReload)
                drnShowAndFadeIn(this.jqSortBy);
        }

        /*
         * Sort direction button.
         */
        const directionButton = this.findDialogItem('sortby-direction');
        const selectedSort = jqSelect.find(":selected");
        const sortType = selectedSort.data('type');
        let sortDirection;
        if (json.hasOwnProperty('sortby'))
            sortDirection = /^(!?)[^"]+$/.exec(json.sortby)[1] === "!" ? SortDirection.DESCENDING : SortDirection.ASCENDING;
        else
            sortDirection = selectedSort.data('direction');

        directionButton.html(this.getSortIcon(sortDirection, sortType));

        directionButton.off();
        directionButton.on("click", () => {
            // Reverse current sort order.
            const reverseDirection = sortDirection === SortDirection.ASCENDING ? SortDirection.DESCENDING : SortDirection.ASCENDING;
            this.oState.sortAscending = reverseDirection === SortDirection.ASCENDING;
            const icon = this.getSortIcon(reverseDirection, sortType);
            directionButton.html(icon);
            this.loadSubsequentPage(1);
        });

        if (fWasCompleteReload)
            drnShowAndFadeIn(directionButton);

        /*
         *  Fill filters DIV with filters
         */
        if (fWasCompleteReload)
        {
            let htmlFilters = '';
            if (json.hasOwnProperty('filters'))
            {
                for (let oFilter of json.filters)
                {
                    let multiButton = '';
                    if (oFilter.multiple)
                        multiButton = this.makeDrillMultipleToggle(oFilter.name);
                    htmlFilters += `<div><div><h4 style="display:inline-block">${oFilter.name_formatted}</h4>${multiButton}</div>${oFilter.html}</div>`;
                }

                this.jqFiltersList.html(htmlFilters);
                drnShowAndFadeIn(this.jqFilters);

                if (htmlFilters)
                    activateShowMoreButton('drn-show-hidden-filters', 'drn-hidden-filter');
            }

            /*
             *  Format buttons on top. The back-end had inserted an empty span with
             *  the '-formats' ID suffix, which we can replace.
             */
            let htmlFormats = '<span class="btn-group" role="group">';
            for (let oFormat of json.aFormats)
                htmlFormats += this.makeFormatButtonLink(json.format, oFormat);
            htmlFormats += '</span>';
            this.findDialogItem('formats').html(htmlFormats);
        }

        // Install click handlers for the pages in the pagination.
        this.jqResults.find('.pagination a').each((index, elm) => {
            let jqPageButton = $(elm);
            let href = jqPageButton.attr('href');
            let aPage;
            if ((aPage = /page=(\d+)/.exec(href)))
            {
                let page = aPage[1];
                jqPageButton.off();
                jqPageButton.on('click', (e) => {
                    e.preventDefault();
                    this.loadSubsequentPage(page);
                    return false;
                });
            }
        });
    }
}
