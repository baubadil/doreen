/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as $ from 'jquery';
import { drnAnimateOnce } from './inc-globals';
import APIHandler from './inc-apihandler';
import AjaxModal from './inc-ajaxmodal';
import { drnFindByID, drnEnablePopovers } from './shared';
import HelpPopover from './help';
import getIcon from './icon';

/********************************************************************
 *
 *  Sortable tables (global functions)
 *
 ********************************************************************/

/**
 *  Common function that initializes one sortable table ("stupidtable").
 *  This gets called from both drnInitSortableTables() for legacy code
 *  as well as the AjaxTable copying code to make the bootstrap tables
 *  sortable.
 *
 *  "stupidtable" is a JQuery plugin that can sort any HTML table via
 *  JavaScript. This works by adding data-sort attributes to the table's
 *  <th> tags in the header.
 *  See https://github.com/joequery/Stupid-Table-Plugin#stupid-jquery-table-sort.
 *
 *  Doreen used stupidtable before AjaxTable was written, and it's nice
 *  and easy, so I added support for them to the AjaxTable as well even
 *  though Bootstrap Table (used by AjaxTable) seems to have its own
 *  table sorting code. Maybe one day this can get replaced.
 */
function drnInitOneSortableTable(jqThisTable: JQuery)
{
    jqThisTable.stupidtable();

    // Add a callback which adds an icon to the column when it's really sorted.
    jqThisTable.on("aftertablesort", (event, data) => {
        // data.column - the numeric index of the column sorted after a click
        // data.direction - the sorting direction (either asc or desc)
        let jqAllTH = jqThisTable.find("th");
        jqAllTH.find(".drn-sort-arrow").remove();
        let dir = (<any>($.fn.stupidtable)).dir;

        let col = jqAllTH.eq(data.column);
        let sorttype = col.attr('data-sort');

        let arrow;
        if (    (sorttype == 'string')
             || (sorttype == 'string-ins')
           )
        {
            if (data.direction === dir.ASC)
                arrow = getIcon('sort_alpha_asc');
            else
                arrow = getIcon('sort_alpha_desc');
        }
        else
        if (data.direction === dir.ASC)
            arrow = getIcon('sort_amount_asc');
        else
            arrow = getIcon('sort_amount_desc');

        // BootstrapTable inserts additional DIVs into the table headers. If
        // we find them, use them instead.
        let jqBootstrapTableDiv = col.find('.th-inner');
        if (jqBootstrapTableDiv.length)
            col = jqBootstrapTableDiv;

        col.append('<span class="drn-sort-arrow"> %SORT%'.replace('%SORT%', arrow));
    });
}

/*
 *  This function initializes sortable tables. This is legacy code invoked
 *  from PHP.
 *  This function then goes through all tables with the .drn-sortable class
 *  and calls stupidtable() on them, which makes those columns sortable which
 *  have the 'data-sort' attribute.
 */
export function drnInitSortableTables()
{
    $('.drn-sortable').each(function(index, tbl)
                            {
                                drnInitOneSortableTable($(tbl));
                            });

}


/********************************************************************
 *
 *  AjaxTableBase class
 *
 ********************************************************************/

/**
 *  Abstract base class that implements the client side of Doreen's "AJAX table".
 *  See HTMLChunk::addAjaxTable() for the server side.
 *
 *  To use this, derive a subclass. At the very minimum, you must override
 *  the abstract insertRow() method, which must take this._htmlTemplateRow,
 *  replace placeholders with the given row data and return an HTML string.
 *
 *  Then, in the "success" callback of your AJAX call, create an instance of your
 *  derivative class and call fill():
 *
                        this._table = new DerivedTableClass(that._idDiv);
                        this._table.fill(jsonData,
                                         'activities');
 *
 *
 *  This transparently supports sortable tables via "stupidtable". If the
 *  table template has "data-sort" attributes in its <th> columns, they
 *  are initialized correctly. See drnInitOneSortableTable().
 */
export abstract class AjaxTableBase extends APIHandler
{
    protected _jqTemplate;
    protected _jqTable;
    protected _jqTarget;
    protected _htmlTemplateRow: string;

    /**
     *  idParent must be the same HTML ID passed to addAjaxTable() in the back-end.
     */
    protected constructor(protected _idParent)
    {
        super();

        this._jqTemplate = drnFindByID(_idParent + '-template');
        this._jqTarget = drnFindByID(_idParent + '-target');
    }

    /**
     *  Helper that replaces the entire table with a spinner. Useful before you execute
     *  your request and call fill() with the result.
     */
    public setSpinner()
    {
        this._jqTarget.html(getIcon('spinner'));
    }

    protected onError(jqXHR)
    {
        this._jqTarget.html('');
        this.handleError($(`#${this._idParent}`), jqXHR);
    }

    /**
     *
     */
    public clear()
    {
        if (this._jqTable)
        {
            // console.log("destroying table");
            this._jqTarget.empty();
            this._jqTable.bootstrapTable('destroy');
            this._jqTable = null;
        }
    }

    /**
     *  Call this with the actual array data for the table (the array of rows),
     *  and this will call the abstract insertRow() method on every row and
     *  insert the data.
     *
     *  If htmlPagination is given, it is assumed to be a pagination HTML chunk
     *  generated by the backend, and it will be inserted before and after the
     *  table.
     *
     *  If htmlPrologue is given, it will be inserted before the entire table
     *  with the pagination bits (if present).
     */
    public fill(aData: any,         //!< in: array of table rows
                htmlPagination = null,
                htmlPrologue = null)
        : void
    {
        this._jqTarget.html('');

        let jqTemplateTable = this._jqTemplate.find('table');
        if (!jqTemplateTable.length)
            throw "Error in AjaxTable.fill(): cannot find <table> under template DIV";

        // Clone the template.
        this._jqTarget.append(jqTemplateTable.clone());

        let jq0 = this._jqTarget.find('tbody tr');
        let jq = jq0.detach();
        // Use plain JavaScript to get more than the inner HTML, http://stackoverflow.com/questions/2419749/get-selected-elements-outer-html
        this._htmlTemplateRow = jq[0].outerHTML;

        // Now find the body of the new table clone and append to that.

        let jqResultsBody = this._jqTarget.find('tbody');
        if (aData)
            this.makeTableData(jqResultsBody, aData);
        this.makeBootstrapTable();

        /* If we have pagination, add a handler to each <a> in it which calls onClickPage(). */
        if (htmlPagination)
        {
            this._jqTarget.prepend(htmlPagination);
            this._jqTarget.append(htmlPagination);
            let domTarget = this._jqTarget[0];

            // Create the closure in a variable, otherwise we'll create a hundred of them in the loop.
            let pfnClickA = (e) =>
            {
                let domTarget = e.target;
                let domParentLI = domTarget.parentNode;
                if (!domParentLI.classList.contains('disabled'))
                {
                    let page = /\?page=(\d+)/.exec(domTarget.href)[1];
                    this.onClickPage(page);
                }
                e.preventDefault();
                return false;
            };
            for (let elmA of domTarget.querySelectorAll('ul.pagination a'))
                elmA.addEventListener('click', pfnClickA);

            /* Now get the two inserted pagination objects and fix the margins
               because they're a little wide. */
            let jqPaginations = this._jqTarget.find('ul.pagination');
            $(jqPaginations[0]).css('margin-top', 3);
            $(jqPaginations[0]).css('margin-bottom', 2);
            $(jqPaginations[1]).css('margin-top', 7);
            $(jqPaginations[1]).css('margin-bottom', 6);
        }

        if (htmlPrologue)
            this._jqTarget.prepend(htmlPrologue);

        drnEnablePopovers(jqResultsBody);
        jqResultsBody.find('[data-toggle="tooltip"]').tooltip();

        this.addHandlersToNewRow();
    }

    protected installHandlersForClass(cls: string,
                                      pfnHandler: (e: Event) => void)
    {
        let jq = this._jqTarget.find('.' + cls);
        jq.click(pfnHandler);
        jq.removeClass(cls);
    }

    /**
     *  Gets called after fill() has successfully inserted all table rows. This
     *  implementation does nothing, but gives subclasses a chance to be notified
     *  if if event handlers need to be added to elements in a new table row.
     *  Derived classes may want to use installHandlersForClass() for that.
     */
    protected addHandlersToNewRow()
    {
    }

    /**
     *  Called by \ref makeTableData() for every row in the results set
     *  to determine whether to include the row in the table. This defaults
     *  to true for every row, but can be overridden by subclasses.
     */
    protected doInsertRow(row: any)
        : boolean
    {
        return true;
    }

    /**
     *  Calls the abstract insertRow() method on every row of the results
     *  data set. Only gets called if the data set is not empty in the
     *  first place.
     *
     *  Can be overridden by subclasses for additional fancyness.
     */
    protected makeTableData(jqResultsBody: JQuery,
                            aData: any)
    {
        for (let i = 0;
             i < aData.length;
             ++i)
        {
            let o = aData[i];
            if (this.doInsertRow(o))
            {
                let toInsert = this.insertRow(o);           // could be HTML or jQuery
                jqResultsBody.append(toInsert);
            }
        }
    }

    /**
     *  Calls bootstrapTable() on this._jqTable. At this time, the table
     *  should contain the data. This also calls drnInitOneSortableTable()
     *  as needed.
     */
    protected makeBootstrapTable()
    {
        /* Call the bootstrapTable method on the <table> tag. */
        this._jqTable = this._jqTarget.find('table');
        this._jqTable.bootstrapTable(
            {   method: null
            });
        this._jqTable.bootstrapTable('hideLoading');

        /* Now, this creates a hierarchy as follows:
                <table ...> (old table created by back-end with template)
                <div id="..-target">
                  <div class="bootstrap-table">
                    <div class="fixed-table-toolbar">
                      <div class="fixed-table-container">
                        <div class="fixed-table-header">
                        <div class="fixed-table-body">         <=== this one needs fixing
                          <table class="table table-striped...">
                            <thead> ...
                            <tbody> ...
            The div.table-body has overflow-x and overflow-y set to "auto", which causes clipping
            with bootstrap dropdowns, so we need to remove it.
         */
        let jqBody = this._jqTarget.find(".fixed-table-body");
        jqBody.css( { 'overflow-x': 'visible',
                      'overflow-y': 'visible' } );

        /* Attach handlers for the checkbox events so that our own method gets called. */
        let that = this;
        for (const eventName of [ 'check.bs.table',
                                  'uncheck.bs.table',
                                  'check-all.bs.table',
                                  'uncheck-all.bs.table' ])
            this._jqTable.on(eventName, function(e, rows)
            {
                that.onCheckUncheck(e, rows);
            });

        // Now make the table sortable by copying the data-sort attributes from the template
        // to the target created by bootstrapTable(), and issuing supertable() on it.

        // Iterate over the <th>'s in the source template.
        let jqTemplateThs = this._jqTemplate.find('th');
        let jqTargetThs = jqBody.find('th');
        let cSortables = 0;
        jqTemplateThs.each((idx, elm) => {
            let jqThis = $(elm);
            // If this has a data-sort attribute, then copy it to the target table.
            let sort = jqThis.data('sort');
            if (sort)
            {
                // Create the data attribute. The data() method does not set the HTML attribute.
                jqTargetThs.eq(idx).attr('data-sort', sort);
                ++cSortables;
            }
        });

        if (cSortables)
            drnInitOneSortableTable(jqBody.find('table'));
    }

    /**
     *  Returns an array of table row objects, one for every row that is checked.
     *  Requires that the table have a checkbox column.
     *
     *  Every row has numeric keys 0, 1, 2, 3 for the table columns, with the
     *  column data as the value.
     *
     *  Additionally, if the row's <tr> has an HTML ID, it is returned as _id,
     *  and the class would be returned as _class.
     */
    public getSelections()
    {
        if (this._jqTable)
            return this._jqTable.bootstrapTable('getSelections');
        return null;
    }

    public findTBody(): JQuery
    {
        let tbody = this._jqTarget.find('table tbody');
        if (!tbody.length)
            throw "Cannot find table body";
        return tbody;
    }

    /**
     *  Attempts to find the table row that corresponds to the given click() event argument.
     *  Returns -1 if not found.
     */
    protected findRowIndexFromClickEvent(e: Event)
        : number
    {
        let jqCell = $(e.target).closest('td');
        let jqRow = jqCell.closest('tr');
        return jqRow.index();
    }

    /**
     *  Returns a JQuery object for the table row with the given index,
     *  or an empty object if not found.
     */
    public findTableRow(iRow: number)
        : JQuery
    {
        let tr: JQuery;
        let tbody = this.findTBody();
        let aTR = tbody.find('tr');
        if (!aTR.length)
            throw "Cannot find table rows";
        tr = aTR.eq(iRow);
        if (!tr.length)
            throw "Cannot find table row " + iRow;
        return tr;
    }

    protected onCheckUncheck(e, rows)
    {
    }

    protected abstract insertRow(row): JQuery;

    protected onClickPage(page)
    {
    }
}


/********************************************************************
 *
 * AjaxTableRenderedBase class
 *
 ********************************************************************/

/**
 *  AjaxTableRenderedBase is another abstract base class that derives
 *  from AjaxTableBase and hopefully makes updating only a part of the
 *  table a bit easier.
 *
 *  This class provides three useful methods for table manipulation:
 *
 *   -- onRowAdded(), to be called after a new row has been added to
 *      the table model (e.g. after a "create" dialog has succeeded
 *      with a POST call);
 *
 *   -- onRowChanged(), to be called after an existing row has been
 *      modified in the table model (e.g. after an "edit" dialog has
 *      succeeded with a PUT call);
 *
 *   -- onRowDeleted(), to be called after an existing row has been
 *      removed from the table model (e.g. after a DELETE call has
 *      succeeded).
 *
 *  For this to work, this class implements the parent's abstract
 *  insertRow() method. A derived class needs to do two things for
 *  of the above to work:
 *
 *   1) implement the newly introduced renderCell() method;
 *
 *   2) identify the identifier of a data column in the ajax row
 *      interface that serves as the "id" of every row and identifies
 *      the row uniquely in the table (e.g. 'uid' for a user ID,
 *      if that is the name of the user ID field in a user row).
 */
export abstract class AjaxTableRenderedBase extends AjaxTableBase
{
    public aRows: any[] = [];
    protected fFetched = false;

    constructor(idDialog: string,
                protected url: string,
                protected aPlaceholders: string[],
                public nameIdColumn,                 //!< in: name of column that holds row IDs
                protected resultsField = 'results')
    {
        super(idDialog);
    }

    public prepareFetchData()
    {
        this.clear();
        this.setSpinner();
    }

    public doFetchData(fnSuccess: (oGetResponse: any) => void)
    {
        this.fFetched = true;
        this.execGET(this.url,
                     fnSuccess);
    }

    /**
     *  Invokes the Ajax call given to the constructor.
     *  This does NOT get called automatically by the constructor since
     *  the derived class may want to delay loading the data if several
     *  tables are instantiated at the same time.
     */
    public fetchData()
    {
        this.prepareFetchData();
        this.doFetchData((oGetResponse: any) => {
            this.onFetchDataSuccess(oGetResponse);
        });
    }

    /**
     *  Called by the fetchData() success callback. This calls fill() in turn.
     *  Subclasses can override this if there is other data besides the results
     *  field in the response data.
     */
    public onFetchDataSuccess(oGetResponse: any)
    {
        if (oGetResponse.hasOwnProperty(this.resultsField))
            this.fill(oGetResponse[this.resultsField]);
    }

    /**
     *  Helper that can be called by a renderCell() implementation to
     *  have a checkbox icon for a boolean value.
     */
    protected makeBoolCell(f: boolean)
        : string
    {
        return '<td>' + getIcon((f) ? 'checkbox_checked' : 'checkbox_unchecked') + '</td>';
    }

    /**
     *  Helper to create an action button for a table cell.
     */
    protected makeActionButton(cls: string,
                               icon: string,
                               tooltip: string,
                               fEnableButton = true)
        : string
    {
        const icon2 = getIcon(icon);
        const tool = this.makeTooltipAttrs(tooltip);
        if (fEnableButton)
            return `<a href='#' class='${cls}'${tool}>${icon2}</a>`;

        return `<span ${tool}>${icon2}</span>`;
    }

    /**
     *  This abstract method must be implemented by a derived class
     *  and return an HTML string for a single table cell. For each
     *  data row from the AJAX call, this will be called several
     *  times with the same row, but different placeholder strings.
     *
     *  The returned string must be enclosed in <td>..</td> tags.
     *  This way the implementation can add color attributes to the
     *  cell if so desired.
     *
     *  The subclass will implement this with a more specific row type
     *  for "row" and then be able to return a string for a given placeholder
     *  from that specific row. As a result, ONLY this function implementation
     *  needs to know what data type "row" actually has.
     */
    protected abstract renderCell(placeholder: string,      //!< in: '%XXX%' placeholder string
                                  row: any,                 //!< in: row data from ajax call (type known to subclass)
                                  extraClasses?: string): string;

    /**
     *  Implementation of the required abstract AjaxTableBase method.
     */
    protected insertRow(row)
        : JQuery
    {
        let htmlRow = this._htmlTemplateRow;
        const jqTH = this._jqTemplate.find('th');
        for (let placeholder of this.aPlaceholders)
        {
            let html = this.renderCell(placeholder, row);

            // Add classes from template.
            const jqHeading = jqTH.eq(this.aPlaceholders.indexOf(placeholder));
            if (jqHeading.length && jqHeading.prop('className'))
            {
                const jqHTML = $(`<tr>${html}</tr>`);
                const extraClasses = jqHeading.prop('className').split(' ');
                const jqTD = jqHTML.find('td');
                for (const className of extraClasses)
                    jqTD.addClass(className);
                html = jqHTML.html();
            }
            htmlRow = htmlRow.replace(`<td>${placeholder}</td>`,
                                      html);
        }

        this.aRows.push(row);

        return $(htmlRow);
    }

    /**
     *  Finds the index of the row with the given ID (where the ID is
     *  the value of the row column with the 'nameIdColumn' identifier
     *  given to the constructor).
     */
    protected findRowIndex(id: number)
        : number
    {
        let i = 0;
        for (let row of this.aRows)
        {
            if (row.hasOwnProperty(this.nameIdColumn))
            {
                let idThat = row[this.nameIdColumn];
                if (idThat == id)
                    return i;
            }
            ++i;
        }
        return -1;
    }

    /**
     *  Finds the raw row data (to be typecast by caller) from the
     *  given click() event, or throws if not found. Useful for
     *  button event handlers in table cells.
     */
    protected findRowDataFromClickEvent(e: Event)
        : any
    {
        const iRow = this.findRowIndexFromClickEvent(e);
        if ((iRow >= 0) && (iRow < this.aRows.length))
            return this.aRows[iRow];

        throw "Cannot find account";
    }

    /**
     *  To be called by a "create new row" dialog after the POST
     *  AJAX call has succeeded and a new row should be added to
     *  the table.
     */
    public onRowAdded(row: any)     //!< in: row object in format that renderCell() understands
    {
        let jqNewRow = this.insertRow(row);
            // this updates the model already

        // Find the headers in the template and copy classes like text-center.
        let jqTH = this._jqTemplate.find('th');
        if (!jqTH.length)
            throw "Cannot find table headers in template";
        jqTH.each((i, elm) => {
            if (elm.className)
                jqNewRow.find('td').eq(i).addClass(elm.className);
        });

        // TODO insert at the end of the table for now, needs sorting support

        let tbody = this.findTBody();
        tbody.append(jqNewRow);

        this.addHandlersToNewRow();

        // Now that it's inserted, find it again as a jquery object so
        // we can animate it and attach another handler to the whole row.
        const indexNewRow = this.aRows.length - 1;
        let jqNewRowPost = this.findTableRow(indexNewRow);
        drnAnimateOnce(jqNewRowPost, 'fadeInDownBig');
    }

    /**
     *  To be called by a "edit row" dialog after the PUT AJAX call
     *  has succeeded and an existing row needs to be updated with
     *  new data.
     */
    public onRowChanged(row: any)   //!< in: row object in format that renderCell() understands
    {
        if (!row.hasOwnProperty(this.nameIdColumn))
            throw "Changed row has no id property";

        let id = row[this.nameIdColumn];
        let iRow = this.findRowIndex(id);
        if (iRow < 0)
            throw "Cannot find row with id " + id;

        let jqTR = this.findTableRow(iRow);
        let jqTD = jqTR.find('td');
        if (!jqTD.length)
            throw "Cannot find table headers in template";

        let i = 0;
        const jqTH = this._jqTemplate.find('th');
        for (let placeholder of this.aPlaceholders)
        {
            let html = this.renderCell(placeholder, row);

            // Add classes from template.
            const jqHeading = jqTH.eq(i);
            if (jqHeading.length && jqHeading.prop('className'))
            {
                const jqHTML = $(`<tr>${html}</tr>`);
                const extraClasses = jqTD.prop('className').split(' ');
                const jqCell = jqHTML.find('td').first();
                for (const className of extraClasses)
                    jqCell.addClass(className);
                html = jqHTML.html();
            }

            jqTD.eq(i).replaceWith(html);
            ++i;
        }

        drnAnimateOnce(jqTR, 'shake');

        // Update our model.
        this.aRows[iRow] = row;

        this.addHandlersToNewRow();
    }

    /**
     *  Handler that can be called to delete a row from the table view
     *  after a row has already been removed from the model (database).
     */
    public onRowDeleted(id: number)
    {
        let iRow = this.findRowIndex(id);
        if (iRow >= 0)
        {
            let tr = this.findTableRow(iRow);

            drnAnimateOnce( tr,
                           'fadeOutDown',
                            () => {
                                tr.remove();
                            });

            // Remove the row from our model too.
            this.aRows.splice(iRow, 1);
        }
    }
}

/**
 *  Helper class that implements a "create" or "edit" dialog for
 *  a row in an AjaxTableRenderedBase. If a row is passed to the constructor,
 *  the dialog must edit that row; otherwise it must create a new row.
 *
 *  This assumes that
 *
 *   -- the same dialog template is used for both "create" and "edit",
 *      probably with minor text modifications (which the derived
 *      class must handle);
 *
 *   -- both the POST and PUT commands return with a 'result' field
 *      containing a row for the table on success (please check the
 *      back-end that they do; older APIs might need adjusting);
 *
 *   -- that the PUT command is the same as the POST command except
 *      for an additional slash followed by the row id, whose name
 *      we retrieve from the parent table.
 */
export class CreateOrEditTableRowDialog extends AjaxModal
{
    constructor(idDialog: string,                      //!< in: id of main <div class="model"> element
                aFieldIDs: string[],         //!< in: list of data items to submit (without "idDialog-" prefixes)
                url: string,                 //!< in: pure POST request name (without rootpage and /api, but with leading slash)
                protected oParent: AjaxTableRenderedBase,
                protected oRow: any | null)                   //!< in: table row for "edit" dialog, or null for "create" dialog
    {
        super(idDialog,
              aFieldIDs,
              url,
              false);           // parent must call show()!

        if (oRow)
        {
            // Editing existing table row:
            this.method = 'PUT';
            this.url += '/' + oRow[oParent.nameIdColumn];
        }
    }

    protected onSubmitSuccess(json: any)
        : void
    {
        if (!json.hasOwnProperty('result'))
            throw "expected 'result' field in JSON response";

        super.onSubmitSuccess(json);

        if (this.oRow === null)
            // Created new row:
            this.oParent.onRowAdded(json.result);
        else
        {
            // Edited existing account:
            this.oRow = json.result;
            this.oParent.onRowChanged(this.oRow);
        }
    }
}

/**
 *  Helper class that implements a "really delete" dialog in relation to an
 *  AjaxTableRenderedBase. This assumes that
 *
 *   -- idDialog has some meaningful text like "are you sure";
 *
 *   -- url is the complete ajax DELETE request that should be invoked if the
 *      user selects OK, including the row ID; we set the method to DELETE here.
 *
 *  This will then invoke onRowDeleted() in the parent table automatically.
 */
export class ConfirmDeleteTableRowDialog extends AjaxModal
{
    constructor(idDialog: string,                       //!< in: ID of delete confirmation dialog to display
                url: string,                            //!< in: pure POST request name (without rootpage and /api, but with leading slash)
                protected oParent: AjaxTableRenderedBase,
                protected oRow: any)                 //!< in: name of column that holds row IDs
    {
        super(idDialog,
              [ ],
              url);

        this.method = 'DELETE';
    }

    protected onSubmitSuccess(json): void
    {
        super.onSubmitSuccess(json);

        this.oParent.onRowDeleted(this.oRow[this.oParent.nameIdColumn]);
    }
}
