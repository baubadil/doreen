/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import APIHandler from './inc-apihandler';
import {Placement} from "bootstrap";

/**
 *  Helper class that wraps around Bootstrap popover to work around some of its
 *  quirks.
 */
export default class DrnPopover extends APIHandler
{
    protected oPop: any;
    protected jqPopoverContents: JQuery;

    constructor(protected _jqAttachPopoverTo: JQuery)       //!< in: control to invoke the popover() function on
    {
        super();
    }

    public init(title: string,                              //!< in: title for the popover
                htmlBody: string,
                placement: Placement = 'auto',
                maxWidth = '70em')                          //!< in: max-width CSS property to set on the popover
    {
        this.oPop = this._jqAttachPopoverTo.popover({
            placement: placement,
            title: title + `<button type="button" class="close">&times;</button>`,
            html: true,
            // container: 'body',
            content: htmlBody // jqCopyPopoverContentsFrom.html()
        }).on('click', (e) => {
            e.preventDefault(); // fix scrolling problems
        }).on('inserted.bs.popover', () => {
            // Find the popover. It shares the parent with the link the user clicked on.
            this.jqPopoverContents = this._jqAttachPopoverTo.parent().find('.popover');
            this.jqPopoverContents.css({
                                       "max-width": maxWidth
                                   } );
            let jqTitle = this.jqPopoverContents.find('.popover-title');
            jqTitle.find('.close').click(() => {
                this.dismiss();
            });
            this.initContents();
        });
    }

    protected initContents()
    {
    }

    public show()
    {
        this._jqAttachPopoverTo.popover('show');
    }

    public dismiss()
    {
        this._jqAttachPopoverTo.popover('hide');
    }
}
