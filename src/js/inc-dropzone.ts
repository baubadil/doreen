/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as Dropzone from 'dropzone';
import { ApiResult } from './inc-api-types';
import { drnFindByID } from './shared';
import SessionManager from './sessionmanager';
import { drnGetJWT } from './main';
import _ from './nls';

declare global
{
    interface Window {
        g_oDropzone: DrnDropzone;
    }
}

/**
 *  The Doreen wrapper code around a JS Dropzone object to make it behave like we want
 *  and have it display our NLS strings from the back-end.
 */

declare class PrivDropzone extends Dropzone {
    public options: any;
}

export default class DrnDropzone
{
    protected readonly _oDropzone: Dropzone;
    protected readonly _jqTotalProgress: JQuery;
    private readonly _jqTotalProgressBar: JQuery;

    private errorMessage: string;

    constructor(protected readonly id: string,
                url: string,                //!< in: API URL without /api but with leading slash, e.g. /attachment/:idTicket
                maxUploadMB: number,        //!< in: 1000-based, not 1024
                autoQueue: boolean = false)
    {
        if (window.g_oDropzone)
            throw new Error("A dropzone has already been added to this page");

        window.g_oDropzone = this;
        const previewTemplate = this.getPreviewTemplate();

        this._oDropzone = new Dropzone(document.body,
                                     {
                                         url: g_rootpage + '/api' + url,
                                         uploadMultiple: false,  // Is theoretically supported but then we'll hit PHP's post_max_size much more easily.
                                         thumbnailWidth: 80,
                                         thumbnailHeight: 80,
                                         parallelUploads: 5,
                                         previewTemplate: previewTemplate,
                                         maxFilesize: maxUploadMB,
                                         autoQueue: autoQueue, // Do not start uploading the queue until the user initiates it
                                         autoProcessQueue: false,
                                         previewsContainer: '#' + id + 'PreviewArea',
                                         clickable: '.' + id + 'AddFileButton',
                                         dictFallbackMessage: _('dzFallback'),
                                         dictFallbackText: null,
                                         dictInvalidFileType: _('dzInvalidFileType'),
                                         dictFileTooBig: _('dzFileTooBig'),
                                         dictMaxFilesExceeded: _('dzMaxFilesExceeded'),
                                         headers: {
                                             Authorization: `Bearer ${drnGetJWT()}`
                                         }
                                     });
        this._oDropzone.on("addedfile", (file) => {
            this.onFileAdded(file);
        });

        this._oDropzone.on("error", (file, errorMsg: ApiResult) => {
            this.onError(file, errorMsg);
        });

        this._jqTotalProgress = drnFindByID(id + 'TotalProgress');
        this._jqTotalProgressBar = this._jqTotalProgress.find('.progress-bar');

        this._oDropzone.on("totaluploadprogress", (progress) => {
            this._jqTotalProgressBar.css({
                width: progress + "%"
            });
        });

        this._oDropzone.on("sending", (file) => {
            this.onSending(file);
        });

        this._oDropzone.on("success", (file, serverResponse: ApiResult) => {
            let jqPreviewElement = $(file.previewElement);
            jqPreviewElement.addClass('bg-success');

            let jqPbar = jqPreviewElement.find(".progress-bar");
            jqPbar.removeClass('progress-bar-info active');
            jqPbar.addClass('progress-bar-success');

            window.setTimeout(function()
            {
                $(file.previewElement).addClass('animated bounceOut');
            }, 500);
            this.onSuccess(file, serverResponse);
            window.setTimeout(function()
            {
                $(file.previewElement).addClass('hidden');
            }, 2000);
        });

        this._oDropzone.on("queuecomplete", (progress) => {
            this._jqTotalProgressBar.removeClass('progress-bar-info active');
            if (!this.errorMessage)
                this.onComplete();
            this.errorMessage = null;
        });

        /*$(document).on('paste', (e: any) => {
            const clipboardData = e.clipboardData || e.originalEvent.clipboardData;
            const items = clipboardData.items;
            for(const item of items) {
                if (item.kind === 'file')
                    this._oDropzone.addFile(item.getAsFile());
            }
        });*/

        SessionManager.registerJWTChangeHandler('dropzone', () => {
            this.updateHeaders();
        });
    }

    /**
     *  Gets the preview tempalte from the markup and returns its contents.
     *  Returns an empty string if the tempalte is not found.
     */
    private getPreviewTemplate(): string
    {
        const previewNode = drnFindByID(this.id + 'PreviewTemplate');
        if (!previewNode)
            return '';
        previewNode.attr('id', "");
        const parent = previewNode.parent();
        const previewTemplate = parent.html();
        previewNode.remove();
        return previewTemplate;
    }

    /**
     *  Gets called on "Cancel all" only.
     */
    public resetUploadForm()
    {
        this._jqTotalProgress.addClass('hidden');
    }

    /**
     *  Called when a file is added to the form. No-op, to be overriden in children.
     */
    protected onFileAdded(file) {}

    /**
     *  Called when an error occurs while uploading. Has default behavior resetting the form.
     */
    protected onError(file, errorMsg: ApiResult)
    {
        // Store error internally so that the 'queue finished' handler doesn't reload the page.
        this.errorMessage = errorMsg.message;

        let jqPreviewElement = $(file.previewElement);
        jqPreviewElement.find(".start").attr("disabled", "disabled");
        jqPreviewElement.addClass('bg-danger');

        let jqPbar = jqPreviewElement.find(".progress-bar");
        jqPbar.parent().addClass('hidden');
        this._jqTotalProgress.addClass('hidden');

        let jqErrorBox = jqPreviewElement.find('.error');
        jqErrorBox.text(_('dzServerError') + ' ' + errorMsg.message);
    }

    /**
     *  Called when a file is being uploaded. Shows the progress bar.
     */
    protected onSending(file)
    {
        this._jqTotalProgress.removeClass('hidden');
        this._jqTotalProgressBar.addClass('active');
    }

    /**
     *  Called when all files were successfully uploaded. No-op, to be overriden by children.
     */
    protected onSuccess(file, serverResponse: ApiResult) {}

    /**
     *  Handles the queue being completed in children. Marsk the progress bar as success.
     */
    protected onComplete()
    {
        this._jqTotalProgressBar.addClass('progress-bar-success');
    }

    /**
     *  Disable the dropzone. Needs to be re-enabled with \ref enable()
     */
    public disable()
    {
        this._oDropzone.disable();
    }

    /**
     *  Ensure the Dropzone is ready for use when picking it up. Does not have to
     *  be called on a freshly constructed dropzone.
     */
    public enable()
    {
        this._oDropzone.enable();
        this.updateHeaders();
        this.resetUploadForm();
        this.getPreviewTemplate();
    }

    /**
     *  Updates the authorization header sent with requests.
     */
    private updateHeaders()
    {
        (this._oDropzone as PrivDropzone).options.headers.Authorization = `Bearer ${drnGetJWT()}`;
    }
}
