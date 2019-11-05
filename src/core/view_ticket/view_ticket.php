<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  ViewTicketPage class
 *
 ********************************************************************/

/**
 *  ViewTicket encapsulates the ticket details view.
 *
 *  Create an instance with new() and then call emit() on it, or use the
 *  static CreateAndEmit() method, which does exactly that.
 *
 *  This is used for:
 *
 *   -- the /ticket GUI in the singular => VIEW_READONLY_DETAILS
 *
 *   -- the /editticket GUI, the ticket editor => MODE_EDIT
 *
 *   -- the /newticket GUI, to create a new ticket from a template => MODE_CREATE.
 *
 *  This class derives from TicketPageBase, which is a base class that is used in a lot
 *  of field handler methods to encapsulate ticket state without having to expose all
 *  view details. As a result, we can pass $this to a lot of field handler method
 *  implementations.
 *
 *  The constructor creates a Dialog instance in TicketPageBase::$oDlg, to which HTML is
 *  added by field handlers.
 */
class ViewTicket extends TicketPageBase
{
    private $aXmlDialogFiles        = [];
    private $aDialogChunks = [];

    public $fHasCommentField    = FALSE;          # TRUE if ticket type has FIELD_COMMENT
    public $fHasChangelogField  = FALSE;          # TRUE if ticket type has FIELD_CHANGELOG
    public $fHasAttachmentField = FALSE;          # TRUE if ticket type has FIELD_ATTACHMENT

    public $aAttachmentIDs = [];                     # Flat list of attachment IDs to initialize in JS.

    /**
     *  Constructor; creates the page header and title and introductory text
     *  and loads the ticket data (in MODE_EDIT and MODE_READONLY_DETAILS modes).
     *  In MODE_READONLY_DETAILS, this also loads the ticket changelog.
     *
     *  $idTemplateOrTicket must be either an integer or a UUID. With UUIDs, this
     *  will look up \ref ticket_uuids directly. This will not work in MODE_CREATE.
     *
     *  After construction, call the emit() method.
     */
    public function __construct(int $mode,             //!< in: one of MODE_CREATE, MODE_EDIT or MODE_READONLY_DETAILS,
                                $idTemplateOrTicket)   //!< in: template ID (in MODE_CREATE) or ticket ID (MODE_EDIT or MODE_READONLY_DETAILS); int or UUID
    {
        $this->oHTMLPage = new HTMLChunk(6);

        $oTicket = NULL;

        if ($uuid = UUID::NormalizeLong($idTemplateOrTicket, FALSE))
        {
            // Get the ticket ID from the UUID. Access permissions are checked below then.
            if (!($idTemplateOrTicket = Ticket::FindByUUID($uuid)))
                throw new BadTicketIDException($idTemplateOrTicket);
        }
        else if (!isInteger($idTemplateOrTicket))
            throw new DrnException("Invalid ticket ID ".Format::UTF8Quote($idTemplateOrTicket).", must be either a UUID or an integer");

        if ($mode == MODE_CREATE)
        {
            /*
             *
             * MODE_CREATE: no ticket exists yet
             *
             */
            if (!$oTemplate = Ticket::FindForUser($idTemplateOrTicket, LoginSession::$ouserCurrent, ACCESS_CREATE))
                throw new DrnException(L('{{L//Invalid ticket template ID %ID%}}', [ '%ID%' => $idTemplateOrTicket ]));

            if (!$oTemplate->isTemplate())
                throw new DrnException("Ticket #$oTemplate->id is not a template");

            if (!($oTemplate->getUserAccess(LoginSession::$ouserCurrent) & ACCESS_CREATE))
                throw new NotAuthorizedCreateException();

            parent::__construct(LoginSession::$ouserCurrent,
                                $oTemplate,
                                $mode);

            $this->htmlTitle = $oTemplate->getInfo(Ticket::INFO_CREATE_TICKET_TITLE);

            # This variable collects the HTML code to be displayed.

            $this->oHTMLPage->addLine($oTemplate->getInfo(Ticket::INFO_CREATE_TICKET_INTRO));

            if (LoginSession::CanSeeTicketDebugInfo())
            {
                $this->oHTMLPage->openDiv(NULL, 'alert alert-info');
                $this->oHTMLPage->openDiv(NULL, 'pull-right');
                $this->oHTMLPage->addPara(Icon::Get('bug'));
                $this->oHTMLPage->close();
                $this->oHTMLPage->addLine(L("<p>{{L//The new %{%TEMPLATE%}% will have the following access permissions:}}</p>", [
                    '%TEMPLATE%' => $oTemplate->getTemplateName()
                ]));
                $this->oHTMLPage->append($this->oTicket->describeACL(6));
                $this->oHTMLPage->close();
            }

            $this->oHTMLPage->addLine("<!-- begin create ticket form -->");

            $this->htmlSavebutton = $oTemplate->getInfo(Ticket::INFO_CREATE_TICKET_BUTTON);

            $this->idDialog = 'createticket';
            $this->url = '/ticket';
        }
        else
        {
            /*
             *
             * MODE_EDIT / MODE_READONLY_DETAILS: ticket exists already
             *
             */
            $oTicket = Ticket::FindForUser($idTemplateOrTicket,
                                           LoginSession::$ouserCurrent,
                                           ACCESS_READ,
                                           TRUE);         # populate

            if ($mode == MODE_EDIT)
            {
                if (!$oTicket->canUpdate(LoginSession::$ouserCurrent))
                    throw new NotAuthorizedException();
            }
            else if ($oTicket->isTemplate())
                throw new BadTicketIDException($idTemplateOrTicket);

            parent::__construct(LoginSession::$ouserCurrent,
                                $oTicket,
                                $mode);

            # MODE_EDIT or MODE_READONLY_DETAILS

            if ($mode == MODE_EDIT)
            {
                $this->htmlPageHeading = $oTicket->makeEditTicketTitle(TRUE);  # can have HTML
                $this->htmlTitle = $oTicket->makeEditTicketTitle(FALSE);  # cannot have HTML

                # This variable collects the HTML code to be displayed.
                $this->htmlPage = "";

                $this->htmlSavebutton = L('{{L//Update ticket}}');

                $this->idDialog = 'editticket';
                $this->url = "/ticket/$idTemplateOrTicket";
            }
        }

        if ($this->oTicket)
            $this->flAccess = $this->oTicket->getUserAccess(LoginSession::$ouserCurrent);

        if (($mode == MODE_CREATE) || ($mode == MODE_EDIT))
        {
            $this->oDlg = new Dialog(6);
            $this->oDlg->addLine();
            $this->oDlg->addLine("<!-- Begin ticket form -->");
            $this->oDlg->openForm($this->idDialog);

            if ($mode == MODE_CREATE)
            {
                $this->oDlg->addHidden("{$this->idDialog}-template", $idTemplateOrTicket);
                $this->aFieldsForDialog[] = 'template';
            }
            else
                $this->oDlg->addHidden("{$this->idDialog}-idticket", $idTemplateOrTicket);
        }

        if (($mode == MODE_READONLY_DETAILS) || ($mode == MODE_EDIT))
        {
            /*
             *  Showing existing ticket (details or in editor): then $oTicket is the ticket
             */
            if (    ($idTemplate = $this->oTicket->created_from)
                 && ($oTemplate = Ticket::FindOne($idTemplate))
               )
            {
                $oTemplateName = $oTemplate->getIconPlusChunk(HTMLChunk::FromString($oTemplate->getTemplateName()));
                if ($oTicket->canChangeTemplate(LoginSession::$ouserCurrent))
                    $this->oHtmlTicketTemplate = $this->addChangeTemplateModal($oTicket, $oTemplate, $oTemplateName);
                else
                    $this->oHtmlTicketTemplate = $oTemplateName;
            }

            if (LoginSession::CanSeeTicketDebugInfo())
            {
                $tooltip = L('{{L//Show debugging details}}').Format::HELLIP;

                $this->aHtmlChunksBeforeTitle[] = HTMLChunk::MakeLink('#',
                                                                      Icon::GetH('bug'),
                                                                      $tooltip,
                                                                      [ 'id' => 'ticket-debug-info-dialog' ] );
                WholePage::Enable(WholePage::FEAT_JS_COPYTOCLIPBOARD);
                $this->aXmlDialogFiles[] = 'view_ticket_permissions_dlg1.xml';
            }

            if ($this->mode == MODE_READONLY_DETAILS)
            {
                if ($this->oTicket->canUpdate(LoginSession::$ouserCurrent))
                    $this->aHtmlChunksBeforeTitle[] = $this->oTicket->makeEditLink();

                if ($this->oTicket->canDelete(LoginSession::$ouserCurrent))
                {
                    $tooltip = L('{{L//Delete this ticket}}').Format::HELLIP;
                    $this->aHtmlChunksBeforeTitle[] = HTMLChunk::MakeLink('#',
                                                                          Icon::GetH('remove'),
                                                                          $tooltip,
                                                                          [ 'id' => 'confirm-delete-ticket' ] );
                    $this->aXmlDialogFiles[] = 'view_ticket_delete_dlg1.xml';
                }
            }

            WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_TOOLTIPS);
        }

        if ($this->mode == MODE_READONLY_DETAILS)
            if ($oTicket->getInfo(Ticket::INFO_DETAILS_SHOW_CHANGELOG))
                $this->oChangelog = $this->oTicket->loadChangelog();
    }

    /**
     *  Adds the bootstrap modal for the change-template dialog for admins.
     */
    private function addChangeTemplateModal(Ticket $oTicket,
                                            Ticket $oTemplate,
                                            HTMLChunk $oTemplateName)
        : HTMLChunk
    {
        $idLink = 'change-template-dialog';
        $idDialog = 'changeTicketTemplateDialog';

        $title = L("{{L//Change the template for this ticket}}").Format::HELLIP;
        $oChunk = HTMLChunk::MakeLink('#',
                                      $oTemplateName,
                                      $title,
                                      [ 'id' => $idLink ]);

        $oDialog = new HTMLChunk();
        $oDialog->openModal($idDialog,
                            "Change template for $oTicket->id");

        $escapedTitle = HTMLChunk::FromString($oTicket->getTitle())->html;
        $oDialog->addPara(L("{{L//The ticket #%ID% (%TITLE%) was created from the template %TEMPLATE% (or maybe its template was later changed to that).}}",
                              [ '%ID%' => $oTicket->id,
                                '%TITLE%' => Format::UTF8Quote($escapedTitle),
                                '%TEMPLATE%' => Format::UTF8Quote($oTemplateName->html)
                              ] ));

        $oDialog->addPara(L("{{L//The type of this template is %TYPE%.}}",
                             [ '%TYPE%' => Format::UTF8Quote($oTemplate->oType->getName()) ]));

        $oDialog->addPara(L("{{L//The following templates are available for tickets of this type:}}"));
        $oDialog->addSelect("$idDialog-template", NULL, NULL, [ 'class' => 'hide' ]);
        $oDialog->appendElement('span', [ 'id' => "$idDialog-select-spinner" ], Icon::GetH('spinner'));
        $oDialog->appendElement('p',
                                [ 'class' => 'drn-margin-above hide' ],
                                HTMLChunk::MakeElement('span',
                                                       [ 'id' => "$idDialog-permissions", ],
                                                       NULL)->prepend(L("<b>{{L//Permissions: }}</b>")));

        $oDialog->addPara(L("{{L//<b>Note: If this ticket has child tickets (blockers), all their templates will be changed as well!</b>}}"));

        $oDialog->closeModal(L("{{L//Cancel}}"),
                             L("{{L//Change template}}"));

        $this->aDialogChunks[] = $oDialog;

        $idType = $oTicket->oType->id;
        WholePage::AddTypescriptCallWithArgs('core', /** @lang JavaScript */'core_initChangeTemplateDialog',
                                             [ $idLink,
                                               $idDialog,
                                               $oTicket->id,
                                               $oTemplate->id,
                                               $idType ] );

        return $oChunk;
    }

    /**
     *  Adds the HTML rows for ticket details in all modes (MODE_CREATE, MODE_EDIT, MODE_READONLY_DETAILS).
     *
     *  It is this method that calls all the field handlers to have the rows added for either read-only
     *  or create/edit mode.
     *
     *  Appends the HTML to $this->oHTMLPage.
     */
    public function makeDetailsRows()
    {
        # Get the details fields that should be visible in tickets of this type.
        $aFields = $this->oType->getVisibleFields(TicketType::FL_FOR_DETAILS | TicketType::FL_INCLUDE_CORE);

//        Debug::Log(0, print_r($aFields, TRUE));

        # Highlight search results, if any, according to the URL. The URL parameters come from
        # the links on the search page.
        $aBinariesFound = NULL;
        if ($highlight = WebApp::FetchParam('highlight', FALSE))
        {
            $this->llHighlightWords = explode(' ', $highlight);
            if ($binariesFound = WebApp::FetchParam('binariesFound', FALSE))
                foreach (explode(',', $binariesFound) as $idBinary)
                    if (isInteger($idBinary))
                        $aBinariesFound[$idBinary] = 1;
                    else
                        throw new DrnException("Invalid values in 'binariesFound' parameter.");
        }


        # Add date field for conflicts detection
        if ($this->mode != MODE_READONLY_DETAILS)
        {
            # add changed hidden field
            $oHandler = FieldHandler::Find(FIELD_LASTMOD_DT);
            $this->oDlg->addHidden($this->idDialog.'-changed', $oHandler->getValue($this));
            $this->aFieldsForDialog[] = 'changed';
        }

        foreach ($aFields as $field_id => $oField)
        {
            if ($field_id == FIELD_COMMENT)
                $this->fHasCommentField = TRUE;
            else if ($field_id == FIELD_CHANGELOG)
                $this->fHasChangelogField = TRUE;
            else if ($field_id == FIELD_ATTACHMENT)
                $this->fHasAttachmentField = TRUE;
            else if (   ($oField->fl & FIELDFL_HIDDEN)
                      || (    ( ($this->mode == MODE_CREATE) || ($this->mode == MODE_EDIT) )
                           && ($oField->fl & FIELDFL_VIRTUAL_IGNORE_POST_PUT)
                         )
                    )
                continue;
            else  if (    ($oField->fl & (FIELDFL_STD_DATA_OLD_NEW | FIELDFL_SHOW_CUSTOM_DATA))
                       && (!($oField->idParent))
                     )
            {
                $oHandler = FieldHandler::Find($field_id);

                if (    ($this->mode == MODE_CREATE)
                     || ($this->mode == MODE_EDIT)
                   )
                {
                    if (!($oField->fl & FIELDFL_FIXED_CREATEONLY))
                    {
                        $this->fInAppendFormRow = TRUE;
                        $oHandler->appendFormRow($this);
                        $oHandler->addFieldToJSDialog($this);
                        $this->fInAppendFormRow = FALSE;
                    }
                }
                else
                {
                    # MODE_READONLY_DETAILS
                    $oHandler->appendReadOnlyRow($this);

                    if ($field_id == FIELD_TITLE)
                    {
                        # add the ticket title to a global variable so that the confirmation dialog can read it
                        $title = json_encode($oHandler->getValue($this));       # adds quotes around string, escapes quotes within
                        WholePage::AddScript("g_globals.ticketTitle = $title;");
                    }
                }
            }
        } # end foreach

        /* If we're editing a ticket that can have comments, then add a comments box to the
           edit ticket form, so people can add a comment together with other changes to
           reduce ticket mail spam. */
        if (    ($this->mode == MODE_EDIT)
             && ($this->fHasCommentField)
           )
        {
            $this->addCommentRow($this->oDlg,
                                 $this->idDialog.'-_comment',
                                 HTMLChunk::FromString(L(<<<EOD
{{L/COMMENTTICKETDETAILSHELP/Here you can comment on the changes you have made above. This works just like adding comments
 via the ticket details page, but instead of sending out several ticket mails, this combines the comment mail with the other.}}
EOD
                                 )));
            $this->aFieldsForDialog[] = '_comment';
        }

        WholePage::AddScript("g_globals.ticketID = ".$this->oTicket->id.';', TRUE);

        if (    ($this->mode == MODE_READONLY_DETAILS)
             && ($this->oChangelog)
             && ($this->oChangelog->cComments)
           )
        {
            $htmlComment = Format::HtmlTruncate($this->oChangelog->dbrowNewestComment->comment,
                                                200);
            if ($htmlComment)
                $htmlComment = CommentHandler::CommentFormatter($htmlComment, $this->oChangelog->dbrowNewestComment->value_1);

            $this->oHTMLPage->openGridRow(NULL,
                                          'drn-padding-above');
            $this->oHTMLPage->openGridColumn(2);
            $this->oHTMLPage->addLine(L("<b>{{L//Newest comment (%WHEN%)}}</b>",
                                        [ '%WHEN%' => Format::Timestamp($this->oChangelog->dbrowNewestComment->chg_dt) ]));
            $this->oHTMLPage->close(); # column

            $this->oHTMLPage->openGridColumn(10);
            $this->oHTMLPage->addLine($htmlComment);
            $this->oHTMLPage->close(); # column

            $this->oHTMLPage->close(); # row
        }

        if ($this->fHasChangelogField || $this->fHasAttachmentField)
            WholePage::Enable(WholePage::FEAT_CSS_ANIMATIONS);

        if (($this->mode == MODE_CREATE) || ($this->mode == MODE_EDIT))
        {
            $this->oDlg->openDlgRow("{$this->idDialog}-error");
            $this->oDlg->openDialogField();
            $this->oDlg->addLine("<div class=\"alert alert-danger hidden drn-error-box\" role=\"alert\"><p>Error message</p></div>");
            $this->oDlg->close();
            $this->oDlg->close();

            $this->oDlg->openDlgRow("{$this->idDialog}-submit");
            $this->oDlg->openDialogField();
            $aAttrs = [ 'type' => 'button',
                        'class' => 'btn btn-primary',
                        'id' => $this->oDlg->dialogNameId ];
            if ($this->fDisableSaveButton)
            {
                $aAttrs['disabled'] = 'disabled';
                $aAttrs['class'] .= ' disabled';
            }
            $this->oDlg->appendElement('button',
                                       $aAttrs,
                                       HTMLChunk::FromEscapedHTML($this->htmlSavebutton));

            $this->oTicket->addCreateOrEditTicketMailInfo($this->mode, $this->oDlg);

            $this->oDlg->close();
            $this->oDlg->close();

            $this->oDlg->close();  # form
            $this->oHTMLPage->append($this->oDlg->html);
            $this->oHTMLPage->addLine("<!-- end create ticket form -->");

            /* When we create a new ticket, we receive the new ticket ID in the JSON data.
             * Reload with that; add an extra arg if the newticketarg item is set in the request data
             * (can be used by plugins). */
            $newTicketURLArg = NULL;
            if (!($newTicketURLArg = getRequestArg('newticketarg')))
                $newTicketURLArg = NULL;

            $afterSaveURLArg = getRequestArg('aftersave');

            WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initTicketEditor',
                                                 [ $this->idDialog,
                                                   $this->aFieldsForDialog,
                                                   $this->url,
                                                   $this->mode == MODE_CREATE,
                                                   Debug::$fDebugCreateTicket,
                                                   $newTicketURLArg,
                                                   $afterSaveURLArg ] );
        }
    }

    /**
     *  Called if the ticket has at least one attachment, to fill the "Files" tab.
     *
     * @param HTMLChunk $oHTML in/out: HTML chunk to add HTML to.
     */
    function makeAttachmentsTab(HTMLChunk $oHTML)
    {
        $oHTML->openDiv('div-page-files', 'hidden');

        $oHTML->openTable('files-table', 'drn-sortable');
        $oHTML->openTableHeadAndRow();
        $oHTML->addTableHeading('',
                                "class=\"hidden drn-show-pick-column\"");
        $oHTML->addTableHeading(L('{{L//Preview}}'));
        $oHTML->addTableHeading(L('{{L//File name}}'),
                                'data-sort="string-ins"');
        $oHTML->addTableHeading(L('{{L//File size}}'),
                                'style="text-align:center;" data-sort="int"');
        $oHTML->addTableHeading(L('{{L//Image size}}'),
                                'style="text-align:center;" data-sort="int"');
        $oHTML->addTableHeading(L('{{L//File type}}'),
                                'style="text-align:center;" data-sort="string-ins"');
        $oHTML->addTableHeading(L('{{L//Attached}}'),
                                'style="text-align:center;" data-sort="int"');

        $oHTML2 = new HTMLChunk();
        $oHTML2->addButton(L('{{L//Cancel}}'),
                           'cancel-pick-file',
                           'btn-danger hidden drn-show-pick-column');
        $oHTML->addTableHeading($oHTML2->html);
        $oHTML->close();

        $oHTML->addHiddenErrorBox('attachment-change-error');

        WholePage::AddNLSStrings([
            'rename' => L('{{L//Rename}}'),
            'cancel' => L('{{L//Cancel}}'),
        ]);

        $oHTML->openTableBody();

        /*
         *  Go through the changelog and add all rows that represent files.
         */
        foreach ($this->oChangelog->aChangelogRows as $oRow)
        {
            if (    ($oRow->field_id == FIELD_ATTACHMENT)
                 && (!$oRow->hidden))
            {
                $oBinary = Binary::CreateFromChangelogRow($oRow);

                $href = Binary::MakeHREF($oBinary->idBinary);
                $thumb = AttachmentHandler::FormatThumbnailer($idBinary = $oBinary->idBinary,
                                                              $href,
                                                              $oBinary->mimetype);
                $oHTML->openTableRow("drn-file-pick-row", "id=\"row-file-binary-$idBinary\"");
                $oHTML->addTableCell("this",
                                     "style=\"vertical-align: middle;\" class=\"hidden drn-show-pick-column\"");
                $oHTML->addTableCell($thumb,
                                     "style=\"vertical-align: middle;\"");
                $oHTML->addTableCell("<a href=\"$href\">".($htmlFilename = toHTML($oBinary->filename)).'</a>',
                                     "style=\"vertical-align: middle;\" data-sort-value=\"$htmlFilename\"");
                $oHTML->addTableCell(Format::Bytes($oBinary->size, 2),      // 1000-based, not 1024
                                     "style=\"text-align:center; vertical-align: middle;\" data-sort-value=\"$oBinary->size\"");
                $strSize = '';
                if ($oBinary->cx)
                    $strSize = $oBinary->cx.Format::NBSP.'x'.Format::NBSP.$oBinary->cy;
                $oHTML->addTableCell($strSize,
                                     "style=\"text-align:center; vertical-align: middle;\" data-sort-value=\"$strSize\"");
                $oHTML->addTableCell(toHTML($oBinary->mimetype),
                                     "style=\"text-align:center; vertical-align: middle;\"");
                $dt = $oRow->chg_dt;
                $oTS = Timestamp::CreateFromUTCDateTimeString($dt);
                $ts = $oTS->getUnixTimestamp();
                $oHTML->addTableCell($dt,
                                     "style=\"vertical-align: middle;\" data-sort-value=\"$ts\"");

                $oHTML->openTableCell("style=\"vertical-align: middle;\"");
                $oHTML->openDiv(NULL, 'btn-group');
                $aMenuItems = [];
                if ($this->oTicket->canDelete(LoginSession::$ouserCurrent))  // TODO figure out better permissions
                    $aMenuItems +=
                    [ /*"hide-$idBinary" => Icon::Get('thumbsdown')
                                                                  .' '
                                                                  .L('{{L//Hide as an older version of another file}}'
                                                                     .Format::HELLIP), only offer when there's more than 1 attachment. */
                      "rename-$idBinary" => Icon::Get('pencil')
                          .' '
                          .L('{{L//Rename}}'.Format::HELLIP),
                      "delete-$idBinary" => Icon::Get('trash')
                          .' '
                          .L('{{L//Delete}}'),
                    ];
                if (count($aMenuItems))
                    $oHTML->addDropdownMenu(Icon::Get('menu'),
                                            $aMenuItems);
                $oHTML->close();
                $this->aAttachmentIDs[] = (int)$idBinary;
                $oHTML->close(); # menu table cell

                $oHTML->close(); # table row
            }
        }
        $oHTML->close(); # table body
        $oHTML->close(); # table

        $oHTML->close();    # div-page-files
    }

    /**
     *  Called to fill the "Changelog" tab. Only gets called if at least one changelog row is present.
     *
     * @param HTMLChunk $oHTML
     */
    public function makeChangelogTab(HTMLChunk $oHTML)
    {
        $oHTML->openDiv('div-page-changelog', 'hidden');

        if ($this->fHasCommentField)
        {
            $oHTML->openGridRow(NULL, 'form-inline');
            $oHTML->openGridColumn(12);
            $oHTML->addCheckbox(L('{{L//Show full comments changelog}}'), 'collapse-comments', 'collapse-comments');
            $oHTML->close();
            $oHTML->close();
        }

        $oHTML->openTable('changelog-table', 'drn-sortable');
        $oHTML->openTableHeadAndRow();
        $oHTML->addTableHeading(L('{{L//Date}}'),
                                'data-sort="string-ins"');
        $oHTML->addTableHeading(L('{{L//User}}'),
                                'data-sort="string-ins"');
        $oHTML->addTableHeading(L('{{L//Change}}'));
        $oHTML->close();

        $oHTML->openTableBody();

        // Change mode.
        $this->mode = MODE_READONLY_CHANGELOG;

        foreach ($this->oChangelog->aChangelogRows as $oRow)
        {
            if (    ($oRow->field_id == FIELD_ATTACHMENT)
                 && ($oRow->hidden))
                continue;

            $field_id = $oRow->field_id;
            # Init the field handler again, it may have been missing from the
            # ticket data fields above.
            if ($oHandler = FieldHandler::Find($field_id, FALSE))
            {
                $htmlChange = $oHandler->tryFormatChangelogItem($this, $oRow);

                if ($field_id == FIELD_COMMENT)
                {
                    if (($this->llHighlightWords) && (count($this->llHighlightWords)))
                        Format::HtmlHighlight($htmlChange, $this->llHighlightWords);
                }
                else if (    ($field_id == FIELD_ATTACHMENT)
                          && ($idBinary = $oRow->value_1)
                          && (isset($aBinariesFound[$idBinary]))
                        )
                    $htmlChange = "<span style=\"background: yellow;\">".$htmlChange."</span>";
            }
            else
                $htmlChange = toHTML($oRow->fieldname);

            $oHTML->addTableRow( [ Format::Timestamp($oRow->chg_dt, 2),
                                   toHTML($oRow->login),
                                   $htmlChange ],
                                NULL,
                                " id=\"change-$oRow->i\"");
        }
        $oHTML->close(); # table body
        $oHTML->close(); # table

        $oHTML->close();    # div-page-changelog
    }

    /**
     *
     */
    public function addAttachmentBox(Ticket $oTicket,
                                     HTMLChunk $oHTML)
    {
        WholePage::Enable(WholePage::FEAT_JS_DROPZONE);

        $maxUploadMB = FileHelpers::GetMaxUploadSizeInMB();

        $oHTML->addLine("<br>");
//             $this->openContainer(FALSE, 'attachment');

        $htmlLabel = L('{{L//Add an attachment (max. %MB%)}}',
                       [ '%MB%' => $maxUploadMB.Format::NBSP.'MB' ]);
        if (LoginSession::IsCurrentUserAdmin())
        {
            $uploadMaxFilesize = FileHelpers::GetIniBytes('upload_max_filesize');
            $postMaxSize = FileHelpers::GetIniBytes('post_max_size');
            $tooltip = L('{{L//Note for administrators: The maximum file size is determined by the PHP.INI settings on this server. The following values are currently active:}}')
                      ." upload_max_filesize = $uploadMaxFilesize, post_max_size = $postMaxSize.";
            $htmlLabel = HTMLChunk::MakeTooltip($htmlLabel, $tooltip);
        }

        $oHTML->openForm('addAttachment-outer-form');

        $oHTML->openFormRow();
        $oHTML->addLabelColumn(HTMLChunk::FromEscapedHTML($htmlLabel));
        $oHTML->openWideColumn();
        $oHTML->addDropzone('addAttachment',
                            '/attachment/'.$oTicket->id,
                            $maxUploadMB);
        $oHTML->close(); # wide column
        $oHTML->close(); # form row

        $oHTML->close(); # form
    }

    private function addCommentRow(HTMLChunk $oHTML,
                                   string $id,
                                   HTMLChunk $oHelp = NULL)
    {
        $oHTML->openFormRow();

        $oHTML->addLabelColumn(HTMLChunk::FromEscapedHTML(L('{{L//Add a comment}}')),
                               $id);

        $oHTML->openWideColumn();
        $oHTML->addWYSIHTMLEditor($id, '', 5);
        if ($oHelp)
            $oHTML->addHelpPara($oHelp);
        $oHTML->close(); # wide column

        $oHTML->close(); # form row
    }

    /**
     *  Entry point. This creates an instance and emits the page.
     */
    public function emit()
    {
        $this->makeDetailsRows();

        # Prepend the ticket template description / link to the list of buttons, if available.
        if ($this->oHtmlTicketTemplate)
        {
            if (count($this->aHtmlChunksBeforeTitle))
                array_unshift($this->aHtmlChunksBeforeTitle, HTMLChunk::FromEscapedHTML(Format::MDASH));

            array_unshift($this->aHtmlChunksBeforeTitle, $this->oHtmlTicketTemplate);
        }

        $oHTML = new HTMLChunk();
        $htmlBeforeTitle = '';
        if (count($this->aHtmlChunksBeforeTitle))
        {
            $htmlBeforeTitle .= "<p>".HTMLChunk::Implode(' ', $this->aHtmlChunksBeforeTitle)->html."</p>\n";
            if (count($this->aHTMLForButtonsHidden))
                $htmlBeforeTitle .= implode('', $this->aHTMLForButtonsHidden);
        }

        if ($oIcon = $this->oTicket->getIcon())
            $htmlBeforeTitle .= "<div class=\"drn-icon-details\">$oIcon->html</div>";

        $oHTML->openPage( ($this->htmlPageHeading) ? $this->htmlPageHeading : $this->htmlTitle,
                          $this->fContainerFluid,
                          NULL,
                          $htmlBeforeTitle);

        // Now add the whole chunk from makeDetailsRows().
        $oHTML->append($this->oHTMLPage->html);

        $fAddCommentBox = $fAddAttachmentBox = FALSE;

        if ($this->mode == MODE_READONLY_DETAILS)
        {
            $oHTML->openList(HTMLChunk::LIST_NAV, NULL, 'drn-padding-above');

            $oHTMLTabPages = new HTMLChunk(6);

            if (    ($this->oChangelog)
                 && ($this->oChangelog->cAttachments)
               )
            {
                $oHTML->addLine("<li id=\"select-files\" class=\"active\"><a href=\"#\">".L('{{L//Files}}')."</a></li>");
                $this->aTabPages[] = 'files';
            }

            if (    ($this->fHasAttachmentField)
                 && ($this->oTicket->canUploadFiles(LoginSession::$ouserCurrent))
               )
            {
                $oHTML->addLine("<li id=\"select-new-attachment\"><a href=\"#\">".L('{{L//Attach a file}}')."</a></li>");
                $this->aTabPages[] = 'new-attachment';
                $fAddAttachmentBox = TRUE;
            }

            if (    ($this->fHasCommentField)
                 && ($this->oTicket->canComment(LoginSession::$ouserCurrent))
               )
            {
                $oHTML->addLine("<li id=\"select-new-comment\"><a href=\"#\">".L('{{L//Add a comment}}')."</a></li>");
                $this->aTabPages[] = 'new-comment';
                $fAddCommentBox = TRUE;
            }

            if (    ($this->oChangelog)
                 && ($this->oChangelog->aChangelogRows)
               )
            {
                $oHTML->addLine("<li id=\"select-changelog\"><a href=\"#\" rel=\"version-history\">".L('{{L//Change log}}')."</a></li>");
                $this->aTabPages[] = 'changelog';
            }

            #
            #  FILES TAB
            #

            if (($this->oChangelog) && ($this->oChangelog->cAttachments))
                $this->makeAttachmentsTab($oHTMLTabPages);


            #
            #  NEW ATTACHMENT TAB
            #

            if ($fAddAttachmentBox)
            {
                $oHTMLTabPages->openDiv('div-page-new-attachment', 'hidden');
                $this->addAttachmentBox($this->oTicket, $oHTMLTabPages);
                $oHTMLTabPages->close();    # div-page-new-attachment
            }

            #
            # CHANGELOG TAB
            #

            if (    ($this->oChangelog)
                 && count($this->oChangelog->aChangelogRows)
               )
                $this->makeChangelogTab($oHTMLTabPages);

            #
            # ADD NEW COMMENT TAB
            #

            if ($fAddCommentBox)
            {
                $commentFormDlgId = 'postNewCommentForm';
                $oHTMLTabPages->openDiv('div-page-new-comment', 'hidden');

                $oHTMLTabPages->openForm($commentFormDlgId);
                $oHTMLTabPages->addLine("<br>");

                $oHTMLTabPages->addHidden($commentFormDlgId.'-ticket_id', $this->oTicket->id);

                $this->addCommentRow($oHTMLTabPages, $commentFormDlgId.'-comment');

                $oHTMLTabPages->addErrorAndSaveRow($commentFormDlgId,
                                                   HTMLChunk::FromEscapedHTML(L('{{L//Add comment}}')));

                $oHTMLTabPages->addLine("<br>");
                $oHTMLTabPages->close(); # form
                $oHTMLTabPages->addLine();

                WholePage::AddNLSStrings([
                    'comment_justnow' =>  L('{{L//just now}}')
                ]);
                $login = LoginSession::$ouserCurrent->login;

                WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */'core_initTicketCommentForm',
                                                     [ $commentFormDlgId, $login ]);

                if ($this->oTicket->canUpdate(LoginSession::$ouserCurrent))
                {
                    $idDialog = 'comment-edit';
                    $oHTML->openGridRow($idDialog, 'hidden');
                    $oHTML->openGridColumn(12);
                    $oHTML->addWYSIHTMLEditor("$idDialog-comment", '', 3, FALSE);
                    $oHTML->addInput('hidden', "$idDialog-comment_id");
                    $oHTML->close();
                    $oHTML->openGridColumn(12);
                    $oHTML->addHiddenErrorBox("$idDialog-error");
                    $oHTML->appendElement('button',
                                         [
                                             'type' => 'button',
                                             'class' => 'btn btn-default',
                                             'id' => "$idDialog-cancel",
                                             'autocomplete' => 'off',
                                         ],
                                         HTMLChunk::FromEscapedHTML(L('{{L//Cancel}}')));
                    $oHTML->appendElement('button',
                                         [
                                             'type' => 'button',
                                             'class' => 'btn btn-primary',
                                             'id' => "$idDialog-save",
                                             'autocomplete' => 'off',
                                         ],
                                         HTMLChunk::FromEscapedHTML(L('{{L//Update comment}}')));
                    $oHTML->close();
                    $oHTML->close();

                    WholePage::AddIcon('edit');
                    WholePage::AddIcon('trash');
                    WholePage::AddNLSStrings([
                        'editCommentTooltip' => L('{{L//Edit comment #%ID%}}'),
                        'deleteCommentTooltip' => L('{{L//Delete comment #%ID%}}'),
                    ]);
                    WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initCommentEditorButtons',
                                                         [ $idDialog ]);
                }

            # rotateInDownRight slideInDown look nice too
            # TODO: clear the editor after success, re-enable the save button

                $oHTMLTabPages->close();    # div-page-new-comment
            }

            $oHTML->close();        # list
            $oHTML->append($oHTMLTabPages->html);

        } # end if ($this->mode == MODE_READONLY_DETAILS)

        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initTicketDetails',
                                             [ $this->aTabPages ?? [],
                                               $this->aAttachmentIDs ?? [],
                                               $fAddCommentBox ] );

        foreach ($this->aXmlDialogFiles as $dlg)
            $oHTML->addXmlDlg(dirname(__FILE__).'/'.$dlg);

        $oHTML->close();        # page

        foreach ($this->aDialogFiles as $file)
            $oHTML->addXmlDlg($file);
        foreach ($this->aDialogChunks as $oChunk)
            $oHTML->appendChunk($oChunk);

        WholePage::Emit($this->htmlTitle, $oHTML);
    }

    public static function CreateAndEmit($mode, $id)
    {
        $o = new self($mode,
                      $id);
        $o->emit();
    }

    public static function CreateAndEmitFromWikiTitle(string $str)
    {
        if (    !($id = Ticket::FindTicketIdByWikiTitle($str))
             || !($oTicket = Ticket::FindForUser($id,
                                                 LoginSession::$ouserCurrent,
                                                 ACCESS_READ,
                                                 FALSE /* populate */))
           )
            throw new DrnException("Either ".Format::UTF8Quote($str)." is not the title of a Wiki article, or you do not have permission to view that article.");

        $o = new self(MODE_READONLY_DETAILS,
                      $id);
        $o->emit();
    }
}
