<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  TitleHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_TITLE.
 *
 *  FIELD_TITLE data is plain text without HTML formatting. Summaries
 *  have a simple text entry field in MODE_CREATE and MODE_EDIT and are
 *  simply printed out otherwise.
 */
class TitleHandler extends FieldHandler
{
    public $label = self::TITLE;

    protected $flSelf =   self::FL_LINKIFY_VALUE
                        | self::FL_SHOW_NO_DATA_MSG
                        | self::FL_HIGHLIGHT_SEARCH_TERMS;



    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_TITLE);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Returns the description for this ticket field. This is used by \ref appendFormRow()
     *  for the label of the dialog row.
     *  $oContext can be inspected for context but MAY BE NULL for a safe default value.
     *
     *  This should return "Title" for the title label, but we allow Ticket (!) subclasses
     *  to override this so they can give their titles a different label without having
     *  to create their own field handler.
     */
    public function getLabel(TicketContext $oContext = NULL)
        : HTMLChunk
    {
        if (    ($oContext)
             && ($oContext->oTicket)
           )
            $str = $oContext->oTicket->getInfo(Ticket::INFO_TITLE_LABEL);
        else
            $str = self::TITLE;

        return HTMLChunk::FromString(L($str));
    }

    /**
     *  Returns the value for the field from the ticket.
     *
     *  The default FieldHandler would return the FIELD_TITLE value, but
     *  we call Ticket::getTitle() instead, which does that. But that method
     *  can be overridden by a Ticket subclass for run-time ticket title
     *  display.
     */
    public function getValue(TicketContext $oContext)
    {
        if ($oContext->mode == MODE_CREATE)
            return '';

        return $oContext->oTicket->getTitle();
    }

    /**
     *  Called for ticket details in MODE_READONLY_DETAILS to add a Bootstrap row div
     *  to the page's HTML to display the ticket data.
     *
     *  The FieldHandler parent would call $this->formatValue() in turn, but we want to
     *  set the page title to the ticket title value instead, so we override this.
     *
     * @return void
     */
    public function appendReadOnlyRow(TicketPageBase $oPage)               //!< in: TicketPageBase instance with ticket dialog information
    {
        $oTitle = HTMLChunk::FromString($this->getValue($oPage));
        if ($oPage->oTicket->getInfo(Ticket::INFO_DETAILS_TITLE_WITH_TICKET_NO))
            $oTitle->prepend("#".$oPage->oTicket->id.": ");
        $oPage->htmlTitle = $oTitle->html;
        $oPage->htmlPageHeading = $oPage->oTicket->getIconPlusChunk($oTitle)->html;
        if ($oPage->llHighlightWords && (count($oPage->llHighlightWords)))
            Format::HtmlHighlight($oPage->htmlPageHeading, $oPage->llHighlightWords);
    }

    /**
     *  Called for ticket details to add a row to the dialog form which
     *  displays the field data for the ticket and allows for editing it.
     *  Only gets called in MODE_CREATE or MODE_EDIT, because otherwise there is
     *  no editable dialog. (In MODE_READONLY_DETAILS, appendReadOnlyRow() gets called instead.)
     *
     *  Normally the parent method works fine, but we suppress the field
     *  entirely if the ticket type has FL_AUTOMATIC_TITLE set.
     *
     * @return void
     */
    public function appendFormRow(TicketPageBase $oPage)   # in: TicketPageBase instance with ticket dialog information
    {
        if (!($oPage->oType->fl & TicketType::FL_AUTOMATIC_TITLE))
            parent::appendFormRow($oPage);
    }

    /**
     *  Called by appendFormRow() to return the explanatory help that should be
     *  displayed under the entry field in a ticket's "Edit" form.
     *
     *  We override this for FIELD_TITLE to instead call Ticket::getInfo(INFO_EDIT_TITLE_HELP),
     *  which gives Ticket subclasses a chance to override the title.
     */
    public function getEntryFieldHelpHTML(TicketPageBase $oPage)
        : HTMLChunk
    {
        return HTMLChunk::FromString($oPage->oTicket->getInfo(Ticket::INFO_EDIT_TITLE_HELP));
    }

    /**
     *  Second function that gets called after appendFormRow() in MODE_CREATE or MODE_EDIT
     *  modes to add the field name to list of fields to be submitted as JSON in the body
     *  of a ticket POST or PUT request (for create or update, respectively).
     *
     *  We override the FieldHandler implementation to suppress the field if the ticket type
     *  has FL_AUTOMATIC_TITLE set. Otherwise we call the parent.
     *
     * @return void
     */
    public function addFieldToJSDialog(TicketPageBase $oPage)
    {
        # Suppress the field name if we have an automatic title.
        if (!($oPage->oType->fl & TicketType::FL_AUTOMATIC_TITLE))
            parent::addFieldToJSDialog($oPage);
    }

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        $str = L('{{L//Title changed}}');

        if ($oPage->mode == MODE_READONLY_DETAILS)
            $str .= self::AddChangedAndShowHideButton("old-title-".$oRow->i,         # idTarget
                                                      toHTML($oRow->text_old));
        return $str;
    }

    /**
     *  Static helper that gets called from formatChangelogItem() and also DescriptionHandler::formatChangelogItem().
     */
    public static function AddChangedAndShowHideButton($idTarget,
                                                       $htmlTextOld)
    {
        $oHTML2 = new HTMLChunk(8);
        $oHTML2->addShowHideButton($idTarget);
        $oHTML2->addLine("<div class=\"hidden\" id=\"$idTarget\">$htmlTextOld</div>");
        return $oHTML2->html;
    }
}


/********************************************************************
 *
 *  DescriptionHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_DESCRIPTION.
 *
 *  FIELD_DESCRIPTION data, like with FIELD_TITLE, is text, but it can contain
 *  HTML formatting. As a result, in MODE_CREATE and MODE_EDIT, the full editor
 *  is added to the HTML form (which produces HTML code). When ticket data is
 *  printed, we can just dump the HTML from the database.
 */
class DescriptionHandler extends FieldHandler
{
    public $label = self::DESCRIPTION;

    public $gridclass = 'md';       # Override parent's 'xs'.

    protected $flSelf =   self::FL_LINKIFY_VALUE
                        | self::FL_SHOW_NO_DATA_MSG
                        | self::FL_HIGHLIGHT_SEARCH_TERMS;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($field_id = FIELD_DESCRIPTION)
    {
        parent::__construct($field_id);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Returns the description for this ticket field. This is used by \ref appendFormRow()
     *  for the label of the dialog row.
     *  $oContext can be inspected for context but MAY BE NULL for a safe default value.
     *
     *  This should return "Title" for the title label, but we allow Ticket (!) subclasses
     *  to override this so they can give their titles a different label without having
     *  to create their own field handler.
     */
    public function getLabel(TicketContext $oContext = NULL)
        : HTMLChunk
    {
        if (    ($oContext)
             && ($oContext->oTicket)
           )
            $str = $oContext->oTicket->getInfo(Ticket::INFO_EDIT_DESCRIPTION_LABEL);
        else
            $str = self::DESCRIPTION;

        return HTMLChunk::FromString(L($str));
    }

    /**
     *  Called for ticket details in MODE_READONLY_DETAILS to add a Bootstrap row div
     *  to the page's HTML to display the ticket data.
     *
     *  We override the parent to be able to add the description without a label
     *  for wiki articles (fCompactDetails).
     */
    public function appendReadOnlyRow(TicketPageBase $oPage)               //!< in: TicketPageBase instance with ticket dialog information
    {
        if (    ($oPage->mode == MODE_READONLY_DETAILS)
             && ($oPage->oTicket->oType->fCompactDetails)
           )
            // Add description as-is, without a label and a wide column (for wiki).
            $oPage->oHTMLPage->appendChunk($this->formatValueHTML($oPage,
                                                                  $this->getValue($oPage)));
        else
            parent::appendReadOnlyRow($oPage);
    }

    /**
     *  Called by appendFormRow() to return the explanatory help that should be
     *  displayed under the entry field in a ticket's "Edit" form.
     *
     *  We override this for FIELD_TITLE to instead call Ticket::getInfo(INFO_EDIT_TITLE_HELP),
     *  which gives Ticket subclasses a chance to override the title.
     */
    public function getEntryFieldHelpHTML(TicketPageBase $oPage)
        : HTMLChunk
    {
        return HTMLChunk::FromString($oPage->oTicket->getInfo(Ticket::INFO_EDIT_DESCRIPTION_HELP));
    }

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  We override this to insert the HTML editor instead of the parent's
     *  plain-text entry field.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,          //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        $value = $this->getValue($oPage);
        $oPage->oDlg->addWYSIHTMLEditor($idControl,
                                        $value,
                                        $oPage->oTicket->getInfo(Ticket::INFO_EDIT_DESCRIPTION_C_ROWS));
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        return Format::HtmlStrip($value);
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        $o = new HTMLChunk();

        /* If some ticket type chooses to display discriptions even in list mode,
         * then we don't want to display all of them. */
        if (    ($oContext->mode == MODE_READONLY_LIST)
             || ($oContext->mode == MODE_READONLY_GRID)
           )
        {
            $o->html = Format::HtmlTruncate($value);
            if ($o->html != $value)
                $o->html .= HTMLChunk::MakeElement('p',
                                                   [],
                                                   $oContext->oTicket->makeLink2(FALSE,
                                                                                 L("{{L//Show more}}").Format::HELLIP)
                                                  )->html;

        }
        else
            $o->html = $value;

        $this->highlightSearchTerms($oContext, $o);
        return $o;
    }

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        $str = L('{{L//Description changed}}');

        if ($oPage->mode == MODE_READONLY_DETAILS)
            # Re-use the code from TitleHandler.
            $str .= TitleHandler::AddChangedAndShowHideButton("old-description-".$oRow->i,         # idTarget
                                                              $oRow->text_old);              # already HTML
        return $str;
    }
}


/********************************************************************
 *
 *  CommentHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_COMMENT.
 *
 *  FIELD_COMMENT data, like FIELD_DESCRIPTION, is HTML-formatted text. The field
 *  does not have the FIELDFL_REQUIRED_IN_POST_PUT bit set though, so its data
 *  is only printed with changelogs.
 *
 *  As a result, this handler has no code to add a form row in MODE_CREATE and
 *  MODE_EDIT; instead, the ticket dialog generator  has some special casing to
 *  add an HTML editor at the beginning of the changelog in ticket details
 *  views.
 */
class CommentHandler extends FieldHandler
{
    # Description and help are not needed because comments are not part of the create/edit ticket dialog.


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($typeId = FIELD_COMMENT)
    {
        parent::__construct($typeId);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        if (    !empty($oRow->comment)
             && $oPage->oTicket)
        {
            $edit = self::GetEditComment($oPage, $oRow);
            return $edit.self::CommentFormatter($oRow->comment, $oRow->value_1);
        }
        return L('<span data-text-id="'.$oRow->value_1.'">{{L//This version of the comment has been retracted}}</span>');
    }

    protected static function GetEditComment(TicketPageBase $oPage,
                                          ChangelogRow $oRow)
        : string
    {
        if ($oPage->oTicket->canUpdateComment(LoginSession::$ouserCurrent, $oRow->i))
        {
            $editIcon = Icon::Get('edit');
            $deleteIcon = Icon::Get('trash');
            $commentId = $oRow->i;
            $tooltipEdit = HTMLChunk::FromString(L('{{L//Edit comment #%ID%}}', [
                '%ID%' => $commentId
            ]))->html;
            $tooltipDelete = HTMLChunk::FromString(L('{{L//Delete comment #%ID%}}', [
                '%ID%' => $commentId
            ]))->html;
            return <<<HTML
<span class="pull-right">
    <a href="" class="drn-edit-comment" data-id="$commentId" title="$tooltipEdit" rel="edit edit-form"><!--
     -->$editIcon<!--
 --></a>
    <a href="" class="drn-delete-comment" data-id="$commentId" title="$tooltipDelete"><!--
     -->$deleteIcon<!--
 --></a>
</span>
HTML;
        }
        return '';
    }

    /********************************************************************
     *
     *  Newly introduced public static methods
     *
     ********************************************************************/


    public static function CommentFormatter(string $comment,
                                            string $textId)     //!< in: ID of the text field. Used to merge comment changelog entries on the client-side.
    {
//        $left = Icon::Get('quote-left').NBSP;
//        $right = NBSP.Icon::Get('quote-right');
        return <<<HTML
<article class="drn-comment" data-text-id="$textId">
    $comment
</article>
HTML;
    }
}

class UpdatedCommentHandler extends CommentHandler
{
    # Description and help are not needed because comments are not part of the create/edit ticket dialog.


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_COMMENT_UPDATED);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        if (!empty($oRow->comment))
        {
            if ($oPage->oTicket)
                $edit = self::GetEditComment($oPage, $oRow);
            else
                $edit = '';
            $updatedInfo = L('<p>{{L//Comment updated to:}}</p>');
            return $edit.$updatedInfo.self::CommentFormatter($oRow->comment, "$oRow->value_2\" data-text-oldid=\"$oRow->value_1");
        }
        return L('<span data-text-id="'.$oRow->value_2.'" data-text-oldid="'.$oRow->value_1.'">{{L//This version of the comment has been retracted}}</span>');
    }
}

class DeletedCommentHandler extends FieldHandler
{
    public function __construct()
    {
        parent::__construct(FIELD_COMMENT_DELETED);
    }

    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        return L('<span data-text-oldid="'.$oRow->value_1.'">{{L//Comment deleted}}</span>');
    }
}

/********************************************************************
 *
 *  AttachmentHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_ATTACHMENT.
 *
 *  An attachment can be any binary file. Like FIELD_COMMENT, the attachment
 *  field does not have the FIELDFL_REQUIRED_IN_POST_PUT bit set though, so
 *  its data is only printed with changelogs.
 */
class AttachmentHandler extends FieldHandler
{
    # Description and help are not needed because comments are not part of the create/edit ticket dialog.


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_ATTACHMENT);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        $oBinary = Binary::CreateFromChangelogRow($oRow);
        return AttachmentHandler::FormatChangelogItemImpl($oBinary);
    }

    /**
     *  Static helper that can be called without having instantiate an AttachmentHandler instance.
     */
    public static function FormatChangelogItemImpl(Binary $oBinary)

    {
        $href = Binary::MakeHREF($oBinary->idBinary);
        $info = L('{{L//Attached file %LINK% (type %TYPE%, %SIZE%)}}',
                  [ '%LINK%' => "<a href=\"$href\">".toHTML($oBinary->filename)."</a>",
                    '%TYPE%' => toHTML($oBinary->mimetype),
                    '%SIZE%' => Format::Bytes($oBinary->size, 2) ]);  // 1000-based, not 1024

        if ($thumb = self::FormatThumbnailer($oBinary->idBinary,
                                             $href,
                                             $oBinary->mimetype))
            return $thumb.$info;

        return $info;
    }

    /**
     *  Static helper that checks if $mimetype can be thumbnailed. If so, returns an image link, otherwise NULL.
     */
    public static function FormatThumbnailer($idBinary,
                                             $href,
                                             $mimetype)
    {
        if (Ticket::IsImage($mimetype))
            return "<a href=\"$href\">".Format::Thumbnail($idBinary, Globals::$thumbsize)."</a> ";

        return NULL;
    }
}


/********************************************************************
 *
 *  TicketImportedHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_TICKET_IMPORTED. See FieldHandler for how these work.
 */
class TicketImportedHandler extends FieldHandler
{

    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_TICKET_IMPORTED);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  Each call of this method is surrounded by a try() block so throwing exceptions
     *  here will print the exception message in the changelog row instead of blowing
     *  up the request entirely.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        return "Imported from xTracker ticket #".$oRow->value_1;
    }
}


/********************************************************************
 *
 *  ImportedFromHandler class
 *
 ********************************************************************/

/**
 *  Field handler for FIELD_IMPORTEDFROM ('importedfrom').
 *
 *  This is an optional field for all kinds of ticket types which may have been
 *  imported from another source, to be able to track if a source ticket has
 *  already been imported or not.
 *
 *  Spec:
 *
 *   -- Can be NULL, this field is optional for tickets that use it.
 *
 *   -- In Ticket instance: integer source ID.
 *
 *   -- GET/POST/PUT JSON data: integer source ID.
 *
 *   -- Database: row in ticket_ints.
 *
 *   -- Search engine: not indexed by default.
 */
class ImportedFromHandler extends FieldHandler
{
    public $label = '{{L//Original ID on source server}}';
    public $help  = '{{L//The ID of the data where this record was imported from.}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($id = FIELD_IMPORTEDFROM)
    {
        parent::__construct($id);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Called for ticket details in MODE_READONLY_DETAILS to add a Bootstrap row div
     *  to the page's HTML to display the ticket data.
     *
     *  We override the parent to only show the entire row if the Ticket INFO_SHOW_IMPORT_ID
     *  info flag returns TRUE. This allows Ticket subclasses to suppress the import ID.
     *
     * @return void
     */
    public function appendReadOnlyRow(TicketPageBase $oPage)               //!< in: TicketPageBase instance with ticket dialog information
    {
        if ($oPage->oTicket->getInfo(Ticket::INFO_SHOW_IMPORT_ID))
            parent::appendReadOnlyRow($oPage);
    }

    /**
     *  Called for ticket details to add a row to the dialog form which
     *  displays the field data for the ticket and allows for editing it.
     *  Only gets called in MODE_CREATE or MODE_EDIT, because otherwise there is
     *  no editable dialog. (In MODE_READONLY_DETAILS, appendReadOnlyRow() gets called instead.)
     *
     *  We override the parent to only show the entire row if the Ticket INFO_SHOW_IMPORT_ID
     *  info flag returns TRUE. This allows Ticket subclasses to suppress the import ID.
     */
    public function appendFormRow(TicketPageBase $oPage)   # in: TicketPageBase instance with ticket dialog information
    {
        if ($oPage->oTicket->getInfo(Ticket::INFO_SHOW_IMPORT_ID))
            parent::appendFormRow($oPage);
    }
}


/********************************************************************
 *
 *  TicketTypeHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_TYPE.
 *
 *  This is somewhat special in that the ticket type is not part of the dynamic
 *  data array but core ticket data. We still want a field handler to have
 *  consistent handling for drill-down filters.
 */
class TicketTypeHandler extends FieldHandler
{
    public $label = '{{L//Ticket type}}';
    public $help  = '';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_TYPE);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        return TicketType::FindName($value);
    }

    /**
     *  Function that is used when building the filters list for ticket search results. This
     *  must return an array of value -> HTMLChunk pairs for each item in the list.
     *
     * @return HTMLChunk[]
     */
    public function formatManyHTML(TicketContext $oContext,
                                   $llValues)
    {
        $aTypes = TicketType::GetAll();
        $aReturn = [];
        foreach ($llValues as $idType)
            if ($oType = $aTypes[$idType] ?? NULL)
            {
                $oHtml = HTMLChunk::FromString($oType->getName());

                if ($oContext->mode == MODE_READONLY_FILTERLIST)
                    if ($oIcon = $oType->getIcon())
                    {
                        $oIcon = HTMLChunk::MakeElement('span',
                                                        [ 'style' => 'width: 100px;' ],
                                                        $oIcon);
                        $oHtml->prepend(Format::NBSP.$oIcon->html.Format::NBSP);
                    }

                $aReturn[$idType] = $oHtml;
            }
            else
                $aReturn[$idType] = new HTMLChunk();

        return $aReturn;
    }
}


/********************************************************************
 *
 *  TicketTypeHandler class
 *
 ********************************************************************/

/**
 * Common ancestor class for CreatedDateTimeHandler and LastModifiedDateTimeHandler.
 */
class DateTimeBaseHandler extends FieldHandler
{

    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *
     *  This default implementation simply returns $value, except for numbers, which are
     *  formatted first according to the current locale. Many subclasses override this to
     *  turn internal values into human-readable representations.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        return Format::Timestamp($value, FALSE);
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        # In ticket mail, use absolute dates/times instead of "less than two hours ago"
        return Format::TimestampH($value,
                                  !($oContext && $oContext->mode == MODE_TICKETMAIL));
    }
}

/**
 *  Specialized field handler for the "creation date/time " ticket field. This is part
 *  of the core tickets table. The handler is only used for display, no other methods
 *  are overridden, and no update methods should be called on this because these
 *  fields are handled in Ticket::update() specially.
 */
class CreatedDateTimeHandler extends DateTimeBaseHandler
{
    public $label = '{{L//Created}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_CREATED_DT);
    }
}

/**
 *  Specialized field handler for the "last modified date/time" ticket field. This is part
 *  of the core tickets table. The handler is only used for display, no other methods
 *  are overridden, and no update methods should be called on this because these
 *  fields are handled in Ticket::update() specially.
 */
class LastModifiedDateTimeHandler extends DateTimeBaseHandler
{
    public $label = '{{L//Changed}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_LASTMOD_DT);
    }
}

/**
 *  Field handler for attachment rename action on a binary. Only visible in the
 *  global changelog.
 */
class AttachmentRenamedHandler extends FieldHandler
{
    public function __construct()
    {
        parent::__construct(FIELD_ATTACHMENT_RENAMED);
    }

    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        $oBinary = Binary::Load($oRow->value_1);
        $renameInfo = json_decode($oRow->value_str);
        $href = Binary::MakeHREF($oBinary->idBinary);
        return L('{{L//Renamed file %OLDNAME% to %LINK%}}',
                  [ '%LINK%' => "<a href=\"$href\">".toHTML($renameInfo->newName)."</a>",
                    '%OLDNAME%' => $renameInfo->oldName ]);
    }
}

/**
 *  Field handler for attachment delete action on a binary. Only visible in the
 *  global changelog.
 */
class AttachmentDeletedHandler extends FieldHandler
{
    public function __construct()
    {
        parent::__construct(FIELD_ATTACHMENT_DELETED);
    }

    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        $oBinary = Binary::Load($oRow->value_1);
        $href = Binary::MakeHREF($oBinary->idBinary);
        return L('{{L//File %LINK% hidden}}',
                  [ '%LINK%' => "<a href=\"$href\">".toHTML($oBinary->filename)."</a>" ]);
    }
}
