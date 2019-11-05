<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  \page intro_ticket_types Ticket types and ticket fields
 *
 *  A couple of concepts must be understood before one can make sense of how Doreen
 *  organizes its data:
 *
 *   -- A <b>ticket</b> is a number (the ticket ID) associated with data fields -- for example,
 *      the ticket title and a description. The minimum data that every ticket has is
 *      defined in the \ref tickets table: basically the ticket ID, the ticket type, an
 *      access control list. Additional ticket data (again, such as the ticket title)
 *      is <i>not</i> in the tickets table, since all ticket data fields are configurable and
 *      optional.
 *
 *   -- <b>Ticket fields</b> can be thought of as the columns of the tickets table, except that
 *      they are not in the tickets table itself, but spread across the rows of other tables.
 *      How this data is interpreted and pulled together is defined by rows in the
 *      \ref ticket_fields table. For example, there is a row for the ticket field with
 *      the FIELD_TITLE ID, which says that ticket titles are stored in rows in the
 *      \ref ticket_texts table.
 *
 *   -- Which tickets have which fields is controlled by <b>ticket types,</b> which are in the
 *      \ref ticket_types table.
 *
 *  Every row in the tickets table can be instantiated as an instance of the Ticket class,
 *  which will take care of pulling additional data from the other tables as required.
 *  Ticket fields are represented by instances of TicketField; ticket types are managed
 *  by the TicketType class.
 *
 *  As an example, the "Wiki" ticket type that is installed by default says that tickets
 *  of its type should have two fields: a title (FIELD_TITLE) and the article content
 *  (FIELD_DESCRIPTION). The rows for both fields specify that ticket data for these
 *  fields will be stored in the \ref ticket_texts table. So for every Ticket instance
 *  which has a "Wiki" type (which corresponds to a row in the tickets table), there
 *  are <i>two</i> extra rows in ticket_texts: one for the title, one for the article content.
 *
 *  In addition to the aforementioned table data, there is specific code that handles data
 *  of a particular ticket field. Whereas the TicketField class is more or less a wrapper
 *  around the database data for a field, the FieldHandler class has code for displaying
 *  and modifying data for a particular field in a ticket. The FieldHandler class is a
 *  base class; many subclasses exist which make sure that, for example, a ticket title
 *  is displayed differently than a ticket priority (e.g. in a drop-down field in edit mode).
 *  See the FieldHandler class and its subclasses for details.
 */

/********************************************************************
 *
 *  TicketType class
 *
 ********************************************************************/

/**
 *  A ticket type determines what ticket fields (data columns) are visible for tickets
 *  and how they behave.
 *  See \ref intro_ticket_types for an introduction how data is pulled together.
 *
 *  Every row in the "tickets" table has a type_id field, which determines its type.
 *  Changing the fields in a type affects all tickets of that type immediately, so this
 *  is reserved to administrators in the GUI.
 *
 *  In detail, a ticket type contains the following information:
 *
 *    - a list of fields to be shown in ticket details views;
 *
 *    - a list of fields to be shown in ticket lists (search results);
 *
 *    - a parent type, if the ticket type is supposed to support parents (e.g. milestones);
 *
 *    - a TicketWorkflow, if the ticket type shows FIELD_STATUS.
 */
class TicketType
{
    public $id;
    private $name;                      # Name row from database; depending on install routine, this can be an L() string
    public $parent_type_id;
    public $idWorkflow;                 # Workflow ID or NULL.
    public $fl;

    public $details_fields;             # Fields visible in ticket details as comma-separated list of numeric field IDs.
    public $aDetailsFields = [];        # Exploded array of numeric field IDs (key = field ID => always 1).
    public $fCompactDetails = FALSE;    # TRUE only if $aDetailsFields consists only of FIELD_TITLE and FIELD_DESCRIPTION, which leads to a compact view.

    public $list_fields;                # Fields visible in ticket lists as comma-separated list of numeric field IDs.
    public $aListFields = [];           # Exploded array of numeric field IDs (key = field ID => always 1).

    public $cUsage = NULL;              # NULL until GetUsage() has been called.

    public static $aAllLoaded = [];

    private static $fLoadedAll = FALSE;

    const FL_AUTOMATIC_TITLE    = 0x01;    # If set, then even if title field is present in ticket details, do not allow for editing it, as it is automatically managed by some field handler.
    const FL_AUTOMATIC_STATUS   = 0x02;    # If set, then even if status field is present in ticket details, do not allow for editing it, as it is automatically managed by some field handler.
//    const FL_HIDE_TEMPLATES     = 0x04;    # If set, then templates of this type can be created, but will be hidden inthe "New" menu. -> replaced with Ticket::canCreate()

    # Flags for getVisibleFields() and GetManyVisibleFields()
    const FL_FOR_DETAILS      = 0x01;
    const FL_INCLUDE_CHILDREN = 0x02;
    const FL_INCLUDE_CORE     = 0x04;         # Include core fields that are in Globals::aFieldsAlwaysVisible
    const FL_INCLUDE_HIDDEN   = 0x08;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    /**
     *  Protected constructor. It is protected because new objects of this class should
     *  only be created through MakeAwakeOnce().
     */
    protected function __construct($id,
                                   $name,
                                   $parent_type_id,
                                   $details_fields,
                                   $list_fields,
                                   $workflow_id,
                                   $fl)
    {
        $this->id = (int)$id;
        $this->name = $name;
        $this->parent_type_id = $parent_type_id;
        $this->details_fields = $details_fields;
        $this->list_fields = $list_fields;
        $this->idWorkflow = $workflow_id;
        $this->fl = $fl;

        $this->buildMemberHashes();

        self::$aAllLoaded[$id] = $this;
    }

    /**
     *  Returns the TicketType instance matching the given ID.
     *
     *  If an object has already been instantiated for that ID, it is returned.
     *  Otherwise a new one is created with the given data.
     */
    public static function MakeAwakeOnce($id,
                                         $name,
                                         $parent_type_id,
                                         $details_fields,
                                         $list_fields,
                                         $workflow_id,
                                         $fl)
    {
        if (isset(self::$aAllLoaded[$id]))
            return self::$aAllLoaded[$id];

        return new self($id, $name, $parent_type_id, $details_fields, $list_fields, $workflow_id, $fl);
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  Returns the name of the ticket type, possibly translated, if a translated name
     *  is available.
     *
     *  This simply runs L() on the name column from the \ref ticket_types table.
     *  As a result, if the type name is not an L string, nothing happens.
     *  To provide translations for ticket names, write an L string into the database
     *  in the installation routine and extract and provide for the gettext translation
     *  the usual way; see See @ref drn_nls for more.
     */
    public function getName()
    {
        return L($this->name);
    }

    private static $aSpecializedTicketClasses = NULL;

    /**
     *  Returns the name of the PHP class that tickets of this type should be instantiated of.
     *  Normally this is "Ticket", unless a plugin provides specialized ticket classes.
     *
     *  This works as follows: A plugin must first report the CAPSFL_TYPE_SPECIALTICKET
     *  capability and implement the ISpecializedTicketPlugin::getSpecializedClassNames()
     *  interface method, which must return at least once typename => classname pair
     *  (with classname being a Ticket subclass).
     *
     *  After that, this function will make sure that whenever a ticket is instantiated,
     *  it will be of the reported Ticket subclass.
     */
    public function getTicketClassName()
    {
        self::InitSpecializedTicketClasses();

        if (!($class = self::$aSpecializedTicketClasses[$this->name] ?? NULL))
            $class = 'Ticket';

        return $class;
    }

    private static function InitSpecializedTicketClasses()
    {
        if (self::$aSpecializedTicketClasses === NULL)
        {
            # First call: ask plugins for specialized classes.
            self::$aSpecializedTicketClasses = [];
            foreach (Plugins::GetWithCaps(IUserPlugin::CAPSFL_TYPE_SPECIALTICKET) as $oImpl)
            {
                /** @var ISpecializedTicketPlugin $oImpl */
                if ($a = $oImpl->getSpecializedClassNames())
                    self::$aSpecializedTicketClasses += $a;
            }
        }
    }

    private static $aTicketLinkAliases = [ 'ticket' => 1 ];        // key = alias, value = always 1 (dummy)

    /**
     *  This can be called by a plugin during its initialization to add a ticket link alias
     *  to our internal list. This must be called by a plugin if it provides a specialized
     *  Ticket subclass with a Ticket::getTicketUrlParticle() override.
     */
    public static function AddTicketLinkAlias(string $alias)
    {
        self::$aTicketLinkAliases[$alias] = 1;
    }

    public static function GetTicketLinkAliases()
        : array
    {
        return self::$aTicketLinkAliases;
    }

    /**
     *  Returns an array of TicketField objects representing the ticket fields
     *  that should be visible in detail views of tickets of this type.
     *
     *  This calls TicketField::GetAll() in turn and then runs over this type's
     *  details or list fields to add those. As a result, this only returns
     *  ticket *data* fields (with FIELDFL_VISIBILITY_CONFIG or FIELDFL_STD_DATA_OLD_NEW
     *  set), not empty changelog events (FIELDFL_EMPTYTICKETEVENT or FIELDFL_EMPTYSYSEVENT).
     *
     *  Ticket child fields are added here depending on the parent's visibility. For example,
     *  with FIELD_CHILD_IDDUPLICATE, it is added if its parent FIELD_STATUS is listed with
     *  the type's details or list fields.
     *
     *  The following flags can be passed in:
     *
     *   -- FL_FOR_DETAILS: include all fields that are configured for this type's details view, not just for list view
     *
     *   -- FL_INCLUDE_CHILDREN: include child fields, if the parent is deemed visible here
     *
     *   -- FL_INCLUDE_CORE: include core fields (parts of the tickets table itself)
     *
     *   -- FL_INCLUDE_HIDEN: include fields that have FIELDFL_HIDDEN set
     *
     * @return TicketField[]
     */
    public function getVisibleFields($fl = 0)   //!< in: FL_FOR_DETAILS, FL_INCLUDE_CHILDREN, FL_INCLUDE_CORE, FL_INCLUDE_HIDDEN
    {
        $fChildren = $fl & self::FL_INCLUDE_CHILDREN;
        $fDetails = $fl & self::FL_FOR_DETAILS;
        $fHidden = $fl & self::FL_INCLUDE_HIDDEN;

        # Do this call first because GetAll loads all ticket fields from the DB if necessary.
        $aFields = TicketField::GetAll($fChildren);

        $aReturn = [];
        if ($fl & self::FL_INCLUDE_CORE)
            foreach (TicketField::GetAlwaysIncludedIDs() as $field_id)
            {
                $oField = TicketField::$aAllLoaded[$field_id];
                if (    ($oField->fl & FIELDFL_HIDDEN)
                     && (!$fHidden)
                   )
                    continue;

                $aReturn[$field_id] = $oField;
            }

        $aCheck = ($fDetails) ? $this->aDetailsFields : $this->aListFields;
        foreach ($aFields as $field_id => $oField)
        {
            if (    ($oField->fl & FIELDFL_HIDDEN)
                 && (!$fHidden)
               )
                continue;

            $fChild = !!$oField->idParent;
            if (    (    ($fChild)
                      && (isset($aCheck[$oField->idParent]))
                    )
                 || (    (!$fChild)
                      && (isset($aCheck[$field_id]))
                    )
               )
                $aReturn[$field_id] = $oField;
        }
        return $aReturn;
    }

    /**
     *  Returns data for this instance as an array for JSON encoding.
     *
     *  The front-end provides the ITicketType interface for the result.
     *
     *  Note: For 'cUsage' to be correct, caller needs to call GetUsage() for an
     *  array of types beforehand, which is more efficiently queried for many types at once.
     */
    public function toArray()
        : array
    {
        return [ 'id' => (int)$this->id,
                 'name' => $this->getName(),
                 'parent_type_id' => $this->parent_type_id ? (int)$this->parent_type_id : NULL,
                 'workflow_id' => $this->idWorkflow ? (int)$this->idWorkflow : NULL,
                 'details_fields' => $this->details_fields,
                 'list_fields' => $this->list_fields,
                 'cUsage' => (int)$this->cUsage,
                 'cUsageFormatted' => Format::Number($this->cUsage),
               ];
    }

    /**
     *  Returns a default icon for tickets of this type. This gets called
     *
     *   -- from Ticket::getIcon() unless overridden by a Ticket subclass
     *      for each ticket whose icon needs to be determined;
     *
     *   -- from TicketTypeHandler::formatManyHTML() to determine type icons
     *      for the search result "by type" filters.
     *
     *  This calls the static Ticket::GetTypeIcon() method in turn.
     *  See \ref Ticket::getIcon() for more.
     */
    public function getIcon()
        : HTMLChunk
    {
        return call_user_func( [ "\\Doreen\\".$this->getTicketClassName(),
                                 'GetTypeIcon' ] );
    }

    /**
     *  Updates the ticket type information with the given new information and
     *  writes it into the database.
     *
     *  This does NOT check access permissions.
     *
     * @param TicketWorkflow $oWorkflow
     */
    public function update($name,
                           $parent_type_id,          //!< in: parent type ID, if already known; NULL for none; or TYPE_PARENT_SELF
                           $details_fields,
                           $list_fields,
                           $idWorkflow = NULL,       //!< in: TicketWorkflow instance or NULL if none
                           $fl = 0)                  //!< in: type flags
    {
        $fNameChanged = ($name != $this->name);
        $fparent_type_idChanged = ($parent_type_id != $this->parent_type_id);
        $fDetailsChanged = ($details_fields != $this->details_fields);
        $fListChanged = ($list_fields != $this->list_fields);
        $fWorkflowChanged = ($idWorkflow != $this->idWorkflow);
        $fFlagsChanged = ($fl != $this->fl);

        if (    $fNameChanged
             || $fDetailsChanged
             || $fListChanged
             || $fWorkflowChanged
             || $fFlagsChanged
           )
        {
            # changed: then first check validity of the fields

            Database::GetDefault()->beginTransaction();

            if ($fNameChanged)
            {
                if (!($this->name = $name))
                    throw new APIException('name', L('{{L//Ticket type names cannot be empty}}'));
                $this->name = $name;
                Database::DefaultExec('UPDATE ticket_types SET name = $1 WHERE i = $2',
                                             [ $name,       $this->id ] );
            }

            if ($fparent_type_idChanged)
            {
                $this->parent_type_id = $parent_type_id;
                Database::DefaultExec('UPDATE ticket_types SET parent_type_id = $1      WHERE i = $2',
                                             [ $parent_type_id,  $this->id ] );
            }

            if ($fDetailsChanged || $fListChanged)
            {
                $aDetails = [];
                $aList = [];
                TicketType::ValidateFieldLists($details_fields,
                                               $list_fields,
                                               $aDetails,
                                               $aList);
                $this->details_fields = $details_fields;
                $this->list_fields = $list_fields;

                $this->buildMemberHashes();

                # PostgreSQL doesn't have a INSERT OR UPDATE (SQL MERGE, UPSERT) feature,
                # so we must delete the old data and insert the new data. If we do the
                # DELETE before the INSERT, then PostgreSQL tries to re-insert the same
                # 'i' SERIAL values and we get a duplicate key error.
                # So first find the 'i' primary indices to delete; then INSERT the new values;
                # then DELETE the old values based on the 'i' indices.
                $aToDelete = [];
                $res = Database::DefaultExec('SELECT i FROM ticket_type_details WHERE type_id = $1',
                                                    [ $this->id ] );
                while ($dbrow = Database::GetDefault()->fetchNextRow($res))
                    $aToDelete[] = $dbrow['i'];

                TicketType::InsertDetails($this->id, $aDetails, $aList);

                $indices = implode(', ', $aToDelete);
                Database::DefaultExec("DELETE FROM ticket_type_details WHERE i IN ($indices)");
            }

            if ($fWorkflowChanged || $fFlagsChanged)
            {
                $this->idWorkflow = $idWorkflow;
                $this->fl = $fl;
                Database::DefaultExec('UPDATE ticket_types SET workflow_id = $1,      fl = $2 WHERE i = $3',
                                             [ $idWorkflow,  $fl,         $this->id ] );
            }

            Database::GetDefault()->commit();
        }
    }

    /**
     *  Deletes this ticket type, both from the database and from the internal static
     *  (class) array of all ticket types.
     *
     *  PHP has no delete operator for objects, so the PHP object ($this) probably
     *  continues to live until there are no more references to it, which might even
     *  be after this function returns.
     *
     *  This will throw with a cryptic database error about foreign key contraints being
     *  violated if the ticket type is still in use.
     *
     *  This does NOT check access permissions.
     *
     *  Writes a FIELD_SYS_TICKETTYPE_DELETED changelog entry.
     */
    public function delete()
    {
        Database::GetDefault()->beginTransaction();

        Database::DefaultExec("DELETE FROM ticket_type_details WHERE type_id = $1", [ $this->id ]);

        Database::DefaultExec("DELETE FROM ticket_types WHERE i = $1", [ $this->id ]);

        Changelog::AddSystemChange(FIELD_SYS_TICKETTYPE_DELETED,
                                   $this->id,
                                   NULL,
                                   NULL,
                                   $this->name);

        Database::GetDefault()->commit();

        unset(self::$aAllLoaded[$this->id]);
    }


    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Loads the given ticket type from the database, together with the
     *  ticket fields that are connected to it. This is efficient for
     *  ticket details views because the required data can be fetched
     *  with a single database hit.
     *
     * @return TicketType | null
     */
    public static function Find($type_id)
    {
        if (isset(TicketType::$aAllLoaded[$type_id]))
            return TicketType::$aAllLoaded[$type_id];

        TicketType::GetAll();
        TicketField::GetAll();           # TODO not really efficient

        if (isset(TicketType::$aAllLoaded[$type_id]))
            return TicketType::$aAllLoaded[$type_id];

        return NULL;
    }

    /**
     *  Convenience function will calls Find() and throws if nothing was found.
     */
    public static function FindOrThrow($type_id)
        : TicketType
    {
        if (!($oType = self::Find($type_id)))
            throw new DrnException(L("{{L//Invalid ticket type ID %ID%}}", array( '%ID%' => $type_id)));
        return $oType;
    }

    /**
     *  Quick function to get the name of the given ticket type.
     *  Returns an empty string if the type was not found.
     *
     *  This returns the type name from the database, but gives plugins a chance to override it.
     */
    public static function FindName($type_id)
        : string
    {
        if ($oType = self::Find($type_id))
            return $oType->getName();

        return '';
    }

    /**
     *  Constructs part of an SQL query to retrieve all ticket types, without SELECT, FROM or WHERE.
     *
     *  You will need to add at least one LEFT JOIN workflows ON workflows.i = ticket_types.workflow_id.
     */
    public static function MakeQueryColumns()
    {
        $groupConcatShowDetails = Database::GetDefault()->makeGroupConcat('ticket_type_details.field_id');
        $groupConcatProcessStatuses = Database::GetDefault()->makeGroupConcat('workflow_statuses.status_id');

        return <<<EOD
    ticket_types.i AS type_id,
    ticket_types.name,
    ticket_types.parent_type_id,
    (SELECT $groupConcatShowDetails FROM ticket_type_details
        WHERE type_id = ticket_types.i
    ) AS details_fields,
    (SELECT $groupConcatShowDetails FROM ticket_type_details
        WHERE (type_id = ticket_types.i) AND (is_in_list = TRUE)
    ) AS list_fields,
    ticket_types.workflow_id,
    workflows.name AS workflow_name,
    workflows.initial AS workflow_initial,
    (SELECT $groupConcatProcessStatuses FROM workflow_statuses
        WHERE workflow_statuses.workflow_id = ticket_types.workflow_id
    ) AS workflow_statuses,
    ticket_types.fl
EOD;
    }

    /**
     *  Returns an array of all the ticket types. Loads them from the database on the first call.
     *
     *  If $aIDs != NULL, it is assumed to be a flat list of ticket type IDs, and we load only those.
     *
     * @return TicketType[]
     */
    public static function GetAll($aIDs = NULL)     //!< in: list of IDs to load, or NULL for all
    {
        if (!self::$fLoadedAll)
        {
            $cols = self::MakeQueryColumns();

            if ($aIDs)
                $where = "\nWHERE ticket_types.i IN (".Database::MakeInIntList($aIDs).')';
            else
                $where = '';

            $res = Database::DefaultExec(<<<EOD
SELECT  -- TicketType::GetAll()
$cols
FROM ticket_types
LEFT JOIN workflows ON workflows.i = ticket_types.workflow_id $where
EOD
                                            );

            while ($row = Database::GetDefault()->fetchNextRow($res))
                self::MakeAwakeOnce($row['type_id'],
                                    $row['name'],
                                    $row['parent_type_id'],
                                    $row['details_fields'],
                                    $row['list_fields'],
                                    $row['workflow_id'],
                                    $row['fl']);

            # Only set "all loaded" if we really loaded all.
            if (!$aIDs)
                self::$fLoadedAll = true;
        }

        return self::$aAllLoaded;
    }

    /**
     *
     */
    public static function GetUsage($aTypes)
    {
        $res = Database::DefaultExec(<<<EOD
SELECT
    type_id,
    COUNT(tickets.i) AS c
FROM tickets
GROUP BY type_id
EOD
                                 );
        # Make a has of type_id/aid pairs.
        $aUsage = [];
        while ($row = Database::GetDefault()->fetchNextRow($res))
        {
            $type_id = $row['type_id'];
            $aUsage[$type_id] = $row['c'];
        }

        foreach ($aTypes as $id => $oType)
        {
            if (!($c = getArrayItem($aUsage, $id)))
                $c = 0;
            $oType->cUsage = $c;
        }
    }

    /**
     *  Like GetAll(), but returns all instances as a PHP array of arrays for JSON encoding.
     *
     *  The front-end provides the ITicketType interface, an array of which is returned here.
     *
     *  Caller must check access permissions before returning any such data!
     */
    public static function GetAllAsArray()
    {
        $aTypes = TicketType::GetAll();
        self::GetUsage($aTypes);

        $aReturn = [];
        foreach ($aTypes as $id => $oType)
            $aReturn[] = $oType->toArray();

        return $aReturn;
    }

    /**
     *  Creates a new ticket type in the database with the given data and
     *  creates a new TicketType instance accordingly, which is returned.
     *
     *  Note that for the parent type ID, you can specify TYPE_PARENT_SELF
     *  if you want the parent type ID to be the same as the type being
     *  created. (Obviously you couldn't specify that ID otherwise, since
     *  you won't know the ID until after the type has been created.)
     */
    public static function Create($name,                    //!< in: type name displayed to administrator
                                  $parent_type_id,          //!< in: parent type ID, if already known; NULL for none; or TYPE_PARENT_SELF
                                  $details_fields,          //!< in: comma-separated list of field IDs
                                  $list_fields,             //!< in: comma-separated list of field IDs
                                  $idWorkflow,               //!< in: TicketWorkflow ID or NULL
                                  $fl = 0)
    {
        if (!$name)
            throw new APIException('name', L('{{L//Ticket type names cannot be empty}}'));

        $aDetails = [];
        $aList = [];
        TicketType::ValidateFieldLists($details_fields,
                                       $list_fields,
                                       $aDetails,
                                       $aList);

        Database::GetDefault()->beginTransaction();

        Database::DefaultExec(<<<EOD
INSERT INTO ticket_types
    ( name,     workflow_id,  fl ) VALUES
    ( $1,       $2,           $3 )
EOD
  , [ $name,    $idWorkflow,  $fl ] );

        $type_id = Database::GetDefault()->getLastInsertID('ticket_types', 'i');

        TicketType::InsertDetails($type_id, $aDetails, $aList);

        if ($parent_type_id)
        {
            if ($parent_type_id == TYPE_PARENT_SELF)
                $parent_type_id = $type_id;
            Database::DefaultExec("UPDATE ticket_types SET parent_type_id = $1 WHERE i = $2", [ $parent_type_id, $type_id] );
        }

        Database::GetDefault()->commit();

        return new TicketType($type_id,
                              $name,
                              $parent_type_id,
                              $details_fields,
                              $list_fields,
                              $idWorkflow,
                              $fl);
    }

    /**
     *  Looks under the given key in GlobalConfig, assumes it is a numeric TicketType ID
     *  and returns that ID.
     *
     *  If the key does not exist, this throws if $fThrow is TRUE; otherwise it returns
     *  NULL. Throws always if the key exists but is not a valid integer ticket type ID.
     *
     * @return int | null
     */
    public static function FindIDFromGlobalConfig($globalConfigKey,
                                                  $fThrow)
    {
        if ($idType = GlobalConfig::Get($globalConfigKey))
        {
            if (!(isInteger($idType)))
                throw new DrnException("Ticket type ID ".Format::UTF8Quote($idType)." from global config key ".Format::UTF8Quote($globalConfigKey)." is not an integer");
            return (int)$idType;
        }
        else if ($fThrow)
            throw new DrnException("Cannot find ticket type ID ".Format::UTF8Quote($globalConfigKey)." in global config");

        return NULL;
    }

    /**
     *  Attempts to return the TicketType instance for the numeric key stored under the
     *  given key in GlobalConfig. This calls \ref FindIDFromGlobalConfig() in turn and
     *  passes $fThrow to it.
     *
     * @return TicketType | null
     */
    public static function FindFromGlobalConfig($globalConfigKey,
                                                $fThrow)
    {
        if ($idType = self::FindIDFromGlobalConfig($globalConfigKey, $fThrow))
            return TicketType::FindOrThrow($idType);

        return NULL;
    }

    /**
     *  Static helper function that plugin installation routines may find useful to either create
     *  or update a ticket type and store its ID in GlobalConfig.
     *
     *  This looks up $globalConfigKey in GlobalConfig. If it doesn't exist, a new ticket type is
     *  created and its ID is stored under that key. If it does exist, the type is updated with the
     *  given information.
     *
     * @return TicketType
     */
    public static function Install($globalConfigKey,      //!< in: globalconfig key
                                   $ticketTypeName,
                                   $parent_type_id,       //!< in: parent type ID, if already known; NULL for none; or TYPE_PARENT_SELF
                                   $llDetls,              //!< in: flat list of field IDs for ticket details
                                   $llList,               //!< in: flat list of field IDs for ticket list
                                   $idWorkflow,
                                   $fl)
    {
        # Kill the ticket fields cache, or else we can't use this plugin's fields since they may just have been inserted.
        TicketField::ForceRefresh();

        if (!($oType = self::FindFromGlobalConfig($globalConfigKey, FALSE)))
        {
            $oType = TicketType::Create($ticketTypeName,
                                        $parent_type_id,
                                        implode(',', $llDetls),
                                        implode(',', $llList),
                                        $idWorkflow,
                                        $fl);

            GlobalConfig::Set($globalConfigKey, $oType->id);
            GlobalConfig::Save();
        }
        else
        {
            $oType->update($ticketTypeName,
                           $parent_type_id,
                           implode(',', $llDetls),
                           implode(',', $llList),
                           $idWorkflow,
                           $fl);
        }

        return $oType;
    }

    /**
     *  Returns an array of TicketField objects representing the fields that should be visible
     *  in a list view that uses the given ticket types.
     *
     *  Note that this DOES include fields which have FIELDFL_HIDDEN set in $fl so check the results list for that.
     *
     * @return TicketField[]
     */
    public static function GetManyVisibleFields($aTypes,                //!< in: array of ticket types (in $type_id => $oType format)
                                                $fl = 0)                //!< in: combination of FL_INCLUDE_HIDDEN, FL_FOR_DETAILS, FL_INCLUDE_CHILDREN

    {
        $aVisibleFields = [];

        $fChildren = !!($fl & self::FL_INCLUDE_CHILDREN);

        # Make sure all fields are instantiated.
        TicketField::GetAll($fChildren);

        $fl2 = 0;
        foreach ( [ self::FL_FOR_DETAILS,
                    self::FL_INCLUDE_CHILDREN,
                    self::FL_INCLUDE_HIDDEN,
                    self::FL_INCLUDE_CORE ] as $f)
            if ($fl & $f)
                $fl2 |= $f;

        /**
         * @var int $type_id
         * @var TicketType $oType
         */
        foreach ($aTypes as $type_id => $oType)
        {
            $aFields = $oType->getVisibleFields($fl2);
            foreach ($aFields as $field_id => $oField)
                $aVisibleFields[$field_id] = $oField;
        }

        # Now we have to sort them again as they originally appeared in TicketField::GetAll().
        uasort( $aVisibleFields,
                function($o1, $o2)
                {
                    if ($o1->ordering < $o2->ordering)
                        return -1;
                    if ($o1->ordering > $o2->ordering)
                        return 1;
                    return 0;
                });

        return $aVisibleFields;
    }


    /********************************************************************
     *
     *  Private helpers
     *
     ********************************************************************/

    private function buildMemberHashes()
    {
        $this->aDetailsFields = [];
        foreach (explode(',', $this->details_fields) as $id2)
            $this->aDetailsFields[$id2] = 1;

        $this->fCompactDetails = FALSE;
        if (    (count($this->aDetailsFields) == 2)
             && (isset($this->aDetailsFields[FIELD_TITLE]))
             && (isset($this->aDetailsFields[FIELD_DESCRIPTION]))
           )
            $this->fCompactDetails = TRUE;

        $this->aListFields = [];
        foreach (explode(',', $this->list_fields) as $id2)
            $this->aListFields[$id2] = 1;
    }

    /**
     *  Goes through the given comma-separated list of numeric field IDs
     *  and checks that the IDs are all valid. Throws if one is not.
     */
    private static function ValidateFieldLists($details_fields,     //!< in: comma-separated list of numeric field IDs for ticket details
                                               $list_fields,        //!< in: comma-separated list of numeric field IDs for ticket lists
                                               &$aDetails,          //!< out: hash of field IDs => 1
                                               &$aList)             //!< out: hash of field IDs => 1
    {
        $aFields = TicketField::GetAll(FALSE);
//         Debug::Log("TicketField::GetAll() returned: ".print_r($aFields, TRUE));

        foreach (explode(',', $details_fields) as $id2)
        {
            if (!isset($aFields[$id2]))
                throw new DrnException("Invalid ticket field ID $id2 in type details fields");
            $aDetails[$id2] = 1;
        }

        foreach (explode(',', $list_fields) as $id2)
        {
            if (!isset($aFields[$id2]))
                throw new DrnException('Invalid ticket field ID $id2 in list fields');
            # Nothing can be in list but not in details.
            if (!isset($aDetails[$id2]))
                throw new DrnException('Ticket field "'.$aFields[$id2]->name.'" must also be in ticket type details to be visible in ticket list views');

            $aList[$id2] = 1;
        }
    }

    /**
     *  Inserts rows into ticket_type_details. Gets called from update() and
     *  Create().
     */
    private static function InsertDetails($type_id,
                                          $aDetails,
                                          $aList)
    {
        $sql = <<<EOD
INSERT INTO ticket_type_details
    ( type_id,  field_id,  is_in_list ) VALUES
EOD;
        $aValues = [];

        $c = 0;
        foreach ($aDetails as $field_id => $dummy1)
        {
            $is_in_list = (isset($aList[$field_id])) ? 'true' : 'false';
            if ($c)
                $sql .= ',';
            $sql .= "\n    (\$".++$c.",       \$".++$c.",       \$".++$c.")";
            $aValues[] = $type_id;
            $aValues[] = $field_id;
            $aValues[] = $is_in_list;
        }

        if ($c)
            Database::DefaultExec($sql, $aValues);
    }
}
