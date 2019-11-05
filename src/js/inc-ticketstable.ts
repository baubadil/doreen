/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import { AjaxTableBase } from './inc-ajaxtable';
import { GetTicketsApiResult } from './inc-api-types';
import { drnGetUrlParameterInt, drnGetUrlParameters } from './inc-globals';

/**
 *  TicketsTable is an abstract class that clients can derive from to have a table
 *  of tickets displayed. This inherits from AjaxTableBase but does not implement
 *  the required insertRow() method, so this is left to the derivative class.
 *
 *  Preconditions:
 *
 *   -- getURL is assumed to be a REST API string starting with `${g_rootpage}/api/`
 *      that yields Doreen ticket data in standard format, with the tickets array under
 *      'subkey'.
 *
 *   -- The getURL REST API must support a ?page=\d+ argument, which gets appended here
 *      for pagination support.
 *
 *   -- idParent must be the same HTML ID passed to addSuperTable() in the back-end
 *      (see AjaxTableBase).
 */
export default abstract class AjaxTicketsTable extends AjaxTableBase
{
    protected page: number;
    protected pageUrlKeyword: string;
    protected jsonData: GetTicketsApiResult;

    constructor(idParent: string,
                private apiEndpoint: string,         //!< in: key under which to find the tickets array in JSON results (e.g. 'results')
                initialPage: number = 1)
    {
        super(idParent);

        this.pageUrlKeyword = /\/([^\/]+)/.exec(this.apiEndpoint)[1];

        let temp = drnGetUrlParameterInt(this.pageUrlKeyword);
        if (temp !== null)
            initialPage = temp;

        this.setSpinner();
        this.loadPage(initialPage);
    }

    /**
     *  Newly introduced method to prepare the "prologue" that gets passed
     *  to this.fill(). The prologue is an arbitrary piece of text that gets
     *  printed before the actual table. This default implementation
     *  prepends nlsFoundMessage to the table but subclasses may want to
     *  override this.
     */
    protected makeTablePrologue(jsonData: GetTicketsApiResult)
    {
        return `<p>${jsonData.nlsFoundMessage}</p>`;
    }

    /**
     *  New method that processes the tickets data and calls the parent's fill().
     */
    protected onSuccess(jsonData: GetTicketsApiResult)
    {
        this.jsonData = jsonData;

        // Success:
        if (jsonData.cTotal)
        {
            let htmlPrologue = this.makeTablePrologue(jsonData);

            this.fill(jsonData.results,
                      jsonData.htmlPagination,
                      htmlPrologue);

            if (this.page != 1)
            {
                let stateObj = {};

                let mapGetParameters = drnGetUrlParameters();
                mapGetParameters[this.pageUrlKeyword] = this.page;
                history.replaceState(stateObj,
                                     `page ${this.page}`,
                                     this.buildURL(mapGetParameters));
            }
        }
        else if (jsonData.nlsFoundMessage)
            this._jqTarget.html(jsonData.nlsFoundMessage);
        else
            // Get rid of the spinner.
            this._jqTarget.html("No data");
    }

    private loadPage(page: number)
    {
        this.page = page;

        this.execGET(   `${this.apiEndpoint}?page=${page}`,
                        (jsonData) =>
                        {
                            this.onSuccess(jsonData);
                        });
    }

    protected onClickPage(page)
    {
        this.loadPage(page);
    }
}
