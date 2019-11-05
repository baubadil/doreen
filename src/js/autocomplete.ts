/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

import {drnFindByID} from "core/shared";

/**
 *  One search suggestion. Part of the array in SuggestSearchesResponse.
 */
export interface SearchSuggestion
{
    v: string;
    id: number;
    score: number;
}

interface AutocompelteSuggestion
{
    data: number;
    value: string;
}

export interface AutocompleteResult
{
    suggestions: AutocompelteSuggestion[];
    status: string
}

/**
 *  Our own wrapper class around https://github.com/devbridge/jQuery-Autocomplete
 *
 *  This is an abstract class; implementations must derive from this and implement onSelect().
 *
 *  This generates HTML like this:
 *  ```HTML
             <div class="autocomplete-suggestions">
             <div class="autocomplete-group"><strong>NHL</strong></div>
             <div class="autocomplete-suggestion autocomplete-selected">...</div>
             <div class="autocomplete-suggestion">...</div>
             <div class="autocomplete-suggestion">...</div>
             </div>
 *  ```
 */
export default abstract class AutocompleteEntryFieldBase
{
    protected jqEntryField: JQuery;     // JQuery object of the entry field to modify

    constructor(protected idTextEntry: string,
                serviceURL: string,                 //!< in: URL for ajax query (without g_rootpage/api/ prefix)
                delimiter?: string|RegExp,          //!< in: Optional delemiter to allow multiple values
                triggerSelect = true)               //!< in: Optionally disable tirggering a select when the input matches a suggestion
    {
        this.jqEntryField = drnFindByID(idTextEntry)

        let opts = {
                       type: 'GET',
                       serviceUrl: `${g_rootpage}/api/${serviceURL}`,
                       paramName: 'q',
                       dataType: 'json',
                       noCache: true,
                       delimiter: delimiter,
                       triggerSelectOnValidInput: triggerSelect,
                       onSelect: this.onSelect.bind(this),
                       transformResult: this.transformResult.bind(this)
                   };
        (<any>this.jqEntryField).autocomplete(opts);
    }

    abstract onSelect();

    protected transformResult(response: AutocompleteResult, originalQuery: string): any
    {
        return response;
    }
}
