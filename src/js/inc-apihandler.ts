/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as ClipboardJS from 'clipboard';
import SessionManager from './sessionmanager';
import {
    ITicketCore,
    ProgressData,
    ProgressDataResult,
    GetUsersApiResult,
    GetAllTemplatesApiResult,
} from './inc-api-types';
import { myEscapeHtml, drnFindByID } from './shared';
import { drnUpdateProgressBar, drnMakeButtonDone } from './inc-globals';
import _ from './nls';
import getIcon from './icon';
import {Trigger} from "bootstrap";

/**
 *  Base class for anything that uses Ajax requests in Doreen. This is very abstract and doesn't require anything
 *  visible on the screen, but FormBase (and thus AjaxForm and AjaxModal) derive from this.
 *
 *  This class has been separated out because some Doreen classes that are NOT forms also use this functionality,
 *  most importantly AjaxTableBase (and thus most Ajax tables in Doreen).
 */
export default class APIHandler
{
    protected jqShowingErrorPopover = null;

    protected jqClickOnError: JQuery = null;

    constructor()
    {
    }

    /**
     *  Attempts to return an object with Doreen field and message information from the given AJAX response object.
     *
     * @param jqXHR
     * @returns {{field: *, htmlMessage: *}}
     */
    protected extractError(jqXHR: JQueryXHR)
    {
        let field;
        let htmlMessage;
        let oJSON;
        if (jqXHR.hasOwnProperty('responseText'))
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

    public showErrorPopover(jqPositionNextTo: JQuery,
                            htmlMessage: string,
                            trigger: Trigger = 'manual')         // or 'focus'
    {
        if (this.jqShowingErrorPopover)
        {
            return this.jqShowingErrorPopover.one('hidden.bs.popover', () => {
                // Defer the action a little
                setTimeout(() => this.showErrorPopover(jqPositionNextTo, htmlMessage, trigger), 0);
            });
        }
        jqPositionNextTo.popover( { placement: 'bottom',
                                    title: () => `<b>${_('error')}</b>` + '<span class="close">&times;</span>',
                                    html: true,
                                    content: htmlMessage,
                                    trigger: trigger
                                  } ).one('shown.bs.popover', function(e)
                                        {
                                            let jqPopover = $(this);
                                            jqPopover.parent().find('div.popover .close').on('click', function(e){
                                                jqPopover.popover('hide');
                                            });
                                        }).one('hidden.bs.popover', () => {
                                            this.jqShowingErrorPopover = null;
                                        });
        jqPositionNextTo.popover('show');
        this.jqShowingErrorPopover = jqPositionNextTo;
    }

    public hideErrorPopover()
    {
        if (this.jqShowingErrorPopover)
            this.jqShowingErrorPopover.popover('destroy');
    }

    /**
     *  This can be called by TabbedPage to make sure that a tabbed page is switched
     *  to automatically when an error occurs. showError(), which fills the Doreen error
     *  box with an error message and shows it, additionally clicks on the element whose
     *  ID is specified here if this was called. The ID given here should be the link
     *  on the tab.
     */
    public setClickOnError(id: string)          //!< in: element ID (without leading '#')
    {
        this.jqClickOnError = drnFindByID(id);
    }

    protected handleError(jqDialog: JQuery,
                          jqXHR)
    {
        let oError = this.extractError(jqXHR);
        if (oError)
        {
            if (oError.hasOwnProperty('htmlMessage'))
            {
                let jqPositionNextTo;
                let idDialog = jqDialog.attr('id');
                if (    (oError.hasOwnProperty('field'))
                     && (idDialog)
                     && (jqPositionNextTo = $('#' + idDialog + '-' + oError.field))
                     && (jqPositionNextTo.length)
                     && (jqPositionNextTo.is(':visible'))   // Do not show the popover if the element is hidden.
                   )
                {
                    /* The datetimepicker thingy uses an <input type=hidden> for the #field ID, which we cannot
                       position things next ... use the parent DIV then. */
                    if (    ('type' in jqPositionNextTo[0])
                         && (jqPositionNextTo[0].type == 'hidden')
                       )
                        jqPositionNextTo = jqPositionNextTo.parent();

                    this.showErrorPopover(jqPositionNextTo, oError.htmlMessage);
                }
                else
                    this.showError(jqDialog, oError.htmlMessage);
            }
        }
    }

    protected showError(jqDialog: JQuery,
                        htmlMessage: string)
    {
        // Assume all errors with an expired session are due to the session.
        if (SessionManager.isSessionExpired())
            SessionManager.onSessionError();
        else
        {
            const errorfield = jqDialog.find('.drn-error-box:first');       // :first in case there are several
            if (!errorfield.length)
                throw "Cannot find error box for error " + htmlMessage;
            else
            {
                errorfield.children().first()   // first() is the <p> element
                        .html(`<strong>${g_nlsStrings['error']}:</strong> ${htmlMessage}`);
                errorfield.removeClass('hidden');
                errorfield.fadeIn();

                // Show the tabbed page, if setClickOnError() was called.
                if (this.jqClickOnError.length)
                        this.jqClickOnError.trigger('click');
            }
        }
    }

    protected hideError(jqDialog: JQuery)
    {
        const errorfield = jqDialog.find('.drn-error-box:first');
        if (errorfield.length)
        {
            errorfield.hide();
            errorfield.addClass('hidden');
        }
    }

    protected onError(jqXHR: JQueryXHR)
    {
        let oError = this.extractError(jqXHR);
        alert(oError.htmlMessage);
    }

    /**
     *  Returns the "base" of the current URL, which is everything before
     *  the GET parameters separator ("?", if present).
     */
    protected getURLBase()
    {
        return /([^\?]+)/.exec(window.location.href)[1];
    }

    /**
     *  Builds a new URL string with the given GET parameters.
     *  This is best used by first getting the current parameters
     *  via \ref getURLParameters(), setting/changing keys in that
     *  object, and then passing it to this function.
     */
    protected buildURL(mapGetParameters: any)
    {
        let url = this.getURLBase();
        let aPairs: string[] = [];
        for (let key in mapGetParameters)
            if (mapGetParameters.hasOwnProperty(key))
                aPairs.push(key + '=' + mapGetParameters[key]);
        return url + "?" + aPairs.join('&');
    }

    /**
     *  Executes an AJAX GET request. url should NOT include g_rootpage or /api, but start
     *  with a slash, the command and the request parameters.
     *
     * @param url
     * @param fnSuccess: success handler, which must have a single 'json' argument, which can be typecast to a JSON subclass.
     */
    public execGET(url: string,             //!< in: /command URL
                   fnSuccess: (data: any) => void)
    {
        $.get(`${g_rootpage}/api${url}`,
              fnSuccess)
         .fail( (jqXHR, textStatus, errorThrown) =>
                {
                    this.onError(jqXHR)
                });
    }

    /**
     *  Executes an AJAX POST request. url should NOT include g_rootpage or /api, but start
     *  with a slash, the command and the request parameters.
     *
     * @param url
     * @param data
     * @param fnSuccess: success handler, which must have a single 'json' argument, which can be typecast to a JSON subclass.
     */
    public execPOST(url: string,            //!< in: /command URL without /api
                    data,
                    fnSuccess: (data: any) => void)
    {
        $.post(`${g_rootpage}/api${url}`,
               JSON.stringify(data),
               fnSuccess)
         .fail( (jqXHR, textStatus, errorThrown) =>
                {
                    this.onError(jqXHR);
                });
    }

    public execPut(url: string,            //!< in: /command URL without /api
                   data,
                   fnSuccess: (data: any) => void)
    {
        $.ajax({
                    type: 'PUT',
                    url: `${g_rootpage}/api${url}`,
                    contentType: 'application/json',    // What format data sent to the server is in. Default is urlencoded.
                    dataType: 'json',                   // What format the data in 'data' is in.
                    data: JSON.stringify(data),
                    success: fnSuccess,
               })
            .fail((jqXHR, textStatus, errorThrown) =>
                  {
                      this.onError(jqXHR)
                  });
    }

    public execDelete(url: string,
                      fnSuccess: (data: any) => void,
                      async: boolean = true)
    {
        $.ajax({
            type: 'DELETE',
            url: `${g_rootpage}/api${url}`,
            success: fnSuccess,
            async: async
        })
            .fail((jqXHR) => {
                this.onError(jqXHR);
            });
    }

    protected _htmlSubmitBackup: string;

    protected getSpinner(jqEl: JQuery): JQuery | null
    {
        return jqEl.find("." + g_globals.cssSpinnerClass);
    }

    protected buttonShowSpinner(jqSubmitButton: JQuery)
    {
        const spinner = this.getSpinner(jqSubmitButton);
        if (!spinner.length)
            jqSubmitButton.html(getIcon('spinner'));
        else
            spinner.show();
    }

    protected buttonHideSpinner(jqSubmitButton: JQuery)
    {
        const spinner = this.getSpinner(jqSubmitButton);
        if (spinner.length)
            spinner.hide();
    }

    protected buttonSubmitting(jqSubmitButton: JQuery)
    {
        this._htmlSubmitBackup = jqSubmitButton.html();
        this.buttonShowSpinner(jqSubmitButton);
        jqSubmitButton.addClass('disabled');
        jqSubmitButton.prop('disabled', true);
    }

    protected buttonSubmitSuccess(jqSubmitButton: JQuery)
    {
        jqSubmitButton.removeClass("btn-primary");
        jqSubmitButton.addClass("btn-success");
        this.buttonHideSpinner(jqSubmitButton);
        jqSubmitButton.text("OK!");
    }

    protected buttonSubmitRestore(jqSubmitButton: JQuery)
    {
        jqSubmitButton.html(this._htmlSubmitBackup);
        jqSubmitButton.removeClass('disabled');
        jqSubmitButton.removeClass("btn-success");
        this.buttonHideSpinner(jqSubmitButton);
        jqSubmitButton.addClass("btn-primary");
        jqSubmitButton.prop('disabled', false);
    }

    /**
     *  Generic API handler, which doees the following:
     *
     *   1) call buttonSubmitting() on the given submit button to turn it into a spinner;
     *
     *   2) execute the HTTP request with the given method, URL and data;
     *
     *   3) call either onSubmitSuccess() or onSubmitError, which the caller should override.
     *
     *  At this time the oData member already has the fresh data from the form.
     */
    public execAjax(jqSubmitButton: JQuery,
                    method: string,
                    URL: string,
                    oData: any)                     //!< in: object with key/value pairs, will be stringified
    {
        this.buttonSubmitting(jqSubmitButton);
        this.hideErrorPopover();

        let oAjax = {
            type: method,
            url: URL,
            contentType: 'application/json',    // What format data sent to the server is in. Default is urlencoded.
            dataType: 'json',                   // What format the data in 'data' is in.
            data: JSON.stringify(oData),
            success: (json) => {
                this.buttonSubmitSuccess(jqSubmitButton);
                window.setTimeout(() => {
                    this.onSubmitSuccess(json);
                }, 500);
            },
            error: (jqXHR) => {
                this.buttonSubmitRestore(jqSubmitButton);
                this.onSubmitError(jqXHR);
            },
        };
        $.ajax(oAjax);
    }

    /**
     *  Gets called by execAjax() with a short delay after the ajax call succeeded. This
     *  default implementation does nothing, but should be overridden by derived classes.
     *
     *  At this time the oData member already has the fresh data from the form. json
     *  is probably compatible with APIResult, but this not guaranteed with older Doreen APIs.
     */
    protected onSubmitSuccess(json)
        : void
    {
    }

    /**
     *  Gets called by execAjax() with a short delay after the ajax call failed. This
     *  default implementation does nothing, but should be overridden by derived classes.
     */
    protected onSubmitError(jqXHR: JQueryXHR)
    {
    }

    protected _jqProgressBar;
    protected _idJSProgressInterval: number = null;
    protected _saveButtonID = null;

    protected _idSession: number = null;

    public startProgressMonitor(idDialog: string,
                                session_id: number,
                                saveButtonSuffix: string = null)
    {
        this._idSession = session_id;

        if (this._idJSProgressInterval)
            this.stopProgressMonitor();

        this._jqProgressBar = drnFindByID(idDialog + ' .progress-bar');
        this._idJSProgressInterval = window.setInterval(() =>
                                                        {
                                                            this.getOneProgress();
                                                        }, 500);

        if (saveButtonSuffix)
            this._saveButtonID = idDialog + '-' + saveButtonSuffix;
    }

    protected stopProgressMonitor()
    {
        window.clearInterval(this._idJSProgressInterval);
        this._idJSProgressInterval = null;
        this._idSession = null;
    }

    /**
     *  Updates the progress bar whose JQuery is in this._jqProgress with the given progress data.
     */
    protected onProgressSuccess(json: ProgressData)
    {
        drnUpdateProgressBar(this._jqProgressBar, json);

        if (json.fDone)
        {
            this.stopProgressMonitor();

            if (this._saveButtonID)
                drnMakeButtonDone(drnFindByID(this._saveButtonID));
        }
    }

    protected getOneProgress()
    {
        $.get(  `${g_rootpage}/api/progress/${this._idSession}`,
                (json: ProgressDataResult) =>
                {
                    this.onProgressSuccess(json);
                })
            .fail( (jqXHR: JQueryXHR, textStatus, errorThrown) =>
                   {
                        this.stopProgressMonitor();
                        if (jqXHR.status != 404)
                            this.onError(jqXHR);
                   });
    }


    private fKeepListening = true;

    /**
     *  Registers the given callback function as a listener of the GET /listen REST API.
     *  See documentation there for details.
     *  This callback will then be notified of all events from the back-end for the
     *  given channel, such as long tasks that have been started, and their progress.
     */
    protected addListener(channel: string,
                          fnCallback: (ListenResult: any) => void)
    {
        $.get(`${g_rootpage}/api/listen/` + channel,
            (data: any) => {
                if (data.hasOwnProperty('event'))
                    fnCallback(data);

                if (this.fKeepListening)
                    // Use settimeout with a timeout of 0 to add this to the browser's work queue.
                    setTimeout(() => {
                        this.addListener(channel, fnCallback);
                    }, 100);
            })
         .fail( (jqXHR: JQueryXHR, textStatus, errorThrown) => {
             this.fKeepListening = false;

             if (jqXHR.responseJSON)
                 fnCallback(jqXHR.responseJSON);        // this is the ListenResultError
         });
    }

    /**
     *  Helper method that invokes the GET /users REST API. On success it calls the given callback.
     */
    public getUsers(fnSuccess: (data: GetUsersApiResult) => void)
    {
        this.execGET(   `/users`,
                        (json) => {
                            fnSuccess(json);
                        });
    }

    public getAllTemplates(fnSuccess: (data: GetAllTemplatesApiResult) => void)
    {
        this.execGET(   '/all-templates',
                        (json) => {
                            fnSuccess(json);
                        });

    }

    public static quote(str: string): string
    {
        return _('openq') + str + _('closeq');
    }

    /**
     *  Two preconditions:
     *
     *    -- You must have called WholePage::Enable(FEAT_JS_COPYTOCLIPBOARD) in the back-end;
     *
     *    -- you must call initClipboardButtons() once afterwards for all the clipboard buttons you have added.
     */
    protected makeCopyToClipboardButton(idCopyFrom: string)
        : string
    {
        return `<button class="btn btn-default btn-sm ${CLASS_COPY_CLIPBOARD_FRONTEND}" title="${_('clipboard')}" data-clipboard-target="#${idCopyFrom}" type="button">${getIcon('clipboard')}</button>`;
    }

    protected initClipboardButtons()
    {
        new ClipboardJS('.' + CLASS_COPY_CLIPBOARD_FRONTEND);
    }

    protected makeTooltipAttrs(title: string,
                               align = "auto right")
        : string
    {
        // return `data-toggle="tooltip" data-placement="${align}" title="${title}"`;
        return "title=\"" + myEscapeHtml(title) + '"';
    }

    /**
     *  Returns the complete <a href=...>...</a> chunk for a link to a
     *  ticket. oTicket must have the href. nlsFlyOver and icon fields set.
     *
     *  htmlTitle could be myEscapeHTML(oTicket.title) but also something else.
     *
     *  If fEdit is true, this looks for the hrefEdit member in TicketCoreData
     *  and will return null if that is not present (which is the case if the
     *  current user does not have update permission).
     */
    public static MakeTicketLink(oTicket: ITicketCore,
                                 htmlTitle: string,
                                 fNewWindow: boolean = false,        //!< in: if true, we add target="_blank"
                                 fEdit: boolean = false,
                                 aExtraClasses: string[] = undefined)
        : string | null
    {
        if (    fEdit
             && (!oTicket.hasOwnProperty('hrefEdit') || !oTicket.hrefEdit)
           )
            return null;

        let icon: string;
        if (!fEdit)
            if (icon = oTicket.icon)
                icon += '&nbsp;';

        return [    '<a href="',
                    g_rootpage,
                    (fEdit) ? oTicket.hrefEdit : oTicket.href,
                    '" title="',
                    (fEdit) ? oTicket.nlsFlyOverEdit + '" rel="edit edit-form' : oTicket.nlsFlyOver,
                    (aExtraClasses) ? `" class="${aExtraClasses.join(' ')}` : '',
                    (fNewWindow) ? '" target="_blank">' : '">',
                    icon,
                    htmlTitle,
                    '</a>' ].join('');
    }

}

export const CLASS_COPY_CLIPBOARD_FRONTEND = 'copy-clipboard-front';
