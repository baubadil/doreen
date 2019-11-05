<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 * @page howdoi_add_ticket_field How do I... add a new ticket field?
 *
 *  If you want to add new functionality to Doreen tickets, the first step is usually adding a new
 *  ticket field. You then define that field to be part of a new ticket type, and all tickets of
 *  that type have that functionality.
 *
 *  To do that in your plugin, here is a step-by-step guide:
 *
 *   1. Create a plugin that implements ITypePlugin and its methods. Leave the methods blank
 *      for now, but you will need to implement some of them (see below).
 *
 *   2. Every ticket field needs an integer field ID (FIELD_* constants). The field IDs of the
 *      Doreen core are all in class_globals.php. Typically plugins define their own
 *      field IDs at the top of the plugin include file, with a prefix (e.g. FIELD_MYPLUG_MYID).
 *      There are no rules for ID spaces yet, unfortunately, try not to conflict with other
 *      plugins.
 *
 *   3. You must add a row for your ticket field to the \ref ticket_fields table. Use your plugin's
 *      install routine for this. Look at an existing plugin: find the plugin database version,
 *      increment it by 1, find the code at the top that upserts ticket_fields, and add a line to it.
 *      If your ticket field uses one of the standard ticket_* tables like ticket_ints or ticket_texts,
 *      then this is easy.
 *
 *   4. Refresh the ticket types that should include your new ticket field. Find the lines that use
 *      $fRefreshTypes, make sure it gets set by your database version numer, and add the type to the
 *      details and list view fields of the ticket type.
 *
 *   5. Implement \ref ITypePlugin::reportFieldsWithHandlers(). You need to return a pair
 *      for your field ID with the FIELDFL_* flags that you want to define for it. This is
 *      crucial, each of these flags can have a MAJOR effect. If you used one of the standard
 *      ticket_* tables, then you must flag at least FIELDFL_STD_DATA_OLD_NEW.
 *
 *   6. Add your own field handler class that derives from FieldHandler.
 *
 *   7. Implement \ref ITypePlugin::createFieldHandler(). Add a switch/case for your new field ID and
 *      create an instance of your field handler class.
 *
 *   8. For drill-down aggregations and search engine support, maybe implement
 *      \ref ITypePlugin::reportDrillDownFieldIDs() and \ref ITypePlugin::reportSearchableFieldIDs() too.
 *
 *  Easy!
 */


/********************************************************************
 *
 *  TicketField class
 *
 ********************************************************************/

/**
 *  Representation of a ticket field as stored in the \ref ticket_fields table.
 *  See \ref intro_ticket_types for an introduction how data is pulled together.
 *
 *  See \ref howdoi_add_ticket_field for how to add a new ticket field to your plugin.
 *
 *  In more detail, a ticket field defines a virtual data column for a ticket. Since those
 *  columns are variable between tickets (depending on the ticket type), the columns that apply to a
 *  specific ticket have to be constructed at runtime. This involves the following steps:
 *
 *    * We look up the ticket in the \ref tickets table, and the type_id column gives us the numeric
 *      type ID.
 *
 *    * We then look up the type in \ref ticket_types and the columns that apply in \ref ticket_type_details.
 *      This gives us a list of field IDs (columns) that apply to the ticket's type. (All this is
 *      managed by the TicketType class.)
 *
 *  Standard ticket fields like "description" and "priority" have the FIELDFL_REQUIRED_IN_POST_PUT
 *  flag set, meaning that data for them is part of the arguments with HTTP POST (ticket creation)
 *  and PUT (ticket update) data. For example, if a ticket type prescribes that the "description"
 *  field should be present for tickets of its type, then ticket creation or updates will fail if
 *  the description field is not present in POST or PUT data, because FIELD_DESCRIPTION has the
 *  FIELDFL_STD_DATA_OLD_NEW bit set.
 *
 *  Those standard fields also have FIELDFL_STD_DATA_OLD_NEW set, which means that the ticket data
 *  is automatically managed with changelog rows.
 *
 *  Some ticket fields are special however, depending on their FIELDFL_* flags:
 *
 *    * Some of these ticket field IDs can have multiple entries per ticket, and these entries
 *      should not be part of the POST or PUT data, but only be shown in changelogs -- in particular,
 *      FIELD_COMMENT and FIELD_ATTACHMENT.
 *
 *      These fields have the FIELDFL_CHANGELOGONLY bit set in addition to FIELDFL_STD_DATA_OLD_NEW.
 *
 *    * Some ticket field IDs have no data at all but are only used for configuration what tickets
 *      should look like. For example, FIELD_CHANGELOG determines whether the ticket changelog
 *      should be shown at all under the ticket details (not for Wiki articles, for example), but the
 *      flag has no effect on what data is stored.
 *
 *    * Some field IDs have no data and only represent ticket or system events in changelogs:
 *
 *       -- Ticket changelog events such as FIELD_TICKET_CREATED and FIELD_TICKET_TEMPLATE_CREATED
 *          are stored with a ticket ID in the change log and represent important ticket events.
 *          These are shown both in ticket changelogs and the global changelog.
 *
 *       -- System changelog events utilize special field IDs starting with FIELD_SYS_* and don't
 *          even have ticket IDs in the changelog rows. These are for things like system user
 *          account changes and are only shown in the global changelog, not ticket changelogs.
 */
class TicketField
{
    public $id;
    public $name;
    public $tblname;
    public $fl;                 # FIELDFL_STD_DATA_OLD_NEW, FIELDFL_DETAILSONLY
    public $idParent;           # Parent ticket field ID or NULL (as for most fields).
                                # If this is set, this has the following consequences:
                                #   --  the field is not configurable, but its visibility depends entirely on the parent field;
                                #   --  the parent field handlers are responsible for writing, reading, displaying the data.
    public $ordering;

    public static $aAllLoaded = [];

    private static $fLoadedAll = FALSE;

    private static $aFieldsAlwaysIncluded =
            [   FIELD_CREATED_DT => 1,
                FIELD_LASTMOD_DT => 1,
                FIELD_CREATED_UID => 1,
                FIELD_LASTMOD_UID => 1
            ];

    /* Array of field_id => FIELDFL_* flags, from which they are copied into TicketField objects by its constructor.
       Ticket type plugins can add to this via their ITypePlugin::reportFieldsWithHandlers() method implementation. */
    private static $aFieldFlags = [
        FIELD_TYPE                      => FIELDFL_STD_CORE,
        FIELD_CREATED_DT                => FIELDFL_STD_CORE                                                                                       | FIELDFL_TYPE_DATE | FIELDFL_SORTABLE | FIELDFL_DESCENDING,
        FIELD_LASTMOD_DT                => FIELDFL_STD_CORE                                                                                                          | FIELDFL_SORTABLE | FIELDFL_DESCENDING | FIELDFL_TYPE_DATE,
        FIELD_CREATED_UID               => FIELDFL_STD_CORE | FIELDFL_HIDDEN,
        FIELD_LASTMOD_UID               => FIELDFL_STD_CORE | FIELDFL_HIDDEN,
        FIELD_TITLE                     =>                    FIELDFL_REQUIRED_IN_POST_PUT | FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_TEXT_NATURAL | FIELDFL_SORTABLE | FIELDFL_SUGGEST_FULL_VALUE,
        FIELD_DESCRIPTION               =>                                                   FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_TEXT_NATURAL,
        FIELD_PROJECT                   => FIELDFL_STD_CORE                                                           | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_INT | FIELDFL_SORTABLE,
        FIELD_PARENTS                   =>                                                   FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_ARRAY | FIELDFL_ARRAY_HAS_REVERSE,
        FIELD_CHILDREN                  =>                                                   FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_ARRAY_REVERSE,
        FIELD_KEYWORDS                  =>                    FIELDFL_REQUIRED_IN_POST_PUT | FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_ARRAY | FIELDFL_WORDLIST,

        FIELD_PRIORITY                  =>                    FIELDFL_REQUIRED_IN_POST_PUT | FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_INT | FIELDFL_SORTABLE | FIELDFL_DESCENDING,
        FIELD_UIDASSIGN                 =>                    FIELDFL_REQUIRED_IN_POST_PUT | FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_INT | FIELDFL_SORTABLE,
        FIELD_STATUS                    =>                    FIELDFL_REQUIRED_IN_POST_PUT | FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_INT | FIELDFL_SORTABLE,

        FIELD_IMPORTEDFROM              =>                                                   FIELDFL_STD_DATA_OLD_NEW | FIELDFL_FIXED_CREATEONLY,// DO NOT USE FIELDFL_INT or else we can't search
        FIELD_IMPORTEDFROM_PERSONID     =>                                                   FIELDFL_STD_DATA_OLD_NEW | FIELDFL_FIXED_CREATEONLY,// DO NOT USE FIELDFL_INT or else we can't search

        FIELD_CHANGELOG                 =>                                                                              FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_TEXT_NATURAL | FIELDFL_DETAILSONLY | FIELDFL_CHANGELOGONLY | FIELDFL_VIRTUAL_IGNORE_POST_PUT,
        FIELD_COMMENT                   =>                                                   FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_TEXT_NATURAL | FIELDFL_DETAILSONLY | FIELDFL_CHANGELOGONLY | FIELDFL_VIRTUAL_IGNORE_POST_PUT,
        FIELD_OLDCOMMENT                =>                                                                                                                                                            FIELDFL_CHANGELOGONLY | FIELDFL_VIRTUAL_IGNORE_POST_PUT | FIELDFL_HIDDEN,
        FIELD_ATTACHMENT                =>                                                   FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_TEXT_NATURAL | FIELDFL_DETAILSONLY | FIELDFL_CHANGELOGONLY | FIELDFL_VIRTUAL_IGNORE_POST_PUT,
        FIELD_ATTACHMENT_RENAMED        => FIELDFL_CHANGELOGONLY,
        FIELD_ATTACHMENT_DELETED        => FIELDFL_CHANGELOGONLY,

        FIELD_SYS_USER_CREATED          => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_USER_PASSWORDCHANGED  => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_USER_LONGNAMECHANGED  => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_USER_EMAILCHANGED     => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_USER_ADDEDTOGROUP     => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_USER_REMOVEDFROMGROUP => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_USER_DISABLED         => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_USER_FTICKETMAILCHANGED => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_USER_PERMITLOGINCHANGED => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_USER_LOGINCHANGED     => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_GROUP_CREATED         => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_GROUP_NAMECHANGED     => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_GROUP_DELETED         => FIELDFL_EMPTYSYSEVENT,
        FIELD_TICKET_CREATED            => FIELDFL_EMPTYTICKETEVENT,
        FIELD_TICKET_TEMPLATE_CREATED   => FIELDFL_EMPTYTICKETEVENT,
        FIELD_SYS_TEMPLATE_DELETED      => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_TICKETTYPE_CREATED    => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_TICKETTYPE_CHANGED    => FIELDFL_EMPTYSYSEVENT,
        FIELD_SYS_TICKETTYPE_DELETED    => FIELDFL_EMPTYSYSEVENT,
        FIELD_COMMENT_UPDATED           => FIELDFL_EMPTYTICKETEVENT,
        FIELD_COMMENT_DELETED           => FIELDFL_EMPTYTICKETEVENT,

        FIELD_TEMPLATE_UNDER_TICKET_CHANGED => FIELDFL_EMPTYTICKETEVENT,
                                ];

    /** @var ITypePlugin[] */
    private static $aPluginRegisterFieldHandlers = [];       # Array of fieldid (FIELD_*) => ITypePlugin instances to be able to call createFieldHandler() quickly.


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($id,
                                $name,
                                $tblname,
                                $idParent,
                                $ordering)
    {
        $this->id = $id;
        $this->name = $name;
        $this->tblname = $tblname;
        if (!($this->fl = getArrayItem(self::$aFieldFlags, $id)))
            myDie("Fatal error: ticket field $id (".Format::UTF8Quote($name).") has no field flags registered!");

        $this->idParent = ($idParent) ? $idParent : NULL;
        $this->ordering = $ordering;

        self::$aAllLoaded[$id] = $this;
    }

    /**
     *  Returns the TicketField instance matching the given ID.
     *
     *  If an object has already been instantiated for that ID, it is returned.
     *  Otherwise a new one is created with the given data.
     */
    public static function MakeAwakeOnce($id,
                                         $name,
                                         $tblname,
                                         $idParent,
                                         $ordering)
    {
        if (isset(self::$aAllLoaded[$id]))
            return self::$aAllLoaded[$id];

        return new self($id, $name, $tblname, $idParent, $ordering);
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  Returns TRUE if this field should be displayed in list views of the given ticket.
     */
    public function isVisibleInListView(Ticket $oTicket)
    {
        return     isset($oTicket->oType->aListFields[$this->id])
                || isset(self::$aFieldsAlwaysIncluded[$this->id]);
    }

    /**
     *  Returns the table name or throws.
     */
    public function getTableNameOrThrow()
    {
        if (!($tblname = $this->tblname))
            throw new DrnException("Cannot insert or update field $this->id with no table name");

        return $tblname;
    }

    public function getTableColumnAttributes()
    {
        if ($this->tblname == 'ticket_amounts')
            return 'class="text-right"';
        return NULL;
    }

    /**
     *  Returns data for this instance as an array for JSON encoding.
     *
     *  The front-end provides the ITicketField interface for the return value.
     */
    public function toArray()
        : array
    {
        return [ 'id' => $this->id,
                 'name' => $this->name,
                 'tblname' => $this->tblname,
                 'fDetailsOnly' => $this->fl & FIELDFL_DETAILSONLY ];
    }


    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Loads the given ticket field from the database and returns it, or NULL
     *  if not found.
     *
     * @return TicketField|null
     */
    public static function Find($field_id)                   //!< in: field ID to search for
    {
        self::GetAll();

        if (isset(self::$aAllLoaded[$field_id]))
            return self::$aAllLoaded[$field_id];

        return NULL;
    }

    /**
     *  Calls \ref Find() and returns its value, or throws if it returns NULL.
     *
     *  @return self
     */
    public static function FindOrThrow($field_id)
        : TicketField
    {
        if (!($oField = TicketField::Find($field_id)))
            throw new DrnException("Invalid field ID ".Format::UTF8Quote($field_id));
        return $oField;
    }

    /**
     *  Returns the name of the field with the given ID, or NULL if not found.
     */
    public static function GetName($field_id)
    {
        if ($oField = self::Find($field_id))
            return $oField->name;

        return NULL;
    }

    /**
     *  Looks for a ticket field with the given name (e.g. 'priority') and returns it,
     *  or NULL if not found.
     *
     *  This is not optimized and loops over all ticket fields.
     *
     *  @return TicketField|null
     */
    public static function FindByName($name)
    {
        self::GetAll();

        foreach (self::$aAllLoaded as $oField)
            if ($oField->name == $name)
                return $oField;

        return NULL;
    }

    private static $fTypePluginsInited = FALSE;

    public static function InitTypePlugins()
    {
        if (!self::$fTypePluginsInited)
        {
            // Load field IDs from type plugins first.
            foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_TYPE) as $oImpl)
            {
                /** @var ITypePlugin $oImpl  */
                if ($aFieldIDs = $oImpl->reportFieldsWithHandlers())
                {
                    // Pass the whole array of field IDs to the ticket fields manager.
                    foreach ($aFieldIDs as $field_id => $fl)
                    {
                        if (isset(self::$aFieldFlags[$field_id]))
                            throw new DrnException("Fatal error: field id $field_id used more than once");
                        self::$aFieldFlags[$field_id] = $fl;
                    }

                    // Store the plugin instance with every field ID so we can call the plugin later.
                    foreach ($aFieldIDs as $field_id => $flField)
                        self::$aPluginRegisterFieldHandlers[$field_id] = $oImpl;
                }
            }

            self::$fTypePluginsInited = TRUE;
        }
    }

    /**
     *  Returns an array of all the ticket fields. Loads them from the database on the first call.
     *
     *  This only retrieves ticket fields with data, not the pseudo props used for system change log events.
     *
     * @return TicketField[]
     */
    public static function GetAll($fIncludeChildren = FALSE,
                                  $flRequired = 0)
        : array
    {
        if (!self::$fLoadedAll)
        {
            self::InitTypePlugins();

            $res = Database::DefaultExec(<<<EOD
SELECT
    ticket_fields.i AS id,
    ticket_fields.name,
    ticket_fields.tblname,
    ticket_fields.parent,
    ticket_fields.ordering
FROM ticket_fields
ORDER BY ordering
EOD
                              );
            while ($row = Database::GetDefault()->fetchNextRow($res))
                self::MakeAwakeOnce($row['id'], $row['name'], $row['tblname'], $row['parent'], $row['ordering']);

            self::$fLoadedAll = true;
        } // if (!self::$fLoadedAll)

        $aReturn = [];
        foreach (self::$aAllLoaded as $id => $o)
            if ( (!$flRequired) || ($o->fl & $flRequired) )
                if (    $fIncludeChildren
                     || ($o->idParent == NULL)
                   )
                    $aReturn[$id] = $o;
        return $aReturn;
    }

    /**
     *  Returns the ITypePlugin that implements that given ticket field, or NULL
     *  if the field is built-in, or if it is unknown.
     *
     * @return ITypePlugin
     */
    public static function GetTypePlugin($field_id)
    {
        self::InitTypePlugins();
        return getArrayItem(self::$aPluginRegisterFieldHandlers, $field_id);
    }

    /**
     *  Like GetAll(), but returns all instances as a PHP array of arrays for JSON encoding.
     *
     *  The front-end provides the ITicketField instance, an array of which is returned here.
     *
     *  Caller must check access permissions before returning any such data!
     */
    public static function GetAllAsArray()
    {
        $aFields = self::GetAll(FALSE, FIELDFL_VISIBILITY_CONFIG | FIELDFL_STD_DATA_OLD_NEW);
        $aReturn = [];
        foreach ($aFields as $id => $oField)
            $aReturn[] = $oField->toArray();

        return $aReturn;
    }

    /**
     *  This forces a reload of all ticket field definitions from the database. This is
     *  used by the install after it has tinkered with the definitions on disk and
     *  the in-memory versions have become invalid.
     */
    public static function ForceRefresh()
    {
        self::$fLoadedAll = FALSE;
        return self::GetAll();
    }

    /**
     *  Returns the (constant) list of field IDs which should always be
     *  shown in list views, regardless of what is configured for ticket types.
     */
    public static function GetAlwaysIncludedIDs()
    {
        return array_keys(self::$aFieldsAlwaysIncluded);
    }

    /**
     *  Removes a ticket field from the database and all ticket rows. This is a
     *  destructive change and should only be called from installation routines
     *  that know that the data is available elsewhere, e.g. because it has been
     *  copied to another field.
     */
    public static function Delete($field_id)
    {
        Database::DefaultExec("DELETE FROM ticket_fields WHERE i = $1",
                                                                       [ $field_id ]);
    }


    /********************************************************************
     *
     *  Search boost field management
     *
     ********************************************************************/

    private static $fBoostFieldIDsQueried = FALSE;

    private static $aSearchBoostFields = [
        FIELD_TITLE             => 5,
        FIELD_DESCRIPTION       => 3,
        FIELD_COMMENT           => 1,
        FIELD_ATTACHMENT        => 1
    ];

    /**
     *  Called from self::GetSearchBoost() to ensure that the boost fields are built
     *  on the first call. This goes into plugins to call reportSearchableFieldIDs().
     */
    private static function BuildBoostIDs()
    {
        if (!self::$fBoostFieldIDsQueried)
        {
            foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_TYPE) as $oImpl)
            {
                /** @var ITypePlugin $oImpl */
                if ($a = $oImpl->reportSearchableFieldIDs())
                    self::$aSearchBoostFields += $a;
            }

            self::$fBoostFieldIDsQueried = TRUE;
        }
    }

    /**
     *  Returns a search boost value if the given ticket field should be searchable,
     *  or NULL if not.
     *
     *  A non-null value for a field ID will cause the field to become searchable in the
     *  full-text search engine (e.g. Elasticsearch). For example, FIELD_TITLE gets a
     *  score of 5. The data of fields specified here will be stored as strings in the
     *  search engine.
     *
     *  Note that during ticket POST/PUT (create/update), boost values are checked only
     *  for whether they are non-NULL. Only during full-text searches are the actual
     *  boost values (e.g. 1 or 5) sent to the search engine. It is therefore possible
     *  to change a non-null boost value to another non-null bost value without reindexing.
     *
     *  Plug-ins can declare search boost for their own field IDs through ITypePlugin::reportSearchableFieldIDs().
     */
    public function getSearchBoost()
    {
        self::BuildBoostIDs();

        return getArrayItem(self::$aSearchBoostFields, $this->id);
    }

    /**
     *  Returns an array of field_id => $oField pairs with all ticket fields that have
     *  a non-zero search boost value, including fields in the changelog.
     *
     * @return TicketField[] | NULL
     */
    public static function GetSearchableFields()
    {
        self::BuildBoostIDs();

        $aReturn = [];

        foreach (self::GetAll() as $field_id => $oField)
            if (    ($boost = getArrayItem(self::$aSearchBoostFields, $field_id))
                 && (!($oField->fl & FIELDFL_CHANGELOGONLY))
               )
            {
                if ($oField->fl & FIELDFL_TYPE_INT)
                    throw new DrnException("field \"{$oField->name}\" cannot have a search boost if FIELDFL_TYPE_INT is set");

                $aReturn[$field_id] = $oField;
            }

        return count($aReturn) ? $aReturn : NULL;
    }


    /********************************************************************
     *
     *  Drill-down field management
     *
     ********************************************************************/

    private static $fDrillDownFieldIDsQueried = FALSE;

    /* Flat list of field IDs for which drill-down should be enabled in ticket lists; see Ticket::FindMany().
     * The data of fields specified here should be INTs, not strings.
     * Values from plugins are added to this by GetDrillDownIDs(). */
    private static $aDrillDownFieldIDs = [
        FIELD_STATUS        => '{{L//status}}',
        FIELD_UIDASSIGN     => '{{L//assignee}}',
        FIELD_PROJECT       => '{{L//project}}',
    ];

    /**
     *  Called from self::GetDrillDownIDs() to ensure that the drill-down field IDs are built
     *  on the first call. This goes into plugins to call reportDrillDownFieldIDs().
     */
    private static function BuildDrillDownIDs()
    {
        if (!self::$fDrillDownFieldIDsQueried)
        {
            foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_TYPE) as $oImpl)
            {
                /** @var ITypePlugin $oImpl */
                if ($a = $oImpl->reportDrillDownFieldIDs())
                    self::$aDrillDownFieldIDs += $a;
            }

            self::$fDrillDownFieldIDsQueried = TRUE;
        }
    }

    /**
     *  Returns the flat list of field IDs for which drill-down should be performed
     *  in search results. For this all plugins are queried whether they would like
     *  to the list provided by the core.
     *
     *  Note that even though FIELD_TYPE is always drilled for, it is NOT returned here,
     *  since it is treated specially pretty much everywhere.
     *
     *  See \ref drill_down_filters for an introduction.
     *
     * @return int[]
     */
    public static function GetDrillDownIDs()
    {
        self::BuildDrillDownIDs();

        return array_keys(self::$aDrillDownFieldIDs);
    }

    /**
     *  Returns a translated filter name for the given drill-down filter field ID.
     *
     *  See \ref drill_down_filters for an introduction.
     */
    public static function GetDrillDownFilterName($fieldid)
    {
        if ($fieldid == FIELD_TYPE)
            return L('{{L//type}}');

        self::BuildDrillDownIDs();

        if ($l = getArrayItem(self::$aDrillDownFieldIDs, $fieldid))
            return L($l);
        return  NULL;
    }
}


class APIMissingDataException extends APIException
{
    public function __construct(TicketField $oField,
                                $legibleName = NULL)
    {
        if (!$legibleName)
            $legibleName = $oField->name;
        parent::__construct($oField->name,
                            L('{{L//The field %FIELD% cannot be left empty}}',
                              [ '%FIELD%' => Format::UTF8Quote($legibleName)." ($oField->id)" ] ));
    }
}
