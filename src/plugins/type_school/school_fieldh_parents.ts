/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as $ from 'jquery';
import APIHandler from '../../js/inc-apihandler';
import { drnFindByID, drnShowAndFadeIn } from '../../js/shared';

interface ParentDef
{
    uid: number;
    email: string;
    longname: string;
    mobile: string;
}

const DELETING_IGNORE_THIS_CLASS_DURING_UPDATE = 'drn-data-ignore';

const PARENT_KEYS = ['uid', 'longname', 'email', 'mobile'];

/**
 */
export class SchoolParentsEditor extends APIHandler
{
    protected jqEntryField;
    protected jqAddAnotherParentButton;
    protected jqRowTemplate;
    protected jqPlayground;
    protected initialValue;

    constructor(protected idEntryField: string)
    {
        super();

        this.jqEntryField = drnFindByID(idEntryField);
        this.jqEntryField.on('input', () => {

        });

        this.jqAddAnotherParentButton = drnFindByID(`${idEntryField}-add-parent`);
        this.jqAddAnotherParentButton.on('click', () => {
            this.insertRow(null);
        });

        this.jqRowTemplate = drnFindByID(`${idEntryField}-row-template`);
        this.jqPlayground = drnFindByID(`${idEntryField}-playground`);

        this.initialValue = this.jqEntryField.val();
        let c = 0;
        if (this.initialValue)
        {
            let aParents: ParentDef[] = JSON.parse(this.initialValue);
            for (let oParent of aParents)
            {
                this.insertRow(oParent);
                ++c;
            }
        }
        if (!c)
        {
            this.insertRow(null);
            this.insertRow(null);
        }
    }

    protected insertRow(oParent: ParentDef | null)
    {
        let newRow = this.jqRowTemplate.clone();
        newRow.attr('id', null);

        for (let key of PARENT_KEYS)
        {
            let jq = newRow.find(`.${this.idEntryField}-${key} input`);
            if (oParent)
                jq.val(oParent[key]);
            jq.on('input', () => {
                this.rebuildJson();
            });
        }

        newRow.find(`.${this.idEntryField}-delete`).on('click', (e) => {
            let jq = $(e.target);       // link probably
            let jqRow: JQuery;
            while (1)
            {
                let jqParent = jq.parent();
                if (!jqParent.length)
                    break;
                if (jqParent.hasClass('row'))
                {
                    jqRow = jqParent;
                    break;
                }
                jq = jqParent;
            }
            jqRow.addClass(DELETING_IGNORE_THIS_CLASS_DURING_UPDATE);
            this.rebuildJson();
            jqRow.fadeOut(400, () => {
                jqRow.remove()
            });
        });

        if (oParent)
            newRow.removeClass("hidden");
        else
            drnShowAndFadeIn(newRow);

        this.jqPlayground.append(newRow);
    }

    protected rebuildJson()
    {
        let aParents: ParentDef[] = [];
        let jqRows = this.jqPlayground.find('.row');
        $.each(jqRows, (iRow, row) => {
            let jqRow = $(row);
            if (!(jqRow.hasClass(DELETING_IGNORE_THIS_CLASS_DURING_UPDATE)))
            {
                let oParentThis: ParentDef = <any>{};
                for (let key of PARENT_KEYS)
                    oParentThis[key] = jqRow.find(`.${this.idEntryField}-${key} input`).val();
                if (oParentThis.email)
                    aParents.push(oParentThis);
            }
        });

        this.jqEntryField.val(JSON.stringify(aParents));
    }
}

