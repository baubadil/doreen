/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import FormBase from '../../js/inc-formbase';
import APIHandler from '../../js/inc-apihandler';
import AjaxForm from '../../js/inc-ajaxform';
import { drnFindByID, drnShowAndFadeIn, dlgHandleError } from '../../js/shared';
import { ListenResult, ListenResultProgress } from '../../js/inc-api-types';
import { drnUpdateProgressBar } from '../../js/inc-globals';
import _ from '../../js/nls';

class ControlHandler extends APIHandler
{
    protected jqControl: JQuery;

    constructor(idSetting: string)
    {
        super();

        this.jqControl = drnFindByID(idSetting);
    }

}

/**
 *  The rule about these settings is that the dialog item ID must be the same
 *  as the string for the POST /config REST API.
 */
export class GlobalSettingsBool extends ControlHandler
{
    constructor(idSetting: string)
    {
        super(idSetting);

        this.jqControl.click(() => {
            let fCheckedNew = this.jqControl.prop('checked');
            this.execPOST('/config',
                          { key: idSetting,
                                 value: fCheckedNew
                          },
                          () => {
                                this.jqControl.popover({ container: 'body',
                                                             content: "Saved!",
                                                             placement: "left" });
                              this.jqControl.popover('show');
                              window.setTimeout(() => {
                                  this.jqControl.popover('destroy');
                              }, 1000);
                          });
        });
    }
}

export class ThemeSelector extends ControlHandler
{
    constructor(idDialog: string)
    {
        super(idDialog + '-select');

        this.jqControl.on('change', () =>
        {
            let theme = this.jqControl.find(':selected').text();
            this.execPut('/theme/' + theme,
                {},
                () => {
                            window.location.reload(true);       // force reload from server, ignore cache
                        });
        });
    }
}

/**
 *  Support for the "Reindex all" button in the global settings.
 */
export class ReindexingAll extends FormBase
{
    private jqButton: JQuery;
    private jqProgress: JQuery;

    constructor(idDialog: string,
                private channelReindexing: string)
    {
        super(idDialog);

        this.jqButton = this.findDialogItem('save');

        this.jqButton.click(() => {
            this.execPOST('/reindex-all',
                          '',
                          () => {
                          })
        });

        this.jqProgress = this.findDialogItem('progress');
        this._jqProgressBar = this.jqProgress.find('.progress-bar');

        this.addListener(this.channelReindexing,
                         (result: ListenResult) => {
                             if (result.event == 'started')
                             {
                                 this.ensureProgress(true);
                             }
                             else if (result.event == 'progress')
                             {
                                 this.ensureProgress(true);
                                 let result2 = <ListenResultProgress>result;
                                 drnUpdateProgressBar(this._jqProgressBar, result2.data);
                                 if (result2.data.fDone)
                                     this.ensureProgress(false);
                             }
                             else if (result.event == 'error')
                             {
                                 // this.showError(this._jqDialog, "error");     TODO
                             }
                         });
    }

    private fRunning = false;

    private ensureProgress(fRunning: boolean)
    {
        // On the first call, disable the button and shot the progress bar.
        if (fRunning != this.fRunning)
        {
            if (fRunning)
            {
                this.buttonSubmitting(this.jqButton);
                drnShowAndFadeIn(this.jqProgress);
            }
            else
                this.buttonSubmitSuccess(this.jqButton);

            this.fRunning = fRunning;
        }
    }
}

export function drnInitAutostarts(idDialog: string)
{
    const jqAutostarts = drnFindByID(idDialog).find(':checkbox');
    $.each(jqAutostarts, function()
    {
        const jqThis = $(this);
        jqThis.on('click', function()
        {
            let service;
            if ((service = jqThis.attr('data-service')))
            {
                const checked = (jqThis.prop('checked')) ? 1 : 0;
                $.ajax({
                    type: 'POST',
                    url: g_rootpage + '/api/set-autostart/' + service + '/' + checked,
                    contentType: 'application/json',    // What format data sent to the server is in. Default is urlencoded.
                    success: function()
                    {
                        jqThis.prop('checked', !!checked);
                    },
                    error: function(jqXHR)
                    {
                        dlgHandleError(idDialog, jqXHR);
                    }
                });
            }
            return false;
        });
    });
}

export class NukeDialog extends AjaxForm
{
    constructor()
    {
        super('nuke',
              [],
              '/nuke',
              'save');
    }

    protected onSubmitSuccess(jsonData)
    {
        super.onSubmitSuccess(jsonData);
        this.findDialogItem('div').removeClass("hidden");
        this.reportProgress(jsonData,
                            this.findDialogItem('save'),
                            (json) =>
                            {
                                this.findDialogItem('deleted').html(json.cCurrentFormatted);
                                this.findDialogItem('total').html(json.cTotalFormatted);
                                let remain = '';
                                if (json.hasOwnProperty('timeRemaining'))
                                    remain = ' &mdash ' + json.timeRemaining;
                                this.findDialogItem('remain').html(remain);
                            },
                            _('nuking'));
    }
}

