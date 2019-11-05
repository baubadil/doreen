/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */
import AjaxModal from './inc-ajaxmodal';
import SessionManager from './sessionmanager';

class SessionErrorModal extends AjaxModal
{
    constructor(idDialog: string)
    {
        if (!SessionManager.isSessionExpired())
            throw new Error("Session not expired, shouldn't show dialog");

        super(idDialog,
              [],
              '',
              true);

        this.findDialogItem('refresh').on('click', () => {
            this.onReload();
        });
    }

    protected onSubmit()
    {
        this.jqDialog.modal('hide');
    }

    private onReload()
    {
        window.location.reload(true);
    }
}

SessionManager.registerSessionErrorHandler(() => new SessionErrorModal('expiredSessionDialog'));
