/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as $ from 'jquery';
import AjaxModal from '../../js/inc-ajaxmodal';
import {
    ITemplate,
    GetAllTemplatesApiResult,
    ApiResult,
    Comment
} from '../../js/inc-api-types';
import { myEscapeHtml, drnShowAndFadeIn, drnFindByID } from '../../js/shared';
import APIHandler from '../../js/inc-apihandler';
import AjaxForm from '../../js/inc-ajaxform';
import DrnWysihtml from '../../js/wysihtml';
import _ from '../../js/nls';
import getIcon from '../../js/icon';
import { cancelPickNewerVersionFile } from './view_ticket_old';


/********************************************************************
 *
 *  ChangeTicketTemplateDialog
 *
 ********************************************************************/

/**
 *  Client-side code for changing the template of a ticket. This is
 *  part of the ticket details page and only available to admins.
 *
 *  An instance of this is instantiated from PHP via the core_initChangeTemplateDialog() entry point.
 */
export class ChangeTicketTemplateDialog extends AjaxModal
{
    protected oTemplatesByID: { [id: number]: ITemplate; } = {};

    protected jqTemplateSelect: JQuery;
    protected jqAccessDescription: JQuery;
    protected fFirst = true;

    constructor(idDialog: string,
                idTicket: number,
                protected idTemplate: number,
                protected idType: number)
    {
        super(idDialog,
              [ 'template' ],     // ID of the template select drop-down
              '/template-under-ticket/' + idTicket,
              false);

        this.method = 'PUT';

        this.jqTemplateSelect = this.findDialogItem('template');
        this.jqAccessDescription = this.findDialogItem('permissions');

        this.jqTemplateSelect.change(() => {
            this.onTemplateSelected();
        });

        this.getAllTemplates((json: GetAllTemplatesApiResult) =>
        {
            for (let o of json.results)
            {
                this.oTemplatesByID[o.ticket_id] = o;

                if (o.type_id == idType)
                {
                    let tpl = myEscapeHtml(o.template);
                    this.jqTemplateSelect.append(`<option value="${o.ticket_id}">${tpl}</option>`);
                }
            }
            this.jqTemplateSelect.removeClass('hide');
            this.findDialogItem('select-spinner').addClass('hide');

            this.jqTemplateSelect.find(`option[value=${idTemplate}]`).prop('selected', 'selected');
            this.onTemplateSelected();
        });

        this.show();
    }

    protected onTemplateSelected()
    {
        let idTemplate = this.jqTemplateSelect.val();
        if (this.oTemplatesByID.hasOwnProperty(idTemplate.toString()))
        {
            let o = this.oTemplatesByID[idTemplate];
            this.jqAccessDescription.html(o.access_formatted);

            if (this.fFirst)
            {
                drnShowAndFadeIn(this.jqAccessDescription.parent());
                this.fFirst = false;
            }
        }
    }
}


/********************************************************************
 *
 *  TicketDebugInfo
 *
 ********************************************************************/

interface TicketDebugInfoResult extends ApiResult
{
    htmlGroups: string;
    usersReadFormatted: string;
    type: number;
    typeFormatted: string;
    class: string;
    fields: any;
}

interface FieldData
{
    fieldName: string;
    data: any;
}

class TicketDebugInfoDialog extends AjaxModal
{
    constructor(idTicket: number)
    {
        super('ticketDebugInfoDialog',
              [],
              null,
              false);

        this.findReplaceFields('drn-find-replace-ticket', { "%IDTICKET%": idTicket.toString() });

        this.show();

        this.execGET(   '/ticket-debug-info/' + idTicket,
                        (data: TicketDebugInfoResult) =>
                        {
                            this.findDialogItem('spinner').addClass('hidden');
                            this.findDialogItem('groups').html(data.htmlGroups);
                            let type = APIHandler.quote(data.typeFormatted);
                            let cls = APIHandler.quote(data.class);
                            this.findDialogItem('type').html(`${data.type} (${type})`);
                            this.findDialogItem('class').html(`${cls}`);
                            this.findDialogItem('users-read').html(data.usersReadFormatted);

                            let aData = [ '<ul>' ];

                            for (let idField in data.fields)
                                if (data.fields.hasOwnProperty(idField))
                                {
                                    let d: FieldData = data.fields[idField];
                                    let htmlData = myEscapeHtml(d.data);
                                    let idCopy = 'data-' + idField;
                                    let copy = this.makeCopyToClipboardButton(idCopy);
                                    aData.push(`<li><code>${d.fieldName}</code> (${idField}): ${copy} <span id="${idCopy}">${htmlData}</span></li>`);
                                }

                            aData.push('</ul>');
                            this.findDialogItem('ticket-data').html(aData.join(''));

                            let jqInfo = this.findDialogItem('info');
                            jqInfo.removeClass('hidden');
                            jqInfo.fadeIn();
                        });

        this.initClipboardButtons();
    }
}

/********************************************************************
 *
 *  TicketConfirmDeleteDialog
 *
 ********************************************************************/

class TicketConfirmDeleteDialog extends AjaxModal
{
    constructor(idTicket: number,
                strTicketTitle: string)
    {
        super('deleteTicketDialog',
              [],
              '/ticket/' + idTicket,
              false);
        this.method = 'DELETE';

        this.findReplaceFields('drn-find-replace-ticket',
                               { "%IDTICKET%": idTicket,
                                   "%SUMMARY%": myEscapeHtml(strTicketTitle)
                               });

        this.show();
    }


    protected onSubmitSuccess(json): void
    {
        super.onSubmitSuccess(json);

        location.assign(g_rootpage);
    }
}

/********************************************************************
 *
 *  AttachmentTab
 *
 ********************************************************************/

class AttachmentTab extends APIHandler
{
    constructor(aAttachmentIDs: number[])
    {
        super();
        for (const idBinary of aAttachmentIDs)
            this.attachBinaryListeners(idBinary);
    }

    /**
     *  Find the row in the files list of the given attachment.
     */
    private static GetAttachmentRow(idBinary: number)
        : JQuery
    {
        return drnFindByID(`row-file-binary-${idBinary}`);
    }

    /**
     *  Reset the row to being a link to the file.
     */
    private static ResetRow(jqNameCell: JQuery,
                            oldName: string,
                            idBinary: number)
    {
        jqNameCell.html(`<a href="${g_rootpage}/binary/${idBinary}">${myEscapeHtml(oldName)}</a>`);
    }

    /**
     *  Hide the generic error box for attachments.
     */
    private hideErrorBox()
    {
        this.hideError(drnFindByID('attachment-change-error').parent());
    }

    /**
     *  Handle XHR errors. The field should contain the binary ID the error
     *  occurred with.
     */
    protected onError(jqXHR)
    {
        const errorInfo = this.extractError(jqXHR);
        if (errorInfo.field)
            this.showErrorPopover(AttachmentTab.GetAttachmentRow(errorInfo.field).find('form'), errorInfo.htmlMessage);
        else
            this.showError(drnFindByID('attachment-change-error').parent(), errorInfo.htmlMessage);
    }

    /**
     *  Starts the renaming process by replacing the file link with a text input.
     *  When saved, sends the new name to the server.
     */
    private onRenameAttachment(idBinary: number)
    {
        const jqRow = AttachmentTab.GetAttachmentRow(idBinary);
        const jqNameCell = jqRow.find('td:nth-child(3)');
        const oldName = jqNameCell.text();
        const jqForm = $(`<form class="form-inline">
    <input type="text" value="${myEscapeHtml(oldName)}" name="newName">
    <button class="btn btn-primary" type="submit">${_('rename')}</button>
    <button class="btn btn-default btn-cancel" type="button">${_('cancel')}</button>
</form>`);

        let submitting = false;

        jqForm.on('submit', (e) => {
            e.preventDefault();
            this.hideErrorPopover();
            this.hideErrorBox();
            submitting = true;
            const newName = jqForm.find('input').val();
            const jqSubmitButton = jqForm.find('button.btn-primary');
            const jqCancelButton = jqForm.find('button.btn-cancel');
            this.buttonSubmitting(jqSubmitButton);
            jqCancelButton.prop('disabled', true);
            jqCancelButton.addClass('disabled');
            this.execPut(`/attachment/${g_globals.ticketID}/${idBinary}`,
                         { newName },
                         () => {
                            this.buttonSubmitSuccess(jqSubmitButton);
                            jqCancelButton.prop('disabled', false);
                            jqCancelButton.removeClass('disabled');
                            submitting = false;
                            AttachmentTab.ResetRow(jqNameCell, newName, idBinary);
                         });
        });

        jqForm.find('.btn-cancel').on('click', () => {
            if (!submitting)
            {
                this.hideErrorPopover();
                this.hideErrorBox();
                AttachmentTab.ResetRow(jqNameCell, oldName, idBinary);
            }
        });

        jqForm.on('keyup', (e) => {
            if (   (e.which === 27)
                 && !submitting)
            {
                e.preventDefault();
                this.hideErrorPopover();
                this.hideErrorBox();
                AttachmentTab.ResetRow(jqNameCell, oldName, idBinary);
            }
        });

        jqNameCell.children().replaceWith(jqForm);
    }

    /**
     *  Delete/hide an attachment. Remove the attachment from the DOM when successful.
     */
    private onHideAttachment(idBinary: number)
    {
        const jqRow = AttachmentTab.GetAttachmentRow(idBinary);
        if (jqRow.find('form').length)
        {
            const oldName = jqRow.find('input').val();
            AttachmentTab.ResetRow(jqRow.find('td:nth-child(3)'), oldName, idBinary);
        }
        this.hideErrorPopover();
        this.hideErrorBox();
        this.execDelete(`/attachment/${g_globals.ticketID}/${idBinary}`, () => {
            jqRow.fadeOut(400, () => {
                jqRow.remove();
            });
        });
    }

    /**
     *  Adds context action listeners for attachments for renaming and hiding.
     */
    private attachBinaryListeners(idBinary: number)
    {
        const jqRename = drnFindByID(`rename-${idBinary}`);
        jqRename.on('click', () => this.onRenameAttachment(idBinary));
        const jqDelete = drnFindByID(`delete-${idBinary}`);
        jqDelete.on('click', () => this.onHideAttachment(idBinary));

        // let jqThis = drnFindByID('hide-' + binary_id);
        // jqThis.on('click', () => {
        //     onHideAsOlderVersion(binary_id);
        // });
    }
}


/********************************************************************
 *
 *  TicketDetailsPage
 *
 ********************************************************************/

/**
 *  Client-side class for the ticket details page. This handles tabs.
 *
 */
export class TicketDetailsPage
{
    constructor(private aTabs: string[],
                aAttachmentIDs: number[])
    {
        // To each #select-$tab's onClick(), attach a closure that calls onSelect($tab).
        for (let tab of aTabs)
        {
            let jqTab = drnFindByID('select-' + tab);
            jqTab.on('click', () => {
                this.onTabSelected(tab);
                return false;
            })
        }

        if (aTabs.length)
            this.onTabSelected(aTabs[0]);

        new AttachmentTab(aAttachmentIDs);

        // Init a number of click handlers. These targets may or may not exist depending
        // on user permissions.
        $('#cancel-pick-file').on('click', () => {
            cancelPickNewerVersionFile();
            return false;
        });

        $('#confirm-delete-ticket').click(() => {
            new TicketConfirmDeleteDialog(g_globals.ticketID, g_globals.ticketTitle);
            return false;
        });

        $('#ticket-debug-info-dialog').click(() => {
            new TicketDebugInfoDialog(g_globals.ticketID);
            return false;
        });

        window.addEventListener("hashchange", () => this.onHashChange(), false);
        $(document).ready(() => this.onHashChange());
    }

    private onHashChange()
    {
        if (window.location.hash.search(/^#change-[0-9]+$/) !== -1)
        {
            $('#select-changelog').click();
        }
    }

    private onTabSelected(tabSelected: string)
    {
        // first hide the tables that are NOT selected
        for (let tabThis of this.aTabs)
            if (tabThis)
                if (tabThis != tabSelected)
                {
                    $("#select-" + tabThis).removeClass("active");
                    $("#div-page-" + tabThis).addClass("hidden");
                }

        // now show the one that IS selected
        $("#select-" + tabSelected).addClass("active");
        let jqDiv = drnFindByID('div-page-' + tabSelected);
        jqDiv.fadeIn();
        jqDiv.removeClass("hidden");
    }
}


/********************************************************************
 *
 *  TicketCommentForm
 *
 ********************************************************************/

/**
 *  Ticket comment form instantiated from PHP via core_initTicketCommentForm() entry point.
 */
export class TicketCommentForm extends AjaxForm
{
    protected jqChangelogTable: JQuery;

    constructor(idDialog: string,
                private login: string)              //!< in: current user's login name (needed for creating comment rows on the fly)
    {
        super(idDialog,
              [ 'ticket_id',
                'comment'
              ],
              '/comment',
              'save'
             );

        this.jqChangelogTable = drnFindByID('changelog-table');
    }

    protected findEditor()
        : DrnWysihtml
    {
        const jqCommentField = this.findDialogItem('comment');
        return g_globals[`wysihtml-${jqCommentField.prop('id')}`];
    }

    protected addNewComment(comment: string,
                            commentId: number)
    {
        CommentRow.CreateRow(commentId, comment, this.login)
            .prependTo(this.jqChangelogTable.find('tbody'));
    }

    protected onSubmitSuccess(data: Comment)
        : void
    {
        const commentEditor = this.findEditor();
        const comment = commentEditor.getValue();

        this.addNewComment(comment, data.comment_id);
        window.location.hash = 'change-' + data.comment_id;

        commentEditor.setValue('');

        window.setTimeout(() => {
            this.buttonSubmitRestore(this.jqSubmitButton);
        }, 500);

        super.onSubmitSuccess(data);
    }
}

class TicketCommentEditor extends AjaxForm
{
    private static readonly TEMPALTE_IDS = [
        'comment',
        'comment-toolbar',
        'comment_id',
        'cancel',
        'save'
    ];

    private commentRoot: JQuery;
    private editor: DrnWysihtml;
    private initialValue: string;

    constructor(private readonly commentId: number,
                private readonly templateId: string)
    {
        super(`${templateId}-${commentId}`,
              [
                'comment',
                'comment_id'
              ],
              '/comment/' + commentId,
              'save');

        this.method = 'PUT';

        this.commentRoot = drnFindByID(`change-${commentId}`);

        this.showEditor();
    }

    private copyTemplate()
        : JQuery
    {
        const template = drnFindByID(this.templateId).clone();
        // Update IDs
        template.prop('id', this._idDialog);
        for (const field of TicketCommentEditor.TEMPALTE_IDS)
            template
                .find(`#${this.templateId}-${field}`)
                .prop('id', `${this._idDialog}-${field}`);

        template.removeClass('hidden');
        // If this is not set, the editor width changes based on focus state
        template.find(`#${this._idDialog}-comment`).css({
            width: '100%'
        });
        return template;
    }

    private showEditor()
    {
        this.initialValue = this.commentRoot.find('.drn-comment').html();
        this.commentRoot.find('span').hide();

        const template = this.copyTemplate();
        const commentContent = this.commentRoot.find('.drn-comment');
        commentContent.children().remove();
        commentContent.append(template);
        this.editor = new DrnWysihtml(`${template.prop('id')}-comment`, true);
        this.editor.setValue(this.initialValue);

        this.findDialogItem('cancel').one('click', () => this.onCancel());
        this.findDialogItem('comment_id').val(this.commentId);

        // Re-run form initialization, now that the editor etc. exists
        this.jqDialog = drnFindByID(this._idDialog);
        this.jqSubmitButton = this.findDialogItem(this.buttonId);

        // Handle Enter key and 'save' button press uniformly, but only once.
        this.jqSubmitButton.click(() => {
            this.onSubmitButtonClicked();
        });
    }

    private restoreComment(comment: string, commentId?: number)
    {
        const jqEditBtn = this.commentRoot.find('span');
        jqEditBtn.show();
        this.commentRoot.find('.drn-comment').html(comment);
        this.editor.destroy();
        this.editor = undefined;
        this.initialValue = undefined;
        this.jqSubmitButton.off('click');
        if (commentId)
        {
            jqEditBtn.find('a').attr('data-id', commentId);
            this.commentRoot.prop('id', `change-${commentId}`);
        }
    }

    protected onSubmitSuccess(data: Comment)
    {
        this.restoreComment(data.comment, data.comment_id);
        super.onSubmitSuccess(data);
    }

    protected onCancel()
    {
        this.restoreComment(this.initialValue);
    }
}

export class CommentRow extends APIHandler
{
    private commentRoot: JQuery;

    constructor(private readonly commentId: number)
    {
        super();

        this.commentRoot = drnFindByID(`change-${commentId}`);
    }

    public delete()
    {
        this.execDelete(`/comment/${this.commentId}`, () => this.commentRoot.fadeOut());
    }

    public edit(templateId: string)
        : TicketCommentEditor
    {
        return new TicketCommentEditor(this.commentId, templateId);
    }

    public static CreateRow(commentId: number, comment: string, author: string)
        : JQuery
    {
        return $(`<tr class="animated zoomInDown" id="change-${commentId}">
    <td>${_('comment_justnow')}</td>
    <td>${myEscapeHtml(author)}</td>
    <td>
        <span class="pull-right">
            <a href="" class="drn-edit-comment" data-id="${commentId}" title="${_('editCommentTooltip', { id: commentId.toString() })}" rel="edit edit-form"><!--
                -->${getIcon('edit')}<!--
         --></a>
            <a href="" class="drn-delete-comment" data-id="${commentId}" title="${_('deleteCommentTooltip', { id: commentId.toString() })}"><!--
                -->${getIcon('trash')}<!--
         --></a>
        </span>
        <article class="drn-comment">
            ${comment}
        </article>
    </td>
</tr>`)
            .one('animationend', (e) => {
                // only animate the row once
                const jqRow = $(e.target);
                jqRow.removeClass('animated');
                jqRow.removeClass('zoomInDown');
            });
    }
}

/**
 *  Main comments class instantiated from PHP via core_initTicketDetails() entry point.
 */
export class ChangelogComments
{
    private static readonly ORIGINAL_ID_SUFFIX = '-old';

    protected jqChangelog: JQuery;
    protected jqCommentToggle: JQuery;
    private fCommentsCollapsed: boolean = false;

    constructor()
    {
        this.jqChangelog = drnFindByID('changelog-table');
        this.jqCommentToggle = drnFindByID('collapse-comments');

        if (!this.jqCommentToggle.prop('checked'))
            this.combineCommentItems();

        this.jqCommentToggle.on('input', () => {
            if (this.fCommentsCollapsed)
                this.restoreOriginal();
            else
                this.combineCommentItems();
        });
    }

    /**
     * Merges the current version of the comment with the initial comment,
     * showing both dates, using the original author and the current ID and contents.
     */
    private createMergedComment(jqOriginalDate: JQuery,
                                jqOriginalAuthor: JQuery,
                                jqLastChange: JQuery,
                                jqComment: JQuery,
                                commentId: string)
        : JQuery
    {
        const jqRow = $(`<tr></tr>`);

        // Ensure there is no duplicate ID
        drnFindByID(commentId).prop('id', commentId + ChangelogComments.ORIGINAL_ID_SUFFIX);
        jqRow.prop('id', commentId);

        jqRow.addClass('replacement');
        const jqChange = $('<td></td>');
        jqChange.append(jqOriginalDate.clone());
        jqChange.append(` (last updated ${jqLastChange.parent().html()})`);
        jqChange.find('u').tooltip();
        jqRow.append(jqChange);
        jqRow.append(jqOriginalAuthor.clone());
        const jqCommentClone = jqComment.clone();
        // remove edit comment
        jqCommentClone.find('> p').detach();
        jqRow.append(jqCommentClone);
        return jqRow;
    }

    /**
     * Finds the latest version of a comment in the changelog table and hides
     * all previous versions.
     */
    private findLatestCommentVersion(jqComment: JQuery)
        : JQuery|null
    {
        let current = undefined, next = jqComment;
        while (next && next.length)
        {
            if (current)
                current.parent().parent().hide();
            current = next;

            next = this.jqChangelog.find(`[data-text-oldid="${current.data('textId')}"]`).first();
        }
        return current ? current.parent().parent() : null;
    }

    /**
     * Reduces all comments in the changelog to a single row, instead of a trail
     * of changes.
     */
    private combineCommentItems()
    {
        if (this.fCommentsCollapsed)
            return;

        // only start with retracted new comments
        const comments = this.jqChangelog.find("span[data-text-id]:not([data-text-oldid])");

        comments.each((i, el) => {
            const jqEl = $(el);
            const jqCurrentVersion = this.findLatestCommentVersion(jqEl);
            if (jqCurrentVersion && !jqCurrentVersion.is(jqEl.parent().parent()))
            {
                const jqRow = jqEl.parent().parent();
                const jqMergedComment = this.createMergedComment(
                    jqRow.find('td:first-child > *').first(),
                    jqRow.find('td:nth-child(2)').first(),
                    jqCurrentVersion.find('td:first-child > *').first(),
                    jqCurrentVersion.find('td:last-child').first(),
                    jqCurrentVersion.prop('id')
                );
                jqRow.before(jqMergedComment);
                jqCurrentVersion.hide();
            }
        });
        this.fCommentsCollapsed = true;
    }

    /**
     * Restores the original changelog format.
     */
    private restoreOriginal()
    {
        if (!this.fCommentsCollapsed)
            return;

        const aComments = this.jqChangelog.find("[data-text-id], [data-text-oldid]");
        const replacedComments = this.jqChangelog.find('tr.replacement');

        aComments.parent().parent().show();
        const suffixLength = ChangelogComments.ORIGINAL_ID_SUFFIX.length;
        aComments.each((i, comment) => {
            const jqComment = $(comment).parent().parent();
            const id = jqComment.prop('id');
            if (id.substr(id.length - suffixLength) == ChangelogComments.ORIGINAL_ID_SUFFIX)
                jqComment.prop('id', id.substr(0, id.length - suffixLength));
        });
        replacedComments.detach();

        this.fCommentsCollapsed = false;
    }
}

