import * as $ from 'jquery';
import { Color, Options as VisOptions } from 'vis';
import _ from './nls';
import getIcon from './icon';

/*****************************
 *
 * Our one and only global variable
 *
 ****************************/

declare global {
    var g_globals: any;
    interface Window {
        g_globals: any;
    }
}

window.g_globals =
    {
        aDialogBackups: {},

        aHTMLEntities:
        {
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': '&quot;',
            "'": '&#39;'
        },

        aTypeaheadLastInputs: [],
        aTypeaheadSelections: [],

        fTemplatesLoaded: false
    };

/*****************************
 *
 *  Helpers
 *
 ****************************/

export function drnFindByID(id: string): JQuery
{
    var jq = $('#' + id);
    if (!jq.length)
        console.log('Cannot find element with id #' + id);
    return jq;
}

/**
 *  Returns true if the given jQuery object's CSS does not have display none set.
 */
export function drnIsVisible(jq: JQuery)
{
    return (jq.css('display') != 'none');
}

function countColumns(tableid: string): number      // must include '#'
{
    const tbl = $(tableid);
    const cols = tbl.find("tbody tr:first td");
    let cColumns = 0;
    for (var i = 0; i < cols.length; i++)
    {
        const colspan = parseInt(cols.eq(i).attr("colspan"), 10);
        if( colspan && colspan > 1)
            cColumns += colspan;
       else
            cColumns++;
    }
    return cColumns;
}

export function fillWholeTable(tableid: string,        // must include '#'
                        rpl: string)
{
    var colspan = countColumns(tableid);
    $(tableid + ' tbody').html('<tr><td colspan=' + colspan + ' align="center">' + rpl + '</td></tr>');
}

export function myEscapeHtml(str: string): string
{
    return String(str).replace(/[&<>"']/g,
                               (key) => g_globals.aHTMLEntities[key]);
}

export function testWhite(x: string): boolean
{
    const reWhite = new RegExp(/^\s$/);
    return reWhite.test(x.charAt(0));
};

/*
 * From http://stackoverflow.com/questions/14484787/wrap-text-in-javascript
 */
export function wordWrap(str: string,
                         width: number): string
{
    if (str.length>width)
    {
        let p = width;
        for (;
             p > 0 && str[p] != ' ';
             p--)
            ;

        if (p > 0)
        {
            const left = str.substring(0, p);
            const right = str.substring(p + 1);
            return left + "\n" + wordWrap(right, width);
        }
    }
    return str;
}

/*
 *  From http://stackoverflow.com/questions/14636536/how-to-check-if-a-variable-is-an-integer-in-javascript
 */
export function isInteger(value: any): boolean
{
    if (isNaN(value))
        return false;
    var x = parseFloat(value);
    return (x | 0) === x;
}

interface IntList
{
    [id: number]: boolean;
}

/*
 *  Takes as input a comma-separated list of numbers and returns an array where each such
 *  number is used as an index for a "true" value.
 *
 *  This is useful for things like group memberships. For example, if a list like "1,2,4"
 *  is thus converted, one can quickly check with array[4] wther '4' was in the list.
 */
export function splitIntoArray(commalist: string): IntList
{
    const aReturn = {};
    if (commalist)
    {
        const aSplitCommaList = commalist.split(',');
        $.map(aSplitCommaList, function(val)
        {
            // val has the gid string of which the user is a member
            const id = parseInt(val, 10);
            aReturn[id] = true;
        });
    }
    return aReturn;
}

/*
 *  From http://www.sitepoint.com/javascript-generate-lighter-darker-color/
 */
export function ColorLuminance(hex: string,            // in: color string (e.g. '#123456'; hash is optional
                        lum: number = 0)            // in: percentage to change (e.g. '-0.1' for '10% less')
{
    // validate hex string
    hex = String(hex).replace(/[^0-9a-f]/gi, '');
    if (hex.length < 6)
        hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];

    // convert to decimal and change luminosity
    let rgb = "#", c;
    for (let i = 0; i < 3; i++)
    {
        c = parseInt(hex.substr(i*2,2), 16);
        c = Math.round(Math.min(Math.max(0, c + (c * lum)), 255)).toString(16);
        rgb += ("00"+c).substr(c.length);
    }

    return rgb;
}

export function drnInitTooltip(t: string)
{
    $(t).tooltip();
    $(t).tooltip('show');
}

export function drnGetLinkColor(): string
{
    // There is a hidden link emitted by WholePage::EmitFooter() that allows us to detect the link color here.
    if (!g_globals.hasOwnProperty('linkColor'))
        g_globals.linkColor = $('#drn-testlink-for-css').css('color');
    return g_globals.linkColor;
}

export function drnShowAndFadeIn(jq: JQuery)
{
    jq.hide();
    jq.removeClass('hidden');
    jq.removeClass('hide');
    jq.fadeIn();
}

/*
 *  Creates a color object for VisJS with the given color values.
 */
export function makeVisJSColor(color: string,
                               hoverColor: string): Color
{
    return {   border: color
             , background: color
             , highlight: {   border: color
                            , background: color
                }
             , hover: hoverColor
           };
}

/**
 *  For each key passed in aKeys, the following must exist in oGraphColors:
 *
 *   -- border
 *   -- back
 *   -- fore
 */
export function drnInitGraphOptions(oOptions: VisOptions,       //!< in: Options object as used by VisJS
                                    oGraphColors: any,
                                    aKeys: string[])            //!< in: array of keywords which must have been set as keys in oGraphColors; these will become VisJS group names
    : VisOptions
{
    const jqBody = $('body');
    oOptions.groups = {};
    const fontFace = jqBody.css('font-family');
    const fontSizeStr = jqBody.css('font-size');
    const fontSize = fontSizeStr.substr(0, fontSizeStr.length - 2);        // this strips "px"
    for (const value of aKeys)
    {
        oOptions.groups[value] =
        {
            shape: 'box',
            shapeProperties: { borderRadius: 3 },       // default is 6
            color: makeVisJSColor(oGraphColors[value].back,
                                  drnGetLinkColor()),       // hover color
            font: {   color: oGraphColors[value].fore
                    , face: fontFace
                    , size: fontSize
                  }
        };
    }

    return oOptions;
}

/**
 *  Opens a new browser window about half the size of the user's desktop screen with the
 *  given HTML inside.
 *
 *  @param innerHTML HTML to insert into the body of the new window.
 */
export function openSecondaryWindow(url: string, innerHTML?: string)
{
    const cx = Math.round(screen.width / 2);
    const cy = Math.round(screen.height * 3 / 4);
    const x = Math.round((screen.width - cx) / 2);
    const y = Math.round((screen.height - cy) / 2);
    const win = window.open(url,
        "Title",
        [ "toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes",
            ", left=", x,
            ", width=", cx,
            ", height=", cy,
            ", top=", y
        ].join('')
    );
    if (innerHTML)
        win.document.body.innerHTML = innerHTML;
}


/*****************************
 *
 *  Dialog object
 *
 ****************************/

export function dlgFetchDataFields(that)
{
    for (const fieldid of that.fields)
    {
        // First, try to find the element by ID. This should work for everything but radio buttons.
        let jqElm = $('#' + that.idDialog + '-' + fieldid);
        if (jqElm.length)
        {
            let combine;
            if (jqElm.is('div') && jqElm.hasClass('btn-group'))
            {
                // button group => has checkboxes: build the value as a comma-separated list
                // of all the 'value' attributes of <input type="checkbox"> which have been checked
                const aValues = [];
                jqElm.find("input[type='checkbox']:checked").each(function(index, elm2)
                {
                    aValues.push(elm2.getAttribute('value')); // append the value to the array
                });
                that.data0[fieldid] = aValues.join();
            }
            else if (    jqElm.is('input')
                      && (combine = jqElm.attr('combineClass'))
                    )
            {
                var a = [];
                var llCheckboxes = $('#' + that.idDialog + ' .' + combine + ':checked');
                llCheckboxes.each(function(index, elm2)
                {
                    a.push(elm2.getAttribute('value')); // append the field ID to the array
                });
                that.data0[fieldid] = a.join();
            }
            else if (    jqElm.is('input')
                      && (jqElm.attr('type') == 'checkbox')
                    )
            {
                // Single traditional checkbox: put boolean true or false in JQUERY
                that.data0[fieldid] = (jqElm.prop('checked'));
            }
            else if (    (jqElm.is('input'))
                      || (jqElm.is('textarea'))
                    )
                that.data0[fieldid] = jqElm.val();         // both <input> and <textarea> need val(), not text()
            else if (jqElm.is('select'))
            {
                /* Create a comma-separated list of the value attributes of all selected <option> elements.
                   The following should work for both plain select/option pairs and the ones beefed up by
                   bootstrap-multiselect, as that updates the 'selected' field in the select/option it was
                   created from automatically.
                 */
                const aSelected = [];
                jqElm.find("option:selected").each(function(index, elm2)
                {
                    aSelected.push(elm2.getAttribute('value'));
                });
                // console.log(aSelected.join(','));
                that.data0[fieldid] = aSelected.join(',');
            }
            else
                that.data0[fieldid] = jqElm.text();        // or html() ?
        }
        else
        {
            // For radio buttons, which in our system don't have IDs but only names to name the group, try another way.
            /// http://stackoverflow.com/questions/9618504/get-radio-button-value-with-javascript
            jqElm = $('input[name="' + that.idDialog + '-' + fieldid + '"]:checked');
            if (jqElm.length)
                that.data0[fieldid] = jqElm.val();
        }
    }
}

/**
 *  Attempts to return an object with Doreen field and message information from the given AJAX response object.
 *
 * @param jqXHR
 * @returns {{field: *, htmlMessage: *}}
 */
export function drnExtractError(jqXHR: JQueryXHR)
{
    var field;
    var htmlMessage;
    var oJSON;
    if ('responseText' in jqXHR)
    {
        try
        {
            if (    (oJSON = JSON.parse(jqXHR.responseText))
                 && (oJSON.status == 'error')
               )
            {
                field = oJSON.field;
                htmlMessage = myEscapeHtml(oJSON.message);
            }
            else
                htmlMessage = myEscapeHtml(jqXHR.responseText);
        }
        catch(e)
        {
            htmlMessage = "Internal error &mdash; caught exception parsing JSON: " + myEscapeHtml(e) + "<br>" + "Data was: " + myEscapeHtml(jqXHR.responseText);
        }

        return {
            field: field,
            htmlMessage: htmlMessage
        };
    }

    return null;
}

export function dlgHandleError(idDialog: string, jqXHR: JQueryXHR)
{
    const oError = drnExtractError(jqXHR);
    if (oError)
    {
        var field;
        var htmlMessage;
        if (    (oError.hasOwnProperty('htmlMessage'))
             && (htmlMessage = oError.htmlMessage)
           )
        {
            var jqPositionNextTo;
            if (    (oError.hasOwnProperty('field'))
                 && (field = oError.field)
                 && (jqPositionNextTo = $('#' + idDialog + '-' + field))
                 && (jqPositionNextTo.length)
                // Do not show the popover if the element is hidden.
                 && (!jqPositionNextTo.hasClass('hidden'))
                 && (!jqPositionNextTo.hasClass('hide'))
               )
            {
                /* The datetimepicker thingy uses an <input type=hidden> for the #field ID, which we cannot
                   position things next ... use the parent DIV then. */
                if (    ('type' in jqPositionNextTo[0])
                     && (jqPositionNextTo[0].type == 'hidden')
                   )
                    jqPositionNextTo = jqPositionNextTo.parent();

                // Scroll the offending element into view.
                var scrollContainer = jqPositionNextTo.closest('.modal');
                if (!scrollContainer)
                    scrollContainer = $('html, body');

                var fromTop = jqPositionNextTo.offset().top;
                var windowOffset = scrollContainer.scrollTop();
                if (fromTop < windowOffset || fromTop > windowOffset + $(window).height())
                    scrollContainer.animate({
                        scrollTop: fromTop
                    }, 500);

                jqPositionNextTo.popover( { placement: 'bottom',
                                            title: function()
                                            {
                                                return '<b>' + _('error') + '</b>' + '<span class="close">&times;</span>';
                                            },
                                            html: true,
                                            content: htmlMessage,
                                            trigger: 'manual'
                                          } ).on('shown.bs.popover', function(e)
                                                {
                                                    var jqPopover = $(this);
                                                    jqPopover.parent().find('div.popover .close').on('click', function(e){
                                                        jqPopover.popover('hide');
                                                    });
                                                });
                $(jqPositionNextTo).popover('show');
            }
            else
                dlgShowError(idDialog, htmlMessage);
        }
    }
}

export function dlgShowError(idDialog: string,
                      htmlMessage: string)
{
    const errorfield = $('#' + idDialog + ' .drn-error-box:first');       // :first in case there are several
    if (errorfield.length)
    {
        errorfield.children().first()   // first() is the <p> element
                .html("<b>" + _('error') + ":</b> " + htmlMessage);
        errorfield.removeClass('hidden');
        errorfield.fadeIn();
    }
}

export function dlgBackupRestore(jqDialog: JQuery)
{
    const idDialog = jqDialog.attr('id');
    // Since we might modify the HTML, make a backup of it and
    // restore it on every call. Otherwise the dialog will look
    // different on every call. Use the global g_globals object for
    // backing up in order not to pollute the global namespace.
    if (!g_globals.aDialogBackups[idDialog])
    // first call for this dialog: then back up the strings
        g_globals.aDialogBackups[idDialog] = jqDialog.html();
    else
    // subsequent calls: restore the HTML from the backups
        jqDialog.html(g_globals.aDialogBackups[idDialog]);
}

/**
 *  Calls the given callback with the JSON from the /progress API. The first parameter indicates success as a bool.
 */
export function getProgress(rootpage,
                            session_id: string,
                            fn: Function)
{
    $.ajax(
    {
        type: 'GET',
        url: g_rootpage + '/api/progress/' + session_id,
        success: function(json)
        {
            fn(true, json);
        },
        error: function(jq)
        {
            fn(false, jq.responseJSON);
        }
    });
}

export function prepareProgress()
{
    if (!g_globals.hasOwnProperty('progressIntervals'))
    {
        g_globals.progressIntervals = {};
        g_globals.progressButtons = {};
    }
}

/*
 *  Sets up a timer (window interval) to report progress on the given dialog.
 *  The given dialog must have '-save' and '-progress' children.
 */
export function reportProgress(idDialog: string,           // in: dialog ID (without '#')
                               idSession: string,          // in: ID of asynchronous session as returned from server JSON API (for callbacks)
                               jqSaveBtn: JQuery,          // in: jQuery object for "save" button or NULL
                               htmlRunning: string,        // in: HTML text to set on button (spinner will be added; only if jqSaveBtn != NULL)
                               intervalMS: number,         // in: how often to call the progress callback in milliseconds (e.g. 500)
                               pfnProgress: Function)        // in: secondary callback for progress report (optional)
{
    prepareProgress();

    const jqProgressBar = $('#' + idDialog + ' .progress');

    // Back up the button text.
    if (jqSaveBtn)
        g_globals.progressButtons[idDialog] = jqSaveBtn.html();

    $('#' + idDialog + ' .progress-bar').css({
        width: "0%"
    });
    jqProgressBar.removeClass('hidden');
    if (jqSaveBtn)
    {
        jqSaveBtn.removeClass("btn-success");
        jqSaveBtn.addClass("btn-primary");
        jqSaveBtn.html(htmlRunning + '&nbsp;' + getIcon('spinner'));
    }

    // Every intervalMS ms, query the server via the /progress API (the getProgress() function does that).
    g_globals.progressIntervals[idDialog] = window.setInterval(function()
    {
        getProgress(g_rootpage,
                    idSession,
                    function(fSuccess, json)
                    {
                        if (fSuccess)
                        {
                            const jqEl = $('#' + idDialog + ' .progress-bar');
                            jqEl.css('width', json.progress + "%");
                            jqEl.html('%PERC%% (%CUR%&nbsp;/ %TOTAL%)'
                                .replace('%PERC%',  json.progress)
                                .replace('%CUR%',   json.cCurrentFormatted)
                                .replace('%TOTAL%', json.cTotalFormatted));
                            if (json.progress == 100)
                            {
                                window.clearInterval(g_globals.progressIntervals[idDialog]);
                                if (jqSaveBtn)
                                {
                                    jqSaveBtn.html('Done!');
                                    jqSaveBtn.removeClass("btn-primary");
                                    jqSaveBtn.addClass("btn-success");
                                }
                                if (!g_globals.hasOwnProperty('reportProgressNotFinal'))
                                    window.setTimeout(function()
                                    {
                                        if (jqSaveBtn)
                                        {
                                            jqSaveBtn.html(g_globals.progressButtons[idDialog]);
                                            jqSaveBtn.removeClass("btn-success");
                                            jqSaveBtn.addClass("btn-primary");
                                            jqSaveBtn.removeAttr('disabled');
                                        }
                                        // jqProgressBar.addClass('hidden');
                                    }, 500);
                            }
                            if (pfnProgress)
                                pfnProgress(json);
                        }
                        else
                        {
                            dlgShowError(idDialog, myEscapeHtml(json.message));
                            window.clearInterval(g_globals.progressIntervals[idDialog]);
                            if (jqSaveBtn)
                                jqSaveBtn.html(getIcon('crash'));
                        }
                    });
    }, intervalMS);
}

/**
 *  Helper for drnInitFadeOutReadMores which can be called independently.
 *
 * @param jqSet
 */
export function drnInitFadeOutReadMores2(jqSet: JQuery)        //!< in: JQuery set of buttons that should have handlers attached
{
    jqSet.click(function()
    {
        var totalHeight = 10;

        var jqButton = $(this);
        var jqReadMorePWithButton  = jqButton.parent();
        var jqUp = jqReadMorePWithButton.prev();
        if (jqUp.hasClass('drn-clipped-content'))
        {
            var jqAllExpanded = jqUp.children();

            // measure how tall inside should be by adding together heights of all inside paragraphs (except read-more paragraph)
            jqAllExpanded.each(function()
            {
                totalHeight += $(this).outerHeight(true);       // include margin
            });

            jqUp.css( {   // Set height to prevent instant jumpdown when max height is removed
                         "height": jqUp.height(),
                         "max-height": 9999 })
                .animate( {
                            "height": totalHeight
                        });

            // fade out read-more
            jqReadMorePWithButton.fadeOut();
        }

        // prevent jump-down
        return false;
    });
}

/**
 *  Courtesy of https://css-tricks.com/text-fade-read-more/ with repairs.
 */
export function drnInitFadeOutReadMores()
{
    drnInitFadeOutReadMores2($(".drn-read-more button"));
}

/**
 *  Little snippet that enables Bootstrap popovers.
 */
export function drnEnablePopovers(jq?: JQuery)
{
    if (jq && jq.length)
        jq.find('[data-toggle="popover"]').popover();
    else
        $('[data-toggle="popover"]').popover();
}
