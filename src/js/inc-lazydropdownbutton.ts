/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */
import APIHandler from './inc-apihandler';
import { drnShowBusyCursor } from './inc-globals';
import { myEscapeHtml } from './shared';

export default abstract class LazyDropdownButton extends APIHandler
{
    constructor(protected readonly jqButton: JQuery,
                private readonly apiEndpoint: string,
                private readonly noResults: string)
    {
        super();
        this.jqButton.one('click', (e) => this.onClick(e));
    }

    protected onClick(e) {
        e.preventDefault();
        drnShowBusyCursor(true);
        this.execGET(this.apiEndpoint, (data) => {
            const array = this.getData(data);
            drnShowBusyCursor(false);
            if (!array.length)
                this.jqButton.text(this.noResults);
            else if(array.length === 1)
                location.assign(this.getURL(array[0]));
            else
            {
                // Multiple accounts: show a drop-down.
                this.jqButton.append(" ");
                this.jqButton.append($("<span class='caret'></span>"));

                this.jqButton.addClass('dropdown-toggle');
                this.jqButton.parent().addClass('dropdown');
                this.jqButton.attr('data-toggle', 'dropdown');
                this.jqButton.attr('role', 'button');
                this.jqButton.attr('aria-haspopup', 'true');
                this.jqButton.attr('aria-expanded', 'false');
                const jqPopup = $('<ul class="dropdown-menu"></ul>');
                for (const item of array)
                {
                    const jgItemButton = $(`<li><a href="${myEscapeHtml(this.getURL(item))}">${myEscapeHtml(this.getLabel(item))}</a></li>`);
                    jqPopup.append(jgItemButton);
                }
                this.jqButton.after(jqPopup);
                this.jqButton.dropdown('toggle');
            }
        });
    }

    protected abstract getData(data: any): any[];
    protected abstract getURL(item: any): string;
    protected abstract getLabel(item: any): string;
}
