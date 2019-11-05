/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import AjaxModal from './inc-ajaxmodal';
import { myEscapeHtml } from './shared';

export class StaticModal extends AjaxModal
{
    constructor(protected idDialog: string,
                fShow: boolean = true)
    {
        super(idDialog, [], null, fShow);
    }
}

export default class DrnMessageBox extends StaticModal
{
    constructor(title: string,
                message: string)
    {
        super('drn-message-box',
              false);
        this.jqDialog.find('.modal-title').html(myEscapeHtml(title));
        this.jqDialog.find('.modal-body').html(myEscapeHtml(message));

        this.show();
    }
}
