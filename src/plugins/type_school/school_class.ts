/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import AjaxForm from '../../js/inc-ajaxform';

export class SchoolCreateClass extends AjaxForm
{
    constructor(idDialog: string)
    {
        super(idDialog,
              [ 'name', 'longname', 'email', 'mobile' ],
              '/school-class-and-rep',
              'save');
    }

    protected onSubmitSuccess(json): void
    {
        super.onSubmitSuccess(json);

        window.location.reload();
    }
}

