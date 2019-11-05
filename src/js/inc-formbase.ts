/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as $ from 'jquery';
import APIHandler from './inc-apihandler';
import { drnFindByID, reportProgress } from './shared';

/**
 *  Helper class for any Doreen page that uses AJAX GET or POST functionality,
 *  possibly with progress bars.
 *
 *  It is assumed that the page has a dialog ID which is used as a prefix for
 *  many of the child elements of the page. For example, a dialog ID like "ticket"
 *  would then use "ticket-progress" as a sub-ID.
 *
 *  For the error handling to work, the page must have at least one hidden
 *  error box added with HTMLChunk.addHiddenErrorBox() in the back-end
 *  (having a 'drn-error-box' class).
 */
export default class FormBase extends APIHandler
{
    constructor(protected _idDialog: string)
    {
        super();
    }

    /**
     *  Returns a JQuery result set for the dialog item with the given HTML sub-ID. The
     *  dialog ID and '-' are prefixed automatically.
     */
    protected findDialogItem(subid: string) //!< in: HTML ID without '# and dialog prefix and '-' (can be an int)
        : JQuery
    {
        return drnFindByID(this._idDialog + '-' + subid);
    }

    /**
     *  Helper to properly check if the given checkbox is checked.
     */
    protected isChecked(jq: JQuery)
        : boolean
    {
        return jq.prop('checked');
    }

    protected onError(jqXHR: JQueryXHR)
    {
        this.handleError($(`#${this._idDialog}`), jqXHR);
    }

    public static SetIFrameContents(jqIFrame: any,
                                    html: string)            //!< in: entire HTML to put into the IFrame
    {
        let doc = jqIFrame[0].contentWindow.document;
        doc.open("text/html", "replace");
        doc.write(html);
        doc.close();
    }

    /**
     *  Helper that can be used within an execPOST() success callback if the
     *  POST call spawns a longtasks and the returned JSON data contains
     *  the session ID in the session_id field. This is the case for all
     *  longtasks spawned by the Doreen back-end.
     *
     *  This requires that a Bootstrap progress bar has been added to the dialog
     *  with a '-progress' suffix appended to the dialog ID passed to the constructor.
     *  This should work if you use the progress bars provided by the Doreen back-end
     *  in HTMLChunk.
     *
     *  Optionally, you can also pass in a JQuery object for a "save" button.
     *  If given, the button will be disabled and replaced with a spinner while
     *  the long task is running.
     *
     *  fnProgress() will get called with JSON data from the /progress API. You can
     *  check the 'fDone' field therein to know when the task has finished.
     */
    public reportProgress(jsonData: any,
                          jqSaveBtn: JQuery,
                          fnProgress: Function,
                          htmlRunning: string = '')
    {
        reportProgress(this._idDialog,
                       jsonData.session_id,
                       jqSaveBtn,
                       htmlRunning,
                       500,
                       fnProgress);
    }
}
