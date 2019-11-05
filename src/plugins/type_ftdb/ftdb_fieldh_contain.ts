/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

import { FTTicketData } from './ftdb_defs';
import APIHandler from '../../js/inc-apihandler';
import { drnFindByID } from '../../js/shared';
import AjaxTicketsTable from '../../js/inc-ticketstable';
import _ from '../../js/nls';
import getIcon from '../../js/icon';

class PartString {
    aIDandCount: string;
}

export class PartDetails
{
    ft_count: string;
    ft_icon_formatted: string;
    ticket_id: number;
    title: string;
    nlsFlyOver: string;
    href: string;
}

abstract class PartsEditorRowBase extends APIHandler
{
    protected _i: number;
    protected _jqRow: JQuery;

    constructor(protected _parentBase: PartsEditorBase)
    {
        super();
        this._i = _parentBase.getNRows();
    }

    public getIndex(): number
    {
        return this._i;
    }

    public getJQRow(): JQuery
    {
        return this._jqRow;
    }

    public abstract refresh(i: number): void;
}

class PartsListRow extends PartsEditorRowBase
{
    private _data: PartDetails;
    private _jqEntryCount: JQuery;
    private _jqButtonsColumn: JQuery;

    constructor(protected _parent: PartsListEditor,
                part: PartDetails)
    {
        super(_parent);

        this._data = part;

        this._jqRow = $(`<div class="row" style="display: flex; align-items: center"></div>`);

        this._jqRow.append($(`<div class="col-xs-8">
    <a href="${g_rootpage + this._data.href}" title="${this._data.nlsFlyOver}">
        ${this._data.ft_icon_formatted}
        ${this._data.title}
    </a>
</div>`));

        this._jqEntryCount = $(`<input type="number" value="${this._data.ft_count}" step="1" class="form-control" min="1">`);
        this._jqEntryCount.on('change', () => { _parent.rebuildValue() });
        this._jqRow.append($(`<div class="col-xs-2"></div>`).append(this._jqEntryCount));

        this._jqButtonsColumn = $(`<div class="col-xs-2"></div>`);
        this.addDeleteButton();
        this._jqRow.append(this._jqButtonsColumn);
    }

    private addDeleteButton()
    {
        const tooltip = this.makeTooltipAttrs(_('remove-part'));
        const btnDelete = `<a href="#" ${tooltip}>${getIcon('remove')}</a>`;
        let jqDeleteButton = $(`${btnDelete}`).on('click', () =>
        {
            this._parent.removeRow(this);
            return false;
        });
        this._jqButtonsColumn.append(jqDeleteButton);
    }

    public raw(): PartString
    {
        return { aIDandCount: `${this._data.ticket_id}:${this._data.ft_count}` };
    }

    public refresh(i: number)
    {
        this._i = i;

        this._data.ft_count = this._jqEntryCount.val();
    }

    public hasRow(rows: PartsListRow[]): boolean
    {
        return rows.some((row) => row._data.ticket_id == this._data.ticket_id);
    }
}

/**
 *  The TableEditorBase is a helpful class to derive from to build an HTML
 *  table (or bootstrap grid) from arbitrary data, and to convert the table
 *  contents back to such data.
 */
abstract class PartsEditorBase extends APIHandler
{
    protected jqParentToAppendTo: JQuery;
    protected aRows: PartsEditorRowBase[] = [];

    constructor()
    {
        super();
    }

    public append(row: PartsEditorRowBase)
    {
        this.jqParentToAppendTo.append(row.getJQRow());
        this.aRows.push(row);
    }

    public getNRows(): number
    {
        return this.aRows.length;
    }

    public removeRow(r: PartsEditorRowBase)
    {
        let n = r.getIndex();
        // Must add 1 to the row index because row 0 is the hidden entry field with the entire JSON.
        let jqRow = this.jqParentToAppendTo.find(`div.row:eq(${n + 1})`);
        jqRow.fadeOut(400, () => {
            jqRow.remove();
        });
        this.aRows.splice(n, 1);

        this.rebuildValue();
    }

    public abstract rebuildValue();
}

/********************************************************************
 *
 *  PartsListEditor
 *
 ********************************************************************/

export class PartsListEditor extends PartsEditorBase
{
    private jqPartsListEntryField: JQuery;
    private jqPartsAddButton: JQuery;
    private jqPartsCountField: JQuery;

    constructor(private idPartsListEntryField: string,
                idPartsListRowsParentDiv: string,
                aPartsList: PartDetails[])
    {
        super();

        this.jqPartsListEntryField = drnFindByID(idPartsListEntryField);
        this.jqParentToAppendTo = drnFindByID(idPartsListRowsParentDiv);
        this.jqPartsAddButton = drnFindByID(`${idPartsListEntryField}-add-submit`);
        this.jqPartsCountField = drnFindByID(`${idPartsListEntryField}-add-count`);

        this.setupSearch();
        this.makeRows(aPartsList);
    }

    private setupSearch()
    {
        this.jqPartsCountField.prop('min', 1);
        this.jqPartsCountField.prop('step', 1);
        this.jqPartsAddButton.parent().parent().parent().css({
            "display": "flex",
            "align-items": "center"
        });

        this.jqPartsAddButton.click((e) => {
            e.preventDefault();
            const jqPartsSearchField = drnFindByID(`${this.idPartsListEntryField}-add`);
            const id = jqPartsSearchField.val().substr(1);
            const popoverParent = jqPartsSearchField.parent().find('.select2');
            popoverParent.popover('destroy');
            this.execGET(`/ticket/${id}`, ({ results: part }) => {
                part.ft_count = this.jqPartsCountField.val();
                // Strip link from thumbnail
                part.ft_icon_formatted = $(part.ft_icon_formatted).html();
                const row = new PartsListRow(this, part);
                if (!row.hasRow(<PartsListRow[]>this.aRows))
                {
                    this.append(row);

                    this.jqPartsCountField.val(1);
                    jqPartsSearchField.val("").trigger('change');
                    this.rebuildValue();
                }
                else
                {
                    popoverParent.popover( { placement: 'bottom',
                                             title() {
                                                 return '<b>Error</b><span class="close">&times;</span>';
                                             },
                                             html: true,
                                             content: _('duplicate-part'),
                                             trigger: 'manual'
                                           } ).on('shown.bs.popover', function(e)
                                           {
                                               var jqPopover = $(this);
                                               jqPopover.parent().find('div.popover .close').on('click', (e) => {
                                                   jqPopover.popover('hide');
                                               });
                                           });
                    popoverParent.popover('show');
                }
            });
        });
    }

    public rebuildValue()
    {
        const a: string[] = [];
        let i: number = 0;
        for (const oRow0 of this.aRows)
        {
            let oRow = <PartsListRow>oRow0;
            oRow.refresh(i);
            a.push(oRow.raw().aIDandCount);
            ++i;
        }

        this.jqPartsListEntryField.val(a.join(","));
    }

    private makeRows(aPartsList: PartDetails[])
    {
        if (aPartsList)     // can be null on create
            for (const part of aPartsList)
            {
                const row = new PartsListRow(this, part);
                this.append(row);
            }
    }
}

/**
 *  An instance of this gets created by the "document ready" function below,
 *  which in turn is called by the parts list GUI code generated from PHP.
 *  This extends from AjaxTicketsTable in the core to display multiple pages
 *  of other tickets, in our case, the contents of an ft kit.
 */
export class PartsListTable extends AjaxTicketsTable
{
    constructor(idDiv: string,
                private _idKit: number)
    {
        super(idDiv,
              `/ft-partslist/${_idKit}`);
    }

    protected onSuccess(jsonData)
    {
        super.onSuccess(jsonData);
    }

    /**
     *  Implementation of the required abstract AjaxTableBase method.
     */
    protected insertRow(row: FTTicketData)
        : JQuery
    {
        return $(this._htmlTemplateRow
                   .replace('%ICON%', APIHandler.MakeTicketLink(row, row.ft_icon_formatted))
                   .replace('%NAME%', APIHandler.MakeTicketLink(row, row.title))
                   .replace('%ARTNO%', row.ft_article_nos_formatted)
                   .replace('%COUNT%', row.ft_count.toString()));
    }
}

