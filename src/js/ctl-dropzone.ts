/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as Dropzone from 'dropzone';
import { ApiResult } from './inc-api-types';
import DrnDropzone from './inc-dropzone';
import { drnFindByID } from './shared';
import _ from './nls';

interface UploadResult extends ApiResult
{
    aChangelogItems: string[];
}

/**
 *  The Doreen wrapper code around a JS Dropzone object to make it behave like we want
 *  and have it display our NLS strings from the back-end.
 */
export class DrnCtlDropzone extends DrnDropzone
{
    private readonly _jqActions: JQuery;
    private readonly _jqActionsStart: JQuery;
    private readonly _jqActionsCancel: JQuery;
    private readonly _jqUploadQueue: JQuery;

    constructor(id: string,
                url: string,                //!< in: API URL without /api but with leading slash, e.g. /attachment/:idTicket
                maxUploadMB: number)        //!< in: 1000-based, not 1024
    {
        super(id, url, maxUploadMB, false);

        this._jqUploadQueue = drnFindByID(id + '-upload-queue');
        this._jqUploadQueue.popover(    {   content: _('dzqueue'),
                                title: "Start uploading the queue",
                                placement: "top",
                                trigger: "focus"
                            } );

        this._jqActions = drnFindByID(id + 'Actions');
        this._jqActionsStart = this._jqActions.find('.start');
        this._jqActionsCancel = this._jqActions.find('.cancel');

        this._jqActionsStart.click(() => {
            this._oDropzone.enqueueFiles(this._oDropzone.getFilesWithStatus(Dropzone.ADDED));
            this._oDropzone.processQueue();
        });

        this._jqActionsCancel.click(() => {
            this._oDropzone.removeAllFiles(true);
            this.resetUploadForm();
        });

        this._oDropzone.on("canceled", (file) => {
            this.updateUploadButtons();
        });

        $(document).on('paste', (e: any) => {
            const clipboardData = e.clipboardData || e.originalEvent.clipboardData;
            const items = clipboardData.items;
            for(const item of items) {
                if (item.kind === 'file')
                    this._oDropzone.addFile(item.getAsFile());
            }
        });
    }

    /**
     *  Gets called on "Cancel all" only.
     */
    public resetUploadForm()
    {
        super.resetUploadForm();
        this._jqActionsStart.addClass('hidden');
        this._jqActionsCancel.addClass('hidden');
        this._jqUploadQueue.popover('hide');
    }

    /**
     *  updateUploadButtons() gets called on "addedfile", "canceled", "error" events for
     *  a file
     */
    public updateUploadButtons()    // Called on "addedfile", "canceled, "error".
    {
        // TODO update these buttons depending on how many items were successfully added.
        let aFiles = this._oDropzone.getFilesWithStatus(Dropzone.ADDED);
        if (aFiles.length)
        {
            $('#' + this.id + 'Actions .start').removeClass('hidden');
            $('#' + this.id + 'Actions .cancel').removeClass('hidden');
            this._jqTotalProgress.popover('show');
        }
        else
        {
            $('#' + this.id + 'Actions .start').addClass('hidden');
            $('#' + this.id + 'Actions .cancel').addClass('hidden');
            this._jqTotalProgress.popover('hide');
        }
    }

    protected onFileAdded(file) {
        file.previewElement.querySelector(".start").addEventListener('click', () => {
            this._oDropzone.enqueueFile(file);
            this._oDropzone.processFile(file);
        });
        this.updateUploadButtons();
        $('[data-toggle="tooltip"]').tooltip();
        this._jqUploadQueue.popover('show');
    }

    protected onError(file, errorMsg: ApiResult) {
        super.onError(file, errorMsg);
        this.updateUploadButtons();
    }

    protected onSending(file) {
        super.onSending(file);
        file.previewElement.querySelector(".start").setAttribute("disabled", "disabled");
    }

    protected onSuccess(file, serverResponse: UploadResult) {
        file.previewElement.querySelector(".cancel").setAttribute("disabled", "disabled");
        file.previewElement.querySelector(".delete").setAttribute("disabled", "disabled");
        const jqChangelogTableBody = drnFindByID('changelog-table').find('tbody');
        window.setTimeout(function()
                          {
                              for (let i = 0; i < serverResponse.aChangelogItems.length; ++i)
                              {
                                  const tablerow = serverResponse.aChangelogItems[i];
                                  jqChangelogTableBody.prepend(tablerow);
                              }
                          }, 700);
    }

    protected onComplete() {
        super.onComplete();
        window.setTimeout(function()
                          {
                              location.reload(true);  // invalidate cache
                          }, 1500);
    }
}
