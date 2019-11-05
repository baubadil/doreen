/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import FormBase from './inc-formbase';
import { drnFindByID, dlgBackupRestore } from './shared';

/**
 *  AjaxForm is an abstractor for any <form> that sends out a Doreen Ajax request with the
 *  form data.
 *
 *  It extends FormBase in that it looks for a submit button and attaches handlers to fetch
 *  data from the form control and produce an Ajax request from it, which is then sent
 *  via the APIHandler grandparent.
 *
 *  AjaxModal derives from this to additionally handle a Bootstrap modal that contains a form.
 *  Use this class directly if you have a form WITHOUT a bootstrap modal.
 */
export default class AjaxForm extends FormBase
{
    protected jqDialog: JQuery;
    protected jqSubmitButton: JQuery;
    protected method = 'POST';
    protected oData: any;               // contains results after onSubmit() has been called

    constructor(idDialog: string,
                protected aFieldIDs: string[],         //!< in: list of data items to submit (without "idDialog-" prefixes)
                protected url: string,                 //!< in: pure POST request name (without rootpage and /api, but with leading slash)
                protected buttonId: string = 'submit') //!< in: Sub-ID of the submit button.
    {
        super(idDialog);

        this.jqDialog = drnFindByID(idDialog);
        if (!this.jqDialog.length)
            throw "Cannot find dialog with ID " + idDialog;

        dlgBackupRestore(this.jqDialog);

        this.jqSubmitButton = this.findDialogItem(buttonId);

        // Handle Enter key and 'save' button press uniformly, but only once.
        this.jqSubmitButton.click((e) => {
            e.preventDefault();
            this.onSubmitButtonClicked();
        });
        // this.buttonHideSpinner(this.jqSubmitButton);
    }

    /**
     *  Overridden in AjaxModal.
     *
     */
    protected onSubmitButtonClicked()
        : void
    {
        this.onSubmit();
    }

    /**
     *  Returns HTML for a button that acts as a checkbox.
     */
    protected makeButtonCheckbox(addtlClass,
                                 htmlLabel,
                                 value,
                                 fChecked)
    {
        return   `<label class="btn btn-default btn-xs ${(fChecked) ? 'active' : ''}">`
               + `<input class="${addtlClass}" type="checkbox" autocomplete="off" value="${value}"${(fChecked) ? ' checked' : ''}>${htmlLabel}</label>`;
    }

    protected enableItem(subid: string, fEnable: boolean): void
    {
        this.findDialogItem(subid).attr('disabled', fEnable ? '' : 'disabled');
    }

    /**
     *  Retrieves the value of the control in the given JQuery object.
     */
    protected getValue(jqElm,
                       idField: string)     //!< in: dialog ID (without dialog prefix and '-')
        : any
    {
        if (jqElm.is('input'))
        {
            let inputType = jqElm.attr('type');
            switch (inputType)
            {
                case 'checkbox':
                    // These are special; see
                    return jqElm.is(":checked");

                case 'button':
                case 'submit':
                    // ignore buttons
                break;

                default:
                    return jqElm.val();
            }
        }
        else if (jqElm.is('textarea'))
        {
            let keyWysi = 'wysihtml-' + idField;
            if (keyWysi in g_globals)
                return g_globals[keyWysi].getValue();
            else
                return jqElm.val();
        }
        else if (jqElm.is('iframe'))
            /* This is how to get the inner HTML out of the IFrame.
             http://stackoverflow.com/questions/1796619/how-to-access-the-content-of-an-iframe-with-jquery */
            return jqElm.contents().find("body").html();
        else if (jqElm.is('select'))
        {
            /* Create a comma-separated list of the value attributes of all selected <option> elements.
             The following should work for both plain select/option pairs and the ones beefed up by
             bootstrap-multiselect, as that updates the 'selected' field in the select/option it was
             created from automatically.
             */
            let aSelected = [];
            for (let elm2 of jqElm.find("option:selected"))
               aSelected.push(elm2.getAttribute('value'));
            return aSelected.join(',');
        }
        else if (    (jqElm.is('div'))
                  && (jqElm.hasClass('drn-checkbox-group'))
                )
        {
            // This is special: this is how we can combine a several ticked checkboxes into a single array value.
            let jqCheckboxes = jqElm.find("input[type='checkbox']:checked");
            let aChecked = [];
            jqCheckboxes.each((index, elm) => {
                aChecked.push($(elm).val());
            });
            return aChecked;
        }
        else if (    (jqElm.is('div'))
                  && (jqElm.hasClass('drn-radio-group'))
                )
        {
            // This is also special: this is how we can submit the checked radio as a single value under the name.
            let jqCheckboxes = jqElm.find("input[type='radio']:checked");
            if (jqCheckboxes.length == 1)
                return jqCheckboxes.val();
        }
        else
            throw "Unrecognized control type for dialog field ID " + FormBase.quote(idField);
    }

    /**
     *  This goes through the field IDs passed to the constructor, assuming that matching form
     *  controls exist in the dialog, and fetches data from them. Returns an object with
     *  according key/value pairs.
     *
     *  Subclasses can override this if a different method for coming up with dialog data is
     *  preferred.
     */
    protected fetchData(): any
    {
        let oData = {};
        for (let idField of this.aFieldIDs)
        {
            let jqElm = drnFindByID(this._idDialog + '-' + idField);
            if (jqElm.length)
                oData[idField] = this.getValue(jqElm, idField);
        }

        return oData;
    }

    protected makePostURL(): string
    {
        if (!this.url)
            throw "Cannot submit without URL";
        return g_rootpage + '/api' + this.url;
    }

    protected onSubmit(): void
    {
        this.hideError(this.jqDialog);
        try
        {
            this.oData = this.fetchData();
            this.execAjax(  this.jqSubmitButton,
                            this.method,
                            this.makePostURL(),
                            this.oData);
        }
        catch(e)
        {
            this.showError(this.jqDialog, e.toString());
        }
    }

    /**
     *  Override of the empty APIHandler method. This gets called with a short delay after execAjax
     *  has reported an error. This default implementation reports the error in the dialogs error box.
     */
    protected onSubmitError(jqXHR): void
    {
        this.handleError(this.jqDialog, jqXHR);
    }
}
