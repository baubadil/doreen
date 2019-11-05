<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  TicketContext and TicketPageBase classes
 *
 ********************************************************************/

/**
 *  TicketContext is an abstraction of Ticket details, an even more simplified version
 *  of TicketPageBase.
 *
 */
class TicketContext
{
    public $mode;                   # One of MODE_CREATE, MODE_READONLY_DETAILS, MODE_EDIT, MODE_READONLY_CHANGELOG, MODE_READONLY_LIST, MODE_READONLY_CARDS, MODE_TICKETMAIL

    public $filterListMode;         # Only for MODE_READONLY_FILTERLIST: MODE_READONLY_LIST or MODE_READONLY_GRID, depending on which mode the list is being built for.

    /** @var  Ticket */
    public $oTicket;                # In MODE_CREATE mode, the template from which we create, otherwise the ticket being shown/edited.
    public $oNewTicket;             # In MODE_CREATE only, the ticket that is being created.
    public $flAccess = 0;           # In MODE_CREATE the access flags for the template, otherwise those of the ticket, for the current user.

    /** @var  TicketType */
    public $oType;                  # The ticket type of the existing ticket, or the template type in MODE_CREATE mode.
    public $aVariableData;          # In MODE_EDIT, the raw API data that was given to Ticket::update().

    # The following two are for MODE_EDIT: the ticket mail that was composed so far (see FieldHandler::addToTicketMail()).
    public $aTicketMailHTML = [];
    public $aTicketMailPlain = [];

    public $hrefTicket;             # In MODE_READONLY_LIST and MODE_READONLY_CARDS, HREF for a link to the ticket details.
    public $llHighlightWords;        # Flat list of words to highlight in search results, if any.

    public $ouserLastMod;
    public $lastmod_uid;
    public $dtNow;

    /** @var Changelog $oChangelog */
    public $oChangelog;

    public $ticketMailSubjectTag;   # Can be set by an writeToDatabase() override for a more specific mail subject.

    /* The following is only TRUE while appendFormRow() is being called. This allows
       dependent methods like SelectFromSetHandlerBase::getValidValues() to filter
       out certain values in the GUI. HACK. */
    public $fInAppendFormRow = FALSE;

    /* Fields for update(): These get written into the DB. */
    public $write_created_dt = NULL;
    public $write_lastmod_dt = NULL;
    public $write_created_uid = NULL;
    public $write_lastmod_uid = NULL;

    public function __construct(User $ouserLastMod = NULL,
                                Ticket $oTicket = NULL,
                                $mode)
    {
        $this->ouserLastMod = $ouserLastMod;
        $this->lastmod_uid = ($ouserLastMod) ? $ouserLastMod->uid : NULL;
        $this->dtNow = Globals::Now();
        if ($this->oTicket = $oTicket)
            $this->oType = $oTicket->oType;
        $this->mode = $mode;
    }
}

/**
 *  TicketPageBase is an abstraction of a Ticket details form in MODE_CREATE or
 *  MODE_EDIT mode. This abstraction can be passed to ticket handlers without
 *  having to include the entire form implementation.
 *
 *  The actual implementation is in a derived class of this and only gets
 *  included when we are actually in the ticket details form.
 */
class TicketPageBase extends TicketContext
{
    # None of the following are set in MODE_READONLY_LIST or MODE_READONLY_CARDS
    /** @var Dialog  */
    public $oDlg = NULL;                # only for MODE_CREATE and MODE_EDIT

    public $aFieldsForDialog = [];      # only for MODE_CREATE and MODE_EDIT

    public $idDialog;
    public $url;
    public $htmlSavebutton;
    public $fDisableSaveButton = FALSE; # If TRUE, the submit button is disabled.
    public $htmlTitle;
    public $htmlPageHeading;            # If NULL, $htmlTitle is used

    /** @var HTMLChunk $oHTMLPage  */
    public $oHTMLPage;                  # This is where the ticket page gets built.

    public $fContainerFluid = FALSE;    # For MODE_READONLY_DETAILS, if a field handler sets this to TRUE,
                                        # a flud (full-width) container is used for the details. For example,
                                        # to display a wide table.

    /** @var HTMLChunk[] $aHtmlChunksBeforeTitle */
    public $oHtmlTicketTemplate;                # Ticket template description, if available.
    public $aHtmlChunksBeforeTitle = [];    # pull-right box etc.
    public $aHTMLForButtonsHidden = [];

    # Tabs for attachments, changelog etc.
    public $aTabPages = [];

    public $aDialogFiles = [];

    public function addDialog($file)
    {
        $this->aDialogFiles[] = $file;
    }
}


/********************************************************************
 *
 *  FieldHandler base class
 *
 ********************************************************************/

/**
 *  Base class for all field handlers.
 *
 *  Every ticket field (i.e. TicketField instances identified by FIELD_* constants, e.g.
 *  FIELD_DESCRIPTION) can have associated with it a field handler, which combines
 *  "model" and "view" functionality:
 *
 *   -- FieldHandler and subclasses get called for every ticket field from
 *      Ticket::createAnother() (on create) and Ticket::update() (on update) with the
 *      new ticket data for every field. The field handler is responsible for parsing
 *      the input data and writing it to the database (see \ref writeToDatabase()).
 *      It must also be able to serialize ticket field values to PHP arrays (see \ref serializeToArray()).
 *
 *   -- Additionally, FieldHandler has methods for formatting ticket field values -- for
 *      example, \ref formatValueHTML() and \ref formatValuePlain(). It must also create
 *      HTML form elements for a field when tickets are created or updated, and
 *      format field data nicely in a changelog.
 *
 *  The field handler system was chosen for two reasons:
 *
 *   1. It is possible to inspect ticket data using only the Ticket and TicketField
 *      classes without having to load the overhead of FieldHandler instances.
 *      The FieldHandlers are only needed when pretty HTML display of ticket
 *      data and changelogs is needed, or for the ticket editor.
 *
 *   2. Plugins can implement useful behavior for ticket fields that the Doreen
 *      core need not even know about. The base FieldHandler class implements
 *      helpful defaults for fields, but there are lots of method overrides
 *      for the various field types Doreen ships with.
 *
 *  If you derive another FieldHandler subclass in a plugin, you must create an
 *  instance of that subclass in a createFieldHandler() method in your
 *  ITypePlugin implementation.
 *
 *  See \ref howdoi_add_ticket_field for step-by-step instructions how to add
 *  a new ticket field with a new field handler.
 *
 *  Note that a subclass of FieldHandler adds functionality for a particular ticket
 *  *field*, whereas a subclass of the Ticket class can add functionality for
 *  for entire tickets of a particular *type* (as a combination of fields).
 *  See TicketType::getTicketClassName().
 */
class FieldHandler
{
    public $field_id;               # Set by constructor.
    /** @var TicketField $oField */
    public $oField;                 # Set by constructor.
    public $fieldname;              # Set by constructor.

    public    $label;                   # To be set by a derived class.
    public    $labelHelpTopic;          # Can optionally be set by derived classes to display a help button next to the label.
    public    $help;                    # To be set by a derived class.
    public    $gridclass = 'xs';        # Bootstrap grid class to be used. Can be overridden by subclasses.
    protected $extraRowClasses = '';  # Extra CSS classes for bootstrap row in details mode

    /*
     *  If true, multiple values can be selected in the drill down filters with a checkbox
     *  (the default); otherwise only one item can be selected at a time (with a radio button).
     */
    public $fDrillMultiple = TRUE;
    /*
     *  If true, a toggle should be shown to allow switching between radio and multi-select
     *  mode when \ref fDrillMultiple is false.
     */
    public $fDrillMultipleOptional = TRUE;

    /** @var FieldHandler[] */
    private static $aFieldHandlers = [];     # Array of instantiated field handlers (field id => object pairs).

    const C_LABEL_COLUMNS             = 3;        # Width of the Bootstrap column for the label. The wide column will be 12 - this.
    const C_MONETARY_SUBLABEL_COLUMNS = 2;        # For monetary amounts, width of the sublabel as part of the wide column.
    const C_MONETARY_AMOUNT_COLUMNS   = 2;        # For monetary amounts, width of the amount as part of the wide column.

    // $flSelf has flags for formatValueHTML(). These matter only if that method is not overridden.
    protected $flSelf = 0;
    const FL_LINKIFY_VALUE          = (1 <<  0);            //!< Print HTML in list view as a link to the ticket (esp. for title).
    const FL_SHOW_NO_DATA_MSG       = (1 <<  1);            //!< Print "NO DATA" if field value is empty. Otherwise links wouldn't work.
    const FL_HIGHLIGHT_SEARCH_TERMS = (1 <<  2);            //!< Highlight search terms in field value when displaying search results.
    const FL_GRID_PREFIX_FIELD      = (1 <<  3);            //!< Print field name before value in grid view.

    // Some string constants shared between derived plugin handlers, which run into gettext confusion otherwise.
    const TITLE = '{{L//Title}}';
    const DESCRIPTION = '{{L//Description}}';
    const TAXES = '{{L//Taxes}}';
    const NET_AMOUNT = '{{L//Net amount}}';
    const SUM = '{{L//Sum}}';
    const INVOICE = '{{L//Invoice}}';
    const PAYMENT = '{{L//Payment}}';
    const EFFECTIVE = '{{L//Effective}}';
    const CREATED = '{{L//Created}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    /**
     *  Derived classes MUST call this constructor to have the field handler instance
     *  registered globally for the given field ID. As an example, for the FIELD_TITLE
     *  handler, whenever Doreen encounters the FIELD_TITLE ID somewhere, it can
     *  call the TitleHandler instance to do the work.
     */
    public function __construct($field_id)          //!< in: field ID (FIELD_* constant) that this instance will handle
    {
        $this->field_id = $field_id;

        if ($field_id != FIELD_IGNORE)
        {
            $this->oField = TicketField::FindOrThrow($field_id);
            $this->fieldname = $this->oField->name;

            self::$aFieldHandlers[$field_id] = $this;
        }
        else
        {
            $this->oField = TicketField::Find(FIELD_STATUS);
            $this->fieldname = $this->oField->name;
        }
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  Gets called by Ticket::PopulateMany() for all fields that have the FIELDFL_CUSTOM_SERIALIZATION
     *  flag set. This must then produce the SQL needed to retrieve stage-2 data from the database for
     *  multiple tickets.
     *
     *  The following output arguments must be filled:
     *
     *   -- The return value must be a LEFT JOIN statement that will be added to the end of the query's
     *      SELECT statement. This must pull rows for this ticket field from the database table containing them
     *      and name a table alias for them.
     *
     *   -- $columnNames must be the column names for the beginnining of SELECT statement. This should reference
     *      the table alias named in the LEFT JOIN statement above. At least TWO columns must be produced:
     *      one with the field name and one called $fieldname_rowid containing the primary row index (for updates
     *      and deletion).
     *
     *   -- $aGroupBy is an array that column names can be appended to for the SELECT statement's GROUP BY
     *      statement.
     *
     *  This default implementation is empty because by default, ticket fields do not have
     *  FIELDFL_CUSTOM_SERIALIZATION set. However, if a ticket field does set that flag, this method
     *  must be overridden, or no data will be retrieved.
     *
     * @return string
     */
    public function makeFetchSql(&$columnNames,
                                 &$aGroupBy)
    {
    }

    /**
     *  The companion to \ref makeFetchSql(). This gets called for every row arriving from the database
     *  and must fill the instance data of the given Ticket instance with the data decoded from the given
     *  database row.
     */
    public function decodeDatabaseRow(Ticket $oTicket,
                                      array $dbrow)
    {
    }

    /**
     *  Returns the label for this ticket field. This is used by \ref appendFormRow()
     *  for the label of the dialog row.
     *  $oContext can be inspected for context but MAY BE NULL for a safe default value.
     *
     *  This default variant simply returns the value of the $label member, which
     *  subclasses can simply set, but subclasses can also override this method instead.
     *
     *  @return HTMLChunk
     */
    public function getLabel(TicketContext $oContext = NULL)  //!< in: TicketContext instance or NULL
        : HTMLChunk
    {
        $descr = ($this->label) ? L($this->label) : $this->oField->name;
        $oHtml = HTMLChunk::FromEscapedHTML($descr);

        if ($oContext)
            if ($oContext->mode == MODE_READONLY_DETAILS)
                if ($this->labelHelpTopic)
                    $oHtml->appendChunk(HTMLChunk::MakeHelpLink($this->labelHelpTopic));

        return $oHtml;
    }

    /**
     *  Special feature for ticket list views that allows a field handler to use the column
     *  of another field. Obviously this should only be used if that other field is not in
     *  use by the ticket type.
     *
     *  This default implementation returns NULL, but a subclass may want to return another
     *  field ID.
     *
     * @return array|null
     */
    public function mapToOtherColumn()
    {
        return NULL;
    }

    /**
     *  Called by FieldHandler::appendReadOnlyRow() to add a link next to the column title to
     *  add another item of the type, e.g. to add a child ticket.
     *
     *  The FieldHandler implementation returns NULL but subclasses can implement something
     *  clever.
     */
    public function makeAddAnother(TicketPageBase $oPage)
    {
        return NULL;
    }

    /**
     *  This must return the initial value for the field in MODE_CREATE.
     *  This default implementation returns NULL.
     */
    public function getInitialValue(TicketContext $oContext)
    {
        return NULL;
    }

    /**
     *  Returns the value for the field from the ticket.
     *
     *  For MODE_CREATE, this must return a default value to be
     *  used in the ticket; the FieldHandler default implementation
     *  simply returns $this->defaultValue, but a subclass can
     *  do something fancier.
     *
     *  If the field has FIELDFL_ARRAY set and the value is a comma-
     *  separated list of values, we return a PHP array.
     *
     *  For ticket details and list views, this must return the
     *  ticket's value for this field.
     *
     * @return mixed
     */
    public function getValue(TicketContext $oContext)
    {
        if ($oContext->mode == MODE_CREATE)
            return $this->getInitialValue($oContext);

        $v = $oContext->oTicket->aFieldData[$this->field_id] ?? NULL;

        if ($this->oField->fl & FIELDFL_ARRAY)
        {
            if (    ($v !== NULL)
                 && (!is_array($v))
               )
                return explode(',', $v);

            return ($v && count($v)) ? $v : NULL;
        }

        # MODE_READONLY_LIST or MODE_READONLY_CARDS or MODE_EDIT or MODE_READONLY_DETAILS:
        return $v;
    }

    /**
     *  Formatting helper for \ref appendReadOnlyRow(), called from \ref addReadOnlyColumnsForRow().
     *  This might be useful for subclasses to call if they override that method altogether.
     *
     *  This returns the no. of bootstrap grid columns for the label column so that the right (wide)
     *  content column can fill the rest.
     */
    protected function addReadOnlyLabelColumn(TicketPageBase $oPage,      //!< in: TicketPageBase instance with ticket dialog information
                                              $htmlAddAnother)            //!< in: "add another" link (e.g. "+" sign with fly-over) or NULL; from makeAddAnother()
        : int
    {
        $cLabelColumns = self::C_LABEL_COLUMNS;

        $oLabel = $this->getLabel($oPage);
        if ($htmlAddAnother)
            $htmlAddAnother = ' '.$htmlAddAnother;

        $oPage->oHTMLPage->openDiv(NULL,
                                   "col-{$this->gridclass}-$cLabelColumns");
        $oPage->oHTMLPage->addLine("<b class=\"drn-breakable-label\">$oLabel->html</b>$htmlAddAnother");
        $oPage->oHTMLPage->close(); // label

        return (int)$cLabelColumns;
    }

    /**
     *  Formatting helper for \ref appendReadOnlyRow().
     *  This might be useful for subclasses to call if they override that method altogether.
     *
     * @return void
     */
    protected function addReadOnlyColumnsForRow(TicketPageBase $oPage,      //!< in: TicketPageBase instance with ticket dialog information
                                                $htmlAddAnother,            //!< in: "add another" link (e.g. "+" sign with fly-over) or NULL; from makeAddAnother()
                                                HTMLChunk $oHTML)           //!< in: everything for the right column (from formatValue())
    {
        $oPage->oHTMLPage->openDiv(NULL,
                                   "row".(($this->extraRowClasses) ? ' '.$this->extraRowClasses : ''),
                                   "row for {$this->fieldname}");

        // Label column
        $cLabelColumns = $this->addReadOnlyLabelColumn($oPage, $htmlAddAnother);

        // Wide column
        $cOtherColumns = 12 - $cLabelColumns;
        $oPage->oHTMLPage->openDiv(NULL,
                                   "col-{$this->gridclass}-$cOtherColumns");
        $oPage->oHTMLPage->appendChunk($oHTML);
        $oPage->oHTMLPage->close(); // label

        $oPage->oHTMLPage->close(); // DIV fieldname
    }

    /**
     *  Called for ticket details in MODE_READONLY_DETAILS to add a Bootstrap row div
     *  to the page's HTML to display the ticket data.
     *
     *  This calls $this->formatValueHTML() in turn, so if a derived class is happy
     *  with the result, this might not need to be overridden.
     *
     * @return void
     */
    public function appendReadOnlyRow(TicketPageBase $oPage)               //!< in: TicketPageBase instance with ticket dialog information
    {
        $this->addReadOnlyColumnsForRow($oPage,
                                        $this->makeAddAnother($oPage),
                                        $this->formatValueHTML($oPage,
                                                               $this->getValue($oPage)));
    }

    /**
     *  Called from \ref appendFormRow() to produce the label column on the left side of
     *  the form for this field.
     *
     *  Subclasses may want to override this for adding additional things to the label,
     *  similar to what \ref addReadOnlyLabelColumn() can do for read-only rows.
     *
     * @return void
     */
    public function addFormLabelColumn(TicketPageBase $oPage,   //!< in: TicketPageBase instance with ticket dialog information
                                       string $idControl,
                                       string $gridclass,
                                       string $extraClasses)
    {
        $oPage->oDlg->addLabelColumn($this->getLabel($oPage),
                                     $idControl,
                                     $gridclass,
                                     $extraClasses);
    }

    /**
     *  Called for ticket details to add a row to the dialog form which
     *  displays the field data for the ticket and allows for editing it.
     *  Only gets called in MODE_CREATE or MODE_EDIT, because otherwise there is
     *  no editable dialog. (In MODE_READONLY_DETAILS, appendReadOnlyRow() gets called instead.)
     *
     *  This calls $this->addDialogField() in turn. which should be implemented
     *  by a derived class. Alternatively a derived class could override this
     *  method altogether.
     *
     * @return void
     */
    public function appendFormRow(TicketPageBase $oPage)   # in: TicketPageBase instance with ticket dialog information
    {
        $idControl = "{$oPage->idDialog}-{$this->fieldname}";

        $oPage->oDlg->openFormRow();
        $this->addFormLabelColumn($oPage, $idControl, 'sm', 'drn-breakable-label col-xs-12');
        $oPage->oDlg->openWideColumn();

        # Add the value as an input.
        $this->addDialogField($oPage, $idControl);

        if ($oHtmlHelp = $this->getEntryFieldHelpHTML($oPage))
            $oPage->oDlg->addHelpPara($oHtmlHelp);

        $oPage->oDlg->close();      # wide column
        $oPage->oDlg->close();      # form row
    }

    /**
     *  Called by appendFormRow() to return the explanatory help that should be
     *  displayed under the entry field in a ticket's "Edit" form.
     *
     *  This FieldHandler default returns the $help static member, which should
     *  be overridden by subclasses. Alternatively, a subclass can override this
     *  method.
     *
     *  @return HTMLChunk
     */
    public function getEntryFieldHelpHTML(TicketPageBase $oPage)
        : HTMLChunk
    {
        return HTMLChunk::FromString(L($this->help));
    }

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  The default in this base class calls this::getValue() and then adds
     *  a simple text input. This is good enough for "Summary", for example.
     *
     *  Subclasses override this often to add something more suitable for
     *  their purposes. For example, the implementation for FIELD_DESCRIPTION
     *  adds the textarea editor here; FIELD_PRIORITY adds a select/option
     *  dropdown, etc.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,          //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        $icon = NULL;
        $fl = 0;
        $type = 'text';
        if (0 == ($this->oField->fl & FIELDFL_ARRAY))
        {
            if ($this->oField->tblname == 'ticket_ints')
                $type = 'number';
            else if ($this->oField->tblname == 'ticket_amounts')
            {
                $icon = 'euro';
                $fl = HTMLChunk::INPUT_PULLRIGHT;
            }
        }

        $value = $this->getValue($oPage);
        if (is_array($value))
            $value = implode(',', $value);

        $oPage->oDlg->addInput($type,
                               $idControl,
                               '',
                               toHTML($value),
                               $fl,
                               $icon);
    }

    /**
     *  Second function that gets called after appendFormRow() in MODE_CREATE or MODE_EDIT
     *  modes to add the field name to list of fields to be submitted as JSON in the body
     *  of a ticket POST or PUT request (for create or update, respectively).
     *
     *  This default implementation simply adds the field name to an array in TicketPageBase,
     *  which should almost always work. But some subclasses may want to do something more
     *  complicated, or suppress the field completely.
     *
     * @return void
     */
    public function addFieldToJSDialog(TicketPageBase $oPage)
    {
        $oPage->aFieldsForDialog[] = $this->oField->name;
    }

    /**
     *  This method gets called in MODE_READONLY_LIST and MODE_READONLY_CARDS to allow field handlers to
     *  preload additional data beyond the ticket field data before they get called again for the actual tickets.
     *
     *  For example, if 20 tickets are to be displayed and the ticket data would contain links to other
     *  tickets, the field handlers might have to do SQL queries for that additional data. It would be much
     *  quicker if the field handler could preload that data by doing one SQL query for all tickets involved.
     *  This method allows for that. It gets called ONCE with the list of tickets before FieldHandler::appendReadOnlyRow()
     *  etc. get called for every ticket separately. This call receives an array of tickets for which the
     *  stage-2 data is about to be retrieved, before the caller invokes Ticket::PopulateMany(). This
     *  method can therefore add additional tickets to that array if additional ticket data is required.
     *  Alternatively the field handler can perform different database queries, if required.
     *
     *  The FieldHandler base implementation does nothing. This is designed to be overridden by
     *  subclasses; see ParentsHandler and ChildrenHandler for examples.
     *
     * @return void
     */
    public function preloadDisplay($aVisibleTickets,
                                   &$aFetchStage2DataFor)    //!< in/out: result ticket set (keys must be ticket IDs to be loaded, values are ignored)
    {
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *
     *  This default implementation simply returns $value, except for numbers, which are
     *  formatted first according to the current locale.
     *
     *  See also \ref formatValueHTML().
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        if (    ($this->oField->tblname == 'ticket_ints')
             && (!$this->oField->fl & FIELDFL_ARRAY)
           )
            return Format::Number($value);

        if (is_array($value))
            return implode(', ', $value);

        return $value;
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     *
     *  This default implementation calls \ref formatValuePlain(), HTML-escapes the
     *  result and puts it into a new HTMLChunk.
     *
     *  If you need to override these methods to display human-readable strings for
     *  internal codes or enumeration values, you need to at least override
     *  formatValuePlain().
     *
     *  Whether you also need to override this method depends on whether you want
     *  to use fancy HTML like links in your values. If not, this default
     *  implementation looks at $fLinkifyValue, $fShowNoData and $fHighlightSearchTerms
     *  in $this. So it may be enough to override formatValuePlain() and set those
     *  variables to FALSE in your FieldHandler subclass.
     *
     *  @return HTMLChunk
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        $o = new HTMLChunk();

        if ($v = $this->formatValuePlain($oContext, $value))
        {
            $o->html = toHTML($v);

            if ($this->flSelf & self::FL_HIGHLIGHT_SEARCH_TERMS)
                $this->highlightSearchTerms($oContext, $o);
        }

        if (    ($oContext->mode == MODE_READONLY_LIST)
             || ($oContext->mode == MODE_READONLY_GRID)
           )
        {
            if ((!$v) && ($this->flSelf & self::FL_SHOW_NO_DATA_MSG))
                $o->html = L("{{L//[NO DATA]}}");

            if ($o->html)
            {
                if ($this->flSelf & self::FL_LINKIFY_VALUE)
                    $o->html = "<a href=\"$oContext->hrefTicket\">$o->html</a>";

                if (    ($oContext->mode == MODE_READONLY_GRID)
                     && ($this->flSelf & self::FL_GRID_PREFIX_FIELD)
                   )
                    $this->prefixDescription($o);
            }
        }

        return $o;
    }

    /**
     *  Function that is used when building the filters list for ticket search results. This
     *  must return an array of value -> HTMLChunk pairs for each item in the list.
     *
     *  This FieldHandler default calls formatValue for each of the items. A subclass might
     *  want to override this to be able to preload items from a database if this causes
     *  excessive database queries, since on the ticket search page, this is invoked before
     *  all tickets have been made awake.
     *
     * @return HTMLChunk[]
     */
    public function formatManyHTML(TicketContext $oContext,
                                   $llValues)
    {
        Debug::FuncEnter(Debug::FL_TICKETDISPLAY, __METHOD__."() for field ".Format::UTF8Quote($this->fieldname));
        $aReturn = [];
        foreach ($llValues as $value)
            $aReturn[$value] = $this->formatValueHTML($oContext, $value);
        Debug::FuncLeave();
        return $aReturn;
    }

    /**
     *  Called by \ref FindResults::makeFilters() to format the list of button filters for a ticket field. This
     *  gets called once for every ticket type that is drillable, and the results end up on top the search
     *  results view.
     *
     *  $oContext will have $mode set to MODE_READONLY_FILTERLIST. Additionally, $filterListMode will be
     *  set to either MODE_READONLY_LIST or MODE_READONLY_GRID, depending on which view the filters list is
     *  being built for.
     *
     *  This returns a single string with <button> elements sorted by field value names,
     *  containing the filters for the current ticket field (e.g. type or category). This
     *  function can be overridden for more complex value types.
     *
     *  Example: When we have no filters initially, and two buckets for ticket types 5 and 6 have been returned
     *  in the aggregations, the following needs to happen:
     *
     *   -- If user clicks on "filter to type 5", we need to produce the URL "filter_type=5,!6"
     *
     *   -- If user clicks on "filter to type 6", we need to produce the URL "filter_type=!5,6"
     *
     *   -- If on the following screen the user clicks on a filter for a different field, we need to
     *      produce the URL "filter_type=!5,!6"
     *
     *  $aParticles2 receives a list of URL particles so the function can produce new URLs
     *  for each button with different filters activated or deactivated.
     *
     *  $aActiveFilters is a two-dimensional array of active drill-down filters; it is an empty array if no filters
     *  are active yet. For example, if the filter for FIELD_TYPE = TYPE_WIKI is already active, it would contain
     *  [ FIELD_TYPE => [ TYPE_WIKI => 1 ] ]. The value is always 1; it is a two-dimensional array so that isset() can
     *  be used quickly on the keys.
     *
     *  The filter field name is normally the same as $this->fieldname.
     */
    public function formatDrillDownResults(TicketContext $oContext,     //!< in: ticket page context
                                           $aValueCounts,               //!< in: integer value => count pairs (e.g. type id for "wiki" => 3)
                                           string $baseCommand,         //!< in: base for generated URLs (e.g. 'tickets' or 'board')
                                           $aParticles2,                //!< in: a copy of the WebApp::ParseURL result particles
                                           $aActiveFilters)             //!< in: two-dimensional array of active filter names and values; empty if none
    {
        $aFilterValuesThisByName = [];

        $aValueChunks = $this->formatManyHTML($oContext, array_keys($aValueCounts));

        foreach ($aValueCounts as $valueThis => $cValueThis)
        {
            /** @var HTMLChunk $oValueChunk */
            $oValueChunk = getArrayItem($aValueChunks, $valueThis);
            $plain = Format::HtmlStrip($oValueChunk->html);

            $aFilterValuesThisByName[$plain] = $this->makeOneDrillDownButton($baseCommand,
                                                                             $aParticles2,
                                                                             $aActiveFilters,
                                                                             $valueThis,
                                                                             $oValueChunk,
                                                                             $cValueThis);
        }

        ksort($aFilterValuesThisByName);

        return HTMLChunk::Implode('<br>', $aFilterValuesThisByName)->html;
    }

    /**
     *  Prefix of the parameter used to switch a radio drill down filter that
     *  can optionally filter multiple values. It is followed by the field name
     *  and the value is not evaluated at all. Its presence alone indicates that
     *  multiple values should be selectable.
     *
     *  @var string
     */
    const MULTIPLE_PARTICLE_PREFIX = 'multiple_';

    /**
     *  Check if multiple values can be selected for this field in drill down
     *  filters. Depends on flags on this field handler and the URL param to
     *  toggle between radio and multi-select mode.
     *
     *  @return bool
     */
    private function canSelectMultiple(array $aParticles)
        : bool
    {
        return (    $this->fDrillMultiple
                 || (    $this->fDrillMultipleOptional
                      && isset($aParticles[self::MULTIPLE_PARTICLE_PREFIX.$this->fieldname]))
               );
    }

    /**
     *  Check if a drill down filter can be toggled between radio and multi-select
     *  mode.
     *
     *  @return bool
     */
    public function shouldShowMultipleToggle()
        : bool
    {
        return !$this->fDrillMultiple && $this->fDrillMultipleOptional;
    }

    /**
     *  Helper function called by \ref formatDrillDownResults() to make one drill-down button.
     *
     *  This is in a separate method because field handlers might want to override the parent
     *  method but still find this formatter useful.
     */
    protected function makeOneDrillDownButton(string $baseCommand,      //!< in: command for building the URL, e.g. 'tickets' (e.g. from WebApp::$command)
                                              $aParticles2,             //!< in: a copy of the WebApp::ParseURL result particles
                                              $aActiveFilters,          //!< in: two-dimensional array of active filter names and values; empty if none
                                              $valueThis,               //!< in: filter value for which button is produced
                                              HTMLChunk $oValueName,
                                              $cValueThis = 0,
                                              $extraClasses = '')
        : HTMLChunk
    {
        $fChecked = FALSE;
        if (isset($aActiveFilters[$this->fieldname][$valueThis]))
            $fChecked = TRUE;

        $url = $this->makeDrillDownFilterURL($baseCommand,
                                             $aParticles2,
                                             $aActiveFilters,
                                             $valueThis);

        $oBadge = NULL;
        if ($cValueThis !== NULL)
            $oBadge = HTMLChunk::MakeElement('span',
                                             [ 'class' => 'badge' ],
                                             HTMLChunk::FromString(Format::Number($cValueThis)));

        return self::FormatDrillDownButton($extraClasses,
                                           $fChecked,
                                           $url,
                                           $oValueName,
                                           $oBadge,
                                           $this->canSelectMultiple($aParticles2));
    }

    /**
     *  Creates the button to toggle between radio and multi-select mode, effectively
     *  toggling the multiple_ parameter for this field and ensuring the selected
     *  values are valid for the current mode.
     *
     *  If multiple values are selected and multiple mode would be disabled, only
     *  the first selected value is preserved for radio mode.
     *
     *  This is only used by the list view, the grid generates this button client-side.
     *
     *  @return HTMLChunk
     */
    public function makeDrillMultipleToggle(string $baseCommand,
                                               $aParticles2)
        : HTMLChunk
    {
        unset($aParticles2['page']);
        $multipleName = self::MULTIPLE_PARTICLE_PREFIX.$this->fieldname;
        $fMultiple = $this->canSelectMultiple($aParticles2);
        if ($fMultiple)
        {
            unset($aParticles2[$multipleName]);
            // Only keep the first value when switching to radio mode.
            if (    isset($aParticles2['drill_'.$this->fieldname])
                 && ($currentValue = $aParticles2['drill_'.$this->fieldname])
                 && ($firstComma = strpos($currentValue, ','))
                 && ($firstComma !== FALSE)
               )
                $aParticles2['drill_'.$this->fieldname] = substr($currentValue, 0, $firstComma);
        }
        else
            $aParticles2[$multipleName] = 1;

        $url = Globals::BuildURL(Globals::$rootpage."/$baseCommand",
                                 $aParticles2,
                                 Globals::URL_URLENCODE);

        return self::FormatDrillDownButton('',
                                           $fMultiple,
                                           $url,
                                           HTMLChunk::FromString(L('{{L//Multiple}}')));
    }

    /**
     *  Produces one drill-down button with the given data.
     */
    public static function FormatDrillDownButton(string $extraClasses = NULL,
                                                 bool $fChecked,
                                                 $url,                          //!< in: URL, will be HTML-escaped
                                                 HTMLChunk $oValueName,
                                                 HTMLChunk $oBadge = NULL,
                                                 bool $fDrillMultiple = TRUE)
        : HTMLChunk
    {
        if ($oBadge)
        {
            $oLink = clone $oValueName;
            $oLink->append(' '.$oBadge->html);
        }
        else
            $oLink = clone $oValueName;
        /** @var HTMLChunk $oLink */

        $checkboxBase = 'checkbox';
        if (!$fDrillMultiple)
            $checkboxBase = 'radio';

        $oLink->prependChunk(Icon::GetH(($fChecked) ? $checkboxBase.'_checked' : $checkboxBase.'_unchecked'));
        $o = HTMLChunk::MakeElement('a',
                                    [ 'href' => $url,
                                      'aria-checked' => $fChecked ? "true" : "false" ],
                                    $oLink);
        $o = HTMLChunk::MakeElement('span',
                                    [ 'class' => "drn-drill-button $extraClasses" ],
                                    $o);

        return $o;
    }

    /**
     *  Helper function for \ref makeOneDrillDownButton() that produces the URL for a single filter button.
     *  Parameters are as with that function, except $valueThis, which is the filter value for which the
     *  button is being produced.
     *
     *  The result is NOT HTML-encoded.
     */
    protected function makeDrillDownFilterURL($baseCommand,             //!< in: command for building the URL, e.g. 'tickets' (e.g. from WebApp::$command)
                                              $aParticles2,             //!< in: a copy of the WebApp::ParseURL result particles
                                              $aActiveFilters,          //!< in: two-dimensional array of active filter names and values; empty if none
                                              $valueThis)               //!< in: filter value for which button is produced
        : string
    {
        /* When filters are changed, always start at page 1. Otherwise we'll risk having too large a pages
           and getting "0 tickets found". */
        unset($aParticles2['page']);

        /* Now, the button URL needs a comma-separated list of values that should be active in the filter.
           Build that list here. */
        $llNewFilterValues = [];

        if ($this->canSelectMultiple($aParticles2))
        {
            /* First keep all previously selected filter values, unless the value needs to be disabled. */
            if (isset($aActiveFilters[$this->fieldname]))
                foreach ($aActiveFilters[$this->fieldname] as $vPreviouslyActive => $dummyAlways1)
                    // Disable the value for this button if it is currently being used.
                    if ($this->canKeepPreviousFilterValue($vPreviouslyActive, $valueThis))
                        $llNewFilterValues[] = $vPreviouslyActive;

            // Enable the value if it hasn't been used.
            if (!isset($aActiveFilters[$this->fieldname][$valueThis]))
                $llNewFilterValues[] = $valueThis;
        }
        else
        {
            if (!isset($aActiveFilters[$this->fieldname][$valueThis]))
                $llNewFilterValues[] = $valueThis;
        }

        if (count($llNewFilterValues))
            $aParticles2["drill_$this->fieldname"] = implode(',', $llNewFilterValues);
        else
            unset($aParticles2["drill_$this->fieldname"]);

        return Globals::BuildURL(Globals::$rootpage."/$baseCommand",
                                 $aParticles2,
                                 Globals::URL_URLENCODE);
    }

    /**
     *  Gets called by makeDrillDownFilterURL() for $this ticket field ONLY for filter values
     *  that have previously been activated.
     *
     *  This default implementation will deselect a previously active filter value if the button
     *  gets clicked again. A subclass may want to override this if additional values need to be
     *  deselected, e.g. for categories in a tree.
     */
    protected function canKeepPreviousFilterValue($vPreviouslyActive,       //!< in: filter value that is active in current filter set
                                                  $valueThisButton)         //!< in: filter value for whose button HTML is being generated
        : bool
    {
        return ($vPreviouslyActive != $valueThisButton);
    }

    /**
     *  Called by \ref Ticket::toArray() to give each field handler a chance to add meaningful
     *  values to the PHP array returned from there.
     *
     *  If the $paFetchSubtickets reference is not NULL, it points to an array in
     *  \ref Ticket::GetManyAsArray(), and this function can add key/subkey/value pairs to that
     *  array to instruct that function to fetch additional subticket data.
     *  The format is: $paFetchSubtickets[idParentTicket][stringKey] = list of sub-ticket IDs.
     *  \ref Ticket::GetManyAsArray() will then fetch JSON for the sub-ticket IDs and add their JSON
     *  data to $aReturn in one go.
     *
     *  This default FieldHandler implementation appends a simple key => value pair to the
     *  array. Depending on the value type, we also add a _formatted variant for the front-end.
     *
     * @return void
     */
    public function serializeToArray(TicketContext $oContext,
                                     &$aReturn,
                                     $fl,
                                     &$paFetchSubtickets)
    {
        Debug::FuncEnter(Debug::FL_TICKETJSON,
                         __METHOD__, "field: $this->fieldname, fl=$fl");
        $value = $this->getValue($oContext);

        $fInt = $fMonetary = FALSE;
        switch ($this->oField->tblname)
        {
            case 'ticket_ints':
            case 'ticket_categories':
            case 'ticket_parents':
                $fInt = TRUE;
            break;

            case 'ticket_amounts':
                $fMonetary = TRUE;
            break;
        }

        $formatted = NULL;
        if ($fMonetary)
        {
            if ($value !== NULL)
            {
                $value = (float)$value;
                $formatted = Format::MonetaryAmount($value, 2);
            }
        }
        else if (    (is_array($value) && $fInt)
                  || (!is_array($value) && isInteger($value))
                )
            $formatted = $this->formatValueHTML($oContext,
                                                $value)->html;
        if ($fInt && !($this->oField->fl & FIELDFL_ARRAY))
            $value = (int)$value;
        $aReturn += [ $this->fieldname => $value ];
        if ($formatted !== NULL)
            $aReturn += [ $this->fieldname.'_formatted' => $formatted ];

        Debug::FuncLeave();
    }

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     *
     *  This basic implementation only prints the field name, which is not very useful.
     *  Subclasses should override this for something more informative.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        if ($this->oField->fl & FIELDFL_STD_DATA_OLD_NEW)
        {
            $old = $oRow->int_old;
            $new = $oRow->int_new;
            return L('{{L//%FIELD% changed from %OLD% to %NEW%}}',
                     [ '%FIELD%' => $this->getLabel($oPage)->html,
                       '%OLD%' => $this->formatValueHTML($oPage, $old)->html,
                       '%NEW%' => $this->formatValueHTML($oPage, $new)->html ]);
        }

        return toHTML($oRow->fieldname);
    }

    /**
     *  Calls formatChangelogItem() in a try/catch block.
     */
    public function tryFormatChangelogItem(TicketPageBase $oPage,
                                           ChangelogRow $oRow)
    {
        try
        {
            return $this->formatChangelogItem($oPage, $oRow);
        }
        catch (\Exception $e)
        {
            return L("{{L//Error formatting changelog message: %MSG%}}",
                     [ '%MSG%' => $e->getMessage() ]);
        }
    }

    /**
     *  This gets called by \ref onCreateOrUpdate() to determine whether the new value
     *  for the field is different from the old. Subclasses may override this if they have
     *  a special data format.
     *
     *  $oldValue will be NULL with MODE_CREATE. It can be NULL with MODE_EDIT if there was
     *  no value previously.
     *
     *  This only gets called if newValue was not NULL in the first place, so it will never be
     *  NULL (but could be an empty string).
     */
    public function isNewValueDifferent(TicketContext $oContext,
                                        $oldValue,          //!< in: old value (can be NULL)
                                        $newValue)          //!< in: new value (never NULL)
        : bool
    {
        if (    (    ($oldValue === NULL)                # ... in create case or if it doesn't exist in current data
                  && ($newValue)
                )
             || ($oldValue != $newValue)            # ... or if the new value differs from the old.
           )
            return TRUE;

        return FALSE;
    }

    /**
     *  This gets called from Ticket::createAnother() and Ticket::update() for every field that might
     *  have field data to update. This method then peeks into the update data (in $oContext->aVariableData),
     *  either from the PUT request or from whoever called Ticket::update() directly. If the data has changed,
     *  it then calls FieldHandler::writeToDatabase().
     *  Must return TRUE if the field was changed, or FALSE otherwise.
     *
     *  Note: In MODE_CREATE, $oContext->oTicket contains the template from which the
     *  new ticket was created, whereas $oTicket has the newly created ticket. In
     *  MODE_EDIT, both are set to the ticket being changed.
     *
     *  Subclasses may want to override this if they need specialized poking around the
     *  field data or need other ways to determine whether an update is needed.
     */
    public function onCreateOrUpdate(TicketContext $oContext,
                                     Ticket $oTicket,            //!< in: new or existing ticket instance
                                     int $fl = 0)                //!< in: combination of Ticket::CREATEFL_NOCHANGELOG and Ticket::CREATEFL_IGNOREMISSING
        : bool
    {
        $fieldname = $this->fieldname;

        $newValue = $oContext->aVariableData[$fieldname] ?? NULL;
        if ($newValue !== NULL)
        {
            $oldValue = ($oContext->mode == MODE_CREATE)
                            ? NULL
                            : $oldValue = $oTicket->aFieldData[$this->field_id] ?? NULL;

            if ($this->isNewValueDifferent($oContext, $oldValue, $newValue))
            {
                Debug::Log(Debug::FL_TICKETUPDATE, __METHOD__."(): field ".Format::UTF8Quote($fieldname)." needs update");
                $this->writeToDatabase($oContext,
                                       $oTicket,
                                       $oldValue,
                                       $newValue,
                                       ($fl & Ticket::CREATEFL_NOCHANGELOG) == 0);
                return TRUE;
            }
            else
                Debug::Log(Debug::FL_TICKETUPDATE, __METHOD__."(): field $fieldname has not changed, needs no update");
        }
        else if (    ($this->oField->fl & FIELDFL_REQUIRED_IN_POST_PUT)
                  && (    (0 == ($this->oField->fl & FIELDFL_FIXED_CREATEONLY))
                       || ($oContext->mode == MODE_CREATE)
                     )
                  && (0 == ($fl & Ticket::CREATEFL_IGNOREMISSING))
                )
            throw new APIMissingDataException($this->oField, L($this->label));

        return FALSE;
    }

    /**
     *  Implements a simple, non-array data change. Called from the default writeToDatabase()
     *  implementation for fields with FIELDFL_STD_DATA_OLD_NEW, but could be used elsewhere.
     *
     *  Note: This returns the new row ID, and the caller MUST update ticket data with it!
     */
    public static function InsertRow(Ticket $oTicket,
                                     TicketField $oField,
                                     $oldValue,
                                     $insertValue)
    {
        $tblname = $oField->getTableNameOrThrow();

        $newRowId = NULL;

        # A NULL value is always translated to "no row in data table", and a NULL row ID.
        if ($insertValue !== NULL)
        {
            Database::DefaultExec(<<<SQL
INSERT INTO $tblname ( ticket_id,     field_id,     value ) VALUES
                     ( $1,            $2,           $3 )
SQL
                   , [ $oTicket->id,  $oField->id,  $insertValue ] );

            # Return the new row ID so parent can insert it into changelog.
            $newRowId = Database::GetDefault()->getLastInsertID($tblname, 'i');

            Debug::Log(Debug::FL_TICKETUPDATE, __FUNCTION__."(): wrote new value, newRowId=$newRowId");
        }
        else
            Debug::Log(Debug::FL_TICKETUPDATE, __FUNCTION__."(): NULL value, no new row");

        $oldRowId = ($oTicket->aFieldDataRowIDs) ? getArrayItem($oTicket->aFieldDataRowIDs, $oField->id) : NULL;
        if (    ($oldValue !== NULL)
             && ($oldRowId)
           )
        {
            # Detach the old data entry from the ticket; we'll
            # use the row ID again below for the changelog entry
            Database::DefaultExec(<<<SQL
UPDATE $tblname
SET ticket_id = NULL
WHERE i = $1
SQL
                , [ $oldRowId ] );
        }
        else
            Debug::Log(Debug::FL_TICKETUPDATE, __FUNCTION__."(): not hiding old value; oldValue=$oldValue, oldRowId=$oldRowId");

        return [$oldRowId, $newRowId];
    }

    protected function insertRowWithChangelogAndMail(TicketContext $oContext,    //!< in: TicketContext instance
                                                     Ticket $oTicket,            //!< in: new or existing ticket instance
                                                     $oldValue,                  //!< in: existing value (NULL if not present or in MODE_CREATE)
                                                     $insertValue,               //!< in: new value from processFieldValue()
                                                     $fWriteChangelog)           //!< in: TRUE for MODE_EDIT (except in special cases), FALSE for MODE_CREATE
    {
        # Simple case (field has single values, no arrays):
        list($oldRowId, $newRowId) = self::InsertRow($oTicket,
                                                     $this->oField,
                                                     $oldValue,
                                                     $insertValue);

        if ($fWriteChangelog) # FALSE for MODE_CREATE
        {
            $this->addToChangelog($oContext,
                                  $oldRowId,
                                  $newRowId,
                                  $insertValue);

            $this->queueForTicketMail($oContext,
                                      $oldValue,
                                      $insertValue);
        }

        return $newRowId;
    }

    /**
     *  This gets called from \ref onCreateOrUpdate() for each ticket
     *  field whose data needs to be written to (or updated in) the database.
     *  Note: In MODE_CREATE, $oContext->oTicket contains the template from
     *  which the new ticket was created, whereas $oTicket has the newly created
     *  ticket. In MODE_EDIT, both are set to the ticket being changed.
     *  In MODE_EDIT, this only gets called if the new value is different from
     *  the old.
     *  This also generates changelog entries, unless $fWriteChangelog is FALSE. See
     *  \ref Changelog::AddTicketChange() for details about the format.
     *  See \ref data_serialization for an overview of the different
     *  data representations.
     *
     *  This version in the FieldHandler base class calls \ref processFieldValue(), writes
     *  to the DB and then calls \ref addToChangelog().
     *  Those methods can be overridden by subclasses for changing specific bits of that
     *  processing.
     *  Alternatively, a subclass can override this method altogether.
     *
     * @return void
     */
    public function writeToDatabase(TicketContext $oContext,    //!< in: TicketContext instance
                                    Ticket $oTicket,            //!< in: new or existing ticket instance
                                    $oldValue,                  //!< in: existing value (NULL if not present or in MODE_CREATE)
                                    $newValue,                  //!< in: new value to be written out
                                    $fWriteChangelog)           //!< in: TRUE for MODE_EDIT (except in special cases), FALSE for MODE_CREATE
    {
//         Debug::FuncEnter(__CLASS__.'::'.__FUNCTION__);

        $insertValue = $this->validateBeforeWrite($oContext,
                                                  $oldValue,
                                                  $newValue);        # reference

        $oField = $this->oField;
        $newRowId = NULL;

        if (!($oField->fl & (FIELDFL_ARRAY | FIELDFL_ARRAY_REVERSE)))
        {
            $newRowId = $this->insertRowWithChangelogAndMail($oContext,
                                                             $oTicket,
                                                             $oldValue,
                                                             $insertValue,
                                                             $fWriteChangelog);
        } # end if (!($oField->fl & FIELDFL_ARRAY))
        else if ($oField->fl & FIELDFL_STD_DATA_OLD_NEW)
        {
            # Array case (FIELD_PARENTS, FIELD_KEYWORDS):
            # The following analysis code is identical for FIELDFL_ARRAY and FIELDFL_ARRAY_REVERSE.
            $aOld = $aNew = [];

            $tblname = $oField->getTableNameOrThrow();

            if (is_array($oldValue))
                $aOld = $oldValue;
            else if ($oldValue)
                foreach (explode(',', $oldValue) as $v)
                    $aOld[$v] = 1;
            if ($insertValue)
            {
                if (is_array($insertValue))
                    $aParseNew = $insertValue;
                else
                    $aParseNew = explode(',', $insertValue);

                foreach ($aParseNew as $v)
                    $aNew[$v] = 1;
            }

            Debug::Log(Debug::FL_TICKETUPDATE, __FUNCTION__." old values aOld: ".print_r($aOld, TRUE));
            Debug::Log(Debug::FL_TICKETUPDATE, __FUNCTION__." new values aNew: ".print_r($aNew, TRUE));

            $fields = ($oldValue) ? $oTicket->aFieldDataRowIDs[$oField->id] : NULL;
            $llRowIDs = ($fields) ? explode(',', $fields) : NULL;
            $aToRemove = $aToAdd = [];          # aToRemove has row IDs as values, aToAdd simple '999' dummy values

            # Changelog items are especially tricky. See Changelog::AddTicketChange() for details about the format.
            $aChangelog
                = $aNewRowIDs
                = $aChangelogReverse            # format: ticketid to add item to ->
                = [];
            $fieldidReverse = 0;

            foreach ($aOld as $v => $dummy)
            {
                $rowid = array_shift($llRowIDs);

                if (!(isset($aNew[$v])))
                    # in old but not in new:
                    $aToRemove[$v] = $rowid;
                else
                    $aNewRowIDs[] = $rowid;
            }

            foreach ($aNew as $v => $dummy)
                if (!(isset($aOld[$v])))
                    # in new but not in old:
                    $aToAdd[$v] = 999;

            if (count($aToAdd))
                Debug::Log(Debug::FL_TICKETUPDATE, __FUNCTION__."(): aToAdd[] (dummy 999 values): ".print_r($aToAdd, TRUE));
            else
                Debug::Log(Debug::FL_TICKETUPDATE, __FUNCTION__."(): aToAdd[] is empty, nothing to do");
            if (count($aToRemove))
                Debug::Log(Debug::FL_TICKETUPDATE, __FUNCTION__."(): aToRemove[] (rowid values): ".print_r($aToRemove, TRUE));
            else
                Debug::Log(Debug::FL_TICKETUPDATE, __FUNCTION__."(): aToRemove[] is empty, nothing to do");

            # Now we need to differentiate between FIELDFL_ARRAY and FIELDFL_ARRAY_REVERSE.

            # Handle newly added values.
            foreach ($aToAdd as $v => $dummy)
            {
                if (!($oField->fl & FIELDFL_ARRAY_REVERSE))
                {
                    # "Forward" array case:
                    $idTicket = $oTicket->id;
                    $insertThis = $v;
                    $fieldid2 = $oField->id;

                    # Changelog handling:
                    $aChangelog[] = '+'.$v;
                    if ($oField->fl & FIELDFL_ARRAY_HAS_REVERSE)
                    {
                        # If the field with FIELDFL_ARRAY also has FIELDFL_ARRAY_HAS_REVERSE set,
                        # then add a companion entry to the *other* ticket.
                        $aChangelogReverse[$idTicket] = '+'.$v;
                        $fieldidReverse = $oField->id + 1;
                    }
                }
                else
                {
                    # "Reverse" array case: this only works if the values are ticket IDs.
                    $idTicket = $v;
                    $insertThis = $oTicket->id;
                    # HACK: reverse field ID must be array field ID + 1, so we insert
                    # the field_id -1 to get e.g. from "children" to "parents".
                    $fieldid2 = $oField->id - 1;

                    # Changelog handling:
                    $aChangelog[] = '+'.$v;
//                    Debug::Log("Storing aChangelogReverse[$insertThis] = +$v;");
                    $aChangelogReverse[$v] = '+'.$insertThis;
                    $fieldidReverse = $oField->id - 1;
                }

                if (!($oField->fl & FIELDFL_ARRAY_COUNT))
                {
                    Database::DefaultExec(<<<EOD
INSERT INTO $tblname
    ( ticket_id,   field_id,   value ) VALUES
    ( $1,          $2,         $3 )
EOD
  , [ $idTicket,   $fieldid2,  $insertThis ] );
                }
                else
                {
                    if (    (preg_match('/^(\d+):(\d+)$/', $insertThis, $aMatches))
                         && ($v2 = getArrayItem($aMatches, 1))
                         && ($c = getArrayItem($aMatches, 2))
                       )
                    {
                        Database::DefaultExec(<<<EOD
INSERT INTO $tblname
    ( ticket_id,   field_id,   value,  count ) VALUES
    ( $1,          $2,         $3,     $4 )
EOD
  , [ $idTicket,   $fieldid2,  $v2,    $c ] );
                    }
                    else
                        throw new DrnException("Invalid syntax in field value \"$insertThis\" when updating field \"$oField->name\" for ticket #{$oTicket->id}");
                }

                # Return the new row ID in case parent needs it, even though in this case we do
                # changelogs differently below.
                $aNewRowIDs[] = Database::GetDefault()->getLastInsertID($tblname, 'i');
            }

            # Handle deleted values.
            # To remove an array value from a ticket, we need to remove the ticket_id / array value
            # combo from $tblname. We have all the row IDs for that in the array values of $aToRemove.
            # This works for both the FIELDFL_ARRAY and the FIELDFL_ARRAY_REVERSE case.
            foreach ($aToRemove as $v => $rowid)
            {
                Database::DefaultExec(<<<EOD
DELETE FROM $tblname WHERE i = $1
EOD
                       , [ $rowid ] );

                $aChangelog[] = '-'.$v;

                if ($oField->fl & FIELDFL_ARRAY_HAS_REVERSE)
                {
                    $aChangelogReverse[$v] = '-'.$oTicket->id;
                    $fieldidReverse = $oField->id + 1;
                }
                else if ($oField->fl & FIELDFL_ARRAY_REVERSE)
                {
                    $aChangelogReverse[$v] = '-'.$oTicket->id;
                    $fieldidReverse = $oField->id - 1;
                }
            }

            if (    ($fWriteChangelog)      # FALSE for MODE_CREATE
                 && (count($aChangelog))
               )
            {
                $this->addToChangelog($oContext,
                                      NULL,     # oldRowId
                                      NULL,     # newRowId
                                      NULL,     # newValue
                                      implode(',', $aChangelog));       # value_str

                if ($fieldidReverse)
                    foreach ($aChangelogReverse as $idTicket1 => $plusMinusTicket)
                        Changelog::AddTicketChange($fieldidReverse,
                                                   $idTicket1,
                                                   $oContext->lastmod_uid,
                                                   $oContext->dtNow,
                                                   NULL, # $oldRowId,
                                                   NULL, # $newRowId,
                                                   $plusMinusTicket);
            }

            # Row ID for ticket data in this case is a comma-separated string list.
            if ($aNewRowIDs)
                $newRowId = implode(',', $aNewRowIDs);

        } # end else if (!($oField->fl & FIELDFL_ARRAY))

        # Fill or update the Ticket instance.
        if (is_array($insertValue))
            $insertValue = implode(',', $insertValue);
        $oTicket->aFieldData[$oField->id] = $insertValue;
        if ($newRowId)
            $oTicket->aFieldDataRowIDs[$oField->id] = $newRowId;

//         Debug::FuncLeave();
    }

    /**
     *  This gets called by \ref writeToDatabase() for every field before the
     *  new value gets written into the database. This must return a value that
     *  fits into the value column of the field's database table, or, in the
     *  case of FIELDFL_ARRAY, an arra of those values. It can also throw a
     *  APIException with the field name if validation fails.
     *
     *  This can be overridden by derived classes for value validation and processing
     *  of special binary formats or JSON that the field handler wants to support.
     *  For example, SelectFromSetHandlerBase overrides this to make sure the
     *  new value is part of the permitted set of values. Typically this method
     *  will only check $newValue, but by taking $oldValue into account, state
     *  transitions can be checked as well.
     *
     * @return mixed
     */
    public function validateBeforeWrite(TicketContext $oContext,    //!< in: TicketContext instance
                                        $oldValue,                  //!< in: current value (or NULL during create ticket)
                                        $newValue)                  //!< in: suggest new value from form field
    {
        // Convert empty strings to actual NULL values for numbers because otherwise they can't be inserted.
        if (    ($newValue === '')
             && (    ($this->oField->tblname == 'ticket_ints')
                  || ($this->oField->tblname == 'ticket_floats')
                  || ($this->oField->tblname == 'ticket_amounts')
                )
           )
            $newValue = NULL;

        // Check the ticket field flags here.
        if (    (    ($newValue === NULL)
                  || ($newValue === '')
                )
             && ($this->oField->fl & FIELDFL_REQUIRED_IN_POST_PUT)
           )
            throw new APIMissingDataException($this->oField, L($this->label));

        return $newValue;
    }

    /**
     *  Gets called by \ref writeToDatabase(), only in MODE_EDIT, to have a changelog entry
     *  written for the update. This is in the middle of a database transaction so the change
     *  will be discarded on errors. Like \ref writeToDatabase(), this only gets calls for
     *  fields whose values are actually changing.
     *
     *  The $oldRowId, $newRowId and $value_str parameters are field-dependent and
     *  are simply passed on to Changelog::AddTicketChange(); see
     *  Changelog::AddTicketChange() for the standard ticket changelog formats.
     *
     * @return void
     */
    public function addToChangelog(TicketContext $oContext,
                                   $oldRowId,
                                   $newRowId,
                                   $newValue,               //!< in: new value (needed by some subclass overrides for inspection)
                                   $value_str = NULL)
    {
        $oContext->oTicket->addChangelogEntry($this->field_id,
                                              $oContext->lastmod_uid,
                                              $oContext->dtNow,
                                              $oldRowId,
                                              $newRowId,
                                              $value_str);
    }

    /**
     *  Gets called by \ref writeToDatabase(), only in MODE_EDIT, to have entries added to
     *  to $oContext->aTicketMailHTML and aTicketMailPlain that will get sent out
     *  by \ref writeToDatabase() afterwards.
     *  Only if more than one item exists in that array AND ticket mail is not suppressed,
     *  ticket mail will actually get sent out later.
     *
     * @return void
     */
    public function queueForTicketMail(TicketContext $oContext,
                                       $old,
                                       $new)
    {
        $tpl = "{{L//%FIELD% changed from %OLD% to %NEW%}}";

        $oContext->aTicketMailHTML[] = L($tpl,
                                         [ '%FIELD%' => $this->getLabel($oContext)->html,
                                           '%OLD%' => $this->formatValueHTML($oContext,
                                                                             $old)->html,
                                           '%NEW%' => $this->formatValueHTML($oContext,
                                                                             $new)->html
                                         ]);
        $oContext->aTicketMailPlain[] = L($tpl,
                                         [ '%FIELD%' => $this->getLabel($oContext)->html,
                                           '%OLD%' => $this->formatValuePlain($oContext,
                                                                              $old),
                                           '%NEW%' => $this->formatValuePlain($oContext,
                                                                              $new)
                                         ]);
    }

    /**
     *  This can get called from a search engine's onTicketCreated() implementation
     *  when a ticket has been created or updated and needs to be indexed for a
     *  search engine. It gets called for every ticket field reported by
     *  \ref ITypePlugin::reportSearchableFieldIDs() and must return the data
     *  that the search engine should index.
     *
     *  This default implementation just returns the field data. A subclass may
     *  want to override this if the field data should be processed before being
     *  indexed.
     */
    public function makeSearchable(Ticket $oTicket)
    {
        return $oTicket->aFieldData[$this->field_id];
    }

    /**
     *  Like \ref makeSearchable(), this can get called from a search engine's
     *  create or update implementation, but this is for fields that are reported
     *  as drill-down fields by a plugin's reportDrillDownFieldIDs() function.
     *
     *  This default implementation returns an array of integers for fields
     *  which have FIELDFL_ARRAY set, or a single integer for fields that don't.
     *  Only integer fields should ever be used for drill-down aggregations.
     *
     *  Subclasses may want to override this.
     */
    public function expandDrillDownData($data)          //!< in: field data from ticket or directly from SQL
    {
        $fieldname = $this->oField->name;
        if ($this->oField->fl & FIELDFL_ARRAY)
        {
            $aIntegers = [];
            if (is_array($data))
                $aData = $data;
            else
                $aData = explode(',', $data);

            foreach ($aData as $v)
            {
                if (!isInteger($v))
                    throw new DrnException("Cannot index drill-down value for $fieldname: has FIELDFL_ARRAY but is not an array of integers");

                $aIntegers[] = (int)$v;
            }

            $rc = $aIntegers;
        }
        else
            $rc = (int)$data;

        return $rc;
    }

    /**
     *  Helper around Format::HighlightWords to highlight search terms before outputting HTML.
     *
     * @return void
     */
    public function highlightSearchTerms(TicketContext $oContext,         //!< in: TicketContext.
                                         HTMLChunk $oHTML)
    {
        if (    ($oContext->llHighlightWords)
             && ($this->oField->getSearchBoost())
           )
            # This field was searchable: then highlight words in it, if found.
            Format::HtmlHighlight($oHTML->html, $oContext->llHighlightWords);
    }


    /********************************************************************
     *
     *  Protected helpers
     *
     ********************************************************************/

    protected function prefixDescription(HTMLChunk $o)
    {
        $o->html = '<b>'.$this->getLabel()->html.'</b>: '.$o->html;
    }


    /********************************************************************
     *
     *  Static functions
     *
     ********************************************************************/

    /**
     *  Returns the field handler for the given TicketField instance, or throws if none was found.
     *
     *  This calls into active plugins to find field handlers there as well.
     *
     * @return FieldHandler|null
     */
    public static function Find($field_id,              //!< in: TicketField instance
                                $fRequired = TRUE)      //!< in: if TRUE, an exception is thrown if no field handler was found
    {
        if (    ($field_id <= FIELD_SYS_FIRST)
             && ($field_id >= FIELD_SYS_LAST)
           )
            return NULL;

        if (isset(self::$aFieldHandlers[$field_id]))
            return self::$aFieldHandlers[$field_id];

        $oHandler = NULL;

        # Try plugins first, which allows for overriding the default field handler.
        if ($oPlugin = TicketField::GetTypePlugin($field_id))
        {
            $oPlugin->createFieldHandler($field_id);
            $oHandler = getArrayItem(self::$aFieldHandlers, $field_id);
        }
        else
        {
            switch ($field_id)
            {
                case FIELD_TYPE:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new TicketTypeHandler;
                break;

                case FIELD_CREATED_DT:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new CreatedDateTimeHandler;
                break;

                case FIELD_LASTMOD_DT:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new LastModifiedDateTimeHandler;
                break;

                case FIELD_PRIORITY:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldh_prio.php";
                    $oHandler = new PriorityHandler;
                break;

                case FIELD_TITLE:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new TitleHandler;
                break;

                case FIELD_KEYWORDS:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldh_keywords.php";
                    $oHandler = new KeywordsHandler;
                break;

                case FIELD_DESCRIPTION:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new DescriptionHandler;
                break;

                case FIELD_UIDASSIGN:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldh_assignee.php";
                    $oHandler = new AssigneeHandler;
                break;

                case FIELD_COMMENT:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new CommentHandler;
                break;

                case FIELD_COMMENT_UPDATED:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new UpdatedCommentHandler;
                break;

                case FIELD_COMMENT_DELETED:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new DeletedCommentHandler;
                break;

                case FIELD_ATTACHMENT:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new AttachmentHandler;
                break;

                case FIELD_PROJECT:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldh_cat.php";
                    $oHandler = new ProjectHandler;
                break;

                case FIELD_TICKET_IMPORTED:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new TicketImportedHandler;
                break;

                case FIELD_PARENTS:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldh_parents.php";
                    $oHandler = new ParentsHandler;
                break;

                case FIELD_CHILDREN:
                    $oHandler = new ChildrenHandler;
                break;

                case FIELD_STATUS:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldh_status.php";
                    $oHandler = new StatusHandler;
                break;

                case FIELD_IMPORTEDFROM:
                case FIELD_IMPORTEDFROM_PERSONID:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new ImportedFromHandler($field_id);
                break;

                case FIELD_ATTACHMENT_RENAMED:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new AttachmentRenamedHandler;
                break;

                case FIELD_ATTACHMENT_DELETED:
                    require_once INCLUDE_PATH_PREFIX."/core/class_fieldhandlers2.php";
                    $oHandler = new AttachmentDeletedHandler;
                break;
            }
        }

        if (    (!$oHandler)
             && ($fRequired)
           )
            throw new DrnException("Missing field handler for field ID $field_id");

        return $oHandler;
    }
}
