/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import AjaxForm from '../../js/inc-ajaxform';

/**
 *  Wrapper class instantiated from PHP via core_initTicketEditor() entry point.
 *  This handles the API calls for pushing the ticket POST or PUT data.
 */
export class TicketEditor extends AjaxForm
{
    constructor(idDialog: string,
                aFieldIds: string[],                //!< in: list of data items to submit (without "idDialog-" prefixes)
                url: string,
                protected fCreateMode: boolean,
                protected fDebugPostPut: boolean,
                protected newTicketArg: string,
                protected afterSaveArg: string)
    {
        super(idDialog, aFieldIds, url);
        if (!fCreateMode)
            this.method = 'PUT';
    }

    protected onSubmitSuccess(json)
    {
        if (!this.fDebugPostPut)
        {
            if (this.fCreateMode)
            {
                let url = g_rootpage + "/ticket/" + json.ticket_id;
                if (this.newTicketArg)
                    url += '?' + this.newTicketArg;
                location.assign(url);
            }
            else if (this.afterSaveArg)
            {
                location.assign(g_rootpage + this.afterSaveArg);
            }
            else
            {
                location.assign(g_rootpage + "/ticket/" + g_globals.ticketID);
            }
        }
    }
}
