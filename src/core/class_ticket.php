<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Exceptions
 *
 ********************************************************************/

class InvalidTicketIDException extends DrnException
{
    public function __construct($id)
    {
        parent::__construct(L('{{L//%ID% is not a valid numeric ticket ID}}', array( '%ID%' => $id)));
    }
}


/**
 * @page data_serialization Ticket field data serialization and storage
 *
 *  As described in \ref intro_ticket_types, a ticket type prescribes a list of fields for
 *  tickets of its type, and every ticket contains values for those fields which are pulled
 *  from multiple tables determined at runtime.
 *
 *  Most abstractly, each such field value can exist in different forms at different times:
 *
 *  <ul>
 *
 *  <li> Persistently, in the database. For simple value types such as texts, integers,
 *      or ticket references, the value will be a single column in a single database
 *      row, e.g. in \ref ticket_texts or \ref ticket_ints.
 *
 *      Field data gets written to the database by \ref FieldHandler::writeToDatabase().
 *      The base FieldHandler implementation handles all values correctly for the
 *      standard tables if the field has the FIELDFL_STD_DATA_OLD_NEW flag set, which
 *      is the case for most fields. For arrays of the standard types, the Doreen
 *      also provides support vial the FIELDFL_ARRAY flag.
 *
 *      Field handlers can, however, define arbitrarily complex data and object formats;
 *      they are then required to suppor these formats themselves, however.</li>
 *
 *  <li> Temporarily, in PHP server memory, in a Ticket instance, e.g. when tickets
 *      are loaded and then populated via Ticket::PopulateMany(). In that case,
 *      the value is stored in each Ticket's $aFieldData array, with the integer field ID
 *      as the array key. Again, for simple value types, the field value will be an
 *      integer or string.
 *
 *      Field data gets loaded from the database into a ticket instance by
 *      \ref Ticket::PopulateMany(), which handles the standard cases itself.
 *      Only if a field has FIELDFL_CUSTOM_SERIALIZATION set for more complicated
 *      data formats, that function calls FieldHandler::makeFetchSql() for that field.</li>
 *
 *  <li> Field values can be queried from the back-end via a GET request, for example when the front-end loads
 *      search results in grid format via AJAX. \ref Ticket::toArray() calls \ref FieldHandler::serializeToArray()
 *      for every field, which must add to a PHP array, which then gets converted to a JSON string as the
 *      GET response.</li>
 *
 *  <li> It can be part of POST or PUT data that comes in through REST APIs, which then call
 *       \ref Ticket::createAnother() or \ref Ticket::update(). See below.
 *
 *  </ul>
 *
 *  When tickets are created,
 *
 *   1) \ref Ticket::createAnother() is called on a ticket template;
 *
 *   2) for every field with a field handler, that calls \ref FieldHandler::onCreateOrUpdate()
 *      with MODE_CREATE. Unless that is overridden, this calls
 *
 *   3) \ref FieldHandler::writeToDatabase() for every field, which makes the actual change in the database.
 *
 *  Updating tickets (MODE_EDIT) is similar, except that it is \ref Ticket::update() at the top, and
 *  \ref FieldHandler::onCreateOrUpdate() calls \ref FieldHandler::writeToDatabase()
 *  only if the new value of a field is actually different from the old.
 *
 *  For array fields (that have the FIELDFL_ARRAY bit set), the behavior is more
 *  complicated. In that case, the value fields are assumed to be strings with
 *  comma-separated array values (e.g. ticket IDs), and FieldHandler has special handling for
 *  those fields.
 *
 *
 *               Simple int / string      Int arrays              Complex data
 *
 *  POST/PUT     Simple int / string      int,int,int string      JSON
 *  (Ticket::createAnother(), Ticket::update(), FieldHandler::onCreateOrUpdate())
 *
 *  aFieldData   Simple int / string      int,int,int string      Single class instance (might contain array)
 *
 *  Database     ticket_ints/_texts       Rows in ticket_ints     To be implemented by class
 */

/**
 *  @page null_values NULL values in Doreen ticket fields
 *
 *  The handling of NULL values was not well defined in Doreen's early days and is still not entirely
 *  clear now. Additionally, Doreen's data management allows for several ways to express the notion of
 *  "value does not exist":
 *
 *   1. A data table (e.g. ticket_texts) has a data row for a ticket and field ID with a value of NULL.
 *
 *   2. A data table (e.g. ticket_texts) has NO data row for a ticket and field ID, even though the field
 *      is part of ticket data.
 *
 *  With arrays it's even more complicated, since there could be several NULL rows for a ticket and field ID.
 *
 *  It would be way too subtle to attach semantically different meanings to 1. and 2. above, so in Doreen
 *  both of them are treated as "value does not exist". If a field has FIELDFL_REQUIRED_IN_POST_PUT and
 *  either 1 or 2 is true, then the create or update fails.
 *
 *  \ref FieldHandler::InsertRow() currently uses approach no. 2 and inserts no data for that case, but I'm not currently
 *  sure whether that goes well with changelog rows. TODO test
 *
 *  For enumerations like categories, there's an additional complication when drilling down aggregation values,
 *  where the "NULL" value should be a valid selection to find tickets with a NULL value. As a result,
 *  drillable enumerations should specify an explicit "Unknown" or "Undefined" value like CAT_UNKNOWN
 *  with a non-NULL value. That makes the communication with the search engine a lot easier.
 */


/********************************************************************
 *
 *  Ticket class
 *
 ********************************************************************/

/**
 *  The Ticket class represents individual tickets and naturally has a complex interaction
 *  with many of the other Doreen classes.
 *  See \ref intro_ticket_types for an introduction how data is pulled together.
 *
 *  The main interfaces for dealing with tickets are:
 *
 *    * \ref Ticket::FindMany() and variants, which instantiate Ticket instances in memory
 *      from ticket data in the database. Note that to avoid hitting the database and
 *      server memory too hard, instantiating tickets is a two-stage process; see
 *      \ref Ticket::PopulateMany(). This allows us, for example, to have thousands of
 *      ticket instances in search results, but only load the full data for those few
 *      which are actually visible.
 *
 *    * \ref Ticket::CreateTemplate() creates a new ticket template.
 *
 *    * \ref Ticket::createAnother() gets called on a template ticket to create another
 *      ticket with the same type and ACL, but with actual data.
 *
 *    * \ref Ticket::update(), which updates a ticket both in memory and in the database.
 *
 *  Note that plugins can create subclasses of Ticket and instruct Doreen to return a
 *  subclass for a certain ticket type. See TicketType::getTicketClassName().
 */
class Ticket
{
    # Stage-1 data (always instantiated).
    public $id;
    public $template;           # if != NULL, this ticket is a template, and this is the template's name

    /** @var  TicketType */
    public $oType;              # instantiated TicketType object

    # Class icon returned by Ticket::getClassIcon(), to be overridden by subclasses.
    protected static $icon = 'file-text-o';     // Default used by "Wiki article".

    public $aid;
    public $created_from;               # template ID

    # Class-global array of objects that have been instantiated already, ordered by UID.
    /** @var Ticket[] $aAwakened */
    public static $aAwakened = [];

    # Stage-2 data: instantiated only on request.
    public $fStage2ListFetched = FALSE;
    public $fStage2DetailsFetched = FALSE;
    public $aFieldData;                  # An array of field_id => data rows (e.g. FIELD_TITLE => "title").
    public $aFieldDataRowIDs;
            /* A corresponding array of field_id => 'i' values (e.g. FIELD_TITLE => 123),
             * listing the primary indices of each data row in their respective tables.
             * As an example, if the "title" of a ticket is from ticket_texts with the following row:
                       i  | ticket_id | idProp |              value
                    ------+-----------+--------+---------------------------------
                     1123 |         3 |     -1 | Test title
             * then $aFieldData of the Ticket object for ticket #3 would have a [-1] => "Test title" pair and
             * and  $aFieldDataRowIDs would have a [-1] => 1123 pair.
             * Note that with FIELDFL_ARRAY, the rows IDs will be a comma-separated list of IDs.
             */
    public $aFieldDataWordIDs;          /* For fields with FIELDFL_WORDLIST only, aFieldData contains a comma-separated
                                           list of words, and aFieldDataRowIDs contains a comma-separated list of indices
                                           into (probably) ticket_ints. This third array, aFieldDataWordIDs, contains a
                                           matching comma-separated list of indices in the keyword_defs table. */

    public $score = NULL;               # Search score. Only set before an array of tickets is returned as a search result.
    public $aBinariesFound = NULL;      # Also for search results, the binary (attachment) IDs, if those are part of the search results, or NULL.

    public $cUsage = NULL;              # Templates only: NULL until GetTemplateUsage() has been called.

    public $fDeleted = FALSE;

    /** @var Changelog $oChangelog */
    public $oChangelog = NULL;

    const CREATEFL_NOCHANGELOG      = (1 <<  0);    # Ticket::createAnother() and Ticket::update(): do not write changelog entry.
    const CREATEFL_NOINDEX          = (1 <<  1);    # Ticket::createAnother: do not pass new ticket to search engine indexer.
    const CREATEFL_NOMAIL           = (1 <<  2);    # Ticket::createAnother() and Ticket::update(): do not send out ticket mail.
    const CREATEFL_IGNOREMISSING    = (1 <<  3);    # Ticket::update: do not complain if fields are missing.

    # Flags for $populate arguments everywhere
    const POPULATE_NONE = 0;
    const POPULATE_LIST = 1;
    const POPULATE_DETAILS = 2;


    /********************************************************************
     *
     *  Model: constructor
     *
     ********************************************************************/

    /**
     *  Hidden default constructor. Never use.
     */
    private function __construct()
    {
    }

    /**
     *  Creates a new ticket instance in memory, either of the Ticket class or a subclass specified by the
     *  ticket type.
     *
     *  This is a private helper method and must not be called unless you know what you're doing. Instead, use
     *  one of the following public interfaces to create new tickets:
     *
     *   -- Ticket::createAnother(), to be called on a template, to create another copy of the same type
     *      both in memory and in the database;
     *
     *   -- Ticket::CreateTemplate(), to create a new ticket template, both in memory and in the database;
     *
     *   -- Ticket::FindMany() to create ticket instances in memory from data in the database.
     *
     *  This is the only place in Doreen that actually creates ticket instances.
     *
     * @return Ticket
     */
    private static function CreateInstance($id,
                                           $template,
                                           TicketType $oType,
                                           $project_id,
                                           $aid,
                                           $owner_uid,
                                           $created_dt,
                                           $lastmod_uid,
                                           $lastmod_dt,
                                           $created_from)
    {
        $class = 'Doreen\\'.$oType->getTicketClassName();
        /** @var static $o */
        $o = new $class();

        $o->id = (int)$id;
        $o->template = $template;
        $o->oType = $oType;
        $o->aFieldData[FIELD_PROJECT] = $project_id;
        $o->aid = $aid;
        $o->aFieldData[FIELD_CREATED_UID] = $owner_uid;
        $o->aFieldData[FIELD_CREATED_DT] = $created_dt;
        $o->aFieldData[FIELD_LASTMOD_UID] = $lastmod_uid;
        $o->aFieldData[FIELD_LASTMOD_DT] = $lastmod_dt;
        $o->created_from = $created_from;

        self::$aAwakened[$id] = $o;

        return $o;
    }

    /**
     *  Returns the Ticket (or subclass) instance matching the given ID.
     *
     *  If an object has already been instantiated for that ID, it is returned.
     *  Otherwise a new one is created with the given data.
     */
    private static function MakeAwakeOnce($id,
                                          $template,
                                          TicketType $oType,
                                          $project_id,
                                          $aid,
                                          $owner_uid,
                                          $created_dt,
                                          $lastmod_uid,
                                          $lastmod_dt,
                                          $created_from)
    {
        if (isset(self::$aAwakened[$id]))
            return self::$aAwakened[$id];

        return Ticket::CreateInstance($id, $template, $oType, $project_id, $aid, $owner_uid, $created_dt, $lastmod_uid, $lastmod_dt, $created_from);
    }


    /********************************************************************
     *
     *  Model: public instance functions
     *
     ********************************************************************/

    /**
     *  Returns TRUE if $this is a ticket template.
     */
    public function isTemplate()
    {
        return !!($this->template);
    }

    /**
     *  Returns the ticket ID of the template that this ticket was created from,
     *  or NULL of none could be found. Always returns NULL for templates.
     *
     * @return int | NULL
     */
    public function findTemplate()
    {
        if ($this->isTemplate())
            return NULL;

        # If this is a new ticket (we added created_from to the tickets table with database version 53),
        # then return the member.
        if ($this->created_from)
            # Value can be 0 from a previous "tried but not found" attempt. Return NULL in that case too.
            return $this->created_from;

        # Old non-template ticket: then try to find the template with a matching type & ACL id in the
        # database and assume it's that.
        $idFound = 0;
        if (    ($res = Database::DefaultExec(<<<EOD
SELECT i
FROM tickets
WHERE type_id = $1       AND aid = $2 AND template IS NOT NULL;
EOD
            , [ $this->oType->id,  $this->aid ] ))
             && ($row = Database::GetDefault()->fetchNextRow($res))
           )
        {
            $idFound = $row['i'];

            $this->updateCreatedFromTemplate($idFound,
                                             $this->aid);       # unchanged
        }

        return $idFound;
    }

    /**
     *  Changes the access control list of this ticket to that of the given template, and also
     *  writes back that template's ID to the database row of this ticket.
     */
    public function setTemplateRecursively(Ticket $oTemplate)
    {
        if (!$oTemplate->isTemplate())
            throw new DrnException("Ticket #$oTemplate is not a template");

        if (!($idTemplateOld = $this->findTemplate()))
            throw new DrnException("Ticket #$this->id has no existing template");

        if ($oTemplate->id != $idTemplateOld)
        {
            $idTypeTicket = $this->oType->id;
            $idTypeTemplate = $oTemplate->oType->id;
            if ($idTypeTicket != $idTypeTemplate)
                throw new DrnException("The ticket type of template #$oTemplate->id ($idTypeTemplate) is not the same as the type of the ticket #$this->id ($idTypeTicket)");

            if (!($oACL = DrnACL::Find($oTemplate->aid)))
                throw new DrnException("Invalid ACL ID $oTemplate->aid in template $oTemplate->id");

            Database::GetDefault()->beginTransaction();

            $this->updateCreatedFromTemplate($oTemplate->id,
                                             $oTemplate->aid);

            $oUser = LoginSession::IsUserLoggedIn();
            $this->addChangelogEntry(FIELD_TEMPLATE_UNDER_TICKET_CHANGED,
                                     $oUser ? $oUser->uid : NULL,
                                     Globals::Now(),
                                     $idTemplateOld,
                                     $oTemplate->id);

            Database::GetDefault()->commit();
        }

        // Now go for the children.
        $this->populate();

        if ($strChildren = $this->aFieldData[FIELD_CHILDREN] ?? NULL)
            foreach (explode(",", $strChildren) as $idChild)
            {
                if (!($oChild = Ticket::FindOne($idChild)))
                    throw new DrnException("Cannot find child ticket #$idChild of parent $this->id");

                $oChild->setTemplateRecursively($oTemplate);
            }
    }

    /**
     *  Writes back to the database which template this ticket was created from.
     */
    protected function updateCreatedFromTemplate(int $idTemplate,
                                                 int $aid)
    {
        Database::DefaultExec(<<<SQL
UPDATE tickets SET created_from = $1,     aid = $2 WHERE i = $3
SQL
                              , [ $idTemplate,  $aid,        $this->id ] );

        $this->created_from = $idTemplate;
        $this->aid = $aid;

        # Reindex the ticket in the search engine, if needed.
        if ($oSearch = Plugins::GetSearchInstance())
            $oSearch->onTicketUpdated($this);
    }

    /**
     *  Returns the ACCESS_* bits of the given user with respect to $this ticket. This takes
     *  the ticket's ACL and calls \ref DrnACL::getUserAccess() on it simply. Returns 0
     *  if the user has no access at all.
     */
    public function getUserAccess(User $oUser = NULL)
        : int
    {
        if ($oACL = DrnACL::Find($this->aid))
            return $oACL->getUserAccess($oUser);
        return 0;
    }

    /**
     *  Returns true if the given user can see this ticket.
     *  This checks the access flags, but subclasses may override this
     *  if visibility should not be permitted regardless of flags.
     */
    public function canRead(User $oUser)
        : bool
    {
        $flAccess = $this->getUserAccess($oUser);
        return !!($flAccess & ACCESS_READ);
    }

    /**
     *  Gets called by the ticket details form to determine whether an "edit" link should be
     *  shown for this ticket. This checks the access flags, but subclasses may override this
     *  if editing is not to be permitted regardless of flags.
     */
    public function canUpdate(User $oUser)
        : bool
    {
        $flAccess = $this->getUserAccess($oUser);
        return !!($flAccess & ACCESS_UPDATE);
    }

    /**
     *  Gets called only on template tickets to determine whether the ticket should be shown
     *  in the "New" menu. This checks the access flags for the given user, but subclasses may
     *  override this if a template should be hidden in the "Mew" menu.
     *
     *  Note that this is only for visibility. The actual creation code does not call this,
     *  so if a subclass returns FALSE, the template can still be used via the API.
     */
    public function canCreate(User $oUser)
        : bool
    {
        $flAccess = $this->getUserAccess($oUser);
        return !!($flAccess & ACCESS_CREATE);
    }

    /**
     *  Returns TRUE if the given user may delete this ticket. ACCESS_DELETE should be granted
     *  to administrators only.
     */
    public function canDelete(User $oUser)
        : bool
    {
        $flAccess = $this->getUserAccess($oUser);
        return !!($flAccess & ACCESS_DELETE);
    }

    /**
     *  Gets called by the ticket details form to determine whether the "add comment" form
     *  should be shown to the given user.
     *
     *  By default anyone with CREATE access can comment and upload files as well. Subclasses
     *  can override this.
     */
    public function canComment(User $oUser)
        : bool
    {
        $flAccess = $this->getUserAccess($oUser);
        return !!($flAccess & ACCESS_CREATE);
    }

    /**
     *  Gets called by the ticket details form to determine whether the "add file" form
     *  should be shown to the given user.
     *
     *  By default anyone with CREATE access can comment and upload files as well. Subclasses
     *  can override this.
     */
    public function canUploadFiles(User $oUser)
        : bool
    {
        $flAccess = $this->getUserAccess($oUser);
        return !!($flAccess & ACCESS_CREATE);
    }

    /**
     *  Returns TRUE if the given user should be allowed to change the template of the
     *  current ticket. By default, this is for admins only; subclasses can override this.
     */
    public function canChangeTemplate(User $oUser)
        : bool
    {
        return $oUser && $oUser->isAdmin();
    }

    /**
     *  Gets called for a Details view of this ticket when FIELD_STATUS is about to be produced.
     *  This can be overridden by subclasses to suppress the "Status" field for details views
     *  without having to tinker with ticket fields.
     */
    public function showStatusInDetails()
    {
        return TRUE;
    }

    /**
     *  Returns the raw permissions from the member ACL as an array of gid => ACCESS_* pairs.
     */
    public function getPermissions()
    {
        if ($oACL = DrnACL::Find($this->aid))
            return $oACL->aPermissions;

        return NULL;
    }

    /**
     *  Calls DrnACL::describe() on the member ACL and formats it into an unordered HTML list with the given indentation.
     */
    public function describeACL($indent)
    {
        if ($oACL = DrnACL::Find($this->aid))
        {
            $spc = str_repeat(' ', $indent);
            if ($aStrings = $oACL->describe())
                return "$spc<ul>\n$spc  <li>".implode("</li>\n$spc  <li>", $aStrings)."</li>\n$spc</ul>\n";
        }

        return NULL;
    }

    /**
     *  Calls ACL::getUsersWithAccess() on the member ACL.
     */
    public function getUsersWithAccess($flRequired = ACCESS_READ)         //!< in: ORed ACCESS_* flags
    {
        if ($oACL = DrnACL::Find($this->aid))
            return $oACL->getUsersWithAccess($flRequired);

        return NULL;
    }

    /**
     *  Shortcut to calling PopulateMany() for one ticket, which is also more efficient.
     */
    public function populate($fDetails = FALSE)
    {
        if (    ($this->fStage2DetailsFetched)
             || (    (!$fDetails)
                  && ($this->fStage2ListFetched)
                )
           )
            ;
        else
            Ticket::PopulateMany( [ $this->id => $this ],
                                 ($fDetails) ? self::POPULATE_DETAILS : self::POPULATE_LIST);
    }

    /**
     *  Returns the value of the given field from the instance data, populating the ticket data
     *  if needed, or NULL if the ticket has no data or the field is not part of the ticket type.
     */
    public function getFieldValue($field_id)
    {
        $pop = Ticket::POPULATE_NONE;
        # If the field is part of the ticket type's list fields, then populating the lsit fields is enough.
        if (isset($this->oType->aListFields[$field_id]))
            $pop = Ticket::POPULATE_LIST;
        else if (isset($this->oType->aDetailsFields[$field_id]))
            $pop = Ticket::POPULATE_DETAILS;

        if ($pop != Ticket::POPULATE_NONE)
            $this->populate(($pop == Ticket::POPULATE_DETAILS));

        if (isset($this->aFieldData[$field_id]))
            return $this->aFieldData[$field_id];

        return NULL;
    }

    /**
     *  Returns a ticket's title as a plain-text string. Never returns
     *  NULL. Caller must call toHTML() on the result before displaying it.
     *
     *  This gets called by lots of code whenever a ticket title is needed,
     *  most importantly by FieldHandler::serializeToArray() to return the
     *  ticket title in JSON. This also gets called from TitleHandler::getValue()
     *  and thus affects the title display in ticket lists and details.
     *
     *  The default implementation returns the FIELD_TITLE value from
     *  the ticket data, but Ticket subclasses can override this, for
     *  example if a dynamic display is preferable.
     */
    public function getTitle()
        : string
    {
        return $this->getFieldValue(FIELD_TITLE) ?? '';
    }

    /**
     *  Returns the template name of this ticket, which is stored in the special 'template'
     *  column of the \ref tickets table. This is NULL if this ticket is not a template.
     *
     *  This is a special method so that plugins can override this via Ticket subclasses
     *  for special functionality.
     */
    public function getTemplateName()
    {
        return $this->template;
    }

    /**
     *  Convenience function to get a ticket's integer status. Returns NULL if the ticket has none.
     */
    public function getStatus()
    {
        return $this->getFieldValue(FIELD_STATUS);
    }

    /**
     *  Returns the User instance of the Doreen user who created this ticket.
     *
     * @return User | null
     */
    public function getCreatedBy()
    {
        if ($uid = getArrayItem($this->aFieldData, FIELD_CREATED_UID))
            return User::Find($uid);

        return NULL;
    }

    /**
     *  Returns the "created" date/time, in UTC, of the ticket.
     */
    public function getCreatedDateTime()
    {
        return getArrayItem($this->aFieldData, FIELD_CREATED_DT);
    }

    /**
     *  Returns the User instance of the Doreen user who last modified this ticket.
     *
     * @return User | null
     */
    public function getLastModifiedBy()
    {
        if ($uid = getArrayItem($this->aFieldData, FIELD_LASTMOD_UID))
            return User::Find($uid);

        return NULL;
    }

    /**
     *  Returns the "last modified" date/time, in UTC, of the ticket.
     */
    public function getLastModifiedDateTime()
    {
        return getArrayItem($this->aFieldData, FIELD_LASTMOD_DT);
    }

    /**
     *  Convenience function which returns the first parent as a Ticket object, if the
     *  ticket has a FIELDID_PARENTS field with valid data, or NULL otherwise.
     *
     *  This calls Ticket::FindOne() in turn and does NOT check access permissions.
     */
    public function getFirstParent($field_id = FIELD_PARENTS,
                                   $populate = self::POPULATE_NONE)
    {
        if ($parents = $this->getFieldValue($field_id))
        {
            if ($aParents = explode(',', $parents))
            {
                $firstParent = array_shift($aParents);
                return Ticket::FindOne($firstParent,
                                       $populate);
            }
        }

        return NULL;
    }

    /**
     *  Returns TRUE if this ticket is configured as the title page ticket to
     *  be displayed by DisplayWikiTicketBlurb.
     */
    public function isTitlePageTicket()
        : bool
    {
        if (    ($idTitlePageTicket = Blurb::GetTitlePageWikiTicket())
             && ($this->id == $idTitlePageTicket)
           )
            return TRUE;

        return FALSE;
    }

    /**
     *  Returns the import ID of this ticket, or throws. This assumes that
     *  FIELD_IMPORTEDFROM is part of the ticket type's details fields.
     */
    public function getImportIDOrThrow()
    {
        if (!($idImport = $this->getFieldValue(FIELD_IMPORTEDFROM)))
            throw new DrnException("Ticket {$this->id} has no IMPORTEDFROM field data");

        return $idImport;
    }

    const JSON_LEVEL_MINIMAL          = 0x00;
    const JSON_LEVEL_PARENTS_CHILDREN = 0x01;
    const JSON_LEVEL_DETAILS          = 0x80;
    const JSON_LEVEL_ALL              = 0xFF;

    /**
     *  Returns ticket information as an array, which the caller can convert to JSON to return
     *  from an API.
     *
     *  The front-end provides the ITicketCore interface for the result, which
     *  contains the ticket field data from the core. That structure cannot know about
     *  additional fields provited by plugin field handlers.
     *
     *  This is used almost every time Doreen produces ticket data for returning it with a
     *  REST API, particularly via \ref Ticket::GetManyAsArray(). This base implementation
     *  goes through all of the ticket fields and calls \ref FieldHandler::serializeToArray()
     *  on them. To customize the JSON output, either override that method in your field
     *  handler, or override this method in a custom Ticket subclass.
     *
     *  $fl can be a combination of self::JSON_LEVEL_* constants. By default, this will load
     *  all ticket details, which can be expensive. If you only want the ticket title, for
     *  example, you can use JSON_LEVEL_MINIMAL, and parents and children will be excluded,
     *  which is the most expensive bit.
     */
    public function toArray($fl = self::JSON_LEVEL_ALL,
                            &$paFetchSubtickets = NULL)
        : array
    {
        Debug::FuncEnter(Debug::FL_TICKETJSON,
                         __METHOD__."(#$this->id, fl=$fl)");

        $uid = $longName = NULL;
        if ($user = $this->getCreatedBy())
        {
            $uid = $user->uid;
            $longName = $user->longname;
        }

        $dtCreated = $this->getCreatedDateTime();
        $oTS = Timestamp::CreateFromUTCDateTimeString($dtCreated);

        $oIcon = $this->getIcon();
        $aReturn = [ 'ticket_id' => (int)$this->id,
                     'type_id' => (int)$this->oType->id,
                     'type' => $this->oType->getName(),
                     'icon' => $oIcon ? $oIcon->html : '',
                     'href' => $this->makeUrlTail(),
                     'createdUTC' => $dtCreated,
                     'createdDate' => $oTS->toDateString(),
                     'created_formatted' => Format::Timestamp2($oTS, 2),# HTML with fly-over
                     'createdByUID' => $uid,
                     'createdByUserName' => $longName,
                     'htmlLongerTitle' => $this->makeLongerHtmlTitle()->html,
                     'nlsFlyOver' => $this->makeGoToFlyover(),
                   ];

        if ($oUser = LoginSession::IsUserLoggedIn())
            if ($this->canUpdate($oUser))
            {
                $aReturn['hrefEdit'] = $this->makeUrlTail(NULL, TRUE);
                $aReturn['nlsFlyOverEdit'] = $this->makeGoToFlyover(TRUE);
            }

        $fDetails = ($fl & self::JSON_LEVEL_DETAILS) ? TRUE : FALSE;

        $this->populate($fDetails); # fetch data for ticket LIST (we only need the title)

        $oContext = new TicketContext(LoginSession::$ouserCurrent,
                                      $this,
                                      MODE_JSON);

        $aFields = $this->oType->getVisibleFields(($fDetails) ? TicketType::FL_FOR_DETAILS : 0);
        foreach ($aFields as $idField => $oField)
            // Skip comments and attachments here, otherwise we'll just have null values in the result.
            if (!($oField->fl & FIELDFL_CHANGELOGONLY))
            {
                if ($oHandler = FieldHandler::Find($idField, FALSE))
                {
                    Debug::FuncEnter(Debug::FL_TICKETJSON, "addToGetJson() for field $oField->name");
                    $oHandler->serializeToArray($oContext,
                                                $aReturn,
                                                $fl,
                                                $paFetchSubtickets);
                    Debug::FuncLeave();
                }
            }

        Debug::FuncLeave();

        return $aReturn;
    }

    /**
     *  Like \ref toArray() but for template tickets, which require different data from
     *  from the GET /all-templates REST API.
     *
     *  Note: For 'usage' to be correct, caller needs to call GetTemplateUsage() for an
     *  array of results beforehand, which is more efficiently queried for many results at once.
     */
    public function toArrayForTemplate()
        : array
    {
        $oACL = DrnACL::Find($this->aid);

        $aPermissions2 = (isset($oACL->aPermissions)) ? DrnACL::FlagsToLetters($oACL->aPermissions) : NULL;

        $cUsage = ($this->cUsage)
            ? $this->cUsage - 1      # substract one for the template itself
            : NULL;

        return [ 'ticket_id' => (int)$this->id,
                 'template' => $this->getTemplateName(),
                 'type_id' => (int)$this->oType->id,
                 'access' => $aPermissions2,
                 'access_formatted' => ($aPermissions2 ? DrnACL::MakeDescriptiveName($oACL->aPermissions) : "NULL"),
                 'usage' => $cUsage,
                 'usage_formatted' => Format::Number($cUsage),
               ];
    }

    /**
     *  Called from \ref ViewTicketsGrid::FormatOne() to format one ticket for the grid
     *  view. This is in a Ticket method so that subclasses could override it.
     *
     *  Tickets can either add to the HTMLChunk directly or add a separate HTMLChunk to
     *  the $llChunks list.
     *
     * @return void
     */
    public function getHtmlForGrid(TicketContext $oContextGrid,
                                   array $aFields,
                                   HTMLChunk $oHtml,                //!< in/out: HTML to append to
                                   array &$llChunks)                //!< in/out: list of HTMLChunks
    {
        // Always put status on top.
        if (isset($aFields[FIELD_STATUS]))
        {
            $fh = FieldHandler::Find(FIELD_STATUS);
            $oHtml->openDiv(NULL, 'pull-right');
            $oHtml->appendChunk($fh->formatValueHTML($oContextGrid, $fh->getValue($oContextGrid)));
            $oHtml->close();
        }

        foreach ($aFields as $idField => $oField)
            // Skip comments and attachments here, otherwise we'll just have null values in the result.
            if (    ($idField != FIELD_TITLE)
                 && ($idField != FIELD_STATUS)      // already handled above
                 && (!($oField->fl & FIELDFL_CHANGELOGONLY))
               )
            {
                if ($oHandler = FieldHandler::Find($idField, FALSE))
                {
                    $o = $oHandler->formatValueHTML($oContextGrid, $oHandler->getValue($oContextGrid));
                    if ($o->html)
                        $llChunks[] = $o;
                }
            }
    }

    /**
     *  Special API that gets called only by the GET /templates REST API for each
     *  template that the current user has ACCESS_CREATE permission for.
     *
     *  $idCurrentTicket is the value of the 'current-ticket' API parameter, if the
     *  details of a ticket are currently being displayed, or NULL. This might be
     *  useful for subclass overrides by plugins.
     */
    public function getTemplateJson($idCurrentTicket)
    {
        $title = $this->getTemplateName();
        $oIcon = $this->getIconPlusChunk(HTMLChunk::FromString($title));
        return [ 'id' => $this->id,
                 'title' => $title,
                 'htmlTitle' => $oIcon->html,
                 'href' => "/newticket/$this->id"
               ];
    }

    /**
     *  Produces a flat list of values for JSON encoding, calling getJSON() for every
     *  ticket ID on the list. $fl is passed to that method; see remarks there.
     *
     *  This will produce an array like the following:
     *
     *      [0] => { 'ticket_id' => int, 'type_id' => int, ... }
     *      [1] => { 'ticket_id' => int, 'type_id' => int, ... }
     *
     *  If $fl has the JSON_LEVEL_PARENTS_CHILDREN bit sets, this may fetch child and
     *  parent information through field handlers.
     *
     *  Returns NULL if the list is empty. Will throw on errors, e.g. invalid ticket IDs.
     *
     *  This gets called from \ref MakeApiResult(), which is used by many places that
     *  return ticket results. Most importantly, that gets called from
     *  \ref ApiTicket::GetMany(), which implements the GET /tickets REST API, which
     *  is used for search results in the grid view. In that case, the $format parameter
     *  is 'grid'. As a result, this function here is central to the formatting of the search results grid.
     *
     *  @return null|array
     */
    public static function GetManyAsArray($llReturnTicketIDs,
                                          $fl = self::JSON_LEVEL_ALL,       //!< in: one of Ticket::JSON_LEVEL_MINIMAL, Ticket::JSON_LEVEL_PARENTS_CHILDREN, Ticket::JSON_LEVEL_DETAILS, Ticket::JSON_LEVEL_ALL
                                          $format = NULL,                   //!< in: NULL or 'grid' for added HTML formatting
                                          $llHighlights = [])               //!< in: array of words to highlight, only used in grid formatting
    {
        Debug::FuncEnter(Debug::FL_TICKETJSON, __METHOD__);

        /* This is a public API which originally resulted in the following subsequent call chain:

             -- Ticket::GetManyAsArray(llTickets)
                foreach (llTickets)
                 -- oTicket->getJSON()
                    For each field handler:
                     -- oFieldHandler->serializeToArray()
                        If field handler handles child tickets: Recursive call getJSONForMany()
                     -- oFieldHandler->serializeToArray()
                 -- oTicket->getJSON()

            This can cause hundreds of SQL roundtrips for child tickets and also potentially endless
            recursion cycles. To avoid this, both Ticket::toArray() and FieldHandler::serializeToArray()
            have an extra array reference through which they can ask this method to collect additional
            sub-ticket data. See FieldHandler::serializeToArray() for the format.
         */
        $aReturn = [];
        if (    (count($llReturnTicketIDs))
             && ($fr = Ticket::FindManyByID($llReturnTicketIDs,
                                            Ticket::POPULATE_DETAILS))
           )
        {
            $aFetchSubtickets = [];
            foreach ($fr->aTickets as $id => $oTicket)
            {
                $aThis = $oTicket->toArray($fl,
                                           $aFetchSubtickets);

                /*
                 *  Grid formatting
                 */
                if ($format == 'grid')
                {
                    Debug::FuncEnter(Debug::FL_TICKETJSON, "making grid results for #$id JSON");

                    $oHtmlTicketThis = ViewTicketsGrid::FormatOne($oTicket);
                    if ($oHtmlTicketThis->html)
                    {
                        if (count($llHighlights))
                            Format::HtmlHighlight($oHtmlTicketThis->html, $llHighlights);
                        $aThis["format_$format"] = $oHtmlTicketThis->html;
                    }

                    if (count($llHighlights))
                    {
                        $oTitle = $aThis['htmlLongerTitle'];
                        Format::HtmlHighlight($oTitle, $llHighlights);
                        $aThis['htmlLongerTitle'] = $oTitle;
                    }

                    Debug::FuncLeave();
                }

                $aReturn[] = $aThis;
            }

            if (count($aFetchSubtickets))
            {
//                Debug::Log(0, "aFetchSubtickets:".print_r($aFetchSubtickets, TRUE));

                /* Loop 1: collect ticket IDs from the array.
                   Loop 2: distribute collected data to the main JSON to be returned. */
                $aJSONSubtickets = [];
                foreach ( [ 1, 2 ] as $loop)
                {
                    $llFetchSubtickets = [];
                    foreach ($aFetchSubtickets as $idParentTicket => $aStringKeysAndSubtickets)
                        foreach ($aStringKeysAndSubtickets as $subticketKey => $llSubtickets)
                            foreach ($llSubtickets as $idSubticket)
                                if ($loop === 1)
                                    $llFetchSubtickets[] = $idSubticket;
                                else
                                {
                                    foreach ($aReturn as $index => $aTicketData)
                                        if (    ($idTicketReturn = getArrayItem($aTicketData, 'ticket_id'))
                                             && ($idTicketReturn == $idParentTicket)
                                           )
                                        {
                                            $aSubticketData = $aJSONSubtickets[$idSubticket];
                                            if (!isset($aReturn[$index][$subticketKey]))
                                                $aReturn[$index][$subticketKey] = [ $aSubticketData ];
                                            else
                                                $aReturn[$index][$subticketKey][] = $aSubticketData;
                                            break;
                                        }
                                }

                    if ($loop === 1)
                    {
                        if ($llJSONSubtickets = self::GetManyAsArray($llFetchSubtickets,
                                                                     # Important: Only get minimal data, or else we'll recurse infinitely.
                                                                     self::JSON_LEVEL_MINIMAL))
                            # This is a flat list for JSON, sort it into an array by ticket ID.
                            foreach ($llJSONSubtickets as $aSubticketData)
                                if ($idSubticket = getArrayItem($aSubticketData, 'ticket_id'))
                                    $aJSONSubtickets[$idSubticket] = $aSubticketData;
//                        Debug::Log(Debug::FL_TICKETJSON, "aReturn old: ".print_r($aReturn, TRUE));
//                        Debug::Log(Debug::FL_TICKETJSON, "aJSONSubtickets: ".print_r($aJSONSubtickets, TRUE));
                    }
//                    else
//                        Debug::Log(Debug::FL_TICKETJSON, "aReturn new: ".print_r($aReturn, TRUE));
                }
            }
        }

        Debug::FuncLeave();

        return count($aReturn) ? $aReturn : NULL;
    }

    /**
     *  Produces an array to be converted into JSON for a ticket API.
     *
     *  The front-end provides the GetTicketsApiResult interface for the response.
     *
     *  The following fields are always set:
     *
     *   -- nlsFoundMessage: a clear text message with either $nlsFoundMessage or
     *      $nlsNotFoundMessage;
     *
     *   -- cTotal: an integer copy of $cTotal.
     *
     *  Only if cTotal > 0, the following are also set:
     *
     *   -- results: array of ticket results from \ref Ticket::GetManyAsArray()
     *
     *   -- cTotalFormatted: NLS-formatted string of cTotal
     *
     *   -- page: a copy of $page
     *
     *   -- cPages: the total no. of pages (at least 1)
     *
     *  Additionally, the following key is always present, but an empty string unless cPages > 1:
     *
     *   -- htmlPagination
     *
     *  @return array
     */
    public static function MakeApiResult($cTotal,               //!< in: total no. of tickets on all pages
                                         $page,                 //!< in: current page
                                         $cPerPage,             //!< in: items per page; if 0, then all items are returned
                                         $aTicketsChunk,        //!< in: chunk of tickets for the page (keys must be ticket IDs)
                                         $flJSON,               //!< in: one of Ticket::JSON_LEVEL_MINIMAL, Ticket::JSON_LEVEL_PARENTS_CHILDREN, Ticket::JSON_LEVEL_DETAILS, Ticket::JSON_LEVEL_ALL
                                         $nlsFoundMessage,      //!< in: what to put in nlsFoundMessage
                                         $nlsNotFoundMessage,   //!< in: L string for when nothing was found
                                         $format = NULL,        //!< in: NULL or 'grid' (passed to GetJSONForMany)
                                         $baseCommand = '',     //!< in: base for generated URLs (e.g. 'tickets' or 'board')
                                         $aParticles = [],      //!< in: array of URL key/value pairs to build sub-URLs correctly
                                         $llHighlights = [])    //!< in: array of words to highlight
    {
        Debug::FuncEnter(Debug::FL_TICKETFIND, __METHOD__);
        $htmlPagination = NULL;

        if ($aTicketsChunk && count($aTicketsChunk))
        {
            $aReturn['results'] = Ticket::GetManyAsArray(array_keys($aTicketsChunk),
                                                         $flJSON,
                                                         $format,
                                                         $llHighlights);

            $cTotalFormatted = Format::Number($cTotal);
            $aReturn['cTotalFormatted'] = $cTotalFormatted;
            $aReturn['nlsFoundMessage'] = $nlsFoundMessage;
            $aReturn['llHighlights'] = array_values($llHighlights);
            $aReturn['page'] = (int)$page;

            if ($cPerPage)
                $cPages = (int)floor(($cTotal + $cPerPage - 1) / $cPerPage);
            else    // unlimited
                $cPages = 1;
            $aReturn['cPages'] = $cPages;

            if ($cPages > 1)
            {
                $oHTML = new HTMLChunk(0);
                $oHTML->addPagination($baseCommand, $aParticles, $page, $cPages);
                $htmlPagination = $oHTML->html;
            }
        }
        else
            $aReturn['nlsFoundMessage'] = $nlsNotFoundMessage;

        $aReturn['cTotal'] = (int)$cTotal;
        $aReturn['cPerPage'] = (int)$cPerPage;
        $aReturn['htmlPagination'] = $htmlPagination;

        Debug::FuncLeave();

        return $aReturn;
    }

    /**
     *  Gets the Changelog object for this ticket instance. Loads all details it on the first call.
     *
     * @return Changelog
     */
    public function loadChangelog()
    {
        if (!$this->oChangelog)
        {
            # Load the changelog for this ticket!
            $this->oChangelog = new Changelog($this->id);
            $this->oChangelog->loadDetails(NULL,   # all rows
                                           TRUE);  # load text fields
        }

        return $this->oChangelog;
    }

    /**
     *  Returns the language for all fields of this ticket that have FIELDFL_TEXT_LANGUAGE set. This
     *  causes search plugins to use an indexer for a specific language.
     */
    public function getLanguage()
    {
        return 'en_US';
    }

    /**
     *  Creates a new ticket with the same TicketType and ACL as $this, which must be a template ticket,
     *  but with actual ticket data for the ticket fields given in $aVariableData.
     *
     *  What kind of data is required for the ticket depends on the template's ticket type, which
     *  has a list of ticket field IDs. Many of those fields will have a "required" bit set, and
     *  if the field is missing in $aVariableData, creation will fail with an exception.
     *
     *  As with \ref Ticket::update(), the given array uses field NAMES (not IDs) as the array keys, which
     *  makes it easier to just pass PUT or POST data to this function and view it readably in the browser
     *  debugger.
     *
     *  Returns a new instance of the Ticket class (or a specialized subclass thereof, depending on the
     *  ticket type).
     *
     *  This does NOT check access permissions. The caller must first verify that the calling user has
     *  ACCESS_CREATE permission on the ticket template.
     *
     *  Note: $created_dt, $lastmod_dt and $forceTicketId should be NULL unless this is being called as
     *  part of an import of an entire legacy database.
     *
     * @return self
     */
    public function createAnother($ouserCreating,           //!< in: user who should be listed as the ticket creator
                                  $ouserLastModified,       //!< in: user who last modified the ticket (for import)
                                  $aVariableData,           //!< in: array of field NAMES (not IDs) => value pairs (e.g. 'title' => "new title")
                                  $fUseParentType = FALSE,  //!< in: whether to use the parent type of the template's ticket type (e.g. in order to create a milestone)
                                  $fl = 0,                  //!< in: CREATEFL_NOCHANGELOG, CREATEFL_NOINDEX, CREATEFL_NOMAIL
                                  $created_dt = NULL,       //!< in: arbitrary creation datetime (for import)
                                  $lastmod_dt = NULL,       //!< in: arbitrary last modification datetime (for import)
                                  $forceTicketId = NULL)    //!< in: force this ticket ID (for import)
        : Ticket
    {
        Debug::FuncEnter(Debug::FL_TICKETUPDATE, __METHOD__, ' type of template: '.$this->oType->getName());

        $oType = $this->oType;
        if ($fUseParentType)
            if (!($oType = TicketType::Find($oType->parent_type_id)))
                throw new DrnException("Parent type requested but template has no parent type");

        $oContext = new TicketContext($ouserLastModified,
                                      $this,        # ticket == template
                                      MODE_CREATE);
        if ($created_dt)
            $oContext->dtNow = $created_dt;
        else
            $created_dt = $oContext->dtNow;
        if (!$lastmod_dt)
            $lastmod_dt = Globals::Now();
        $oContext->flAccess = $this->getUserAccess($ouserCreating);
        $oContext->aVariableData = $aVariableData;

        # Get the project ID from the template.
        if (!(array_key_exists(FIELD_PROJECT, $this->aFieldData)))
            throw new DrnException(L('{{L//Ticket template has no data for project field}}') );
        $project_id = $this->aFieldData[FIELD_PROJECT];

        Database::GetDefault()->beginTransaction();

        # Create a new ticket in the database.
        if (!$forceTicketId)
        {
            Database::DefaultExec(<<<SQL
INSERT INTO tickets
    ( type_id,     project_id,   aid,         owner_uid,               created_dt,   lastmod_uid,             lastmod_dt,   created_from ) VALUES
    ( $1,          $2,           $3,          $4,                      $5,           $6,                      $7,           $8 )
SQL
  , [ $oType->id,  $project_id,  $this->aid,  $oContext->lastmod_uid,  $created_dt,  $oContext->lastmod_uid,  $lastmod_dt,  $this->id ] );

            /* The primary index of the new row is the ticket ID.
               We need it below for the data we're going to put into the other ticket_* tables. */
            $ticket_id = Database::GetDefault()->getLastInsertID('tickets', 'i');
        }
        else
        {
            Database::DefaultExec(<<<SQL
INSERT INTO tickets
    ( i,               type_id,     project_id,   aid,         owner_uid,               created_dt,   lastmod_uid,             lastmod_dt,   created_from ) VALUES
    ( $1,              $2,          $3,           $4,          $5,                      $6,           $7,                      $8,           $9 )
SQL
  , [ $forceTicketId,  $oType->id,  $project_id,  $this->aid,  $oContext->lastmod_uid,  $created_dt,  $oContext->lastmod_uid,  $lastmod_dt,  $this->id ] );

            $ticket_id = $forceTicketId;
        }

        # Now create the Ticket instance in memory.
        $oNewTicket = Ticket::CreateInstance($ticket_id,
                                             NULL,              # Template name = NULL: we're not creating a template.
                                             $oType,            # The type from the template ($this).
                                             $project_id,
                                             $this->aid,
                                             $oContext->lastmod_uid,
                                             $created_dt,
                                             $oContext->lastmod_uid,
                                             $lastmod_dt,
                                             $this->id);            # created_from template ID
        $oContext->oNewTicket = $oNewTicket;

        $aFields = $oType->getVisibleFields(TicketType::FL_INCLUDE_CHILDREN
                                            | TicketType::FL_FOR_DETAILS
                                            | TicketType::FL_INCLUDE_HIDDEN /* | TicketType::FL_INCLUDE_CORE */ );

        foreach ($aFields as $field_id => $oField)
        {
            $fieldname = $oField->name;
            # Do not fail if TITLE or STATUS are missing and the ticket type says they're automatic.
            if (    (    ($oField->id == FIELD_TITLE)
                      && ($this->oType->fl & TicketType::FL_AUTOMATIC_TITLE)
                    )
                 || (    ($oField->id == FIELD_STATUS)
                      && ($this->oType->fl & TicketType::FL_AUTOMATIC_STATUS)
                    )
               )
                ;
            else if ($oField->fl & FIELDFL_MAPPED_FROM_PROJECT)
            {
                $oNewTicket->aFieldData[$oField->id] = $project_id;
            }
            else if (!($oField->fl & FIELDFL_VIRTUAL_IGNORE_POST_PUT))          # Do not fail if no field handler exists but this flag is set.
            {
                # Initialize the field handler, either from a plugin or built-in.
                if (!($oHandler = FieldHandler::Find($field_id,
                                                     FALSE)))
                    throw new DrnException("No handler found for \"$fieldname\" field in ticket data");

                $oHandler->onCreateOrUpdate($oContext,
                                            $oNewTicket,
                                            self::CREATEFL_NOCHANGELOG);
            }
            else if ($oField->fl & FIELDFL_REQUIRED_IN_POST_PUT)
                throw new APIException( $fieldname, L('{{L//Missing ticket data for field %FIELD%}}',
                                                      [ '%FIELD%' => Format::UTF8Quote($fieldname) ] ));
        }

        # Put the new ticket into the search engine, if needed.
        if (!($fl & self::CREATEFL_NOINDEX))
            if ($oSearch = Plugins::GetSearchInstance())
                $oSearch->onTicketCreated($oNewTicket);

        if (!($fl & self::CREATEFL_NOCHANGELOG))
            Changelog::AddSystemChange(FIELD_TICKET_CREATED,
                                       $ticket_id);

        Database::GetDefault()->commit();

        # We haven't fetched the details, but we set them above, so we set the flag to TRUE
        # because stage-2 data is complete. Otherwise we'd query the database again for
        # composing ticket mail below which is not necessary.
        $oNewTicket->fStage2ListFetched = TRUE;
        $oNewTicket->fStage2DetailsFetched = TRUE;

        if (!($fl & self::CREATEFL_NOMAIL))
        {
            #
            # Send out ticket mail!
            #
            $oNewTicket->buildAndSendTicketMail(MODE_CREATE,
                                                $ouserCreating,
                                                "{{L//%USER% has created %TICKET%.}}",
                                                'CREATED');
        }

        Debug::FuncLeave("new ticket ID: ".$oNewTicket->id);

        return $oNewTicket;
    }

    /**
     *  Public method to update the ticket information with the given new information
     *  and writes it into the database. This also writes changelog entries and sends
     *  out ticket mail for the given changes (unless flags are set, see below).
     *
     *  This gets called by ApiTicket::Put() after a user has submitted new ticket data
     *  via the PUT /ticket REST API.
     *
     *  The given array uses field NAMES (not IDs) as the array keys because
     *  certain fields may be required by plugins, but this code code cannot
     *  translate between IDs and names without knowing those in detail.
     *  This also makes it simpler to just pass PUT or POST data to this function.
     *
     *  Note that this function requires that all ticket fields be specified in
     *  $aVariableData; it is not possible to omit fields to update only some ticket
     *  fields. Otherwise ambiguity could occur as to whether fields should be
     *  deleted or preserved. But see CREATEFL_IGNOREMISSING below.
     *
     *  As a special feature, if $aVariableData contains a '_comment' field, this
     *  will invoke \ref addComment() automatically and combine the comment with
     *  the ticket mail being sent out. This is used by the the "Add comment"
     *  field of the ticket editor and hopefully helps to reduce the amount of
     *  ticket mail spam on busy systems.
     *
     *  Returns the no. of fields which were detected to have changed; if
     *  this is > 0, then the ticket was actually updated, and changelog
     *  entries have been created. (That includes the comment line; this will
     *  return 1 if only a comment was added but no other fields were changed.)
     *
     *  The following flags can be set with $fl:
     *
     *   -- Ticket::CREATEFL_NOMAIL: do not send out ticket mail for this change.
     *
     *   -- Ticket::CREATEFL_NOCHANGELOG: do not write changelog entry and do not update
     *      the "lastmod" timestamp, even if fields have changed.
     *
     *   -- Ticket::CREATEFL_IGNOREMISSING: do not fail if fields marked with
     *      FIELDFL_REQUIRED_IN_POST_PUT are missing.
     *
     *  Do not use this method on template tickets; use updateTemplate() instead.
     *
     *  This does NOT check whether the current user is authorized to make this call.
     *  The caller should verify that the current user has UPDATE permission on the ticket.
     */
    public function update(User $ouserChanging = NULL,  //!< in: user who is making that change (recorded in changelogs)
                           $aVariableData,              //!< in: array of field NAMES (not IDs) => value pairs (e.g. 'title' => "new title")
                           $fl = 0)                     //!< in: combination of CREATEFL_NOCHANGELOG or CREATEFL_IGNOREMISSING or CREATEFL_NOMAIL
        : int
    {
        if ($this->isTemplate())
            throw new DrnException(L('{{L//Ticket %ID% is a template}}', [ $this->id ]));

        # Get the current stage-2 data so we can figure out which fields have actually changed.
        $this->populate(TRUE); # all details

        Debug::Log(Debug::FL_TICKETUPDATE, "Ticket::update(): new data: ".print_r($aVariableData, TRUE));

        $oContext = new TicketContext($ouserChanging,
                                      $this,
                                      MODE_EDIT);
        $oContext->aVariableData = $aVariableData;

        Database::GetDefault()->beginTransaction();

        /*
         *  Process fields
         */
        $cChanged = 0;
        $aFields = $this->oType->getVisibleFields(TicketType::FL_INCLUDE_CHILDREN | TicketType::FL_FOR_DETAILS | TicketType::FL_INCLUDE_CORE | TicketType::FL_INCLUDE_HIDDEN);

        foreach ($aFields as $field_id => $oField)
        {
            $fieldname = $oField->name;
            Debug::FuncEnter(Debug::FL_TICKETUPDATE, "handling field name $fieldname (fl: ".sprintf("0x%lX", $oField->fl).")");
            if (    (    ($oField->id == FIELD_TITLE)
                      && ($this->oType->fl & TicketType::FL_AUTOMATIC_TITLE)
                    )
                || (    ($oField->id == FIELD_STATUS)
                     && ($this->oType->fl & TicketType::FL_AUTOMATIC_STATUS)
                   )
               )
                ;
            # The "created" and "changed" fields can be set for customized creation and lastmod
            # timestamps, e.g. when synchronizing from another server.
            # TODO this is probably unsafe and should be restricted to specific callers!
            else if ($field_id == FIELD_CREATED_DT)
            {
                if (    ($v = getArrayItem($aVariableData, $fieldname))
                     && ($v != $this->aFieldData[$field_id])
                   )
                    $oContext->write_created_dt = $v;
            }
            else if ($field_id == FIELD_LASTMOD_DT)
            {
                if (    ($v = getArrayItem($aVariableData, $fieldname))
                     && ($v != $this->aFieldData[$field_id])
                   )
                    $oContext->write_lastmod_dt = getArrayItem($aVariableData, $fieldname);
            }
            else if ($field_id == FIELD_CREATED_UID)
            {
                if (    ($v = getArrayItem($aVariableData, $fieldname))
                     && ($v != $this->aFieldData[$field_id])
                   )
                    $oContext->write_created_uid = getArrayItem($aVariableData, $fieldname);
            }
            else if ($field_id == FIELD_LASTMOD_UID)
            {
                if (    ($v = getArrayItem($aVariableData, $fieldname))
                     && ($v != $this->aFieldData[$field_id])
                   )
                    $oContext->write_lastmod_uid = getArrayItem($aVariableData, $fieldname);
            }
            else if (!($oField->fl & FIELDFL_VIRTUAL_IGNORE_POST_PUT))          # Do not fail if no field handler exists but this flag is set.
            {
                # Initialize the field handler, either from a plugin or built-in.
                if (!($oHandler = FieldHandler::Find($field_id,
                                                     FALSE)))
                    throw new DrnException("No handler found for ".Format::UTF8Quote($fieldname)." field in ticket data");

                if ($oHandler->onCreateOrUpdate($oContext,
                                                $this,      # ticket
                                                $fl))
                    ++$cChanged;
            }
            else if (    ($oField->fl & FIELDFL_REQUIRED_IN_POST_PUT)
                      && (!($fl & self::CREATEFL_IGNOREMISSING))
                    )
            {
                throw new APIMissingDataException($oField);
            }
            Debug::FuncLeave();
        }

        /*
         *  Combine comment with change?
         */
        if ($htmlComment = $aVariableData['_comment'] ?? NULL)
        {
            // If the comment is the only change, then send out a COMMENT mail
            // with the proper subject line here. Otherwise we'll add the comment
            // to the regular bug mail below.
            $fSendCommentMail = ($cChanged == 0);
            $this->addComment($ouserChanging,
                              $htmlComment,
                              $fSendCommentMail);
            ++$cChanged;
        }

        /*
         *  Commit changes to database
         */
        if ($cChanged)
        {
            if ($oContext->write_created_dt)
                Database::DefaultExec(<<<EOD
UPDATE tickets
SET   created_dt = $1                  WHERE i = $2;
EOD
               , [ $oContext->write_created_dt,  $this->id]);

            if ($oContext->write_created_uid)
                Database::DefaultExec(<<<EOD
UPDATE tickets
SET   owner_uid = $1                   WHERE i = $2;
EOD
              , [ $oContext->write_created_uid,  $this->id]);

            if (    (0 == ($fl & self::CREATEFL_NOCHANGELOG))
                 || ($oContext->write_lastmod_dt)
                 || ($oContext->write_lastmod_uid)
               )
            {
                if (!$oContext->write_lastmod_dt)
                    $oContext->write_lastmod_dt = $oContext->dtNow;
                if (!$oContext->write_lastmod_uid)
                    $oContext->write_lastmod_uid = $oContext->lastmod_uid;

                Database::DefaultExec(<<<EOD
UPDATE tickets
SET   lastmod_uid = $1,               lastmod_dt = $2                  WHERE i = $3;
EOD
                , [ $oContext->write_lastmod_uid,  $oContext->write_lastmod_dt,  $this->id]);
            }
        }

        # End the transaction before sending mail because that starts another.
        Database::GetDefault()->commit();

        /*
         *  Send ticket mail
         */
        if ($cChanged)
        {
            # Reindex the ticket in the search engine, if needed.
            if ($oSearch = Plugins::GetSearchInstance())
                $oSearch->onTicketUpdated($this);

            /* The onCreateOrUpdate() calls above may have called FieldHandler::queueForTicketMail(),
               which added to aTicketMailItems[]. Send mail if anything is in there. (Note that the array is
               empty if only a comment was added; see above.) */
            if (    $oContext->aTicketMailHTML
                 && count($oContext->aTicketMailHTML)
                 && (!($fl & self::CREATEFL_NOMAIL))
               )
            {
                if (!($subjectTag = $oContext->ticketMailSubjectTag))
                    $subjectTag = 'UPDATED';

                $oldmode = $oContext->mode;
                $oContext->mode = MODE_TICKETMAIL;      // Required for proper date formatting.

                $this->buildAndSendTicketMail(MODE_EDIT,
                                              $ouserChanging,
                                              "{{L//%USER% has changed the following in ticket %TICKET%:}}",
                                              $subjectTag,
                                              $oContext,
                                              function (string &$strTicketMailHTML,
                                                        string &$strTicketMailPlain)
                                              use($oContext,
                                                  $htmlComment)
                                              {
                                                  $strTicketMailHTML .= "\n<ul>\n";
                                                  foreach ($oContext->aTicketMailHTML as $mailitem)
                                                      $strTicketMailHTML .= "<li>$mailitem</li>\n";
                                                  foreach ($oContext->aTicketMailPlain as $mailitem)
                                                      $strTicketMailPlain .= "\n\n".$mailitem;

                                                  $strTicketMailHTML .= "\n</ul>\n";

                                                  if ($htmlComment)
                                                  {
                                                      $htmlComment = "\n<br>\n<b>Comment:</b>\n$htmlComment";
                                                      $strTicketMailHTML .= $htmlComment;
                                                      $strTicketMailPlain = Format::HtmlStrip($htmlComment);
                                                  }
                                              });

                $oContext->mode = $oldmode;
            }
        }

        return $cChanged;
    }

    /**
     *  Returns TRUE if the value was changed, or FALSE if not. Throws if the ticket has no priorty
     *  field, or on other errors.
     *
     *  Does NOT send mail, but does create a changelog entry.
     */
    public function setPriority(User $ouserChanging,
                                int $priority)
        : bool
    {
        $aFields = $this->oType->getVisibleFields(TicketType::FL_INCLUDE_CHILDREN | TicketType::FL_FOR_DETAILS | TicketType::FL_INCLUDE_CORE | TicketType::FL_INCLUDE_HIDDEN);
        if (!($oField = $aFields[FIELD_PRIORITY] ?? NULL))
            throw new DrnException("Ticket type of ticket #$this->id does not have a priority field");

        if ($this->update($ouserChanging,
                          [ $oField->name => $priority ],
                          self::CREATEFL_IGNOREMISSING | self::CREATEFL_NOMAIL)) // but do changelog
            return TRUE;

        return FALSE;
    }

    /**
     *  Gets called by DateHandler::processFieldValue whenever a date field of a ticket changes
     *  (always on creation, and on update if the date changes).
     *
     *  This implementation does nothing, but ticket subclasses may want to override this to
     *  keep statistics about date changes, if necessary.
     *
     * @return void
     */
    public function onDateFieldChanged(int $field_id,
                                       Date $oNew)
    {
    }

    /**
     *  Calls Changelog::AddTicketChange() and updates FIELD_DT_LASTMOD in this ticket's field data.
     *
     *  For this reason, this method should always be called instead of calling the Changelog method directly.
     *
     *  Also call this method BEFORE sending out ticket mail for the change, or else the "last modified"
     *  field in the ticket mail will be outdated.
     */
    public function addChangelogEntry($field_id,
                                      $chg_uid,
                                      $dtNow,
                                      $oldRowId,
                                      $newRowId,
                                      $value_str = NULL)
    {
        Changelog::AddTicketChange($field_id,
                                   $this->id,
                                   $chg_uid,
                                   $dtNow,
                                   $oldRowId,
                                   $newRowId,
                                   $value_str);
        $this->aFieldData[FIELD_LASTMOD_DT] = $dtNow;
    }

    /**
     *  Reindexes this ticket. Useful if ticket was created with the CREATEFL_NOINDEX flag set,
     *  or if a manual reindexing of all tickets is triggered by the user.
     *
     *  If $oChangelog == NULL, this only indexes the ticket data. Otherwise the comments
     *  and attachments are also indexed.
     */
    public function reindex(Changelog $oChangelog = NULL)
    {
        if (!$this->fStage2DetailsFetched)
            throw new DrnException("Cannot reindex ticket without having fetched stage-2 data first");

        Debug::Log(Debug::FL_TICKETUPDATE, "reindexing ticket: ".$this->id);

        if ($oSearch = Plugins::GetSearchInstance())
        {
            $oSearch->onTicketCreated($this);

            # Now load all the comments and attachments for this ticket.
            if ($oChangelog)
            {
                $aStage2RowIDs = [];
                foreach ($oChangelog->aRowsAndWhats as $rowid => $what) # what == ticket ID
                    if ($what == $this->id)
                        $aStage2RowIDs[] = $rowid;

                if (count($aStage2RowIDs))
                {
                    $oChangelog->loadDetails($aStage2RowIDs);

                    while ($row = $oChangelog->fetchNextRow())
                    {
                        if (   (   $row->field_id == FIELD_COMMENT
                                || $row->field_id == FIELD_COMMENT_UPDATED)
                            && !empty($row->comment))
                        {
//                             Debug::Log("reindexing comment: ".print_r($row, TRUE));
                            $comment = $row->comment;
                            $oSearch->onCommentAdded($this,
                                                     $row->i,
                                                     $comment);
                        }
                        else if ($row->field_id == FIELD_ATTACHMENT)
                        {
                            $oBinary = Binary::CreateFromChangelogRow($row);
                            $oSearch->onAttachmentAdded($this,
                                                        $oBinary);
                        }
                    }
                }
            }
        }
    }

    /**
     *  Special variant of update() to use on template tickets.
     *
     *  The ticket's ACL, which was created when the ticket was created, will
     *  be updated with the given access bits. $aAccess must be a list of
     *  $gid => $flPermissions pairs, which will replace the ones in the ACL.
     */
    public function updateTemplate($oUserChanging,          //!< in: user who is making that change (recorded in changelogs)
                                   $name,
                                   $oType,
                                   $aPermissions)           //!< in: permission bits array ($gid => ACCESS_* flags)
    {
        /** @var User|null $oUserChanging */
        $chg_uid = ($oUserChanging) ? $oUserChanging->uid : NULL;
        $dtNow = gmdate('Y-m-d H:i:s');

        if (!$name || !strlen($name))
            throw new APIException('name', L('{{L//Ticket template names must not be empty}}'));

        if (!$this->isTemplate())
            throw new DrnException(L('{{L//Ticket %ID% is not a template}}', array( $this->id)));

        if (!($oACL = DrnACL::Find($this->aid)))
            throw new DrnException("Invalid ACL entry {$this->aid} in ticket template ID {$this->id}");

        Database::GetDefault()->beginTransaction();

        $oACL->update(NULL,
                      $aPermissions);

        Database::DefaultExec(<<<EOD
UPDATE tickets
SET template = $1,  type_id = $2,  lastmod_dt = $3,  lastmod_uid = $4 WHERE i = $5;
EOD
  , [          $name,         $oType->id,       $dtNow,            $chg_uid,    $this->id ] );

//         Changelog::AddTicketChange(FIELD_SYS_TEMPLATE_CHANGED, $this, $chg_uid, $dtNow, NULL, NULL);         TODO changelog

        Database::GetDefault()->commit();

        $this->template = $name;
        $this->oType = $oType;
    }

    /**
     *  Adds a new comment to the ticket.
     *
     *  This does NOT check whether the current user is authorized to make this call.
     *  The caller should only call this if the current user has UPDATE permission on the ticket.
     */
    public function addComment(User $oUserChanging,
                               string $htmlComment,            //!< in: comment to add, in simple HTML
                               bool $fSendMail = TRUE)
        : int
    {
        $chg_uid = ($oUserChanging) ? $oUserChanging->uid : NULL;
        $dtNow = Globals::Now();

        Database::GetDefault()->beginTransaction();

        Database::DefaultExec(<<<EOD
INSERT INTO ticket_texts
    ( ticket_id,  field_id,       value) VALUES
    ( $1,         $2,             $3 )
EOD
  , [ $this->id,  FIELD_COMMENT,  $htmlComment ] );

        $newRowId = Database::GetDefault()->getLastInsertID('ticket_texts', 'i');

        $this->addChangelogEntry(FIELD_COMMENT,
                                 $chg_uid,
                                 $dtNow,
                                 $newRowId,
                                 NULL);

        $changelogRowId = Database::GetDefault()->getLastInsertID('changelog', 'i');

        Database::DefaultExec(<<<EOD
UPDATE tickets
SET   lastmod_uid = $1,  lastmod_dt = $2  WHERE i = $3;
EOD
  , [               $chg_uid,         $dtNow,       $this->id ] );

        # Add the comment to the search engine index, if needed.
        if ($oSearch = Plugins::GetSearchInstance())
            $oSearch->onCommentAdded($this,
                                     $changelogRowId,
                                     $htmlComment);

        Database::GetDefault()->commit();

        #
        # Send out ticket mail!
        #
        if ($fSendMail)
            $this->buildAndSendTicketMail(MODE_COMMENT_ADDED,
                                          $oUserChanging,
                                          "{{L//%USER% has added the following comment to %TICKET%:}}",
                                          'COMMENT',
                                          NULL,
                                          function (string &$strTicketMailHTML,
                                                    string &$strTicketMailPlain)
                                          use ($htmlComment)
                                          {
                                              $strTicketMailHTML .= $htmlComment;
                                              $strTicketMailPlain .= Format::HtmlStrip($htmlComment);
                                          });

        return $changelogRowId;
    }

    /**
     *  Check if an user can edit a comment.
     */
    public function canUpdateComment(User $oUserChanging,
                                     int $commentId)
        : bool
    {
        if (!$this->canComment($oUserChanging) || $this->loadChangelog()->cComments === 0)
            return false;
        if ($oUserChanging->isAdmin())
            return true;
        if ($oRow = $this->oChangelog->findRow($commentId))
        {
            if($oRow->field_id == FIELD_COMMENT)
                return $oUserChanging->uid == $oRow->chg_uid;
            else
                return $oUserChanging->uid == $oRow->value_str;
        }
        return false;
    }

    /**
     *  Updates the contents of a comment and returns the new changelog row ID.
     *
     *  @return int
     */
    public function updateComment(User $oUserChanging,
                                  int $commentId,
                                  string $htmlComment)
        : int
    {
        $oRow = $this->oChangelog->findRow($commentId);
        if (!$oRow)
            throw new DrnException('Comment does not exist');
        /** @var ChangelogRow $oRow */
        if (!$oRow->comment)
            throw new DrnException('Not current version of the comment');

        $chg_uid = ($oUserChanging) ? $oUserChanging->uid : NULL;
        $dtNow = Globals::Now();

        $oldValue = $oRow->field_id == FIELD_COMMENT ? $oRow->value_1 : $oRow->value_2;

        Database::GetDefault()->beginTransaction();

        // Mark current comment value as old comment
        Database::DefaultExec(<<<SQL
UPDATE ticket_texts
SET    field_id = $1
WHERE  i = $2
SQL
  , [ FIELD_OLDCOMMENT, $oldValue ] );

        // Insert new comment value
        Database::DefaultExec(<<<SQL
INSERT INTO ticket_texts
( ticket_id,  field_id,       value ) VALUES
( $1,         $2,             $3 )
SQL
        , [ $this->id,  FIELD_COMMENT,  $htmlComment ] );

        $newRowId = Database::GetDefault()->getLastInsertID('ticket_texts', 'i');

        $origAuthor = $oRow->chg_uid;
        if ($oRow->field_id == FIELD_COMMENT_UPDATED)
            $origAuthor = $oRow->value_str;

        // Insert changelog item for updated comment
        $this->addChangelogEntry(FIELD_COMMENT_UPDATED,
                                 $chg_uid,
                                 $dtNow,
                                 $oldValue,
                                 $newRowId,
                                 $origAuthor);
        $changelogRowId = Database::GetDefault()->getLastInsertID('changelog', 'i');

        # Add the comment to the search engine index, if needed.
        if ($oSearch = Plugins::GetSearchInstance())
        {
            // Remove old comment from search index
            $oSearch->onCommentDeleted($this, $commentId);
            // Add new comment to search index
            $oSearch->onCommentAdded($this,
                                     $changelogRowId,
                                     $htmlComment);
        }

        Database::GetDefault()->commit();

        return $changelogRowId;
    }

    /**
     *  Deletes a comment.
     */
    public function deleteComment(User $oUserChanging,
                                  int $commentId)
    {
        $oRow = $this->oChangelog->findRow($commentId);
        if (!$oRow)
            throw new DrnException('Comment does not exist');
        $chg_uid = ($oUserChanging) ? $oUserChanging->uid : NULL;
        $dtNow = Globals::Now();

        $oldValue = $oRow->field_id == FIELD_COMMENT ? $oRow->value_1 : $oRow->value_2;

        Database::GetDefault()->beginTransaction();

        // Mark current comment value as old comment
        Database::DefaultExec(<<<SQL
UPDATE ticket_texts
SET    field_id = $1
WHERE  i = $2
SQL
  , [ FIELD_OLDCOMMENT, $oldValue ] );

        // Insert changelog item for updated comment
        $this->addChangelogEntry(FIELD_COMMENT_DELETED,
                                 $chg_uid,
                                 $dtNow,
                                 $oldValue,
                                 NULL);

        # Add the comment to the search engine index, if needed.
        if ($oSearch = Plugins::GetSearchInstance())
        {
            // Remove old comment from search index
            $oSearch->onCommentDeleted($this, $commentId);
        }

        Database::GetDefault()->commit();
    }

    /**
     *  Helper function which creates a new row in ticket_binaries for the
     *  given attachment file data. Used by Ticket::attachFiles() and from
     *  within the import code.
     *
     *  This can get the attachment data in one of two ways:
     *
     *   -- You can pass the actual binary data in $contents. This is useful
     *      when importing from another database.
     *
     *   -- You can pass the temporary name in the HTTP upload case with
     *      $tmp_name in the Binary constructor.
     *
     *  In any case, the 'filename', 'mimetype' and 'size' fields must be set
     *  in $aFileItems. See Ticket::attachFiles() for the meanings.
     *
     *  THIS DOES NOT WRITE THE CHANGELOG, does not update the ticket row itself
     *  and does not send mail.
     *  Without the changelog entry, the attachment does not show up anywhere.
     *
     *  This modifies the given Binary instance data, in particular, the
     *  idBinary member.
     *
     *  Use Ticket::ProcessAttachmentInfo() for format these values usefully.
     */
    public function createOneAttachment(Binary $oBinary,            //!< in: upload info
                                        $contents = NULL,
                                        $strSpecial = NULL)         //!< in: optional arbitrary string data for 'special' column (for plugins, unused by the base system)
    {
        $filename = $oBinary->filename;
        $mimetype = $oBinary->mimetype;
        $size = $oBinary->size;
        $blob = $cx = $cy = NULL;

        # Fix broken MIME types.
        switch ($mimetype)
        {
            case 'image/jpg':
                $mimetype = 'image/jpeg';
            break;
        }

        $fStoreUploadsInDB = GlobalConfig::Get('StoreUploadsInDB', FALSE);

        if ($tmp_name = $oBinary->tmp_name)
        {
            if (!($cbSource = filesize($tmp_name)))
                throw new DrnException("Uploaded file cannot have zero bytes");

            if ($fStoreUploadsInDB)
            {
                # Uploaded binaries should all be in database:
                if (!($SourceFile = fopen($tmp_name, 'rb')))
                    throw new DrnException("Failed to open uploaded file server-side");
                $contents = fread($SourceFile, $cbSource);
                fclose($SourceFile);

                $blob = Database::GetDefault()->encodeBlob($contents);
            }
            # else: handled below
        }
        else if (!$contents)
            throw new DrnException("Missing binary data for ticket attachment \"$filename\"");

        $targetfile = NULL;
        if (!$fStoreUploadsInDB)
        {
            # Uploaded files should be in attachments directory:
            # then build a unique filename, and encode critical characters so we're not depending on the
            # underlying filesystem to get this right. TODO what with really long $filename strings?
            $targetfile = DOREEN_ATTACHMENTS_DIR.'/'.date('Y-m-d-H-i-s').'_'.uniqid().'_'.urlencode($filename);

            if ($tmp_name)
            {
                if (!move_uploaded_file($tmp_name, $targetfile))
                    throw new DrnException("Could not move uploaded file to attachments directory.");
            }
            else
                if (!(@file_put_contents($targetfile, $contents)))
                    throw new DrnException("Could not write to attachments file $targetfile.");

            # Hack alert: negative size means file is in filesystem.
            $size = -$size;
            $filename = basename($targetfile);

            # Now determine image size.
            if (Ticket::IsImage($mimetype))
            {
                if (!($aSize = @getimagesize($targetfile)))
                    throw new DrnException("Corrupt image file, cannot determine image size");
                $cx = $aSize[0];
                $cy = $aSize[1];
            }
        }

        Database::DefaultExec(<<<EOD
INSERT INTO ticket_binaries
    ( ticket_id,  filename,   mime,       size,   data,   cx,   cy,   special ) VALUES
    ( $1,         $2,         $3,         $4,     $5,     $6,   $7,   $8 )
EOD
  , [ $this->id,  $filename,  $mimetype,  $size,  $blob,  $cx,  $cy,  $strSpecial ] );

        $idBinary = Database::GetDefault()->getLastInsertID('ticket_binaries', 'i');

        $oBinary->setInfo($idBinary,
                          $fStoreUploadsInDB ? NULL : $targetfile,
                          $cx,
                          $cy);

        # Add the comment to the search engine index, if needed.
        if ($oSearch = Plugins::GetSearchInstance())
            $oSearch->onAttachmentAdded($this,
                                        $oBinary,
                                        $contents);     # defaults to NULL
    }

    /**
     *  Adds a binary attachment to the ticket.
     *
     *  This will move the file to DOREEN_ATTACHMENTS_DIR and create a row in the binaries table and
     *  update the Binary object with it.
     *
     *  This does NOT check whether the current user is authorized to make this call.
     *  The caller should only call this if the current user has UPDATE permission on the ticket.
     *
     * @return void
     */
    public function attachFile($oUserChanging,
                               Binary $oBinary,
                               $fSendMail = TRUE)
    {
        $chg_uid = ($oUserChanging) ? $oUserChanging->uid : NULL;
        $dtNow = gmdate('Y-m-d H:i:s');

        Database::GetDefault()->beginTransaction();

        $this->createOneAttachment($oBinary);

        $this->addChangelogEntry(FIELD_ATTACHMENT,
                                 $chg_uid,
                                 $dtNow,
                                 $oBinary->idBinary,
                                 NULL);

        Database::DefaultExec(<<<EOD
UPDATE tickets
SET lastmod_uid = $1, lastmod_dt = $2 WHERE i = $3;
EOD
  , [             $chg_uid,        $dtNow,      $this->id ] );

        Database::GetDefault()->commit();

        if ($fSendMail)
        {
            #
            # Send out ticket mail!
            #
            $this->buildAndSendTicketMail(MODE_FILE_ATTACHED,
                                          $oUserChanging,
                                          "{{L//%USER% has attached a file to %TICKET%:}}",
                                          'ATTACHMENT',
                                          NULL,
                                          function (string &$strTicketMailHTML,
                                                    string &$strTicketMailPlain)
                                          use ($oBinary)
                                          {
                                              $href = WebApp::MakeUrl("/binary/$oBinary->idBinary");

                                              $tpl = "{{L//File %LINK% (type %TYPE%, %SIZE%)}}";

                                              //        self::ProcessAttachmentInfo($aInfo2);
                                              //        $idBinary = $aInfo2['value_1'];
                                              //        $filename = $aInfo2['filename'];
                                              //        $mimetype = $aInfo2['mime'];
                                              //        $filesize = $aInfo2['filesize'];

                                              $strTicketMailHTML .= L("<br><br>\n".$tpl,
                                                                      [  '%LINK%' => "<a href=\"$href\">".toHTML($oBinary->filename)."</a>",
                                                                         '%TYPE%' => toHTML($oBinary->mimetype),
                                                                         '%SIZE%' => Format::Bytes($oBinary->size, 2) ]);  // 1000-based, not 1024

                                              $strTicketMailPlain .= L("\n\n$tpl",
                                                                      [  '%LINK%' => $oBinary->filename.' -- '.$href,
                                                                         '%TYPE%' => $oBinary->mimetype,
                                                                         '%SIZE%' => Format::Bytes($oBinary->size, 2) ]);  // 1000-based, not 1024
                                          });
        }
    }

    /**
     *  Deletes the given tickets from the database, including all past und present ticket data and references
     *  to it from changelogs. This is only to be used if ticket data is to disappear without a trace,
     *  and permission to do so should only be given to an administrator.
     *  This also removes the PHP object from the internal static (class) array of all tickets.
     *
     *  This is for both regular tickets AND templates.
     *
     *  This does NOT check whether the current user is authorized to make this call. Only administrators
     *  should be allowed to delete tickets or results.
     *
     *  If ($fRecursive == TRUE), this recurses into the ticket's children, if any, before deleting
     *  the ticket itself.
     *
     *  A word of warning for templates: this does NOT check whether other tickets still exist
     *  that were created from that template. The GUI enforces that templates cannot be deleted while
     *  tickets exist that were created from it, but this code doesn't.
     *
     *  Deleting tickets is not reversible. No changelog entry is written for ticket deletion, but
     *  on deleting templates, we write FIELD_SYS_TEMPLATE_DELETED.
     *
     *  PHP has no delete operator for objects, so the PHP object ($this) probably continues to live
     *  until there are no more references to it, which this function has no control over.
     */
    public static function Delete($aTickets0)
    {
        $aTickets = [];
        $aTemplates = [];
        /** @var Ticket $oTicket */
        foreach ($aTickets0 as $ticket_id => $oTicket)
        {
            if (!$oTicket->fDeleted)
            {
                $aTickets[$ticket_id] = $oTicket;
                # Remember whether this is a template for later.
                if ($oTicket->isTemplate())
                    $aTemplates[$ticket_id] = $oTicket;
            }
        }

        Database::GetDefault()->beginTransaction();

        $aFields = TicketField::GetAll(TRUE,            # include children
                                       FIELDFL_STD_DATA_OLD_NEW | FIELDFL_EMPTYTICKETEVENT);

        # In this array we collect all field IDs that need deleting from changelog.
        $aChangelogFieldToDelete = [];

        # Collect the primary indices of table rows to delete. These come from two sources:
        # 1) current table field data (collected by PopulateMany(); indices are then
        #    in $this->aFieldDataRowIDs);
        # 2) changelog data.

        # We maintain a two-dimensional array: key 1 is the table name (e.g. 'ticket_ints'),
        # key 2 the primary index of the row to delete therein. The value is always 1; we
        # use key 2 to make sure that there are no duplicate row IDs, since row IDs can
        # appear several times in changelog entries.
        $aToDelete = [];
        $aDeleteFiles = [];     # physical attachment files to delete

        # Source 1): Current ticket field data.
        self::PopulateMany($aTickets,
                           self::POPULATE_DETAILS);

        foreach ($aFields as $field_id => $oProp)
        {
            if ($oProp->fl & FIELDFL_STD_DATA_OLD_NEW)
            {
                $aChangelogFieldToDelete[$field_id] = 1;

                /** @var Ticket $oTicket */
                foreach ($aTickets as $ticket_id => $oTicket)
                {
                    if (isset($oTicket->aFieldDataRowIDs[$field_id]))
                    {
                        if ($i = $oTicket->aFieldDataRowIDs[$field_id])
                        {
                            $tblname = $oProp->tblname;     # ticket_ints or ticket_texts
                            Debug::Log(Debug::FL_TICKETUPDATE, "delete field $field_id: adding tblname='$tblname', rowid='$i'");

                            if (!isset($aToDelete[$tblname]))
                                # first call for this table name:
                                $aToDelete[$tblname] = [];
                            $aToDelete[$tblname][$i] = 1;
                        }
                    }
                }
            }
            else if ($oProp->fl & FIELDFL_EMPTYTICKETEVENT)
                $aChangelogFieldToDelete[$field_id] = 1;
        }

        # Source 2): changelog data.
        $strFieldIDs = implode(', ', array_keys($aChangelogFieldToDelete));
        $strTicketIDs = Database::MakeInIntList(array_keys($aTickets));
        $dbres = Database::DefaultExec(<<<EOD
SELECT
    changelog.i,
    changelog.field_id,
    changelog.value_1,
    changelog.value_2,
    ticket_binaries.filename,
    ticket_binaries.mime,
    ticket_binaries.size AS filesize
FROM changelog
LEFT JOIN ticket_binaries ON (changelog.field_id = $1)
WHERE field_id IN ($strFieldIDs) AND what IN ($strTicketIDs)
EOD
            , [ FIELD_ATTACHMENT ] );

        $aChangeIDs = [];
        while ($dbrow = Database::GetDefault()->fetchNextRow($dbres))
        {
//            Debug::Log(Debug::FL_TICKETUPDATE, "tblrow ".print_r($dbrow, TRUE));
            # Remember the primary key of this changelog row for later deletion.
            $aChangeIDs[$dbrow['i']] = 1;
            # Remember the oldid and the newid

            $field_id = $dbrow['field_id'];
            if (isset($aFields[$field_id]))
            {
                $oProp = $aFields[$field_id];

                $tblname = $oProp->tblname;     # ticket_ints or ticket_texts
                if ($i = $dbrow['value_1'])
                    $aToDelete[$tblname][$i] = 1;
                if ($i = $dbrow['value_2'])
                    $aToDelete[$tblname][$i] = 1;

                # For ticket attachments with negative filesize, we also need to
                # the the local file.
                if (    ($field_id == FIELD_ATTACHMENT)
                    && ($dbrow['filesize'] < 0)
                )
                    $aDeleteFiles[] = DOREEN_ATTACHMENTS_DIR.'/'.$dbrow['filename'];
            }
        }

        foreach ($aToDelete as $tblname => $aRowIndices)
        {
            $delete = implode(', ', array_keys($aRowIndices));
            if ($tblname && $delete)
                Database::DefaultExec("DELETE FROM $tblname WHERE i IN ($delete)");
        }

        $changeIDs = implode(',', array_keys($aChangeIDs));
        if ($changeIDs)
            Database::DefaultExec("DELETE FROM changelog WHERE i IN ($changeIDs)");

        Database::DefaultExec("DELETE FROM tickets WHERE i IN ($strTicketIDs)");

        # Remove the ticket from the search engine, if needed.
        if ($oSearch = Plugins::GetSearchInstance())
            foreach ($aTickets as $ticket_id => $oTicket)
                $oSearch->onTicketDeleted($oTicket);

        foreach ($aDeleteFiles as $file)
            if (@file_exists($file))
                @unlink($file);

        Database::GetDefault()->commit();

        foreach ($aTemplates as $oTemplate)
            Changelog::AddSystemChange(FIELD_SYS_TEMPLATE_DELETED,
                                       $oTemplate->id,          # what
                                       $oTemplate->oType->id,    # value_1
                                       $oTemplate->aid,          # value_2
                                       $oTemplate->template);    # value_str

        foreach ($aTickets as $ticket_id => $oTicket)
        {
            $oTicket->fDeleted = TRUE;
            unset(self::$aAwakened[$ticket_id]);
        }
    }

    /**
     *  Attempts to release the memory for this ticket. This is not guaranteed to work as there may be
     *  other variables holding a reference to the object instance.
     */
    public function release()
    {
        unset(self::$aAwakened[$this->id]);
    }


    /********************************************************************
     *
     *  View: public instance functions
     *
     ********************************************************************/

    /**
     *  Returns the ticket ID with a dash and the title. Used by getJSON(). Can be overridden
     *  by subclasses.
     */
    public function makeLongerHtmlTitle()
        : HTMLChunk
    {
        $o = HTMLChunk::FromString($this->getTitle());
        if ($this->getInfo(Ticket::INFO_DETAILS_TITLE_WITH_TICKET_NO))
            $o->prepend('#'.$this->id.' &mdash; ');
        return $o;
    }

    /**
     *  Produces the string that is used in <a title=...> in links to this
     *  ticket. This should describe the ticket sufficiently.
     */
    public function makeGoToFlyover(bool $fEdit = FALSE)    //!< in: if TRUE, describe an editor link instead
    {
        # NOTE: Keep these on three lines or else the dgettext extractor will get confused
        $lstr = ($fEdit)
            ? "{{L//Open editor for ticket #%TICKET%: %{%TITLE%}%}}"
            : "{{L//View details for #%TICKET%: %{%TITLE%}%}}";

        return L($lstr,
                 [ '%TICKET%' => $this->id,
                   '%TITLE%' => HTMLChunk::FromString($this->getTitle())->html
                 ] );
    }

    /**
     *  Returns the link particle that should be used for ticket details links.
     *
     *  If overridden, this will make sure that details links to tickets of the given
     *  type will be formed using "/$alias/" instead of the default "/ticket/". This is
     *  purely cosmetic, regular /ticket/ links will continue to work.
     *  Note that when overriding this method to return something different, you MUST call
     *  TicketType::AddTicketLinkAlias() during plugin initialization for those specialized
     *  ticket links to work. See remarks there.
     *
     *  This default implementation returns the word 'ticket', unless overridden by a
     *  specialized Ticket subclass.
     */
    public function getTicketUrlParticle()
        : string
    {
        return 'ticket';
    }

    /**
     *  Returns the URL tail part that a link to this ticket would need.
     *
     *  WITHOUT the rootpage since this may be called from the CLI
     *  and we can't see it there.
     */
    public function makeUrlTail($extra = NULL,         //!< in: optional additional URL parameters, must start with '?'
                                bool $fEdit = FALSE)        //!< in: if TRUE, make an editor link instead
    {
        if ($this->template)
            return "/newticket/".$this->id;
        $href = ($fEdit) ? '/editticket/' : '/'.$this->getTicketUrlParticle().'/';
        $href .= $this->id;
        if ($extra)
            $href .= $extra;
        return $href;
    }

    /**
     *  Returns HTML with an <a> link to the ticket with tooltip and ticket information.
     */
    public function makeLink($fIcon = TRUE,
                             $extra = NULL)
    {
        $title = $this->getTitle();
        if (strlen($title) == 0)
            $title = '#'.$this->id;

        $oIcon = HTMLChunk::FromString($title);
        if ($fIcon)
            $oIcon = $this->getIconPlusChunk($oIcon);

        return HTMLChunk::MakeTooltip($oIcon->html,
                                      $this->makeGoToFlyover(),
                                      WebApp::MakeUrl($this->makeUrlTail($extra)));
    }

    /**
     *  Returns HTML with a complete <a...> link to the ticket with tooltip and ticket information.
     *  Returns HTMLChunk.
     */
    public function makeLink2($fIcon = TRUE,        //!< in: if TRUE, the ticket icon will be printed before strLink
                              $strLink = NULL,      //!< in: link string (what is in between <a> and </a>
                              $extra = NULL)        //!< optional additional URL parameters, must start with '?'
        : HTMLChunk
    {
        if (!$strLink)
            if (!($strLink = $this->getTitle()))
                $strLink = '#'.$this->id;

        $oIcon = HTMLChunk::FromString($strLink);
        if ($fIcon)
            $oIcon = $this->getIconPlusChunk($oIcon);

        return HTMLChunk::MakeLink(WebApp::MakeUrl($this->makeUrlTail($extra)),
                                   HTMLChunk::MakeFlyoverInfo($oIcon,
                                                              $this->makeGoToFlyover()));
    }

    /**
     *  Returns HTML with a link to the "edit ticket" form.
     */
    public function makeEditLink()
        : HTMLChunk
    {
        $tooltip = $this->makeEditTicketTitle(FALSE).Format::HELLIP;
        $idTicket = $this->id;
        $url = Globals::$rootpage."/editticket/$idTicket";
        if ($saveDestination = getRequestArg('aftersave'))
            $url .= "?aftersave=".$saveDestination;

        return HTMLChunk::MakeLink($url,
                                   Icon::GetH('edit'),
                                   $tooltip,
                                   [ 'rel' => 'edit edit-form' ]);
    }

    /**
     *  Produces a string that is used as the title of the "Edit ticket" form.
     *
     *  Can be overridden by subclasses for something nicer.
     */
    public function makeEditTicketTitle($fHTMLHeading = TRUE)       //!< in: if TRUE, may contain HTML for page <h1>; otherwise no, for <head><title>
    {
        $l = L('{{L//Edit ticket #%ID%}}',
               [ '%ID%' => $this->id ]);

        if ($this->isTitlePageTicket())
            $l .= ' ('.L("{{L//used for the %DOREEN% title page}}").')';

        return $l;
    }

    /**
     *  This gets called from ViewTicket for MODE_CREATE and MODE_EDIT to add
     *  an info next to the "submit" button how many users will receive ticket
     *  mail as a result of the change.
     *
     *  This is in a separate method in case Ticket specializations want to
     *  enable a different regime.
     *
     * @return void
     */
    public function addCreateOrEditTicketMailInfo(int $mode,                //!< in: MODE_CREATE or MODE_EDIT
                                                  HTMLChunk $oHtml)
    {
        if ($aUsers = $this->getTicketMailRecipients())
        {
            $cUsers = count($aUsers);
            $oHtml->addLine(' '.Format::NBSP.Format::NBSP.' '
                            .Ln("{{Ln//This will send notification mail to one user.//This will send notification mails to %COUNT% users.}}",
                                $cUsers,
                                [ '%COUNT%' => $cUsers ] ));
        }
    }

    /**
     *  Returns an array of ticket data that can be encoded in JSON and then given to
     *  JavaScript client code (e.g. VisJS) for ticket visualization, e.g. in the
     *  ticket dependency graph.
     *
     *  $fieldidParents must be the field ID of the PARENTS field (e.g. FIELDID_PARENTS);
     *  it is assumed again that the "children" field ID is off by one.
     */
    public function makeGraphData($fieldidParents,
                                  $defaultColor)            //!< in: background color if ticket has no status
    {
        $status = $statusColor = NULL;
        if (isset($this->aFieldData[FIELD_STATUS]))
        {
            $status = $this->aFieldData[FIELD_STATUS];
            TicketWorkflow::GetAllStatusValues();
            $statusColor = TicketWorkflow::GetStatusColor($status);
        }
        else
            $statusColor = $defaultColor;;

        $cParents = $cChildren = 0;
        if ($parents = getArrayItem($this->aFieldData, $fieldidParents))            # FIELDID_PARENTS  = -34
            $cParents = count(explode(',', $parents));
        $fieldidChildren = $fieldidParents + 1;                                     # FIELDID_CHILDREN = -33
        if ($children = getArrayItem($this->aFieldData, $fieldidChildren))
            $cChildren = count(explode(',', $children));

        return  [ 'id' => $this->id,
                  'title' => $this->getTitle(),
                  'status' => $status,
                  'statusColor' => $statusColor,
                  'nlsFlyOver' => $this->makeGoToFlyover(),
                  'cParents' => $cParents,
                  'cChildren' => $cChildren
                ];
    }

    /**
     *  Provides an icon for all tickets of a given type. See \ref Ticket::getIcon() for
     *  details, which calls this in turn.
     *
     *  This default implementation looks at the static $icon member, which allows Ticket
     *  subclasses to simply set that to a value and have it returned for all icons of the
     *  class.
     *
     *  NOTE: This may not be shown as used by static code analyzers, but it DOES
     *  get called from TicketType::getIcon() through some class trickery.
     */
    public static function GetTypeIcon()
        : HTMLChunk
    {
        if ($icon = static::$icon)      // static = dynamic run-time class resolution
            return Icon::GetH($icon);

        return NULL;
    }


    /**
     *  Attempts to return an icon for the ticket by calling \ref GetTypeIcon() and then
     *  adding a status color if $fStatusColor == TRUE.
     *
     *  To determine the icon for a ticket, Doreen calls this method, which calls
     *  TicketType::getIcon() by default, which calls the static Ticket::GetTypeIcon()
     *  method, which returns the static Ticket::$icon variable.
     *
     *  Therefore, to change icons for tickets, the following options are recommended:
     *
     *   -- Simply override the protected static Ticket::$icon class member in a subclass
     *      of Ticket. (See above for how this gets read. That will also change the icon
     *      for the "type" filters in search results.
     *
     *   -- Additionally, you can override this method for a subclass of Ticket; this
     *      allows for returning different icons for every ticket depending on instance data.
     */
    public function getIcon($fStatusColor = FALSE)
        : HTMLChunk
    {
        if ($oIcon = $this->oType->getIcon())        // calls static::GetTypeIcon() in turn
        {
            if (    $fStatusColor
                && ($status = $this->getStatus())
               )
            {
                TicketWorkflow::GetAllStatusValues();
                if ($color = TicketWorkflow::GetStatusColor($status))
                    $oIcon = HTMLChunk::MakeElement('span',
                                                    [ 'style' => "color: $color" ],
                                                    $oIcon);
            }
        }

        return $oIcon;
    }

    /**
     *  Calls \ref getIcon() and appends the given chunk with a non-breaking space to its result if not empty.
     */
    public function getIconPlusChunk(HTMLChunk $o,
                                     $fStatusColor = FALSE)
        : HTMLChunk
    {
        if ($o2 = $this->getIcon($fStatusColor))
            return $o2->append(Format::NBSP.$o->html);

        return $o;
    }

    /**
     *  Formats the typical ticket mail intro like "User has modified ticket #1". The following placeholders
     *  can be used:
     *
     *   -- %USER%
     *
     *   -- %TICKET%
     *
     *  Returns a list (flat array) of HTML and plain-text intro strings.
     */
    public function makeMailIntro($oUserChanging,
                                  $tpl)
    {
        $idTicket = $this->id;
        $link = WebApp::MakeUrl("/ticket/".$this->id);
        $title = $this->getTitle();
        $htmlTitle = toHTML($title);
        $strTicketMailHTML = L($tpl,
                               [ '%USER%' => $oUserChanging->login,
                                 '%TICKET%' => "<a href=\"$link\">#$idTicket ($htmlTitle)</a>"
                               ]);
        $strTicketMailPlain = L($tpl,
                                [ '%USER%' => $oUserChanging->login,
                                  '%TICKET%' => "#$idTicket ($link -- $title)"
                                ]);

        return [ $strTicketMailHTML, $strTicketMailPlain ];
    }

    /**
     *  Appends a table with ticket field data to the given HTML and plain-text
     *  ticket mail bodies.
     *
     *  NOTE: Make sure that all data in the Ticket instance has been updated
     *  before calling this. In particular, call addChangelogEntry() before this,
     *  or else the "last modified" ticket field will be outdated.
     */
    public function appendMailTicketSummary(TicketContext $oContext,
                                            &$strTicketMailHTML,
                                            &$strTicketMailPlain)
    {
        $this->populate(TRUE);

        $strTicketMailHTML .= L("\n<hr>\n<h3>{{L//Summary}}</h3>\n");
        $strTicketMailHTML .= "\n<table>\n";

        $aFields = $this->oType->getVisibleFields(TicketType::FL_INCLUDE_CHILDREN | TicketType::FL_FOR_DETAILS | TicketType::FL_INCLUDE_CORE /* | TicketType::FL_INCLUDE_HIDDEN */ );

        foreach ($aFields as $field_id => $oField)
        {
//                $fieldname = $oField->name;
            if ($oHandler = FieldHandler::Find($field_id,
                                               FALSE))      // do not fail if not found
            {
                $strTicketMailHTML .= "\n<tr>\n";
                $htmlName = $oHandler->getLabel($oContext)->html;
                $value = $oHandler->getValue($oContext);
                $htmlValue = $oHandler->formatValueHTML($oContext, $value)->html;

                $strTicketMailHTML .= "\n<td>$htmlName</td>";
                $strTicketMailHTML .= "\n<td>$htmlValue</td>";
                $strTicketMailHTML .= "\n</tr>\n";
            }
        }
        $strTicketMailHTML .= "\n</table>\n";
    }

    /**
     *  Returns a list of ticket mail recipients for updates of this ticket, as user ID => User
     *  pairs, or NULL if there are none.
     *
     *  Users receive ticket mail if all of the following are true:
     *
     *   1) They have ACCESS_MAIL permission.
     *
     *   2) Their user account is not disabled.
     *
     *   3) They have the User::FLUSER_TICKETMAIL flag set.
     *
     * @return User[] | null
     */
    public function getTicketMailRecipients()
    {
        $aReturn = [];

        if ($aUserIDs = self::getUsersWithAccess(ACCESS_MAIL))
        {
            $aUsers = User::FindMany(array_keys($aUserIDs));

            Debug::Log(Debug::FL_TICKETMAIL, "Users to send mail to: ".print_r($aUsers, TRUE));
            foreach ($aUsers as $uid => $oUser)
                if (0 == ($oUser->fl & (User::FLUSER_DISABLED | User::FLUSER_NOLOGIN)))
                    if ($oUser->fl & User::FLUSER_TICKETMAIL)
                        $aReturn[$uid] = $oUser;
        }

        return count($aReturn) ? $aReturn : NULL;
    }

    /**
     *  Sends out mail with the given subject and body to all people who should receive ticket
     *  mail for this ticket. See \ref getTicketMailRecipients().
     *
     *  This also checks if ticket mail is enabled globally and does nothing otherwise.
     *
     *  The new default for ticket mail as of 2018-12 is now to name and email address of
     *  the person who made the change as the sender, instead of the global values in
     *  Globals.
     */
    public function sendTicketMail(User $oUserChanging,
                                   $subject,
                                   $strTicketMailHTML,
                                   $strTicketMailPlain,
                                   $aBCC = [])
    {
        if (GlobalConfig::IsTicketMailEnabled())
        {
            if (    (!count($aBCC))
                 && ($aUsers = $this->getTicketMailRecipients())
               )
                foreach ($aUsers as $uid => $oUser)
                    $aBCC[] = $oUser->email;

            if (count($aBCC))
                Email::Enqueue(NULL,                # to
                               $aBCC,
                               $subject,
                               $strTicketMailHTML,
                               $strTicketMailPlain,
                               $oUserChanging ? $oUserChanging->email : NULL,
                               $oUserChanging ? $oUserChanging->longname : NULL);
        }
    }

    /**
     *  Builds and sends the ticket notification mail. The mail is sent out in
     *  the last language a user selected or the current system language, when
     *  the user has not set their language yet.
     *
     *  Building involves making the intro (\ref makeMailIntro()), running a custom
     *  callback to append to the body, appending the ticket change summary
     *  (\ref appendMailTicketSummary()), localizing the subject and finally sending
     *  the message.
     *
     *  Does nothing when ticket mail is disabled.
     *
     *  $mode is one of MODE_CREATE, MODE_EDIT, MODE_COMMENT_ADDED, or MODE_FILE_ATTACHED.
     */
    public function buildAndSendTicketMail(int $mode,
                                           User $oUserChanging,                                         //!< in: user that triggered the ticket mail.
                                           string $introTemplate,                                       //!< in: template for the mail intro.
                                           string $subjectTag,                                          //!< in: subject line to translate.
                                           TicketContext $oContext = NULL,                              //!< in: ticket context, when not provided a new one is generated.
                                           callable $appendBody = NULL)                                 //!< in: callback that takes two in/out strings, the first one is the html body, the second one the plaintext body.
    {
        if (GlobalConfig::IsTicketMailEnabled())
        {
            $aEmailsByLang = [];
            $initialLang = DrnLocale::Get();
            if ($aUsers = $this->getTicketMailRecipients())
            {
                foreach ($aUsers as $uid => $oUser)
                {
                    $userLang = $oUser->getExtraValue(DrnLocale::USER_LOCALE) ?? $initialLang;
                    $aEmailsByLang[$userLang][] = $oUser->email;
                }
            }

            foreach ($aEmailsByLang as $lang => $llEmails)
            {
                Debug::Log(Debug::FL_TICKETMAIL, "Building ticket mail body for $lang");
                DrnLocale::Set($lang, FALSE);
                list($strTicketMailHTML, $strTicketMailPlain) = $this->makeMailIntro($oUserChanging,
                                                                                     $introTemplate);

                if ($appendBody)
                    $appendBody($strTicketMailHTML,
                                $strTicketMailPlain);

                if (!$oContext)
                    $oContext = new TicketContext($oUserChanging,
                                                  $this,
                                                  MODE_TICKETMAIL);

                $this->appendMailTicketSummary($oContext,
                                               $strTicketMailHTML,
                                               $strTicketMailPlain);

                $subject = GlobalConfig::GetTicketMailSubjectPrefix().L($subjectTag);       // CREATED, UPDATED, COMMENT, ATTACHMENT etc.
                if ($this->getInfo(self::INFO_DETAILS_TITLE_WITH_TICKET_NO))
                    $subject .= " #".$this->id;
                $subject .= " ".$this->getTitle();

                $this->sendTicketMail($oUserChanging,
                                      $subject,
                                      $strTicketMailHTML,
                                      $strTicketMailPlain,
                                      $llEmails);
            }

            DrnLocale::Set($initialLang, FALSE);
        }
    }

    const INFO_SHOW_IMPORT_ID                   = 1;            // boolean for whether to show FIELD_IMPORTEDFROM (Ticket default: TRUE)

    // FIELD_TITLE handling.
    const INFO_TITLE_LABEL                      = 2;            // Defaults to "Title".
    const INFO_EDIT_TITLE_HELP                  = 3;            // Plain text help for "edit title" entry field in editor.
    const INFO_CREATE_TICKET_TITLE              = 4;
    // const INFO_EDIT_TICKET_TITLE                = 5;         // Obsolete, now uses makeEditTicketTitle()

    const INFO_DETAILS_TITLE_WITH_TICKET_NO     = 6;            // boolean for whether to prefix tickets with ticket #'s

    // Description field handler hacking.
    const INFO_EDIT_DESCRIPTION_LABEL           = 7;
    const INFO_EDIT_DESCRIPTION_HELP            = 8;            // Plain text help for "edit description" entry field in editor.
    const INFO_EDIT_DESCRIPTION_C_ROWS          = 9;            // No. of rows for the description wysiwyg editor (default 5).

    const INFO_CREATE_SHOW_PARENTS_AND_CHILDREN = 10;
    const INFO_CREATE_TICKET_BUTTON             = 11;
    const INFO_CREATE_TICKET_INTRO              = 12;

    const INFO_DETAILS_SHOW_CHANGELOG           = 13;           // boolean for whether a changelog and attachments should be shown in details (default: TRUE)

    /**
     *  Returns miscellanous small bits of information about this ticket. This method is designed
     *  to be overridden by Ticket subclasses so that certain bits of ticket behavior can be modified
     *  if desired.
     *
     *  The following bits of information can be requested via $id:
     *
     *   -- INFO_SHOW_IMPORT_ID: Gets called by ImportedFromHandler::appendReadOnlyRow() if FIELD_IMPORTEDFROM
     *      is part of the ticket fields list. If this returns TRUE, then the "import ID" row gets shown.
     *      This Ticket implementation always returns TRUE. If a subclass overrides this and returns FALSE,
     *      it can suppress the column even when it's part of the ticket field list.
     *
     *   -- INFO_EDIT_TITLE_DESCRIPTION: what to display under the "Title" entry field for FIELD_TITLE.
     *
     *   -- INFO_CREATE_TICKET_TITLE: the heading for the "Create ticket" form. The default displays
     *
     *   -- INFO_CREATE_SHOW_PARENTS_AND_CHILDREN: whether to show the "parents" and "children" fields
     *      in the create form. This method returns TRUE, but ticket subclasses can return FALSE to hide
     *      those two fields depending on user permissions. Gets called from \ref ParentsHandler::appendFormRow().
     *
     *   -- INFO_CREATE_TICKET_BUTTON: button label to submit "Create ticket" from.
     *
     *  When overriding this method in a Ticket subclass, you MUST call the parent for all $id cases
     *  you don't handle yourself.
     */
    public function getInfo($id)
    {
        switch ($id)
        {
            case self::INFO_SHOW_IMPORT_ID:
                return TRUE;

            case self::INFO_TITLE_LABEL:
                return FieldHandler::TITLE;     // caller calls L() on it

            case self::INFO_EDIT_TITLE_HELP:
                if ($this->isTitlePageTicket())
                    return L("{{L//This title is displayed as the heading of the %DOREEN% title page for every user.}}");
                return L("{{L//The title is displayed as the tickets's heading and also describes the ticket in search results.}}");

            case self::INFO_CREATE_TICKET_TITLE:
                return L('{{L//Create new %{%TEMPLATE%}% ticket}}',
                         [ '%TEMPLATE%' => $this->getTemplateName() ]);

            case self::INFO_EDIT_DESCRIPTION_LABEL:
                return FieldHandler::DESCRIPTION;

            case self::INFO_EDIT_DESCRIPTION_HELP:
                if ($this->isTitlePageTicket())
                    return L("{{L//This text is displayed on the %DOREEN% title page for every user.}}");
                return L('{{L//The ticket description is a potentially longer text that can use formatting.}}');

            case self::INFO_EDIT_DESCRIPTION_C_ROWS:
                return 5;

            case self::INFO_CREATE_SHOW_PARENTS_AND_CHILDREN:
                return TRUE;

            case self::INFO_CREATE_TICKET_BUTTON:
                return L('{{L//Create %TEMPLATE%}}',
                         [ '%TEMPLATE%' => $this->getTemplateName() ]);

            case self::INFO_CREATE_TICKET_INTRO:
                return L("<p>{{L//Please fill in the fields below and press the %{Create}% button.}}</p>");

            case self::INFO_DETAILS_TITLE_WITH_TICKET_NO:
            case self::INFO_DETAILS_SHOW_CHANGELOG:
                if ($this->oType->id == GlobalConfig::Get(GlobalConfig::KEY_ID_TYPE_WIKI))
                    return FALSE;
                return TRUE;
        }

        return NULL;
    }


    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Returns the lowest primary index of the \ref tickets table currently in use
     *  minus one. This is used for creating templates and must be called in a transaction block.
     */
    public static function GetNextLowestTicketId()
        : int
    {
        // Get the lowest ticket ID currently in use. This should yield 0 on the first call.
        if (!($id = Database::GetDefault()->execSingleValue("SELECT MIN(i) AS min FROM tickets", [], 'min')))
            $id = 0;        # in case this yields NULL

        // Make sure we don't return a 0 ticket ID.
        if ($id == 1)
            $id = 0;

        return $id - 1;
    }

    /**
     *  Creates a new template, which is a special type of ticket that other tickets can be
     *  created from (with Ticket::createAnother()).
     *
     *  This will automatically create an ACL for the new ticket with the access permissions
     *  given in $aPermissions, which must be an array of $gid => $flPermission (ACCESS_* bits)
     *  entries.
     *
     *  As a result, there is one ACL per ticket template, which is then shared with all the
     *  tickets created from that template. If the ACL is modified later, this will automatically
     *  change the permissions of all the tickets created from the template
     *
     *  Returns the new Ticket object representing that template.
     *
     *  This does NOT check whether the current user is authorized to make this call. Normally
     *  only administrators should be allowed to create templates, unless a plugin implements
     *  some special scheme.
     *
     *  Note: As per 2018-11-22, this now uses negative ticket IDs (< 0) for templates. This will
     *  only affect NEW templates, old templates will continue to exist with positive ticket IDs.
     *  This change was made to make imports of legacy data possible with preserving old ticket IDs
     *  without conflicts.
     */
    public static function CreateTemplate($oUserChanging,           //!< in: user who should be stored as the template's owner
                                          $name,                    //!< in: template name, shown in "New" menu
                                          TicketType $oType,        //!< in: ticket type, determining the visibility of ticket fields
                                          $project_id,              //!< in: numeric project ID, or NULL
                                          $aPermissions)            //!< in: permission bits array ($gid => ACCESS_* flags)
        : Ticket
    {
        if (!$name || !strlen($name))
            throw new APIException('name', L('{{L//Ticket template names must not be empty}}'));

        # Make this all-or-nothing. We have no use for the ACL if the ticket cannot be created.
        # DrnACL::Create has its own transaction, but our database layer allows for nesting.
        Database::GetDefault()->beginTransaction();

        $owner_uid = ($oUserChanging) ? $oUserChanging->uid : NULL;
        $dtNow = gmdate('Y-m-d H:i:s');

        $idTemplate = self::GetNextLowestTicketId();

        # 1) Create the ticket for the template. We have no ACL yet, but we want the ticket ID in
        # the ACL name, so first create the ticket without an ACL and then update.
        Database::DefaultExec(<<<SQL
INSERT INTO tickets
    ( i,            template,                  type_id,     project_id,   aid,   owner_uid,   created_dt, lastmod_uid,  lastmod_dt, created_from ) VALUES
    ( $1,           $2,                        $3,          $4,           $5,    $6,          $7,         $8,           $9,         $10  )
SQL
  , [ $idTemplate,  $name,                     $oType->id,  $project_id,  NULL,  $owner_uid,  $dtNow,     $owner_uid,   $dtNow,     NULL ]);

        # $idTemplate = Database::GetDefault()->getLastInsertID('tickets', 'i');

        # 2) Create the ACL.
        $oACL = DrnACL::Create(NULL,
                               $aPermissions);

        # 3) Update the template with the ACL ID.
        Database::DefaultExec('UPDATE tickets SET aid = $1 WHERE i = $2',
                                                            [ $oACL->aid,  $idTemplate ] );

        Changelog::AddSystemChange(FIELD_TICKET_TEMPLATE_CREATED,
                                   $idTemplate,
                                   NULL,        # value_1
                                   NULL,        # value_2
                                   $name);

        Database::GetDefault()->commit();

        return Ticket::CreateInstance($idTemplate,
                                      $name,
                                      $oType,
                                      $project_id,
                                      $oACL->aid,
                                      $owner_uid,
                                      $dtNow,
                                      $owner_uid,
                                      $dtNow,
                                      NULL);            # created_from template ID
    }

    /**
     *  Convenience function that calls \ref CreateTemplate() if the given template does not yet
     *  exist, or updates the existing template if it does. This is designed for use from
     *  installation routines.
     */
    public static function InstallTemplate(string $configKey,       //!< in: settings key for GlobalConfig where to store the template ID
                                           string $name,
                                           TicketType $oType,       //!< in: use TicketType::FindFromGlobalConfig()
                                           string $keyProject = NULL,
                                           array $aPermissions)     //!< in: array of group ID => ACCESS_* flag pairs
        : Ticket
    {
        if ($id = GlobalConfig::Get($configKey))
        {
            $oTemplate = self::FindTemplateOrThrow($id);
            $oACL = DrnACL::Find($oTemplate->aid);
            $oACL->update(NULL, $aPermissions);
        }
        else
        {
            $idProject = NULL;
            if ($keyProject)
                $idProject = GlobalConfig::GetIntegerOrThrow($keyProject);
            $oTemplate = Ticket::CreateTemplate(NULL,
                                                $name,
                                                $oType,
                                                $idProject,
                                                $aPermissions);
            GlobalConfig::Set($configKey, $oTemplate->id);
        }

        return $oTemplate;
    }

    /**
     *  Little helper that converts variable field data (e.g. for createAnother())
     *  from fieldid => data format to field name => data, which is what the Ticket
     *  class expects, but is unwieldy to type.
     */
    public static function ConvertFieldData($aFields)
    {
        $aVariableData = [];
        foreach ($aFields as $field_id => $data)
        {
            $oField = TicketField::FindOrThrow($field_id);
            $aVariableData[$oField->name] = $data;
        }

        return $aVariableData;
    }

    /**
     *  Parses the given "sortby" URL argument string (which can have a '!' prefix),
     *  returns the pure string without the prefix and sets $fAscending accordingly.
     */
    public static function ParseSortBy(string $sortbyIn,
                                       &$fAscending)
    {
        $oSearchOrder = SearchOrder::FromParam($sortbyIn);
        $fAscending = $oSearchOrder->direction == SearchOrder::DIR_ASC;
        return $oSearchOrder->getParam();
    }

    /**
     *  Static helper that helps us build the WHERE and LEFT JOIN bits of an SQL query
     *  with drill-down filters active or not. This is a bit complicated and needs to
     *  handle two situations:
     *
     *   -- If $oFieldThis is NULL, then this needs to prepare the WHERE and LEFT JOIN
     *      strings for the actual query, including all the filters in $aActiveFilters.
     *      This is supposed to produce the result set with all filters active.
     *
     *   -- If $oFieldThis is not NULL however, it is the ticket field for which the
     *      caller wants to produce a drill-down. We then need to add all active filters
     *      EXCEPT the one for the field in $oFieldThis, or else we won't get the
     *      counts for the values of the field that we're not filtering for.
     */
    private static function BuildWhereForFilters($aActiveFilters,
                                                 TicketField $oFieldThis = NULL,           //!< in: field ID to prepare drill-down for, or NULL
                                                 &$strWhereThis,
                                                 JoinsList &$oJoinsList)
    {
//         Debug::Log("oFieldThis: ".print_r($oFieldThis, TRUE));
        # Add to the WHERE clause if drill-down filters are active.
        if (is_array($aActiveFilters))
        {
            foreach ($aActiveFilters as $filterFieldID => $aValidValues)
            {
                if (    (!$oFieldThis)
                     || ($filterFieldID != $oFieldThis->id)
                   )
                {
                    if ($filterFieldID == FIELD_TYPE)
                        $strWhereThis .= " AND (tickets.type_id IN (".Database::MakeInIntList($aValidValues).'))'; //"))"
                    else
                    {
                        if (!($oField = TicketField::Find($filterFieldID)))
                            throw new DrnException("Invalid filter field ID \"$filterFieldID\" (1)");
                        if (    ($oField->tblname != 'ticket_ints')
                             && ($oField->tblname != 'ticket_categories')
                             && ($oField->tblname != 'ticket_parents')
                           )
                            throw new DrnException("Table name for filter field ID $filterFieldID is not ticket_ints");

                        # We don't need to add a LEFT JOIN clause if we have the table already in the FROM clause.
                        # The LEFT JOIN we're producing below needs to reference the current row's ticket ID.
                        # This is "tickets.i" if the main table (in FROM) is 'tickets':
                        if (    (!$oFieldThis)
                             || ($oFieldThis->id == FIELD_TYPE)
                           )
                            $ticketIDReference = "tickets.i";
                        else
                        {
                            # Otherwise caller has a FROM .. ticket_ints tbl_FIELDNAME with FIELDNAME being the name
                            # of $oFieldThis, so use that here.
                            $fieldnameThis = $oFieldThis->name;
                            $ticketIDReference = "tbl_$fieldnameThis.ticket_id";
                        }

                        $oLJ = new LeftJoin($oField,
                                            $ticketIDReference);
                        $oJoinsList->add($oLJ);

                        $strWhereThis .= " AND ($oLJ->tblAlias.value IN (".Database::MakeInIntList($aValidValues).'))'; // "))"
                    }
                }
            }
        }
    }

    /**
     *  Implements full-text search when no proper search engine is available.
     *
     *  This does a simple ILIKE search, no bells and whistles, no scoring. It does
     *  not yet search comments or attachments either.
     */
    private static function FulltextSQL($strFulltext,
                                        &$strWhereInput)
    {
        Debug::FuncEnter(Debug::FL_TICKETFIND, __METHOD__);

        $aRes = [];

        if (    (strlen($strFulltext) == 36)
             && (preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i', $strFulltext))
           )
        {
            $aRes[] = Database::DefaultExec(<<<SQL
SELECT ticket_id AS i FROM ticket_uuids WHERE value = $1
SQL
                                             , [ $strFulltext ] );
        }
        /* Make a list of fields that should have full-text search. */
        else foreach (TicketField::GetSearchableFields() as $field_id => $oField)
        {
            if ($oField->tblname == "ticket_uuids")
                ;
            else
            {
                $textMatch = Database::GetDefault()->makeFulltextQuery('t2.value', $strFulltext);
                $aRes[] = Database::DefaultExec(<<<SQL
SELECT
    tickets.i
FROM tickets
JOIN $oField->tblname t2
  ON tickets.i = t2.ticket_id
 AND t2.field_id = $1
 AND $textMatch
WHERE $strWhereInput
SQL
             , [ $field_id ]
                                            );
            }
        }

        $aTicketIDs = [];
        foreach ($aRes as $res)
            while ($row = Database::GetDefault()->fetchNextRow($res))
            {
                $i = (int)$row['i'];
                $aTicketIDs[$i] = 1;
            }

        if (count($aTicketIDs))
            $strWhereInput = "(tickets.i IN (".Database::MakeInIntList(array_keys($aTicketIDs))."))";
        else
            $strWhereInput = "FALSE";

        Debug::FuncLeave();
    }

    /**
     *  \page drill_down_filters Doreen drill-down filters
     *
     *  With drill-down filters, Doreen allows for narrowing down search results. For example, if
     *  the user searches for "foo" via the search bar, all the tickets that match "foo" (as defined
     *  by Doreen's search engine) are returned (taking the user's access permissions into account),
     *  sorted by search score.
     *
     *  Doreen then automatically analyzes the tickets returned according to which drill-down fields
     *  are returned by TicketField::GetDrillDownIDs(). This analysis performs a two-dimensional
     *  aggregation of ticket counts: for every field ID returned by that function, we count the
     *  distribution of tickets across the ticket values found. Search engines and databases usually
     *  call the values which are counted "buckets", because one can think that every time a value
     *  is found, it is counted by throwing it into a bucket.
     *
     *  By default, GetDrillDownIDs() returns FIELD_STATUS and FIELD_UIDASSIGN. Note that aggregation
     *  for FIELD_TYPE is always performed in addition automatically. Plugins often return additional
     *  drill-down IDs.
     *
     *  Also note that drill-down aggregation is only useful for a sparsely allocated set of integer
     *  values, for example enumerations, where the same value occurs in the ticket fields of several ticket.
     *  Useful candidates in the stock install are FIELD_TYPE and FIELD_STATUS, which have very few
     *  possible values.
     *
     *  Simple example. Assume that there are four tickets that are found by searching for "foo":
     *  one Wiki article and three tasks. The aggregation would then return:
     *
     *   [ FIELD_TYPE => [ TYPE_WIKI => 1, TYPE_TASK => 3 ] ].
     *
     *  Since we only aggregated for FIELD_TYPE, the top-level array has only one entry, with two
     *  "buckets" of values in it.
     *
     *  Assume that in addition to FIELD_TYPE, we also aggregate for FIELD_STATUS. Since only task
     *  tickets have a status field (where as wiki articles don't), the one wiki ticket doesn't
     *  get counted at all. Assume further that of the three found tasks one is open and two are
     *  closed, the total aggregation would then be:
     *
     *  [ FIELD_TYPE => [ TYPE_WIKI => 1, TYPE_TASK => 3 ],
     *    FIELD_STATUS => [ STATUS_OPEN => 1, STATUS_CLOSED => 2 ]
     *  ]
     */

    /**
     *  Legacy code that gets statistics the FindMany() results set via SQL. At least with PostgreSQL,
     *  this is very slow so this only gets called if a search plugin is not available.
     *
     *  See \ref drill_down_filters for an introduction.
     *
     *  This must return a FindResults instance with the $cTotal, $aTypes, $aDrillDownCounts fields
     *  set, but the $aTickets array left to NULL, which the caller will then fill.
     *  If the cTickets field therein is also set to NULL, then the caller will do an SQL COUNT on the
     *  results set, which is also fairly slow (0.2 seconds for 600K tickets).
     *
     * @return SearchResults
     */
    private static function DrillDownSQL($strWhereInput,
                                         $joinSearchScores,
                                         $aActiveFilters,
                                         JoinsList $oJoinsList)
    {
        Debug::FuncEnter(Debug::FL_TICKETFIND, __METHOD__, "strWhereInput=\"$strWhereInput\"");

        # Now step 1): get the list of ticket types in use, and do the drill-down on ticket types.
        $aDrillDownCounts = [];                 # field ID (e.g. FIELD_TYPE) => [ field value => count, ... ]

        $strWhereThis = $strWhereInput;
        $oJoinsList2 = clone $oJoinsList;
        self::BuildWhereForFilters($aActiveFilters,
                                   TicketField::Find(FIELD_TYPE),
                                   $strWhereThis,
                                   $oJoinsList2);

        $strLeftJoinThis = $oJoinsList2->toString();
        $res = Database::DefaultExec(<<<SQL
-- Ticket::FindMany() prepare drill-down for ticket types
SELECT
    tickets.type_id,
    COUNT(tickets.i) AS c
FROM tickets
JOIN ticket_types ON ticket_types.i = tickets.type_id$strLeftJoinThis$joinSearchScores
WHERE $strWhereThis
GROUP BY type_id;
SQL
                                        );

        # Instantiate the types found.
        Globals::Profile("FindMany() before instantiating types");
        $aCountsTemp = [];
        while ($row = Database::GetDefault()->fetchNextRow($res))
            $aCountsTemp[$row['type_id']] = $row['c'];
        $aTypes = TicketType::GetAll(array_keys($aCountsTemp));
        $aDrillDownCounts[FIELD_TYPE] = $aCountsTemp;

        # Drill-down staticstics desired?
        if ($aActiveFilters !== NULL)
        {
            # Find out which fields are visible so we can ignore invisible fields in drill-down.
            $aVisibleFields = [];
            foreach ($aTypes as $type_id => $oType)
            {
                $aFields = $oType->getVisibleFields(TicketType::FL_INCLUDE_CHILDREN | TicketType::FL_FOR_DETAILS);

                foreach ($aFields as $field_id => $oField)
                    $aVisibleFields[$field_id] = 1;
            }

            # And count the different values of the field IDs the caller has given us to prepare drill-down.
            foreach (TicketField::GetDrillDownIDs() as $idFieldDrillDown)
            {
                if (    (isset($aVisibleFields[$idFieldDrillDown]))             # Do this only for fields that are actually used by the types in the result set.
                     && ($oField = TicketField::Find($idFieldDrillDown))
                   )
                {
                    $fieldname = $oField->name;
                    Debug::Log(Debug::FL_TICKETFIND, "Preparing drill-down for field ID $idFieldDrillDown ($fieldname)");

                    $strWhereThis = $strWhereInput;
                    $oJoinsList2 = clone $oJoinsList;
                    self::BuildWhereForFilters($aActiveFilters,
                                               $oField,
                                               $strWhereThis,
                                               $oJoinsList2);

                    $strLeftJoinThis = $oJoinsList2->toString();

//                    Debug::Log(Debug::FL_DRILLDOWN, "strWhereThis = $strWhereThis");
//                    Debug::Log(Debug::FL_DRILLDOWN, "strLeftJoinThis = $strLeftJoinThis");

                    $tblname = $oField->tblname;
                    $tblAlias = "tbl_$fieldname";
                    $res = Database::DefaultExec(<<<SQL
-- Ticket::FindMany() prepare drill-down for field "$fieldname"
SELECT
    $tblAlias.value,
    COUNT(tickets.i) AS c
FROM $tblname $tblAlias$strLeftJoinThis
JOIN tickets ON ($tblAlias.ticket_id = tickets.i) AND $strWhereThis$joinSearchScores
WHERE ($tblAlias.field_id = $idFieldDrillDown)
GROUP BY $tblAlias.value;
SQL
                                                    );
                    $aCountsTemp = [];
                    while ($row = Database::GetDefault()->fetchNextRow($res))
                        $aCountsTemp[$row['value']] = $row['c'];
                    $aDrillDownCounts[$idFieldDrillDown] = $aCountsTemp;
                }
            }
        }

        Debug::FuncLeave();

        return new SearchResults(NULL,                   # Set cTickets to NULL so that caller will do the SQL afterwards.
                               NULL,
                               $aTypes,
                               $aDrillDownCounts);
    }

    /**
     *  Returns Ticket objects for the tickets that match the given search filters as a
     *  FindResults instance, or NULL if no tickets match the filters.
     *
     *  If no filters are given, this returns truly all tickets from the database, including
     *  those that the current user should not see. That is rarely useful. Instead, specify
     *  an array of SearchFilter instances with $aHardFilters; see that class for the many
     *  useful options available, including fulltext queries.
     *
     *  We call these "hard" filters because they are not supposed to be manipulated by
     *  the user, as opposed to the drill-down filters, which could be selected or disabled
     *  for further narrowing a search (see below). For example, when a user searches for
     *  "foo" in Doreen's search bar, this method gets called indirectly through
     *  \ref Access::findByFulltextQuery() with a "hard" SearchFilter instance of the
     *  fulltext type, yielding the tickets that match the fulltext query. The user can then
     *  filter the search results, e.g. by ticket type, by running the method again with
     *  additional drill-down filters.
     *
     *  This is the one function that wakes up Ticket objects from the database. Many other
     *  specialized "find" functions (which you may find more convenient) call this in turn:
     *
     *   -- \ref Ticket::FindManyByID()
     *
     *   -- \ref Ticket::FindOne()
     *
     *   -- \ref Ticket::FindForUser()
     *
     *   -- \ref Access::findTickets()
     *
     *   -- \ref Access::findTemplates()
     *
     *   -- \ref Access::findByFulltextQuery()
     *
     *  This does NOT check access permissions. When displaying data to the user, use the
     *  aforementioned Access methods, which call this function with appropriate filters.
     *
     *  The following optional features are supported:
     *
     *   -- The $sortby argument accepts a field name, possibly prefixed with a "!" character for
     *      descending sort. $sortby is converted here into an appropriate ORDER BY SQL clause.
     *      Any name for a ticket field that has FIELDFL_SORTABLE set is accepted, and the
     *      corresponding data fields will be pulled in automatically. Four special values are
     *      accepted: 'id', 'created', 'template' (for ordering by template name alphabetically,
     *      e.g. for the "New" submenu) and 'score' (for sorting full-text search results, see below).
     *
     *      The array in the returned FindResults instance is an ordered map: even though it has
     *      ticket_id => object pairs, the pairs do come in the order specified by the $sortby
     *      argument, if any. If you iterate over the result in a foreach loop, the sort order
     *      will be correct.
     *
     *   -- If $page is not NULL, this reduces the result set to the given page window for a much
     *      smaller SQL result set, which also greatly speeds up the resulting PHP processing. For
     *      that, $cPerPage (which defaults to Globals::$cPerPage = 20) is used, and only that many
     *      Ticket instances will actually be instantiated. Even if $page is given, FindResult->$cTotal
     *      will always contain the total no. of tickets beyond the current page so the caller can
     *      display pagination correctly (i.e. "page 20 out of 123").
     *
     *   -- Full-text queries are supported if you use the SearchFilter::Fulltext() variant. Use
     *      Access::findByFulltextQuery() for an easier interface, which will call this in turn
     *      and take access permissions into account. If a search plugin is configured, that will
     *      be used, otherwise \ref FulltextSQL() gets called for a dumb SQL search.
     *
     *   -- Drilling down via filters is also supported if $aDrillDownFilters != NULL (see below).
     *
     *  If nothing was found, NULL is returned. This should only throw in exceptional circumatances
     *  with invalid ticket data from the database, although this should normally be prevented by
     *  the use of foreign IDs therein.
     *
     *  Regarding drill-down filtering and aggregations, see \ref drill_down_filters for an introduction.
     *  In terms of arguments for this function, this translates into three options:
     *
     *   1) If $aDrillDownFilters is NULL, this performs no aggregations (the default), which can
     *      speed up the search dramatically, especially when no search plugin is available.
     *
     *   2) If $aDrillDownFilters is not NULL, however, the search results are examined with all the
     *      field IDs that should have it. Plug-ins can add field IDs to that array, and
     *      this function will do aggregations (counts) automatically, i.e. this function will
     *      automatically count how many tickets in the whole result set exist for every existing
     *      value in that field, which allows the GUI to display Amazon-like filter checkboxes to
     *      narrow down the search result by a value of a particular ticket field. This is only
     *      supported for enumeration-like INTEGER fields in ticket_ints, or else things would slow
     *      down terribly.
     *
     *   3) If $aDrillDownFilters is an array of field_id => [ value, value, ... ] pairs, this
     *      represents ACTIVE filters on top of (2) and will narrow the search and adjust the
     *      aggregations accordingly. This is what happens after the user has clicked on filters
     *      in the ticket results lists.
     *
     * @return FindResults | NULL
     */
    public static function FindMany($aHardFilters = NULL,           //!< in: array of SearchFilter instances or NULL
                                    $sortby = NULL,                 //!< in: field name to sortby, optionally with '!' prefix, or NULL
                                    $page = NULL,                   //!< in: page window to display (starting with 1!) or entire result set if NULL
                                    $cPerPage = NULL,
                                    $aDrillDownFilters = NULL)     //!< in: NULL (disable drill-down) or empty array or array of field_id => [ value, ... ] pairs
    {
        Debug::FuncEnter(Debug::FL_TICKETFIND, __METHOD__.'()');
        Globals::Profile("Entering FindMany(page=$page)");

        if (    ($aHardFilters !== NULL)
             && (!is_array($aHardFilters))
           )
            throw new DrnException('$aHardFilters must be either NULL or an array of SearchFilter instances');

        if (!$cPerPage)
            $cPerPage = Globals::$cPerPage;

        $stageOne = <<<EOD
-- Ticket::FindMany() stage-1 query
SELECT
    tickets.i,
    tickets.template,
    tickets.type_id,
    tickets.project_id,
    tickets.aid,
    tickets.owner_uid,
    tickets.created_dt,
    tickets.lastmod_uid,
    tickets.lastmod_dt,
    tickets.created_from
EOD;

        /*
         *  We do several queries on the same set of tickets:
         *
         *   1)  To get all the types that are involved, so we can instantiate the TicketType objets;
         *       we do this in SQL instead of going over the whole ticket set in PHP because it is
         *       about 10 times faster for many tickets;
         *
         *   2)  to actually get the core ticket data of the set, typically as a page of 20 tickets
         *       into the whole set;
         *
         *   3)  to do aggregations on the set, like which types are used how often, to prepare the
         *       GUI for drill down.
         *
         *  If a qualified search plugin is installed, only 2) is actually ran on the main database.
         */

        $fr = NULL;
        $aHighlights = [];                                              // receives terms to highlight as $term => 1

        $aFilterTypes = array_column($aHardFilters ?? [], 'type');

        if (    (    ($aDrillDownFilters !== NULL)
                  || in_array(SearchFilter::TYPE_FULLTEXT, $aFilterTypes))
             && ($sortby !== SearchOrder::TEMPLATE_NAME)
             && (!in_array(SearchFilter::TYPE_TEMPLATES, $aFilterTypes)) // templates are not indexed
             && (!in_array(SearchFilter::TYPE_FIELDVALUELIST, $aFilterTypes)) // not all fields are indexed
             && (!in_array(SearchFilter::TYPE_FIELDVALUERANGE, $aFilterTypes)) // not all fields are indexed
             && ($oSearch = Plugins::GetSearchInstance())
           )
        {
            Globals::Profile("FindMany() before aggregations (drill-down requested)");
            $aSort = [];
            if ($sortby)
                $aSort[] = SearchOrder::FromParam($sortby);

            //TODO ES will only search up to 10000 items. Could bumpt that to a 20000 max by inverting sort and paginating from the back.
            if (    $page
                 && $aHardFilters
                 && !in_array(SearchFilter::TYPE_LIMIT, $aFilterTypes)
               )
                $aHardFilters[] = SearchFilter::Limit($cPerPage);

            $fr = $oSearch->search($aHardFilters ?? [],
                                   $aSort,
                                   $aDrillDownFilters,
                                   $page);

            unset($aSort);

            if (!$fr || !$fr->cTotal || !count($fr->aTicketIDs))
                return NULL;

            // Make table to left join the ticket data into, so we can preserve
            // the search result order.
            Database::DefaultExec(<<<SQL
CREATE TEMPORARY TABLE search_results
(   ticket_id INT,
    position INT
);
SQL
            );

            $aValues = [];
            foreach ($fr->aTicketIDs as $i => $id)
            {
                $aValues[] = $id;
                $aValues[] = $i;
            }

            Database::GetDefault()->insertMany('search_results',
                                               [ 'ticket_id', 'position' ],
                                               $aValues);
            unset($aValues);
            unset($fr->aTicketIDs);

            $stageOne .= " FROM search_results LEFT JOIN tickets ON tickets.i = search_results.ticket_id ORDER BY search_results.position ASC";
        }
        else
        {
            $aWheres = [];
            $oJoinsList = new JoinsList();
            $strFulltextForSQL = NULL;
            $fResultsPossibleAtAll = TRUE;

            $joinSearchScores = NULL;
            /** @var SearchFilter[] $aHardFilters */
            foreach ($aHardFilters as $oFilter)
                if ($filter = $oFilter->makeSQL($oJoinsList,
                                                $strFulltextForSQL,
                                                $fResultsPossibleAtAll))
                    $aWheres[] = $filter;

            if (!$fResultsPossibleAtAll)
                return NULL;

            $strWhereInput = (count($aWheres)) ? '('.implode(') AND (', $aWheres).')' : '';
            Debug::Log(Debug::FL_TICKETFIND, "strWhereInput = $strWhereInput");

            /* If the user wants to perform full-text search and we have no full-text search engine, go legacy. */
            if ($strFulltextForSQL)
            {
                self::FulltextSQL($strFulltextForSQL,
                                  $strWhereInput);      // modified!
                foreach (preg_split('/\s+/', $strFulltextForSQL) as $word)
                    $aHighlights[$word] = 1;
            }

            // Only generate slow drilldowns if they're actually requested.
            if ($aDrillDownFilters !== NULL)
            {
                Debug::Log(Debug::FL_TICKETFIND, 'Generating inefficient drill down counts');
                $fr = self::DrillDownSQL($strWhereInput,
                                         $joinSearchScores,
                                         $aDrillDownFilters,
                                         $oJoinsList);
            }
            else
                $fr = new FindResults(NULL, NULL, NULL, NULL);

            if (!$fr)
                return NULL;

            Globals::Profile("FindMany() after aggregations");
            Debug::Log(Debug::FL_DRILLDETAILS, "Drill-downs: ".print_r($fr->aDrillDownCounts, TRUE));

            $strWhereThis = $strWhereInput;
            self::BuildWhereForFilters($aDrillDownFilters,
                                       NULL,
                                       $strWhereThis,
                                       $oJoinsList);
            $strLeftJoinThis = $oJoinsList->toString();

            $limitoffset = '';
            if ($page)
            {
                if ($fr->cTotal === NULL)
                {
                    Globals::Profile("FindMany() before counting entire result set");
                    # If the caller wants pagination (i.e. only a limited window into the result set), we
                    # need to do an extra query to get the no. of tickets of the WHOLE results set first.
                    # We only need the raw number so we can omit ORDER BY here for speed.
                    # In most cases we could have gotten the number from the ticket type drill-down above,
                    # but that breaks down if other filters are active, so let's just do it here.
                    $res = Database::DefaultExec(<<<SQL
-- Ticket::FindMany() count query
SELECT
COUNT(tickets.i) AS c
FROM tickets$strLeftJoinThis$joinSearchScores
WHERE $strWhereThis
SQL
                                                    );
                    $row = Database::GetDefault()->fetchNextRow($res);
                    $fr->cTotal = $row['c'];
                }

                # Add LIMIT and OFFSET to the query.
                $limitoffset = " LIMIT $cPerPage OFFSET ".(($page - 1) * $cPerPage);
            }

            # Finally, for the actual ticket data, we need to apply LIMIT and OFFSET for pagination,
            # and for that to work, the result set needs to be sorted. So NOW figure out the sort order.
            $sqlOrderBy = NULL;
            if ($sortby)
            {
                switch ($sortby2 = self::ParseSortBy($sortby, $fAscending))
                {
                    case 'id':
                        $sqlOrderBy = 'tickets.i';
                    break;

                    case 'created':
                        $sqlOrderBy = 'created_dt';
                    break;

                    case 'changed':
                        $sqlOrderBy = 'lastmod_dt';
                    break;

                    case 'template':
                        $sqlOrderBy = 'template';
                    break;

                    case 'score':
                        // Silently swallow Postgres SQL full text queries, we don't support ranking them yet.
                    break;

                    default:
                        if (    ($oField = TicketField::FindByName($sortby2))
                             && ($oField->fl & FIELDFL_SORTABLE)
                           )
                        {
                            $oLJ = new LeftJoin($oField);
                            $oJoinsList->add($oLJ);
            //                        $strLeftJoinThis .= "\nLEFT JOIN $tblname ON ($tblname.ticket_id = tickets.i AND $tblname.field_id = $field_id)";
                            $sqlOrderBy = "(CASE WHEN $oLJ->tblAlias.value IS NULL THEN 1 ELSE 0 END), $oLJ->tblAlias.value";
                                    # PostgreSQL also supports NULLS LAST but MySQL doesn't so we'll use the above.
                        }
                        else
                            throw new DrnException("Invalid 'sortby' criterion \"$sortby\" specified.");
                    break;
                }

                if (!$sqlOrderBy)
                    $sqlOrderBy = "\nORDER BY tickets.i DESC";
                else
                {
                    $sqlOrderBy = "\nORDER BY $sqlOrderBy";

                    if (!$fAscending)
                        $sqlOrderBy .= " DESC";

                    # Sort order must be deterministic, so add tickets.i as an auxiliary criterion.
                    if ($sortby2 != 'id')
                        $sqlOrderBy .= ", tickets.i";
                }
            }

            # Left joins may have changed by orderby above, so rebuild.
            $strLeftJoinThis = $oJoinsList->toString();

            #
            # BUILD THE STAGE-1 QUERY WITH ORDER BY AND LIMIT AND OFFSET!
            #
            $stageOne .= " FROM tickets".$strLeftJoinThis.$joinSearchScores."\n WHERE ".$strWhereThis.$sqlOrderBy.$limitoffset;
        }
        if (!$fr)
            return NULL;
        /** @var SearchResults $fr */

        unset($aFilterTypes);

        Globals::Profile("FindMany() before making objects");

        $aReturn = [];
        $c = 0;

        Globals::Profile("FindMany() before stage-1 query");

        $res = Database::DefaultExec($stageOne);

        while ($row = Database::GetDefault()->fetchNextRow($res))
        {
            $idTicket = $row['i'];
            $type_id = $row['type_id'];
            if (!($oType = TicketType::Find($type_id)))     # should have been instantiated by ES but if not we'll give it a second chance here
                throw new DrnException("Error instantiating ticket $idTicket: invalid ticket type $type_id");

            $oTicket = self::MakeAwakeOnce($idTicket,
                                           $row['template'],
                                           $oType,
                                           $row['project_id'],
                                           $row['aid'],
                                           $row['owner_uid'],
                                           $row['created_dt'],
                                           $row['lastmod_uid'],
                                           $row['lastmod_dt'],
                                           $row['created_from']);

            # If we had search scores, add them to the tickets here and now.
            if ($fr instanceof SearchResults)
            {
                if ($fr->aScores && count($fr->aScores) && array_key_exists($idTicket, $fr->aScores))
                    $oTicket->score = $fr->aScores[$idTicket];
                if ($fr->aBinaries && count($fr->aBinaries))
                    $oTicket->aBinariesFound = $fr->aBinaries[$idTicket];
                if ($fr->aHighlights && $highlights = getArrayItem($fr->aHighlights, $idTicket))
                {
                    foreach($highlights as $highlight)
                        $aHighlights[$highlight] = 1;
                }
            }

            # This appends a new pair to the end of the ordered map.
            $aReturn[$idTicket] = $oTicket;

            ++$c;
        }

        if (!$page)
            $fr->cTotal = $c;     # then it wasn't set above

        Globals::Profile("Made objects, leaving FindMany(): cTotal=$c");
        Debug::FuncLeave();

        if (count($aReturn))
        {
            $fr->aTickets = $aReturn;
            $fr->llHighlights = array_keys($aHighlights);

            /* Sort these by length in descending order. This makes sures that partial matches
               are not highlighted when a longer match is also found. */
            uasort($fr->llHighlights, function($a, $b)
            {
                $lenA = strlen($a);
                $lenB = strlen($b);
                if ($lenA > $lenB)
                    return -1;
                if ($lenA < $lenB)
                    return +1;
                return 0;
            });

            if ($fr instanceof SearchResults)
            {
                unset($fr->aHighlights);
                unset($fr->aScores);
                unset($fr->aBinaries);
            }

            return $fr;
        }

        return NULL;
    }

    /**
     *  Returns Ticket objects for the tickets with the given IDs as a FindResults instance,
     *  or NULL if no such tickets are found.
     *
     *  The IDs in the given list MUST be numeric, or this will throw.
     *
     *  This is a convenience wrapper around \ref FindMany() with a simple cache so that we only hit
     *  the database for ticket IDs that have not yet been loaded. So if all the given tickets
     *  are already awake, this does not hit the database and has very little overhead.
     *
     *  Like \ref FindMany(), this does NOT check access permissions. When displaying data to the user,
     *  use Access to first determine which tickets the user may see, and then retrieve those
     *  tickets only.
     *
     *  If $populate != POPULATE_NONE, we'll call \ref PopulateMany() on the result set for convenience.
     *  Obviously this should only be used with SMALL result sets if you like your server.
     *
     * @return FindResults|null
     * @throws DrnException
     */
    public static function FindManyByID($llIDs,                     //!< in: flat array (list) of ticket IDs
                                        $populate = self::POPULATE_NONE)
    {
        $rc = NULL;

        if (is_array($llIDs) && count($llIDs))
        {
            $findResults = new FindResults(0, NULL, NULL, NULL);

            $aNotAwake = [];
            foreach ($llIDs as $id)
            {
                if (!isInteger($id))
                    throw new InvalidTicketIDException($id);
                else if ($oTicket = getArrayItem(Ticket::$aAwakened, $id))
                    # Ticket is already awake:
                    $findResults->add($oTicket);
                else
                    # Ticket needs to be awoken from database:
                    $aNotAwake[] = $id;
            }

            Debug::Log(Debug::FL_AWAKETICKETS, __CLASS__.'::'.__FUNCTION__.": ".count($findResults->aTickets ?? [])." tickets already awake, ".count($aNotAwake)." tickets need to be fetched.");

            if (count($aNotAwake))
            {
                Debug::FuncEnter(Debug::FL_TICKETFIND, __METHOD__.'('.implode(',', $llIDs).')');

                /*
                 *  FindMany() call!
                 */
                if ($findResults2 = self::FindMany([ SearchFilter::FromTicketIDs($llIDs) ]))
                    foreach ($findResults2->aTickets as $ticket_id => $oTicket)
                        $findResults->add($oTicket);

                Debug::FuncLeave();
            }

            if ($findResults->aTickets && count($findResults->aTickets))
            {
                if ($populate != self::POPULATE_NONE)
                    self::PopulateMany($findResults->aTickets,
                                       $populate);

                $rc = $findResults;
            }
        }

        return $rc;
    }

    /**
     *  An even more convenient wrapper around FindManyByID(). Returns one Ticket instance or NULL.
     *
     *  Like \ref FindMany() this does NOT check access permissions. Use \ref Ticket::FindForUser() instead.
     *
     * @return Ticket|null
     */
    public static function FindOne(int $id,     //!< in: ticket ID
                                   $populate = self::POPULATE_NONE)
    {
        if ($findResults = self::FindManyByID( [$id],
                                               $populate))
            return $findResults->aTickets[$id];

        return NULL;
    }

    /**
     *  Attempts to find the Ticket object for the given ticket ID and optionally checks if the given user
     *  has the given permissions. Returns a single Ticket instance.
     *
     *  If $oUser is NULL and the ticket was not found, this returns NULL also.
     *
     *  If $oUser is not NULL and the ticket was not found OR if the user does not have sufficient
     *  access permissions, this throws a human-readable error message. So, unlike FindMany, this
     *  DOES check access permissions by automatically calling Ticket::getUserAccess() with the given
     *  user and ACCESS_* flags for convencience.
     */
    public static function FindForUser(int $id,
                                       User $oUser,              //!< in: user instance to check permissions for or NULL
                                       $flRequired = 0,          //!< in: ORed ACCESS_* permission flags
                                       $fPopulate = FALSE)
    {
        $oTicket = self::FindOne($id);

        if (    ($oUser)
             && (    (!$oTicket)
                  || (($oTicket->getUserAccess($oUser) & $flRequired) != $flRequired)
                )
           )
            throw new BadTicketIDException($id);

        if ($fPopulate)
            $oTicket->populate(TRUE);

        return $oTicket;
    }

    /**
     *  Looks up the \ref ticket_uuids table for the given UUID (which must be in "long lower"
     *  format and returns its ticket ID, or zero if none was found.
     *
     *  This disregards the field ID in the tickets table, assuming that every UUID will
     *  only be in the table once regardless of field ID.
     *
     *  Does not check access permissions.
     */
    public static function FindByUUID($uuid)
        : int
    {
        # Test ticket_id for NOT NULL because only those rows are current (others are for changelog).
        if ($res = Database::DefaultExec(<<<SQL
SELECT ticket_id
  FROM ticket_uuids
 WHERE value = $1 AND ticket_id IS NOT NULL
SQL
            , [ $uuid ]
           ))
        {
            if ($row = Database::GetDefault()->fetchNextRow($res))
            {
                if ($idTicket = $row['ticket_id'] ?? NULL)
                    return $idTicket;
            }
        }

        return 0;
    }

    private static $aTicketIDsByImportID = [];

    /**
     *  Finds the tickets with FIELD_IMPORTEDFROM matching the given list of import IDs.
     *
     *  Returns an array of import ID  => ticket ID pairs, no objects. Does not fail if a ticket
     *  was not found. Returns NULL if nothing was found at all.
     */
    public static function FindManyTicketIDsByImportID($llIDs)
    {
        $aReturn = [];
        if ($llIDs && count($llIDs))
        {
            $llNeedFetching = [];

            foreach ($llIDs as $id)
                if ($ticket_id = getArrayItem(self::$aTicketIDsByImportID, $id))
                    $aReturn[$id] = $ticket_id;
                else
                    $llNeedFetching[] = $id;

            if (count($llNeedFetching))
            {
                $ids = Database::MakeInIntList($llNeedFetching);
                # Test ticket_id for NOT NULL because only those rows are current (others are for changelog).
                $res = Database::DefaultExec(<<<SQL
SELECT ticket_id, value
  FROM ticket_ints
 WHERE field_id = $1 AND value IN ($ids) AND ticket_id IS NOT NULL
SQL
              , [ FIELD_IMPORTEDFROM ]);

                while ($row = Database::GetDefault()->fetchNextRow($res))
                {
                    $ticket_id = $row['ticket_id'];
                    $id = $row['value'];
                    $aReturn[$id] = $ticket_id;

                    # And add to cache so this pair never gets fetched again.
                    self::$aTicketIDsByImportID[$id] = $ticket_id;
                }
            }
        }

        return count($aReturn) ? $aReturn : NULL;
    }

    /**
     *  Finds the ticket with FIELD_IMPORTEDFROM matching the given $importID, which is returned as
     *  an object. If not found, NULL is returned.
     */
    public static function FindTicketIDByImportID($importID)
    {
        if (    ($a = self::FindManyTicketIDsByImportID([ $importID ] ))
             && ($idTicket = getArrayItem($a, $importID))
           )
            return $idTicket;

        return NULL;
    }

    /**
     *  Finds the ticket with FIELD_IMPORTEDFROM matching the given $importID, which is returned as
     *  an object. If not found, NULL is returned.
     */
    public static function FindTicketByImportID($importID)
    {
        if ($idTicket = self::FindTicketIDByImportID($importID))
            return self::FindOne($idTicket);

        return NULL;
    }

    /**
     *  Returns the ID of the wiki ticket with the given title, or NULL if not found. This only
     *  searches tickets of the "Wiki" ticket type.
     *
     * @return int|null
     */
    public static function FindTicketIdByWikiTitle(string $strTitle)
    {
        # Test ticket_id for NOT NULL because only those rows are current (others are for changelog).
        $res = Database::DefaultExec(<<<SQL
SELECT ticket_id
  FROM ticket_texts
  JOIN tickets ON tickets.i = ticket_texts.ticket_id
 WHERE ticket_texts.ticket_id IS NOT NULL
   AND tickets.type_id = $1
   AND ticket_texts.field_id = $2
   AND ticket_texts.value = $3;
SQL
            , [ GlobalConfig::Get(GlobalConfig::KEY_ID_TYPE_WIKI),
                FIELD_TITLE,
                $strTitle ]);

        while ($row = Database::GetDefault()->fetchNextRow($res))
            return (int)$row['ticket_id'];

        return NULL;
    }

    /**
     *  Attempts to find a specific template, or throws an exception if no such ticket
     *  exists, or the given ID does not refer to a ticket template (but a regular ticket).
     */
    public static function FindTemplateOrThrow(int $idTemplate)
    {
        if ($oTicket = self::FindOne($idTemplate))
            if ($oTicket->template)
                return $oTicket;

        throw new DrnException(L('{{L//Invalid ticket template ID %ID%}}', [ '%ID%' => Format::UTF8Quote($idTemplate) ]));
    }

    /**
     *  Wrapper around \ref FindTemplateOrThrow() that attempts to find the numeric template ID
     *  in GlobalConfig first. Throws if either the key or the template does not exsist.
     */
    public static function FindTemplateFromConfigKeyOrThrow(string $key)
    {
        if (!($idTemplate = GlobalConfig::Get($key)))
            throw new DrnException("Cannot find template ID under ".Format::UTF8Quote($key)." config key");
        return Ticket::FindTemplateOrThrow($idTemplate);
    }

    /**
     *  Returns an array of Ticket objects (in ticket_id => object format) for all
     *  templates on the system.
     *
     *  This is a convenience wrapper around FindMany(). Like FindMany(), this does NOT
     *  check access permissions. Unlike FindMany(), this returns a ticket array or
     *  NULL, not a FindResults instance.
     *
     *  @return Ticket[]|null
     */
    public static function FindAllTemplates()
    {
        if ($findResults = self::FindMany([ SearchFilter::Templates() ]))
            return $findResults->aTickets;
        return NULL;
    }

    /**
     *  Collects usage data for the given array of ticket ID => template object pairs.
     *
     *  Usage data means that for every template ticket object, this looks up in the
     *  database how many tickets were created from it. This count is stored in each
     *  objects 'cUsage' member, which is normally to NULL until this function is
     *  called.
     */
    public static function GetTemplateUsage($aTemplates)
    {
        # We don't store the template ID in tickets created from that template, but when a
        # ticket gets created from a template, it inherits its type + AID, so we can look
        # for those.
        $res = Database::DefaultExec(<<<EOD
SELECT
    type_id,
    aid,
    COUNT(tickets.i) AS c
FROM tickets
JOIN ticket_types ON type_id = ticket_types.i
GROUP BY type_id, ticket_types.name, aid;
EOD
                          );
        # Make a has of type_id/aid pairs.
        $aUsage = [];
        while ($row = Database::GetDefault()->fetchNextRow($res))
        {
            $type_id = $row['type_id'];
            $aid = $row['aid'];
            $aUsage["$type_id-$aid"] = $row['c'];
        }

        /** @var Ticket[] $aTemplates */
        foreach ($aTemplates as $id => $oTemplate)
        {
            $type_id = $oTemplate->oType->id;
            $aid = $oTemplate->aid;
            if (!($c = getArrayItem($aUsage, "$type_id-$aid")))
                $c = 0;
            $oTemplate->cUsage = $c;
        }
    }

    /**
     *  Returns a PHP array with key/value pairs to be returned as JSON. Part of
     *  the implementation for the GET /all-templates REST API.
     *
     *  Does not check access permissions!
     */
    public static function FindAllTemplatesForJson()
    {
        $aTemplates = self::FindAllTemplates();
        self::GetTemplateUsage($aTemplates);

        $aReturn = [];
        foreach ($aTemplates as $ticket_id => $oTicket)
            $aReturn[] = $oTicket->toArrayForTemplate();

        return $aReturn;
    }

    /**
     *  Fetches the stage-2 data for the tickets in the given array.
     *  Returns nothing, but after calling this, the Ticket objects in
     *  the array are fully populated.
     *
     *  To avoid hitting the database and server memory too hard during
     *  ticket searches, instantiating tickets is a two-stage process:
     *
     *   1) The Ticket constructor only fills the "stage 1" fields, which
     *      are those common to all tickets regardless of type and plugin.
     *      These are ID, template ID, oType (the instantiated TicketType
     *      object), AID plus UID and timestamp of creation and last update.
     *
     *   2) The more voluminous data fields such as title, description and
     *      whatever plugins implement are only loaded on demand, by
     *      Ticket::PopulateMany().
     *
     *  This allows for faster searching. When tickets are returned from a
     *  search result, they are normally only in stage 1. This allows for
     *  retrieving *all* tickets that match a search query, determining the
     *  subset that should be seen by the user (e.g. "page 5 out of 200"),
     *  and loading the full data only for that subset.
     *
     *  This is a (static) class method, accepting an array of ticket objects,
     *  instead of an instance method, so that the data can be fetched in
     *  one database round-trip instead of many.
     */
    public static function PopulateMany($aTickets0,                         //!< in: tickets to fetch data for as ticket_id => object pairs
                                        $populate = self::POPULATE_LIST)    // or POPULATE_DETAILS
    {
        # Nothing in, nothing to do.
        if (    (!$aTickets0)
             || !(count($aTickets0))
           )
            return;

        # Now make a list of tickets that actually have work to do. There may
        # be tickets on the list that already have their details fetched.
        /** @var Ticket[] $aTickets */
        $aTickets = [];
        $aTypesUsed = [];
        /** @var Ticket[] $aTickets0 */
        foreach ($aTickets0 as $ticket_id => $oTicket)
        {
            Debug::Log(Debug::FL_AWAKETICKETS, "Testing #$ticket_id");

            if (!$oTicket->fDeleted)
            {
                if (    (!$oTicket->fStage2ListFetched)
                     || (    ($populate == self::POPULATE_DETAILS)
                          && (!$oTicket->fStage2DetailsFetched)
                        )
                   )
                {
                    Debug::Log(Debug::FL_AWAKETICKETS, "Adding #$ticket_id to list");
                    $aTickets[$ticket_id] = $oTicket;
                    # Also build a list of types that are in use.
                    $aTypesUsed[$oTicket->oType->id] = $oTicket->oType;
                }
            }
        }

        if (!count($aTickets))
            return;

        Debug::Log(Debug::FL_AWAKETICKETS, "PopulateMany for ".print_r(array_keys($aTickets), TRUE));

        # Get the visible fields for these tickets.
        $fl = TicketType::FL_INCLUDE_CHILDREN | TicketType::FL_INCLUDE_HIDDEN;          # we need the data
        if ($populate == self::POPULATE_DETAILS)
            $fl |= TicketType::FL_FOR_DETAILS;
        $aVisibleFields = TicketType::GetManyVisibleFields($aTypesUsed,
                                                           $fl);

        # Make a "dbTickets" string as a comma-separated list of tickets.
        # This goes ino the WHERE clause of the query.
        $dbTickets = implode(',', array_keys($aTickets));

        # For every field that should be visible, add a LEFT JOIN clause and a column name
        # to the columns list of the SELECT statement. This way, if a field is not set for
        # a given ticket, the query does not fail, but returns an empty column. This can happen
        # easily when the input tickets have different types (and thus different details columns),
        # but also if the type of a ticket is retroactively changed somehow.
        $leftjoin = '';
        $columnNames = '';
        $aGroupBy = [ 'tickets.i' ];
        foreach ($aVisibleFields as $field_id => $oField)
        {
            # Consider only fields which have table relations; exclude changelogs etc.
            if (    $oField->tblname
                 && (!($oField->fl & (   FIELDFL_CHANGELOGONLY          // Nothing to do if the field never has data.
                                       | FIELDFL_MAPPED_FROM_PROJECT)   // Nothing to do if the field data is copied from tickets.project_id
                                     ))
               )
            {
                if ($oField->fl & FIELDFL_CUSTOM_SERIALIZATION)
                {
                    $oHandler = FieldHandler::Find($field_id);
                    $leftjoin .= $oHandler->makeFetchSql($columnNames, $aGroupBy);
                }
                else if ($oField->fl & FIELDFL_STD_DATA_OLD_NEW)
                {
                    $tableFrom = $oField->tblname;
                    $tableAlias = "tbl_".$oField->name;

                    $thisLeftJoin = "\nLEFT JOIN $tableFrom $tableAlias ON ($tableAlias.ticket_id = tickets.i AND $tableAlias.field_id = ".(int)$field_id.")";

                    if ($oField->fl & FIELDFL_WORDLIST)
                    {
                        # Wordlist magic: produce THREE group concats, with row IDs, keyword IDs, and actual string keywords, all comma-separated:
                        # 1) add the keyword_defs table to the left join already produced above
                        $thisLeftJoin .= "\n-- wordlist:";
                        $thisLeftJoin .= "\nLEFT JOIN keyword_defs words_$tableAlias ON (words_$tableAlias.i = tbl_keywords.value)";
                        # 2) make three GROUP CONCATs instead of just two as for the default case below
                        $groupby2 = "tbl_keywords.ticket_id";
                        $columnNames .= "\n-- wordlist:";
                        $columnNames .= "\n,    ".Database::GetDefault()->makeGroupConcat("words_$tableAlias.keyword", $groupby2)." AS {$oField->name}"
                                       .",\n    ".Database::GetDefault()->makeGroupConcat("$tableAlias.value", $groupby2)." AS {$oField->name}_wordids"
                                       .",\n    ".Database::GetDefault()->makeGroupConcat("$tableAlias.i", $groupby2)." AS {$oField->name}_rowid";
                        $aGroupBy[] = "$tableAlias.value";
                        $aGroupBy[] = "$tableAlias.i";
                        $aGroupBy[] = "words_$tableAlias.keyword";
                    }
                    else if ($oField->fl & FIELDFL_ARRAY)
                    {
                        # Array case: m:n relation between tickets and values. The leftjoin is the same, but in the SELECT, we need to
                        # GROUP_CONCAT the possibly multiple values from the left join per ticket into one result.
//                        $groupby2 = "$tableAlias.ticket_id";
//                        $columnNames .= ",\n    ".Database::GetDefault()->makeGroupConcat("$tableAlias.value", $groupby2)." AS {$oField->name}"
//                                       .",\n    ".Database::GetDefault()->makeGroupConcat("$tableAlias.i", $groupby2)." AS {$oField->name}_rowid";

                        # Use a sub-select. Much faster than window functions.
                        $thisLeftJoin = '';
                        $columnNames .= ",\n    ARRAY_TO_STRING(ARRAY(SELECT value FROM $tableFrom WHERE $tableFrom.field_id = $field_id AND $tableFrom.ticket_id = tickets.i), ',') AS $oField->name"
                                       .",\n    ARRAY_TO_STRING(ARRAY(SELECT i     FROM $tableFrom WHERE $tableFrom.field_id = $field_id AND $tableFrom.ticket_id = tickets.i), ',') AS {$oField->name}_rowid";

                        # Do NOT add the columns to GROUP BY since we use them in the aggregate function, or else we'll get multiple
                        # rows for the same ticket ID.
//                        $aGroupBy[] = "$tableAlias.value";
//                        $aGroupBy[] = "$tableAlias.i";
                    }
                    else if ($oField->fl & FIELDFL_ARRAY_REVERSE)
                    {
                        # Like FIELDFL_ARRAY before, but in $tableFom, reverse ticket_id and value. Works only with table_tickets, I guess.
                        $thisLeftJoin = "\nLEFT JOIN $tableFrom $tableAlias ON ($tableAlias.value = tickets.i AND $tableAlias.field_id = ".((int)$field_id - 1).")";
                        $columnNames .= ",\n    ".Database::GetDefault()->makeGroupConcat("$tableAlias.ticket_id")." AS {$oField->name}"
                                       .",\n    ".Database::GetDefault()->makeGroupConcat("$tableAlias.i")." AS {$oField->name}_rowid";
                    }
                    else
                    {
                        # Simple case: 1:0/1 relation between tickets and values. Just pull them from the table specified in 'tblname'.
                        $columnNames .= ",\n    -- $oField->name (simple field)";
                        $columnNames .= "\n    $tableAlias.value AS {$oField->name}";
                        $columnNames .= ",\n    $tableAlias.i AS {$oField->name}_rowid";

                        # Add the columns to GROUP BY since it's not consumed by an aggregate function.
                        $aGroupBy[] = "$tableAlias.value";
                        $aGroupBy[] = "$tableAlias.i";
                    }
                    $leftjoin .= $thisLeftJoin;
                }
            }
        }

        # Compose a database query to fetch that data.
        $groupby = implode(', ', $aGroupBy);
        $query = <<<SQL
-- PopulateMany() stage-2 query
SELECT
    tickets.i AS ticket_id$columnNames
FROM tickets$leftjoin
WHERE
    tickets.i IN ($dbTickets)
GROUP BY $groupby
SQL;

        # For a plain Wiki ticket, this yields something like:
        #
        #   SELECT
        #       tickets.i AS ticket_id,
        #       tbl_title.value AS title,
        #       tbl_description.value AS description,
        #       tbl_keywords.value AS keywords
        #       FROM tickets
        #       LEFT JOIN ticket_texts tbl_summary ON (tbl_title.ticket_id = tickets.i AND tbl_title.field_id = -1)
        #       LEFT JOIN ticket_texts tbl_description ON (tbl_description.ticket_id = tickets.i AND tbl_description.field_id = -2)
        #       LEFT JOIN ticket_keywords tbl_keywords ON (tbl_keywords.ticket_id = tickets.i AND tbl_keywords.field_id = -3)
        #       WHERE
        #           tickets.i IN (3)

        $res = Database::DefaultExec($query);

        while ($dbrow = Database::GetDefault()->fetchNextRow($res))
        {
            $ticket_id = $dbrow['ticket_id'];
            $oTicket = $aTickets[$ticket_id];

//             Debug::Log("  row ".print_r($dbrow, TRUE));

            foreach ($aVisibleFields as $field_id => $oField)
            {
                if ($oField->fl & FIELDFL_MAPPED_FROM_PROJECT)
                {
                    $oTicket->aFieldData[$field_id] = $oTicket->aFieldData[FIELD_PROJECT];
                    $oTicket->aFieldDataRowIDs[$field_id] = NULL;
                }
                else if ($oField->fl & FIELDFL_CUSTOM_SERIALIZATION)
                {
                    $oHandler = FieldHandler::Find($field_id);
                    $oHandler->decodeDatabaseRow($oTicket, $dbrow);
                }
                else if (    ($oField->tblname)
                          && (isset($dbrow[$oField->name]))
                          # Now this is tricky. We DO want a field data item for real rows in ticket_texts, which can have an empty ('') value,
                          # but we DON'T for 'wordlist' because group concats return '' for an empty wordlist (which doesn't have any table rows):
                          && (    (($value = $dbrow[$oField->name]) !== '')       # This magic is required for 'wordlist': group concat returns '' for an empty list
                               || (0 == ($oField->fl & FIELDFL_WORDLIST))
                             )
                        )
                {
                    if (!$value && ($oField->fl & FIELDFL_ARRAY))
                        $value = NULL;

                    $oTicket->aFieldData[$field_id]       = $value;
                    $oTicket->aFieldDataRowIDs[$field_id] = $dbrow[$oField->name.'_rowid'];
                    if ($oField->fl & FIELDFL_WORDLIST)
                        $oTicket->aFieldDataWordIDs[$field_id] = $dbrow[$oField->name.'_wordids'];
                }
            }

            $oTicket->fStage2ListFetched = TRUE;
            if ($populate == self::POPULATE_DETAILS)
                $oTicket->fStage2DetailsFetched = TRUE;

            Debug::Log(Debug::FL_AWAKETICKETS, "PopulateMany #$ticket_id: fStage2ListFetched=".$oTicket->fStage2ListFetched.", fStage2DetailsFetched=".$oTicket->fStage2DetailsFetched);

//            Debug::Log("PopulateMany #$ticket_id: ".print_r($oTicket->aFieldData, true));
//            Debug::Log("PopulateMany #$ticket_id: ".print_r($oTicket->aFieldDataRowIDs, true));
        }
    }

    /**
     *  Ticket data preloader to load data for many tickets more efficiently. This is used from both
     *  the ticket results list and from \ref Ticket::GetManyAsArray() to handler FieldHandler::prepareDisplay()
     *  more easily.
     *
     *  This first calls PopulateMany() for the given tickets array to load ticket stage-2 data
     *  for all tickets in one go. However, when accessing ticket data later, additional database
     *  hits may occur since many tickets have parents or children or additional data fields that
     *  need to be fetched. Instead of loading that data for every ticket individually, this function
     *  calls FieldHandler::prepareDisplay() for all field handlers involved.
     */
    public static function PreloadDisplay($aTickets,            //!< in: array of tickets to populate (e.g. from FindResults::$aTickets)
                                          $aVisibleFields,
                                          $populate = self::POPULATE_DETAILS)
    {
        Debug::FuncEnter(Debug::FL_TICKETDISPLAY, __METHOD__);
        Globals::Profile("Calling PopulateMany()");
        Ticket::PopulateMany($aTickets,
                             $populate);
        /** @var Ticket[] $aTickets */
        Globals::Profile("Returned from PopulateMany()");

        $aHandlersInvolved = [];
        /** @var TicketField[] $aVisibleFields */
        foreach ($aVisibleFields as $field_id => $oField)
            if (!($oField->fl & FIELDFL_STD_CORE))
            {
                if ($oField->fl & FIELDFL_SHOW_CUSTOM_DATA)
                    $aHandlersInvolved[$field_id] = 1;
                else
                    foreach ($aTickets as $ticket_id => $oTicket)
                        if (isset($oTicket->aFieldData[$field_id]))
                            if (FieldHandler::Find($field_id, FALSE))
                                $aHandlersInvolved[$field_id] = 1;
            }

        # As an additional step, allow field handlers to pre-fetch additional ticket
        # data, for example in order to display names of parent tickets in the table.
        Globals::Profile("Calling prepareDisplay() for field handlers (".count($aHandlersInvolved)." handlers involved)");
        $aFetchStage2DataFor = [];
        foreach ($aHandlersInvolved as $field_id => $dummy)
        {
            $oHandler = FieldHandler::Find($field_id);
            $oHandler->preloadDisplay($aTickets,
                                      $aFetchStage2DataFor);
        }
        Globals::Profile("Done calling prepareDisplay() for field handlers (".count($aHandlersInvolved)." handlers involved)");

        # Now load and pre-populate what the field handlers have given us.
        if (count($aFetchStage2DataFor))
        {
            Debug::FuncEnter(Debug::FL_TICKETDISPLAY, __METHOD__."(): populating tickets reported by fieldhandler preloadDisplay() calls");
            Ticket::FindManyByID(array_keys($aFetchStage2DataFor),
                                 $populate);
            Debug::FuncLeave();
        }

        Debug::FuncLeave();
    }

    /**
     *  Adjusts the 'filesize' and 'filename' items in the given array and might add 'local_file' if needed.
     */
    public static function ProcessAttachmentInfo(&$dbrow)
    {
    }

    /**
     *  Returns a flat array (list) with all attachments for all tickets from the database, but without the
     *  actual data.
     *
     *  Every array item is a row array with the following keys: binary_id, ticket_id, filename, mimetype, filesize, cx, cy.
     */
    public static function GetAllAttachments()
    {
        $aReturn = [];
        $res = Database::DefaultExec('SELECT i AS binary_id, ticket_id, filename, mime AS mimetype, size AS filesize, cx, cy FROM ticket_binaries');
        while ($row = Database::GetDefault()->fetchNextRow($res))
            $aReturn[] = $row;
        return $aReturn;
    }

    /**
     *  Returns TRUE if $mimetype describes a JPEG, PNG or GIF image.
     */
    public static function IsImage($mimetype)
    {
        switch ($mimetype)
        {
            case 'image/jpg':           # not legal, but just in case
            case 'image/jpeg':
            case 'image/png':
            case 'image/gif':
                return TRUE;
        }
        return FALSE;
    }

    public static function CountAll()
    {
        $res = Database::DefaultExec("SELECT COUNT(i) AS c FROM tickets");
        $row = Database::GetDefault()->fetchNextRow($res);
        return $row['c'];
    }

    /**
     *  Deletes all non-template tickets from the database.
     */
    public static function Nuke(Callable $pfnProgress = NULL)
    {
        if ($cTickets = Ticket::CountAll())
        {
            if ($pfnProgress)
                $pfnProgress(0, $cTickets);

            $cDeleted = 0;
            if ($findResults = self::FindMany([ SearchFilter::NonTemplates() ]))
            {
                foreach ($findResults->aTickets as $ticket_id => $oTicket)
                {
                    # TODO use new API to make this faster
                    Ticket::Delete( [ $ticket_id => $oTicket ] );

                    ++$cDeleted;
                    if (($cDeleted % 10) == 0)
                        if ($pfnProgress)
                            $pfnProgress($cDeleted, $cTickets);
                }
            }
        }

        if ($pfnProgress)
            $pfnProgress($cTickets, $cTickets);
    }
}
