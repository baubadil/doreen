/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import { drnFindByID } from './shared';
import _ from './nls';

export class DrnSelect2Result
{
    id: string;
    text: string;
    loading?: boolean;
    disabled?: boolean;
}

export class DrnSelect2ResultSet
{
    results: DrnSelect2Result[];
    pagination: { more: boolean; };
}

export interface DrnSelect2Params
{
    term: string;
}

/**
 *  Wrapper class around the select2 control. Our TicketPicker class derives from this.
 *
 *  This just adds some type-safety to the required callbacks for fetching data for the
 *  control via Doreen AJAX REST APIs. Subclasses must implement the required abstract methods.
 */
export default abstract class DrnSelect2
{
    protected readonly jqSelect;

    protected nlsTooShort: string;

    /**
     *  This takes the DOM ID of a <select> element and invokes select2() on it to convert it
     *  into an AJAX control that invokes the given URL for searching.
     */
    constructor(idSelect: string,              //!< in: ID of <select> element, without leading '#'
                url_,                          //!< in: Doreen API url without /api but with leading slash
                cMinimumChars: number,
                width: string = 'resolve',
                tagSeparators: string[] = [])

    {
        this.jqSelect = drnFindByID(idSelect);

        this.nlsTooShort = _('select2-too-short', { MIN: cMinimumChars.toString() });

        if (width === 'resolve')
            this.jqSelect.css({ width: '100%' });

        this.jqSelect.select2(
            {
                placeholder: this.nlsTooShort,
                allowClear: true,
                ajax:
                {
                    url: `${g_rootpage}/api${url_}`,
                    method: 'GET',
                    dataType: 'json',
                    delay: 250,
                    // The function given with the 'data' option gets called by select2 to produce the search query.
                    data: (params: any) =>
                    {
                        // Inserting the extra filters here doesn't work, so we do it in the query string above.
                        let fHasStarsOrSpaces = /(?:\s+|\*+)/.exec(params.term);
                        let term = params.term;
                        if (!fHasStarsOrSpaces)
                            term += '*';
                        return {
                            fulltext: term, // search term
                            page: params.page
                        };
                    },
                    processResults: (data, params) => this.processResults(data, params),
                    // cache: true
                },
                // Override the default HTML-escaper because our routines already create valid HTML.
                escapeMarkup: (markup) => markup,
                minimumInputLength: cMinimumChars,
                // The following formats results in the drop-down.
                templateResult: (data) => this.templateResult(data),
                // The following formats what the user selected.
                templateSelection: (data) => this.templateSelection(data),
                language:
                {
                    errorLoading: () => _('select2-error'),
                    inputTooShort: (args) => this.nlsTooShort,
                    loadingMore: () => _('select2-searching-more'),
                    noResults: () => _('select2-nothing-found'),
                    searching: () => _('select2-searching')
                },
                width: width,
                tags: tagSeparators.length > 0,
                tokenSeparators: tagSeparators,
                createTag: (data) => this.createTag(data)
             } );
    }

    /**
     *  Handler for the processResults() callback. This gets the raw JSON from
     *  the Doreen back-end and must produce 'id' and 'text' items for the
     *  <option> element in select2.
     */
    protected abstract processResults(data,
                                      params: DrnSelect2Params)
        : DrnSelect2ResultSet;

    /**
     *  Handler for the templateSelection() callback. This can format what appears
     *  in the entry field after the user has selected something from the drop-down.
     *
     *  'data' is one item as returned from processResults(), so if we need a field
     *  from the JSON ticket data, we must copy it over in processResults() first.
     */
    protected abstract templateSelection(data: DrnSelect2Result)
        : string;

    /**
     *  Handler for the templateResult() callback. By default return the text
     *  property of data. This must return validly escaped HTML!
     */
    protected templateResult(data: DrnSelect2Result)
        : string
    {
        return data.text;
    }

    /**
     *  Handler for the createTag callback. This can reject user-created tags to
     *  be invalid and add additional parameters. The value can be found as "term"
     *  property on the params input. The default implementation rejects all
     *  user-created tags.
     */
    protected createTag(params: DrnSelect2Params): DrnSelect2Result | null
    {
        return null;
    }

    /**
     *  Destroys this select2 instance.
     */
    public destroy()
    {
        this.jqSelect.select2('destroy');
    }
}
