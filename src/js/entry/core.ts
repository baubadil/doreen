/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */
import entry from './entry';
import * as $ from 'jquery';
import * as ClipboardJS from 'clipboard';
import EntryPoint from './entrypoint';
import {
    drnInitAutostarts,
    ReindexingAll, ThemeSelector, GlobalSettingsBool, NukeDialog,
} from '../../core/view_globalsettings/view_globalsettings';
import {
    ChangelogComments, ChangeTicketTemplateDialog, CommentRow,
    TicketCommentForm,
    TicketDetailsPage,
} from '../../core/view_ticket/view_ticket';
import {TicketEditor} from '../../core/view_ticket/view_ticket_editor';
import {ChildTicket, drnInitHierarchyGraph, ParentTicket} from '../../core/view_ticket/view_ticket_old';
import {GridState, TicketsGrid} from '../../core/view_ticketsgrid/view_ticketsgrid';
import { onTicketTableReady, onTicketResultsGraphDocumentReady } from '../../core/view_ticketslist/view_ticketslist';
import { UsersAndGroupsPage } from '../../core/view_usersgroups/view_usersgroups';
import {JsonAmountsHandler} from '../../core/class_fieldh_json';
import { initSetPriorityButtons } from '../../core/class_fieldh_prio';
import {
    drnInitUserAlert,
    onEditTaskPageReady,
    handleAcceptCookies,
    activateShowMoreButton, drnUpperCaseEntryField
} from '../main';
import {drnFindByID, drnInitFadeOutReadMores, drnShowAndFadeIn} from '../shared';
import {TypeAheadFind} from '../typeaheadfind';
import SessionManager from '../sessionmanager';
import { drnInitDateTimePicker, drnLinkTimePickers } from '../ctl-datetimepicker';
import { drnInitMultiSelect } from '../ctl-multiselect';
import { TicketPicker} from '../ctl-ticketselect';
import DrnWysihtml from '../wysihtml';
import {DrnCtlDropzone} from '../ctl-dropzone';
import HelpPopover from '../help';
import { initRegisterForm } from '../../core/form_register';
import {drnInitStopImpersonating, ImpersonateDialog} from '../impersonate';
import {Placement} from "bootstrap";
import {MyAccountForm, UserDialogData} from "../../core/view_myaccount/view_myaccount";
import DrnBootstrapSlider from "core/ctl-slider";
import {SubAmountHandler} from "../../core/class_fieldh_amount";
import {drnEscapeForRegExp, drnShowBusyCursor} from "core/inc-globals";
import {GetTemplatesApiResult} from "core/inc-api-types";

const CORE_NAME = 'core';

/*
 *  Global settings
 */
(<any>window).core_initReindexAllButton = (idDialog: string,
                                           channelReindexing: string) =>
{
    new ReindexingAll(idDialog, channelReindexing);
};

(<any>window).core_initThemeSelector = (idDialogTheme: string) =>
{
    new ThemeSelector(idDialogTheme);
};

(<any>window).core_initGlobalSettingBool = (idSetting: string) =>
{
    new GlobalSettingsBool(idSetting);
};

(<any>window).core_initAutostarts = (idDialog: string) =>
{
    drnInitAutostarts(idDialog);
};

(<any>window).core_initNukeDialog = () =>
{
    new NukeDialog();
};


/*
 *  Main
 */

/**
 *  Called from the document-ready chunk emitted by WholePage::BuildMainMenuLeft()
 */
(<any>window).core_initNewSubmenu = (idNewSubmenu: string) =>
{
    let jqNewSubmenu = drnFindByID(idNewSubmenu);
    jqNewSubmenu.on('click', () =>
    {
        $('.navbar-collapse').collapse('hide');
        // Fill the submenu only once.
        if (!g_globals.fTemplatesLoaded)
        {
            let jqSubmenu = $('#drn-new-submenu').find('.dropdown-menu');
            if (jqSubmenu.length)
            {
                drnShowBusyCursor(true);
                let getURL = g_rootpage + '/api/templates';

                // Hand over the current ticket ID to the create-template form; some plugins have a use for that.
                if ('ticketID' in g_globals)
                    getURL += `?current-ticket=${g_globals.ticketID}`;

                $.get(  getURL,
                        (data: GetTemplatesApiResult) =>
                        {
                            for (const tpl of data.results)
                            {
                                const href = g_rootpage + tpl.href;
                                let classes = '';
                                const regExpHref = drnEscapeForRegExp(href);
                                if (window.location.href.search(new RegExp(`${regExpHref}(?:/|$|\\?)`)) !== -1)
                                    classes = ' class="active"';
                                jqSubmenu.append(`<li${classes}><a href="${href}" rel="create-form">${tpl.htmlTitle}&hellip;</a></li>`);
                            }

                            g_globals.fTemplatesLoaded = true;
                            drnShowBusyCursor(false);
                        });
            }
        }
    } );
};

(<any>window).core_initHelpLink = (topic: string,
                                   align: Placement) =>
{
    new HelpPopover(topic, align);
};

(<any>window).core_enableAutoComplete = (idTextEntry: string) =>    //!< in: ID of input type="text" element without "#"
{
     new TypeAheadFind(idTextEntry);
};

/**
 *  Show the back to top link, if the page is scrolling enough for it to be out
 *  of the viewport. Gets called from WholePage for every emitted page unless
 *  the "back to top" link has been explicitly disabled.
 */
(<any>window).core_addBackToTop = () =>
{
    const jqFooter = $('footer');
    if (jqFooter.length)
    {
        const jqWindow = $(window);
        let currentOffset;
        // Show BTT link if the page is scrolling and return true.
        const showBTTLink = () => {
            currentOffset = jqFooter.offset().top;
            if (jqWindow.height() < currentOffset)
            {
                const jqLink = $('a[href="#top"]');
                drnShowAndFadeIn(jqLink);
                return true;
            }
            return false;
        };
        // If the BTT link was not shown, listen for scrolls, in case we change the
        // page dynamically.
        if (!showBTTLink())
            jqWindow.on('scroll.btt', () => {
                if (showBTTLink())
                    jqWindow.off('scroll.btt');
            });
    }
};

(<any>window).core_keepSessionAlive = (sessionLength: number) =>
{
    SessionManager.keepSessionAlive(sessionLength);
};

(<any>window).core_initTextEditor = (idEditor: string) =>
{
    new DrnWysihtml(idEditor, true /* fExtended */);
};

(<any>window).core_initSlider = (idControl: string,
                                 value: number,
                                 iMin: number,
                                 iMax: number) =>
{
    let jqEntryForSlider = drnFindByID(idControl);
    new DrnBootstrapSlider(jqEntryForSlider, value, iMin, iMax);
};

(<any>window).core_initDropzone = (id: string,
                                   url: string,
                                   maxUploadMB: number) =>        // 1000-based, not 1024
{
    new DrnCtlDropzone(id, url, maxUploadMB);
};


/*
 *  Users
 */
(<any>window).core_initMyAccountForm = (o: UserDialogData) =>
{
    new MyAccountForm(o);
};

/**
 *  Initializes the "impersonate" menu item in the user menu, which is only shown if the current
 *  user is an administrator. Called from the document-ready only in that case.
 *
 *  This attaches a handler to the "impersonate" menu item which creates an instance of ImpersonateDialog.
 */
(<any>window).core_initImpersonate = (idMenuItem: string) =>
{
    drnFindByID(idMenuItem).on('click', () =>
    {
        new ImpersonateDialog();        // Reloads the page on success.
        $('.navbar-collapse').collapse('hide');
    });
};

(<any>window).core_initStopImpersonating = (idMenuItem: string) =>
{
    drnInitStopImpersonating(idMenuItem);
};


/*
 *  Tickets
 */
(<any>window).core_initTicketsGrid = (idDialog: string,           //!< in: 'ticketsgrid' ID stem
                                      cCardsPerRow: number,       //!< in: from back-end, currently hard-coded as 3
                                      oState: GridState) =>
{
    new TicketsGrid(idDialog, cCardsPerRow, oState);
};

(<any>window).core_initTicketDetails = (aTabs: string[],
                                        aAttachmentIDs: number[],
                                        fHasComments: boolean) =>
{
    new TicketDetailsPage(aTabs, aAttachmentIDs);
    if (fHasComments)
        new ChangelogComments();
};

(<any>window).core_initHierarchyGraph = (idCanvas: string,       //!< in: HTML ID of canvas DIV (without '#')
                                         aParents: ParentTicket[],       //!< in: array of parent tickets (each with 'id' and 'title' fields)
                                         aChildren: ChildTicket[],      //!< in: array of child tickets (each with 'id' and 'title' fields)
                                         oConfig: any) =>
{
    drnInitHierarchyGraph(idCanvas, aParents, aChildren, oConfig);
};

(<any>window).core_initTicketCommentForm = (dlgId: string,
                                            login: string) =>
{
    new TicketCommentForm(dlgId, login);
};

(<any>window).core_initTicketEditor = (idDialog: string,
                                       aFieldIds: string[],
                                       url: string,
                                       fCreateMode: boolean,
                                       fDebugPostPut: boolean,
                                       newTicketArg: string,
                                       afterSaveArg: string) =>
{
    new TicketEditor(idDialog,
                     aFieldIds,
                     url,
                     fCreateMode,
                     fDebugPostPut,
                     newTicketArg,
                     afterSaveArg);
};

(<any>window).core_initTicketPicker = (id: string,                    //!< in: ID of <select> element, without leading '#'
                                       cMinimumChars: number,
                                       cItemsPerPage: number,         //!< in: how many items to display per page (must be from Globals)
                                       extraQuery: string = '',       //!< in: extra parameters for GET /api/tickets; if not empty, must start with '?' and be URL-encoded
                                       aTitleFields: string[]) =>
{
    new TicketPicker(id, cMinimumChars, cItemsPerPage, extraQuery, aTitleFields);
};

(<any>window).core_initChangeTemplateDialog = (idLink: string,
                                               idDialog: string,
                                               idTicket: number,
                                               idTemplate: number,
                                               idType: number) =>
{
    $('#' + idLink).click(() => {
        new ChangeTicketTemplateDialog(idDialog, idTicket, idTemplate, idType);
    });
};

(<any>window).core_initCommentEditorButtons = (templateId: string) =>
{
    // Attach this listener to any edit comment button, not just the pre-existing ones
    drnFindByID('changelog-table')
        .on('click', '.drn-edit-comment', (e) =>
        {
            e.preventDefault();
            const row = new CommentRow(parseInt($(e.target.parentElement).data('id'), 10));
            row.edit(templateId);
        })
        .on('click', '.drn-delete-comment', (e) =>
        {
            e.preventDefault();
            const row = new CommentRow(parseInt($(e.target.parentElement).data('id'), 10));
            row.delete();
        });
};

(<any>window).core_initSubAmountHandler = (idEntryFieldStem: string,      //!< in: prefix to which to append -sum or -id
                                           aCategoryIDs: number[]) =>
{
    new SubAmountHandler(idEntryFieldStem, aCategoryIDs);
};

(<any>window).core_initUsersAndGroupsPage = (aTabs: string[],
                                             aPlaceholdersUsers: string[],
                                             aPlaceholdersGroups: string[],
                                             uidCurrentlyLoggedIn: number,
                                             gidAllUsers: number) =>
{
    new UsersAndGroupsPage(aTabs,
                           aPlaceholdersUsers,
                           aPlaceholdersGroups,
                           uidCurrentlyLoggedIn,
                           gidAllUsers);
};

(<any>window).core_initShowMore = (id1: string, id2: string) =>
{
    let jq1 = drnFindByID(id1);
    jq1.on('click', () => {
        let jq2 = drnFindByID(id2);
        jq1.css('display', 'none');
        drnShowAndFadeIn(jq2);
    });
};

/**
 *  Attaches a handler to #idButton that will show all elements with .classInactiveButtons.
 *  It is assumed that those buttons all have a .hide class to hide them.
 */
(<any>window).core_initShowMoreFilters = (classActivateButton: string,
                                          classInactiveButtons: string) =>
{
    activateShowMoreButton(classActivateButton, classInactiveButtons);
};

(<any>window).core_initJsonHandler = (idControl: string,      //!< in: prefix to which to append -sum or -id
                                      oKeys: any) =>
{
    new JsonAmountsHandler(idControl, oKeys);
};

class CoreEntry implements EntryPoint
{
    action(action: string, ...args)
    {
        switch(action)
        {
            case 'onTicketTableReady':
                onTicketTableReady();
                break;
            case 'onTicketResultsGraphDocumentReady':
                onTicketResultsGraphDocumentReady();
                break;
            case 'initSetPriorityButtons':
                initSetPriorityButtons(args[0], args[1], args[2]);
                break;
            case 'dialogClick':
                drnFindByID(args[0]).click(() => entry.action(args[1], args[2], ...args.slice(3)));
                break;
            case 'upperCaseEntryField':
                drnUpperCaseEntryField(args[0]);
                break;
            case 'initUserAlert':
                drnInitUserAlert(args[0], args[1], args[2]);
                break;
            case 'onEditTaskPageReady':
                onEditTaskPageReady(args[0], args[1], args[2]);
                break;
            case 'handleAcceptCookies':
                handleAcceptCookies(args[0]);
                break;
            case 'clipboard':
                new ClipboardJS(args[0]);
                break;
            case 'initFadeOutReadMores':
                drnInitFadeOutReadMores();
                break;
            case 'initDateTimePicker':
                drnInitDateTimePicker(args[0], args[1], args[2], args[3], args[4]);
                break;
            case 'initMultiSelect':
                drnInitMultiSelect(args[0], args[1], args[2], args[3]);
                break;
            case 'linkTimePickers':
                drnLinkTimePickers(args[0], args[1], args[2]);
                break;
            case 'initBSGallery':
                drnFindByID(args[0]).bsPhotoGallery( { "classes" : "col-lg-2 col-md-4 col-sm-3 col-xs-4 col-xxs-12",
                                                        "hasModal" : true,
                                                        'iconClose': 'fa fa-lg fa-close',
                                                        'iconLeft': 'fa fa-lg fa-arrow-left',
                                                        'iconRight': 'fa fa-lg fa-arrow-right'
                                                      } );
                break;
            case 'initRegisterForm':
                initRegisterForm(args[0], args[1], args[2]);
                break;
            case 'initShowHide':
                $('.drn-show-hide').each(function(index, value)
                {
                    var jqButton = $(value);
                    jqButton.click(function()
                    {
                        var jqButton = $(this);
                        var datatarget = jqButton.attr('data-drn-target');
                        var target = $('#' + datatarget);
                        if (target.hasClass("hidden"))
                        {
                            jqButton.attr('data-drn-backup-title', jqButton.text());
                            var datahide = jqButton.attr('data-drn-hide');
                            jqButton.html(datahide);
                            target.fadeIn();
                            target.removeClass('hidden');
                        }
                        else
                        {
                            target.addClass('hidden');
                            jqButton.html(jqButton.attr('data-drn-backup-title'));
                        }
                    });
                });
                break;
            default:
                console.warn("Could not find entry point", action, 'in', CORE_NAME);
        }
    }
}

entry.registerEntryPoint(CORE_NAME, new CoreEntry());
