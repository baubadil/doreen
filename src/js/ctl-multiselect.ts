/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import { drnFindByID } from './shared';
import _ from './nls';

export function drnInitMultiSelect(id: string,
                            nlsNothingSelected: string,
                            manySelectedThreshold: number,
                            nlsManySelected: string)
{
    let jq = drnFindByID(id);
    jq.multiselect( {
       enableHTML: true,
       includeSelectAllOption: true,
       selectAllText: _('selectAll'),
       maxHeight: 400,
       enableCollapsibleOptGroups: true,
       enableClickableOptGroups: true,
       enableFiltering: true,
       enableCaseInsensitiveFiltering: true,
       templates: {
         filter: '<li class="multiselect-item multiselect-filter"><div class="input-group"><span class="input-group-addon"><i class="fa fa-search"></i></span><input class="form-control multiselect-search" type="text"></div></li>',
         filterClearBtn: '<span class="input-group-btn"><button class="btn btn-default multiselect-clear-filter" type="button"><i class="fa fa-times-circle-o"></i></button></span>'
       },
       buttonText: (options, select) =>
       {
           if (options.length === 0)
               return nlsNothingSelected;
           if (options.length > manySelectedThreshold)
               return nlsManySelected.replace('%SELECTED%', options.length);
           let labels = [];
           options.each(function()
                        {
                            if ($(this).attr('label') !== undefined)
                                labels.push($(this).attr('label'));
                            else
                                labels.push($(this).html());
                        });
           return labels.join(', ') + '';
       }
   } );
}
