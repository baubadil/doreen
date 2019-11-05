/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import { ApiResult } from './inc-api-types';
import DrnPopover from './inc-popover';
import { drnShowBusyCursor } from './inc-globals';
import { drnFindByID } from './shared';
import {Placement} from "bootstrap";

/**
 *  Response parameter for the GET /help REST API.
 */
interface HelpResult extends ApiResult
{
    htmlHeading: string;
    htmlBody: string;
}

/**
 *  Subclass based on DrnPopover to implement Doreen help links.
 *  An instance of this is created for each drnInitHelpLink() call.
 */
export default class HelpPopover
{
    protected jqHelpFor: JQuery;
    protected oPopover: DrnPopover;
    private fInited = false;

    constructor(protected topic: string,
                protected align: Placement = 'auto')            //!< in: parameter for DrnPopover (e.g. 'auto right')
    {
        this.jqHelpFor = drnFindByID(topic + '-help');

        this.jqHelpFor.click(() => {
            // We only install the popover on the first click on the link, and the popover
            // installs its own handler. So remove this handler first.
            this.jqHelpFor.off('click');
            this.init();
            return false;
        });
    }

    protected init()
    {
        this.oPopover = new DrnPopover(this.jqHelpFor);

        drnShowBusyCursor(true);
        $.get(g_rootpage + '/api/help/' + this.topic,
              (data: HelpResult) =>
                {
                    drnShowBusyCursor(false);
                    this.setContents(data.htmlHeading,
                                     data.htmlBody);
                })
         .fail( (jqXHR, textStatus, errorThrown) =>
                {
                    drnShowBusyCursor(false);
                    this.setContents('Error',
                                     jqXHR.responseJSON.message);
                });
    }

    protected setContents(htmlTitle: string, htmlContents: string)
    {
        this.oPopover.init(htmlTitle,
                           htmlContents,
                           this.align);
        this.oPopover.show();
    }
}
