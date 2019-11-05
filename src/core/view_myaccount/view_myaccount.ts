/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import AjaxForm from '../../js/inc-ajaxform';
import { drnFindByID } from '../../js/shared';

// var d = new Dialog( { idDialog: 'myaccount',
//                       method: 'PUT',
//                       cmd: '%ROOTPAGE%api/account/%UID%',
//                       fields: [ 'current-password',
//                                 'password',
//                                 'password-confirm',
//                                 'longname',
//                                 'email',
//                                 'fTicketMail',
//                                 'fAbsoluteDates' ]
//                     });
//

export interface UserDialogData
{
    uid: number;
    fTicketMail: boolean;
    dateFormat: number;
    fCanChangeEmail: boolean;
}

export class MyAccountForm extends AjaxForm
{
    constructor(o: UserDialogData)
    {
        super('myaccount',
              [ 'current-password',
                'password',
                'password-confirm',
                'longname',
                'email',
                'fTicketMail',
                'dateFormat' ],
            '/account/' + o.uid.toString());

        this.method = 'PUT';

        for (const field of [ 'fTicketMail' ])
            this.findDialogItem(field).prop('checked', o[field]);
        if (!o.fCanChangeEmail)
            this.findDialogItem('email').attr('disabled', 'disabled');
        this.findDialogItem('dateFormat-row').find(`input[value="${o.dateFormat}"]`).prop('checked', true);

        drnFindByID('apiToken-generate').on('click', (e) => {
            e.preventDefault();
            this.execPOST(`/token/${o.uid}`, {}, (resp) => {
                drnFindByID('apiToken').val(resp.token);
            });
            return false;
        });
    }

}
