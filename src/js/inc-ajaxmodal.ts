/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import AjaxForm from './inc-ajaxform';
import DrnWysihtml from './wysihtml';
import { drnFindByID } from './shared';

export const DRN_NAMESPACE = '.drn-handler-namespace';

/**
 *  Wrapper around a bootstrap modal which automatically sends an AJAX POST request when
 *  the user presses the submit button.
 *
 *  This is based on AjaxForm but additionally handles the showing and hiding of the Bootstrap modal.
 *
 *  For this to work, do the following:
 *
 *   -- Have a <div class="modal"...>...</div> in your HTML, or generate it dynamically.
 *
 *      NOTE: the button MUST be of type 'submit', as opposed as with the parent AjaxForm.
 *
 *   -- Create an instance, passing the the list of fields and the POST URL to the constructor.
 *
 *   -- Enjoy.
 */
export default class AjaxModal extends AjaxForm
{
    private jqTextInputs;

    /**
     *  idDialog must be the ID of the main <div class="modal...> element.
     *
     *  The dialog MUST have a submit button with the `${_idDialog}-submit` ID.
     *
     *  The constructor runs the Bootstrap modal() function, so immediately after creating an
     *  instance of this, the dialog shows up, unless fShowNow is false.
     */
    constructor(idDialog: string,                      //!< in: id of main <div class="model"> element
                aFieldIDs: string[],         //!< in: list of data items to submit (without "idDialog-" prefixes)
                url: string,                 //!< in: pure POST request name (without rootpage and /api, but with leading slash)
                fShowNow: boolean = true)
    {
        super(idDialog, aFieldIDs, url);

        this.jqTextInputs = $('input[type=text]');
        this.jqTextInputs.on('keypress' + DRN_NAMESPACE, (e) => {
            if (e.which == 13) {
                e.preventDefault();
            }
        });

        // All the submit variants end up here. Call out method.
        this.jqDialog.on('submit' + DRN_NAMESPACE, (e) => {
            this.onSubmit();
            e.stopPropagation();
        });

        // Disable Enter key handling in the text entry field, or else we'll get it twice.
        this.jqDialog.on('keypress' + DRN_NAMESPACE, (e) => {
            if (e.which == 13)
                this.jqDialog.trigger('submit');
            e.stopPropagation();
        });

        if (fShowNow)
            this.show();

        // Now, attach a handler to the "hide" event in which we can remove all
        // the above handlers again. Otherwise we get multiple ajax requests for
        // the same modal if it gets opened more than once!
        this.jqDialog.one(  'hidden.bs.modal',
                            () => {
                                this.onHide();
                            });
    }

    /**
     *  Event handler for the 'hidden.bs.modal' Bootstrap Modal event, which gets
     *  fired when the dialog has been dismissed (submitted, canceled, closed, for
     *  whatever reason). We use this handler to detach event handlers from the
     *  dialog; if you derive a subclass from AjaxModal which installs its own
     *  event handlers, you should override this method to detach them as well.
     */
    protected onHide()
        : void
    {
        this.jqTextInputs.off('keypress' + DRN_NAMESPACE);
        this.jqDialog.off('submit' + DRN_NAMESPACE);
        this.jqDialog.off('keypress' + DRN_NAMESPACE);
    }

    /**
     *  Searches the DOM under the dialog for all elements with the given class
     *  and then does a find/replace on their HTML with the data from the given
     *  object. This is useful for updating %PLACEHOLDER% variables from a template.
     */
    public findReplaceFields(domClass: string,       //!< in: DOM class name without '.' prefix
                             oFindReplace: any)
        : void
    {
        this.jqDialog.find('.' + domClass).each((index, elm) =>
        {
           let jqElm = $(elm);
           let htmlElm = jqElm.html();
           let c = 0;
           for (let key in oFindReplace)
               if (oFindReplace.hasOwnProperty(key)) {
                   let replace = oFindReplace[key];
                   htmlElm = htmlElm.replace(key, replace);
                   ++c;
               }

            if (c)
                jqElm.html(htmlElm);
        });
    }

    /**
     *  Helper to get WYSIHTML dialogs to work within Bootstrap dialogs.
     *  Call this from your derived constructor with the full ID of the
     *  textarea.
     */
    public initWYSIHTML(idControl,                      //!< in: full ID of text area
                        fExtended: boolean = false)     //!< in: extended mode?
        : DrnWysihtml
    {
        let o = new DrnWysihtml(idControl, fExtended);

        this.jqDialog.on('hidden.bs.modal', function(e)
        {
            let jqTextArea = drnFindByID(idControl);
            let jqParent = jqTextArea.parent();
            jqParent.find('iframe').remove();
            jqTextArea.show();
        });

        return o;
    }

    public show()
        : void
    {
        this.jqDialog.modal({ keyboard: true,
                              backdrop: 'static' } );
    }

    /**
     *  Called by the parent when the submit button gets clicked. With the bootstrap
     *  modal, we assume there is an actual type=submit button and trigger that.
     *  This will call the onSubmit() method in the parent.
     */
    protected onSubmitButtonClicked()
        : void
    {
        this.jqDialog.trigger('submit');
    }

    /**
     *  Override of the empty APIHandler method. This gets called with a short delay after execAjax
     *  has reported success. This default implementation hides the dialog, but subclasses can
     *  implement additional things.
     */
    protected onSubmitSuccess(json)
        : void
    {
        this.jqDialog.modal('hide');
    }

}
