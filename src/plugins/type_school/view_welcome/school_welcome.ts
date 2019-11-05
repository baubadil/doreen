/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import AjaxForm from '../../../js/inc-ajaxform';

export class SchoolSetPassword extends AjaxForm
{
    constructor(idDialog: string)
    {
        super(idDialog,
              [ 'email', 'token', 'password', 'password-confirm' ],
              '/school-set-password',
              'save');
    }

    protected onSubmitSuccess(json): void
    {
        super.onSubmitSuccess(json);

        window.location.assign(g_rootpage);
    }
}

