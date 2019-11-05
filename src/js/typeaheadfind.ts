/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import { ApiResult } from './inc-api-types';
import AutocompleteEntryFieldBase, { SearchSuggestion, AutocompleteResult } from './autocomplete';
import { drnFindByID } from './shared';

/**
 *  Result set returned by the GET /suggest-searches REST API.
 */
interface SuggestSearchesResponse extends ApiResult
{
    suggestions: SearchSuggestion[];
}

export class TypeAheadFind extends AutocompleteEntryFieldBase
{
    private currentValue: string;

    constructor(idTextEntry: string)
    {
        super(  idTextEntry,
                'suggest-searches',
                undefined,
                false);

        // Save the current value of the input field and assume that it is the
        // current search query.
        this.currentValue = this.jqEntryField.val();
    }

    public onSelect()
    {
        // Submit the form if a new value is selected.
        if (this.jqEntryField && this.jqEntryField.val() !== this.currentValue)
        {
            let jqForm = this.jqEntryField.parents('form');
            if (jqForm.length)
                jqForm.submit();
        }
    }

    public transformResult(response: AutocompleteResult, originalQuery: string)
    {
        // Filter the current query out of the suggestions.
        if (originalQuery === this.currentValue)
        {
            response.suggestions = response.suggestions.filter((suggestion) => suggestion.value !== originalQuery);
        }
        return response;
    }
}
