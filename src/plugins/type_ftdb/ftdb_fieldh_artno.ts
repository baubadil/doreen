import { drnMakeTooltipAttrsT } from "../../js/inc-globals";
import { drnFindByID } from "../../js/shared";
import _ from '../../js/nls';
import getIcon from '../../js/icon';

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

/********************************************************************
 *
 *  Instance method overrides
 *
 ********************************************************************/

abstract class TableEditorRowBase
{
    protected _i: number;
    protected _jqRow: JQuery;

    constructor(protected _parentBase: TableEditorBase)
    {
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

    public abstract clear(): void;

    public abstract refresh(i: number): void;
}

/**
 *  The TableEditorBase is a helpful class to derive from to build an HTML
 *  table (or bootstrap grid) from arbitrary data, and to convert the table
 *  contents back to such data.
 */
abstract class TableEditorBase
{
    protected _jqParentToAppendTo: JQuery;
    protected _aRows: TableEditorRowBase[] = [];

    constructor()
    {
    }

    public append(row: TableEditorRowBase)
    {
        this._jqParentToAppendTo.append(row.getJQRow());
        this._aRows.push(row);
    }

    public getNRows(): number
    {
        return this._aRows.length;
    }

    public removeRow(r: ArticleNoRow)
    {
        // Do not delete the last row, clear it instead.
        if (this._aRows.length == 1)
            r.clear();
        else
        {
            let n = r.getIndex();
            // Must add 1 to the row index because row 0 is the hidden entry field with the entire JSON.
            let jqRow = this._jqParentToAppendTo.find(`div.row:eq(${n + 1})`);
            jqRow.fadeOut(400, () => {
                jqRow.remove();
            });
            this._aRows.splice(n, 1);
        }

        this.rebuildJSON();
    }

    public abstract rebuildJSON();
}


/********************************************************************
 *
 *  ArticleNoEditor
 *
 ********************************************************************/

export class ArticleNoString
{
    aYearAndNo: string[];
}

class ArticleNoParsed
{
    year: number | null;
    articleNo: string | null;
}

/**
 *  Every row has a <div> with two text entry fields.
 */
class ArticleNoRow extends TableEditorRowBase
{
    private _data: ArticleNoParsed;
    private _jqEntryYear: JQuery;
    private _jqEntryArticleNo: JQuery;
    private _jqButtonsColumn: JQuery;

    constructor(private _parent: ArticleNosEditor,
                year: number,
                articleNo: string | null)
    {
        super(_parent);

        this._data = { year: year, articleNo: articleNo };

        const valign = `style="display: flex; align-items: center"`;
        this._jqRow = $(`<div class="row" ${valign}></div>`);

        let strYear: string = (year) ? year.toString() : '';
        let entryYear = `<input type='text' class='form-control' value='${strYear}'>`;
        this._jqEntryYear = $(`${entryYear}`).on('change', () => { _parent.rebuildJSON() });
        this._jqRow.append($(`<div class="col-md-2"></div>`).append(this._jqEntryYear));

        if (articleNo == null)
            articleNo = '';
        let entryArticleNo = `<input type='text' class='form-control' value='${articleNo}'>`;
        this._jqEntryArticleNo = $(`${entryArticleNo}`).on('change', () => { _parent.rebuildJSON() });
        this._jqRow.append($(`<div class="col-md-8"></div>`).append(this._jqEntryArticleNo));

        this._jqButtonsColumn =  $(`<div class="col-md-2"></div>`);

        if (year !== null)
        {
            this.addDeleteButton();
        }
        else
        {
            // Year === null means last, empty row: add "enter" button
            let helpAdd = drnMakeTooltipAttrsT(_('add-article-no'));
            let btnAdd = `<span class="drnFTAddButton">&nbsp;<a href="#" ${helpAdd}>${getIcon('add-another')}</a></span>`;
            let jqAddButton = $(`${btnAdd}`).on('click', () =>
            {
                this._jqRow.find('.drnFTAddButton').remove();
                this.addDeleteButton();
                this._parent.addRowBehind(this);
                return false;
            });
            this._jqButtonsColumn.append(jqAddButton);
        }

        this._jqRow.append(this._jqButtonsColumn);
    }

    private addDeleteButton()
    {
        let helpDelete = drnMakeTooltipAttrsT(_('remove-article-no'));
        let btnDelete = `<a href="#" ${helpDelete}>${getIcon('remove')}</a>`;
        let jqDeleteButton = $(`${btnDelete}`).on('click', () =>
        {
            this._parent.removeRow(this);
            return false;
        });
        this._jqButtonsColumn.append(jqDeleteButton);
    }

    public empty(): boolean
    {
        return (this._data.year == null);
    }

    public clear(): void
    {
        this._data = { year: null, articleNo: null };
        this._jqEntryYear.val('');
        this._jqEntryArticleNo.val('');
    }

    public refresh(i: number): void
    {
        this._i = i;
        let y: string = this._jqEntryYear.val().trim();
        let no = this._jqEntryArticleNo.val();
        if (!no.trim())
            no = null;
        this._data = {
            year: (y) ? parseInt(y) : null,
            articleNo: no
        };
    }

    /**
     *  Returns the year and article no of this row as a string pair.
     */
    public toStrings() : ArticleNoString
    {
        let as: string[] = [];
        as.push(this._data.year.toString());
        as.push(this._data.articleNo);
        return { aYearAndNo: as };
    }
}

export class ArticleNosEditor extends TableEditorBase
{
    private _jqArticleNosEntryField: JQuery;
    /**
     *  The entry field is a child of a bootstrap column, which in turn is a child of a bootstrap row;
     *  that row was inserted into the wide column of the standard Doreen ticket editor, but under
     *  another logical DIV with the ID given to us in idArticleRowsParentDIV.
     *  For the article number editor, we need to insert additional rows under that DIV.
     *
     */
    constructor(private _idArticleNosEntryField: string,
                _idArticleNoRowsParentDIV: string,
                aArticleNos: ArticleNoString[])
    {
        super();

        this._jqArticleNosEntryField = drnFindByID(_idArticleNosEntryField);
        this._jqParentToAppendTo = drnFindByID(_idArticleNoRowsParentDIV);

        this.makeRows(aArticleNos);
    }

    private makeRows(aArticleNos0: ArticleNoString[])
    {
        let i: number = 0;
        if (aArticleNos0)
        {
            // Sort the input by year.
            let aArticleNos: ArticleNoString[] = aArticleNos0.sort( (n1: ArticleNoString, n2: ArticleNoString) =>
                                                                    {
                                                                        return parseInt(n1[0]) - parseInt(n2[0]);
                                                                    });


            for (let as of aArticleNos)
            {
                let year = parseInt(as[0]);
                let articleNo = (as[1]) ? as[1] : null;

                ++i;
                if (i >= this._aRows.length)
                {
                    let o = new ArticleNoRow(this, year, articleNo);
                    this.append(o);
                }
            }
        }

        let o = new ArticleNoRow(this, null, null);
        this.append(o);
    }

    public addRowBehind(row: ArticleNoRow)
    {
        let o = new ArticleNoRow(this, null, null);
        this.append(o);
    }

    public rebuildJSON()
    {
        let a: string[][] = [];
        let i: number = 0;
        for (let oRow0 of this._aRows)
        {
            let oRow = <ArticleNoRow>oRow0;
            oRow.refresh(i);
            if (!oRow.empty())
                a.push(oRow.toStrings().aYearAndNo);
            ++i;
        }

        this._jqArticleNosEntryField.val(JSON.stringify(a));
    }

}
