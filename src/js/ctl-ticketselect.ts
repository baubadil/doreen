/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import DrnSelect2, { DrnSelect2Result, DrnSelect2ResultSet } from './ctl-select2';
import APIHandler from './inc-apihandler';

class TicketPickerResult extends DrnSelect2Result
{
    href: string;
    icon: string;
    nlsFlyOver: string;
}

/**
 *  Helper class around a select2 select/option created by the PHP back-end HTMLChunk::addTicketPicker()
 *  function. An instance of this is created by the core_initTicketPicker() entry point.
 *
 *  On creation, this calls select2 on the HTML entry field specified by _id and initializes it with
 *  a ton of parameters to connect it to the Doreen GET /api/tickets REST API.
 *
 *  extraQuery can contain an extra URL-encoded parameter to restrict what kinds of tickets are
 *  searched for the fulltext from the select2 control. This is passed with the
 *  GET /api/tickets REST call. If not empty, it must start with a '?' and have several filters
 *  separated by '&', like '?type=5'. The control will then add a 'fulltext=' parameter with the
 *  user's search input.
 *
 *  On submit, the ticket ID is submitted as a '#' plus the ticket ID.
 */
export class TicketPicker extends DrnSelect2
{
    constructor(id: string,            //!< in: ID of <select> element, without leading '#'
                cMinimumChars: number,
                private _cItemsPerPage: number, //!< in: how many items to display per page (must be from Globals)
                extraQuery: string = '',        //!< in: extra parameters for GET /api/tickets; if not empty, must start with '?' and be URL-encoded
                private aTitleFields: string[] = [])
    {
        super(id,
              `/tickets${extraQuery}`,
              cMinimumChars);
    }

    /**
     *  Handler for the processResults() callback. This gets the raw JSON from
     *  the Doreen back-end and must produce 'id' and 'text' items for the
     *  <option> element in select2.
     */
    protected processResults(data, params)
        : DrnSelect2ResultSet
    {
        params.page = params.page || 1;

        let aResults: TicketPickerResult[] = [];
        if ('results' in data)
            for (let oTicket of data.results)
            {
                aResults.push(
                {
                    id: '#' + oTicket.ticket_id,
                    text: this.aTitleFields.map((f) => oTicket[f]).join(' &mdash; '),
                    href: oTicket.href,
                    icon: oTicket.icon,
                    nlsFlyOver: oTicket.nlsFlyOver
                } );
            }

        return {
            results: aResults,
            pagination: {
                more: (params.page * this._cItemsPerPage) < data.cTotal
            }
        };
    }

    /**
     *  Handler for the templateSelection() callback. This can format what appears
     *  in the entry field after the user has selected something from the drop-down.
     *
     *  'data' is one item as returned from processResults(), so if we need a field
     *  from the JSON ticket data, we must copy it over in processResults() first.
     */
    protected templateSelection(data: any)
        : string
    {
        if (data.id)
        {
            if (!data.href)
            {
                data.href = data.element.hasAttribute("data-href") ? data.element.getAttribute("data-href") : '/ticket/' + data.id.substr(1);
                data.nlsFlyOver = data.element.title || data.text;
                data.icon = data.element.getAttribute("data-icon");
            }
            // Only do this if we have an ID. Otherwise we'll mess up the placeholder as a broken link.
            return APIHandler.MakeTicketLink(data,
                                             data.text,
                                             true)
        }

        return data.text;
    }

}

