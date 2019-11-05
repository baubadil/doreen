<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Changelog class
 *
 ********************************************************************/

/**
 *  The Changelog class helps with dealing with ticket or global changelogs, as represented by the \ref changelog table.
 *
 *  Loading the changelog is a two-step process to be able to handle enormous number of rows (possibly hundreds of
 *  thousands) without bringing the server down.
 *
 *  When an instance of Changelog is constructed, it only counts the no. of rows in the changelog, which are then
 *  stored in $this->aRowIDs.
 *
 *  The caller can then inspect $this->aRowsAndWhats and construct a window into those rows and call loadDetails()
 *  only for the rows in that window.
 */
class Changelog
{
    private $res;
    private $where;
    private $ticket_id;

    public $aRowsAndWhats = [];           # list of rowid => what (ticket no.) pairs

    # The following fields are only set after loadDetails().
    /** @var ChangelogRow[] $aChangelogRows */
    public $aChangelogRows = [];

    public $cComments = 0;                 # TRUE if more than one comment actually exists in changelog
    public $cAttachments = 0;                   # No. of actual attachments found.

    /** @var ChangelogRow $dbrowNewestComment */
    public $dbrowNewestComment = NULL;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    /**
     *  The constructor offers two modes of operation:
     *
     *    * loading the changelog for one ticket (with $ticket_id != NULL)
     *
     *    * or loading the global changelog if ticket_id == NULL;
     *      in that case, you can also optionally restrict the global changelog
     *      to a set of particular ticket fields (e.g. FIELD_COMMENT).
     */
    public function __construct($ticket_id = NULL,
                                $llFieldIDs = NULL,
                                $flSee = NULL,
                                int $cLimit = NULL,
                                int $cOffset = NULL)
    {
        $this->ticket_id = $ticket_id;

        if ($flSee === NULL)
            $flSee = FIELDFL_EMPTYTICKETEVENT | FIELDFL_STD_DATA_OLD_NEW;

        $aFields0 = TicketField::GetAll(TRUE);
        $aFields = [];
        # If caller wants to restrict the changelog to the given field IDs, then get the TicketField objects for those.
        if ($llFieldIDs)
        {
            foreach ($llFieldIDs as $field_id)
                $aFields[$field_id] = $aFields0[$field_id];
        }
        else
            # Otherwise use all field IDs.
            $aFields = $aFields0;

        # Now filter by $flSee.
        $llFieldsUse = [];
        foreach ($aFields as $field_id => $oField)
            if ($oField->fl & $flSee)
                $llFieldsUse[] = $field_id;

        $this->where = "WHERE (changelog.field_id IN (".Database::MakeInIntList($llFieldsUse).'))';

        if ($ticket_id)
            $this->where .= "\n  AND (changelog.what = $ticket_id)";

        $limit = '';
        if ($cLimit)
        {
            $limit .= 'LIMIT '.$cLimit;
            if ($cOffset)
                $limit .= ' OFFSET '.$cOffset;
        }

        $res = Database::DefaultExec(<<<EOD
SELECT
    changelog.i,
    changelog.what
FROM changelog
{$this->where}
ORDER BY changelog.chg_dt DESC
$limit;
EOD
                                 );
        while ($row = Database::GetDefault()->fetchNextRow($res))
            $this->aRowsAndWhats += [ $row['i'] => $row['what'] ];
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  Loads the memory-consuming details for the given window into a member
     *  variable.
     *
     *  In global changelog mode (no ticket_id was given to the constructor), the caller can
     *  then use $this->fetchNextRow() to get the actual data.
     *
     *  Otherwise (ticket mode), this immediately loads all rows into $this->aChangelogRows,
     *  calling that function, filling the array with the data in the format described there.
     */
    public function loadDetails($aRowIDs = NULL,                //!< in: flat array (list) of row IDs from the stage-1 changelog.
                                $fAllTextFields = FALSE)
    {
        $FIELD_COMMENT = FIELD_COMMENT;
        $FIELD_COMMENT_UPDATED = FIELD_COMMENT_UPDATED;
        $FIELD_ATTACHMENT = FIELD_ATTACHMENT;
        $FIELD_STATUS = FIELD_STATUS;

        $where2 = $this->where;
        if ($aRowIDs)
            $where2 .= "\n  AND changelog.i IN (".implode(', ', $aRowIDs).')';

        $cols2 = '';
        if ($fAllTextFields)
            $cols2 = <<<EOD

    ticket_binaries.special,
    texts_old.value AS text_old,
    texts_new.value AS text_new,
EOD;

        $sql = <<<SQL
SELECT
    changelog.i,
    changelog.field_id,
    ticket_fields.name AS fieldname,
    ticket_fields.tblname,
    changelog.what,
    changelog.chg_uid,
    users.login,
    users.longname,
    changelog.chg_dt,
    changelog.value_1,
    changelog.value_2,
    changelog.value_str,
    ints_old.value AS int_old,
    ints_new.value AS int_new,
    ticket_texts.value AS comment,
    ticket_binaries.filename,
    ticket_binaries.mime,
    ticket_binaries.size AS filesize,
    ticket_binaries.cx AS cx,
    ticket_binaries.cy AS cy,
    ticket_binaries.hidden AS hidden,$cols2
    ticket_types.workflow_id
FROM changelog
JOIN ticket_fields ON (changelog.field_id = ticket_fields.i)
LEFT JOIN users ON (changelog.chg_uid = users.uid)
LEFT JOIN ticket_ints ints_old ON (     (ticket_fields.i = changelog.field_id)
                                    AND (tblname = 'ticket_ints')
                                    AND (changelog.value_1 = ints_old.i) )
LEFT JOIN ticket_ints ints_new ON (     (ticket_fields.i = changelog.field_id)
                                    AND (tblname = 'ticket_ints')
                                    AND (changelog.value_2 = ints_new.i) )
SQL;

    if ($fAllTextFields)
        $sql .= <<<SQL

LEFT JOIN ticket_texts texts_old ON (   (ticket_fields.i = changelog.field_id)
                                    AND (tblname = 'ticket_texts')
                                    AND (changelog.value_1 = texts_old.i) )
LEFT JOIN ticket_texts texts_new ON (   (ticket_fields.i = changelog.field_id)
                                    AND (tblname = 'ticket_texts')
                                    AND (changelog.value_2 = texts_new.i) )
SQL;

    $sql .= <<<SQL

LEFT JOIN ticket_texts ON (   (    changelog.field_id = $FIELD_COMMENT
                               AND ticket_texts.field_id = $FIELD_COMMENT
                               AND changelog.value_1 = ticket_texts.i)
                           OR (    changelog.field_id = $FIELD_COMMENT_UPDATED
                               AND ticket_texts.field_id = $FIELD_COMMENT
                               AND changelog.value_2 = ticket_texts.i) )
LEFT JOIN ticket_binaries ON (changelog.field_id = $FIELD_ATTACHMENT AND changelog.value_1 = ticket_binaries.i)
LEFT JOIN tickets ON (changelog.field_id = $FIELD_STATUS AND changelog.what = tickets.i)
LEFT JOIN ticket_types ON (changelog.field_id = $FIELD_STATUS AND tickets.type_id = ticket_types.i)
$where2
ORDER BY changelog.chg_dt DESC;
SQL;

        $this->res = Database::DefaultExec($sql);

        # Only in ticket mode, preload all rows immediately and store them in aChangelogRows.
        if ($this->ticket_id)
            while ($oRow = $this->fetchNextRow())
            {
                if ($oRow->field_id == FIELD_ATTACHMENT)
                    ++$this->cAttachments;
                else if ($oRow->field_id == FIELD_COMMENT)
                {
                    ++$this->cComments;
                    //TODO get latest version of the comment
                    # First comment coming in is the newest.
                    if (!$this->dbrowNewestComment)
                        $this->dbrowNewestComment = $oRow;
                }

                $this->aChangelogRows[] = $oRow;
            }
    }

    /**
     *  Returns another row from the changelog data as an array with the following
     *  fields:
     *
     *   -- i           -- primary index of changelog row
     *   -- field_id    -- ID of field that was changed (e.g. FIELD_TITLE)
     *   -- fieldname   -- matching field name from ticket_fields table (e.g. "title")
     *   -- tblname     -- matching table name from ticket_fields table
     *   -- what        -- meaning depends on field ID, but typically the ticket ID for ticket changes
     *   -- chg_uid     -- ID of user making the change
     *   -- login       -- matching login from users table
     *   -- longname    -- matching real name from users table
     *   -- chg_dt      -- timestamp of the change
     *   -- value_1     -- meaning depends on field ID, but typically the old row ID in 'tblname'
     *   -- value_2     -- meaning depends on field ID, but typically the new row ID in 'tblname'
     *   -- value_str   -- meaning depends on field ID
     *   -- comment     -- for FIELD_COMMENT rows, the comment text
     *   -- workflow_id -- for FIELD_STATUS, the process ID from the ticket's type (see TicketWorkflow and StatusHandler)
     *
     *  Do not call this in ticket mode. This has already been called by loadDetails() in that case.
     *
     *  @return ChangelogRow|null
     */
    public function fetchNextRow()
    {
        if ($row = Database::GetDefault()->fetchNextRow($this->res))
            return new ChangelogRow($row);

        return NULL;
    }

    /**
     *  @return ChangelogRow|null
     */
    public function findRow(int $rowID)
    {
        foreach ($this->aChangelogRows as $oRow)
        {
            if ($oRow->i == $rowID)
                return $oRow;
        }

        return NULL;
    }

    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Adds a new line to the system changelog for a ticket change. These types
     *  of changelog entries will appear in both the system and the ticket changelog.
     *
     *  For ticket fields that have FIELDFL_STD_DATA_OLD_NEW, but NOT FIELDFL_ARRAY
     *  set, whenever the value of a ticket field changes, we do NOT overwrite the
     *  old value in the table row specified by ticket_fields.tblname. Instead, a
     *  NEW row is added to that table with the ticket_id and field_id like in the
     *  old row. The old row's ticket_id is then set to NULL so that the defalt
     *  ticket query will no longer find it.
     *
     *  The old row (with the NULL ticket_id) is then pointed to only by a new changelog
     *  row created by this call (which in turn contains the ticket id in its "what" column).
     *
     *  The format for such changelog rows is thus as follows:
     *
     *   -- oldRowId points to the row ID (in the table specified by ticket_fields.tblname)
     *      that has the previous ticket field value.
     *
     *   -- newRowId points to the row ID that is now in use by the ticket.
     *
     *  For ticket fields that have FIELDFL_ARRAY set, things are more complicated.
     *
     *   -- For "plain" FIELDFL_ARRAY fields (without a reverse companion,
     *      e.g. FIELD_KEYWORDS), we just add a comma-separated list of values to the ticket, each prefixed with
     *      '+' (for 'added') or '-' (for 'removed').
     *
     *   -- For FIELDFL_ARRAY fields that also have FIELDFL_ARRAY_HAS_REVERSE set (e.g. FIELD_PARENTS), we do
     *      the same, but for each such parent ticket that was added / removed, we also need to add a changelog
     *      entry to the other ticket, with the other field ID.
     *
     *   -- For fields with FIELDFL_ARRAY_REVERSE set (e.g. FIELD_CHILDREN), the same applies, but with the
     *      field ID of the companion field with FIELDFL_ARRAY set (e.g. FIELD_PARENTS).
     */
    public static function AddTicketChange($field_id,
                                           $ticket_id,
                                           $chg_uid,
                                           $dtNow,
                                           $oldRowId,
                                           $newRowId,
                                           $value_str = NULL)
    {
        Database::DefaultExec(<<<EOD
INSERT INTO changelog
    ( field_id,   what,        chg_uid,   chg_dt,  value_1,    value_2,    value_str ) VALUES
    ( $1,         $2,          $3,        $4,      $5,         $6,         $7 )
EOD
  , [ $field_id,  $ticket_id,  $chg_uid,  $dtNow,  $oldRowId,  $newRowId,  $value_str ]);
    }

    /**
     *  Adds a new line to the system changelog only. This is for events like "user created"
     *  which do not relate to a particular ticket.
     */
    public static function AddSystemChange($field_id,
                                           $what,
                                           $value_1 = NULL,
                                           $value_2 = NULL,
                                           $value_str = NULL)
    {
        $chg_uid = ( LoginSession::$ouserCurrent ) ? LoginSession::$ouserCurrent->uid : NULL;

        $dtNow = gmdate('Y-m-d H:i:s');
        Database::DefaultExec(<<<EOD
INSERT INTO changelog ( field_id,   what,   chg_uid,   chg_dt,  value_1,    value_2,   value_str ) VALUES
                      ( $1,         $2,     $3,        $4,      $5,         $6,        $7 )
EOD
               , array( $field_id,  $what,  $chg_uid,  $dtNow,  $value_1,   $value_2,  $value_str));
    }

    /**
     *  Counts the rows in the changelog table and thus the changelog entries.
     *
     *  @return int
     */
    public static function CountRows()
        : int
    {
        $res = Database::DefaultExec("SELECT COUNT(i) as count FROM changelog");
        $row = Database::GetDefault()->fetchNextRow($res);
        return $row['count'];
    }
}
