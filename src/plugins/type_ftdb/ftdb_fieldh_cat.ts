/*
*  Copyright 2015-18 Baubadil GmbH. All rights reserved.
*/
import { drnShowAndFadeIn } from "../../js/shared";

export function onCategoryClicked(jqThis: JQuery,
                                  ftExtraIcons: string[])
{
    // jqThis is the <a> the user clicked on. The parent is a <li> which has a <ul> child that may or may not be hidden.
    let jqParentLI = jqThis.parent();
    // console.log(jqParentLI);
    // jqThis.removeClass('hidden');
    let jqAdjacentUL = jqParentLI.find('div:first');
    if (jqAdjacentUL.length)
    {
        if (jqAdjacentUL.hasClass('hidden'))
        {
            drnShowAndFadeIn(jqAdjacentUL);
            jqThis.html(ftExtraIcons['minus-square-o']);
        }
        else
        {
            jqAdjacentUL.addClass('hidden');
            jqThis.html(ftExtraIcons['plus-square-o']);
        }
    }
}
