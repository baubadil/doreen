/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */
import AjaxForm from '../js/inc-ajaxform';
import _ from '../js/nls';

// var d = new Dialog( {   idDialog: '$idDialog',
//                           method: 'post',
//                           cmd: g_rootpage + '/api/$apiCmd',
//                           fields: $apiFields,
//                           onSuccess: function(jsonData)
//                           {
//                               showSuccessMessage(this.idDialog,
//                                                  "$nlsDone");
//                           }
//                       });
class RegisterForm extends AjaxForm
{
    constructor(idDialog: string,
                aFields: string[],
                url: string)
    {
        super(idDialog,
              aFields,
              '/' + url,
              'save');

        this.jqDialog.on('submit', (e) => {
            e.preventDefault();
            this.onSubmit();
        });
    }

    protected onSubmitSuccess(jsonData)
    {
        super.onSubmitSuccess(jsonData);
        this.showSuccessMessage(_('registerDone'));
    }

    private showSuccessMessage(htmlMessage)
    {
        var elmMsg = this.findDialogItem('error');
        elmMsg.removeClass("alert-danger");
        elmMsg.addClass("alert-success");
        elmMsg.children().first().html(htmlMessage);
        elmMsg.fadeIn();
        elmMsg.removeClass('hidden');

        this.jqSubmitButton.hide();
    }

}

export function initRegisterForm(idDialog: string,
                                 aFields: string[],
                                 url: string)
{
    new RegisterForm(idDialog, aFields, url);
}
