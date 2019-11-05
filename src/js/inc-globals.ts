/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import { ProgressData } from './inc-api-types';
import _ from './nls';

declare global
{
    const g_rootpage: string;
    const g_adminLevel: number;   // 0 = user, 1 = guru, 2 = admin

    // Extend JQuery type with plugin methods that don't have typings.
    interface JQuery
    {
        multiselect(argsOrId, args?): JQuery;
        stupidtable(): JQuery;
        bsPhotoGallery(options): JQuery;
    }
}

export function drnShowBusyCursor(fBusy: boolean)
{
    $("body").css("cursor",
                  fBusy ? "progress" : "default");
}

export function drnMakeTooltipAttrsT(title: string,
                              align = "auto")       // or "auto right"
    : string
{
    return `data-toggle="tooltip" data-placement="${align}" title="${title}"`;
}

/*
 *  Takes as input a comma-separated list of numbers and returns an array where each such
 *  number is used as an index for a "true" value. A bit like PHP explode().
 *
 *  This is useful for things like group memberships. For example, if a list like "1,2,4"
 *  is thus converted, one can quickly check with array[4] wther '4' was in the list.
 */
export function drnExplode(commalist: string): any
{
    let oReturn = {};
    if (commalist)
    {
        let aSplitCommaList = commalist.split(',');
        for (let val of aSplitCommaList)
            // if (aSplitCommaList.hasOwnProperty(val))
        {
            let key = parseInt(val, 10);
            oReturn[key] = true;
        }
    }

    return oReturn;
}

/**
 *  Adds the given CSS animation (https://github.com/daneden/animate.css) to the given
 *  JQuery set and removes it again when the animation has elapsed. The removal is necessary
 *  because otherwise the animation can be replayed if the element is hidden and exposed again.
 */
export function drnAnimateOnce(jq: JQuery,
                        cls: string,
                        pfnComplete: () => void = null)
    : void
{
    jq.addClass('animated ' + cls);
    jq.one('webkitAnimationEnd mozAnimationEnd MSAnimationEnd oanimationend animationend', () => {
        jq.removeClass('animated ' + cls);
        if (pfnComplete)
            pfnComplete();
    });
}

/**
 *  Escapes a string to be safe for usage inside an RegExp.
 */
export function drnEscapeForRegExp(str: string)
    : string
{
  return str.replace(/[\[\]\\{}().*+?^$|-]/g, "\\$&");
}

/**
 *  Returns an object with key/value pairs representing the URL's
 *  GET parameters (everything after the "?" separator, where
 *  multiple parameters are separated by "&").
 *
 *  Courtesy of https://stackoverflow.com/questions/5448545/how-to-retrieve-get-parameters-from-javascript
 */
export function drnGetUrlParameters()
    : any
{
    let mapGetParameters = {};
    location.search
            .substr(1)
            .split("&")
            .forEach(function (item) {
                const [ name, value ] = item.split("=");
                mapGetParameters[name] = decodeURIComponent(value);
            });
    return mapGetParameters;
}

export function drnIsInteger(value)
    : boolean
{
    return (    !isNaN(value)
             && parseInt(value) == value
             && !isNaN(parseInt(value, 10))
           );
}

/**
 *  Helper around drnGetUrlParameters() that looks up the given key
 *  in the URL parameters and also tests whether the value is an integer.
 *  In that case the number is returned; otherwise this returns null.
 */
export function drnGetUrlParameterInt(key: string)
    : number | null
{
    let mapGetParameters = drnGetUrlParameters();
    if (mapGetParameters.hasOwnProperty(key))
    {
        let temp = mapGetParameters[key];
        if (drnIsInteger(temp))
            return temp;
    }

    return null;
}

/**
 *  Helper to properly enable or disable a button. This requires BOTH setting the attribute
 *  and the bootstrap class.
 */
export function drnEnableButton(jq: JQuery,
                         f: boolean)
    : void
{
    if (f)
    {
        jq.removeAttr('disabled');
        jq.removeClass('disabled');
    }
    else
    {
        jq.attr('disabled', 'disabled');
        jq.addClass('disabled');
    }
}

export function drnMakeButtonDone(jq: JQuery)
{
    jq.html(_('done'));
    jq.removeClass("btn-primary");      // in case it was there
    jq.addClass('btn-success');
}

export function drnUpdateProgressBar(jqProgress: JQuery,
                              json: ProgressData)
    : void
{
    let progress: string;
    if (!json.cTotal)
        progress = "0";
    else
    if (json.cCurrent >= json.cTotal)
        progress = "100";
    else
        progress = Math.floor(json.cCurrent * 100 / json.cTotal).toString();

    jqProgress.css('width', progress + "%");
    jqProgress[0].innerHTML = '%PERC%% (%CUR%&nbsp;/ %TOTAL%)'.replace('%PERC%',  progress.toString())
                                                              .replace('%CUR%',   json.cCurrentFormatted)
                                                              .replace('%TOTAL%', json.cTotalFormatted);
}
