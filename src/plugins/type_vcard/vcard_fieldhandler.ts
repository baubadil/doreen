/*
*  Copyright 2015-17 Baubadil GmbH. All rights reserved.
*/
import APIHandler from "../../js/inc-apihandler";
import { drnFindByID, drnShowAndFadeIn } from "../../js/shared";

interface Address
{
    type: 'HOME' | 'WORK' | 'OTHER';
    extended: string;
    street: string;
    city: string;
    region: string;
    zip: string;
    country: string;
}

interface Phone
{
    type: 'HOME' | 'WORK' | 'FAX' | 'CELL' | 'PAGER';
    phone: string;
}

interface Email
{
    type: 'HOME' | 'WORK' | 'INTERNET';
    email: string;
}

interface Url
{
    type: 'HOME' | 'WORK';
    url: string;
}

interface VCard
{
    lastname: string;
    firstname: string;
    additional: string;
    prefix: string;
    suffix: string;

    organization: string;
    title: string;              // job title

    aAddresses: Address[];
    aPhones: Phone[];
    aEmails: Email[];
    aUrls: Url[];

    note: string;
}

const A_ADDRESS_FIELDS = [ 'extended', 'street', 'city', 'region', 'zip', 'country' ];

/**
 *  Wrapper class around the many entry fields and other controls generated by
 *  VCardHandler in the PHP back-end in MODE_EDIT.
 *
 *  This attaches handlers to almost all controls of the field and updates the hidden
 *  entry field that contains the vCard JSON that actually gets submitted.
 */
export class VCardEditor extends APIHandler
{
    protected jqVcardFormOuterDiv;
    protected jqVcardFormEntryField;
    protected jqTitleEntryField;
    protected fTitleEntryFieldManuallyEdited = false;  // Set to true as soon as the user types into the title field, to disable automatic updates.

    constructor(private idDialog: string,               // e.g. "editticket"
                private idControlBase: string,          // e.g. "editticket-vcard"
                private aSimpleDialogFields: string[],  //!< in: simple fields like 'company'
                private oArrayClassNames: any,          //!< in: fields with multiple entries as array => class pairs (aEmails: email etc.)
                private fSimpleFormat: boolean)
    {
        super();

        this.jqVcardFormOuterDiv = drnFindByID(`${idControlBase}-outerdiv`);
        this.jqVcardFormEntryField = drnFindByID(idControlBase);

        // Hack into the entry field of the "title" handler so we can update it if needed.
        this.jqTitleEntryField = drnFindByID(`${idDialog}-title`);
        this.jqTitleEntryField.on('input', () => {
            this.fTitleEntryFieldManuallyEdited = true;
        });

        for (let idstem of aSimpleDialogFields)
            drnFindByID(idControlBase + '-' + idstem).on('input', () => {
                this.updateCombinedJsonAndTitle();
            });

        for (let arrayname in this.oArrayClassNames)
            if (this.oArrayClassNames.hasOwnProperty(arrayname) && this.oArrayClassNames[arrayname])
            {
                let classname = this.oArrayClassNames[arrayname];
                this.jqVcardFormOuterDiv.find(`.vcard-${classname}-type`).on('change', () => {
                    this.updateCombinedJsonAndTitle();
                });
                let aSubfields = (classname == 'address')
                    ? A_ADDRESS_FIELDS
                    : [ 'value' ];

                for (let subfield of aSubfields)
                    this.jqVcardFormOuterDiv.find(`.vcard-${classname}-${subfield}`).on('input', () => {
                        this.updateCombinedJsonAndTitle();
                    })
            }

        if (fSimpleFormat)
        {
            this.onSimpleFormatClicked(true);
            drnShowAndFadeIn(this.jqVcardFormOuterDiv);
        }

        let jqCheckbox = drnFindByID(idControlBase + '-simpleformat');
        jqCheckbox.on('click', () => {
            this.onSimpleFormatClicked(jqCheckbox.prop('checked'));
        });
    }

    private updateCombinedJsonAndTitle()
    {
        let oCombined = {};
        for (let idstem of this.aSimpleDialogFields)
        {
            let id = this.idControlBase + '-' + idstem;
            let jq = drnFindByID(id);
            let val = jq.val();
            if (val)
                oCombined[idstem] = val;
        }

        for (let arrayname in this.oArrayClassNames)
            if (this.oArrayClassNames.hasOwnProperty(arrayname) && this.oArrayClassNames[arrayname])
            {
                let classname = this.oArrayClassNames[arrayname];

                // Each of the groups has a select and an entry field, except address, which is more complicated.
                // A group has at least one bootstrap row, but addresses have several.
                let jqRows = this.jqVcardFormOuterDiv.find(`.vcard-${classname}-group`);
                jqRows.each((index, elm) => {
                    let valType = $(elm).find('select').val();

                    let o = { type: valType };
                    let fPush = false;
                    if (classname == 'address')
                    {
                        for (let subfield of A_ADDRESS_FIELDS)
                        {
                            let valEntry = $(elm).find(`.vcard-address-${subfield}`).val();
                            if (valEntry)
                            {
                                o[subfield] = valEntry;
                                fPush = true;
                            }
                        }
                    }
                    else
                    {
                        let valEntry = $(elm).find('input').val();
                        if (valEntry)
                        {
                            o[classname] = valEntry;
                            fPush = true;
                        }
                    }

                    if (fPush)
                    {
                        if (!oCombined.hasOwnProperty(arrayname))
                            oCombined[arrayname] = [];
                        oCombined[arrayname].push(o);
                    }
                })
            }

        // Update the hidden JSON entry field. Only that gets submitted.
        this.jqVcardFormEntryField.val(JSON.stringify(oCombined));

        // Update the ticket title ("display name") automatically unless the user has typed into the title themselves.
        if (!this.fTitleEntryFieldManuallyEdited)
        {
            let jqFirstName = drnFindByID(this.idControlBase + '-firstname');
            let strFirstName = jqFirstName.val();
            let jqLastName = drnFindByID(this.idControlBase + '-lastname');
            let strLastName = jqLastName.val();

            let strDisplayName = strFirstName;
            if (strFirstName.length && strLastName.length)
                strDisplayName += " ";
            strDisplayName += strLastName;

            this.jqTitleEntryField.val(strDisplayName);
        }
    }

    /**
     *  Returns the "real" parent DIV of an input control. Trouble is if the input
     *  has an icon, then bootstrap wraps an additional 'input-group' div around it,
     *  which we need to skip.
     */
    private findInputParentDiv(jq: JQuery)
        : JQuery
    {
        let jqParent = jq.parent();
        if (jqParent.hasClass('input-group'))
            return jqParent.parent();
        return jqParent;
    }

    /**
     *  Handler for when the "Simple format" checkbox gets clicked. Also gets called if simple format
     *  was initially enabled.
     */
    private onSimpleFormatClicked(fSimple: boolean)
    {
        let aControlsToShowOrHide: JQuery[] = [];

        for (let idSuffix of ['prefix', 'suffix', 'organization', 'title'])
        {
            let idControl = this.idControlBase + '-' + idSuffix;
            let jqControl = drnFindByID(idControl);
            let jqParentDiv = this.findInputParentDiv(jqControl);

            let jqGrandparentDiv = jqParentDiv.parent();
            if (jqParentDiv.hasClass('col-xs-10'))
                // Show or hide the entire row.
                aControlsToShowOrHide.push(jqGrandparentDiv);
            else
            {
                aControlsToShowOrHide.push(jqControl);
                let jqLabel = jqGrandparentDiv.find(`label[for='${idControl}']`);
                aControlsToShowOrHide.push(jqLabel);
            }
        }

        let jqShrinkSimple = this.jqVcardFormOuterDiv.find('div .vcard-shrink-simple');
        let jqHideSimple = this.jqVcardFormOuterDiv.find('div .vcard-hide-simple');
        if (fSimple)
        {
            jqShrinkSimple.removeClass('col-xs-4');
            jqShrinkSimple.addClass('col-xs-2');
            aControlsToShowOrHide.push(jqHideSimple);
        }
        else
        {
            jqShrinkSimple.removeClass('col-xs-2');
            jqShrinkSimple.addClass('col-xs-4');
            aControlsToShowOrHide.push(jqHideSimple);
        }

        for (let arrayname in this.oArrayClassNames)
            if (this.oArrayClassNames.hasOwnProperty(arrayname) && this.oArrayClassNames[arrayname])
            {
                let type = this.oArrayClassNames[arrayname];
                let jq = this.jqVcardFormOuterDiv.find(`.vcard-${type}-type`);
                aControlsToShowOrHide.push(jq);
            }

        for (let divclass of [ 'vcard-email-group',      // whole row
                               'vcard-url-group',        // whole row
                               'vcard-address-extended',
                               'vcard-address-region',
                               'vcard-address-country' ])
        {
            let jq = this.jqVcardFormOuterDiv.find(`.${divclass}`);
            let jqParent = jq.parent();
            if (jqParent.hasClass('input-group'))
                jq = jq.parent();
            aControlsToShowOrHide.push(jq);
        }

        for (let jq of aControlsToShowOrHide)
            if (fSimple)
                jq.addClass('hide');
            else
                drnShowAndFadeIn(jq);
    }
}

