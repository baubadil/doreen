<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  ChangelogRow class
 *
 ********************************************************************/

/**
 *  ChangelogRow represents a single row from the changelog.
 */
class ChangelogRow
{
    public $i;              # primary index of changelog row
    public $field_id;       # ID of field that was changed (e.g. FIELD_TITLE)
    public $fieldname;      # matching field name from ticket_fields table (e.g. "title")
    public $tblname;        # matching table name from ticket_fields table
    public $what;           # meaning depends on field ID, but typically the ticket ID for ticket changes
    public $chg_uid;        # ID of user making the change
    public $login;          # matching login from users table
    public $longname;       # matching real name from users table
    public $chg_dt;         # timestamp of the change
    public $value_1;        # meaning depends on field ID, but typically the old row ID in 'tblname'
    public $value_2;        # meaning depends on field ID, but typically the new row ID in 'tblname'
    /** @var string|null $value_str */
    public $value_str;      # meaning depends on field ID
    public $comment;        # for FIELD_COMMENT, FIELD_COMMENT_UPDATED rows, the comment text
    public $workflow_id;    # for FIELD_STATUS, the process ID from the ticket's type (see TicketWorkflow and StatusHandler)

    public $int_old;
    public $int_new;
    /** @var string|null $text_old */
    public $text_old;
    /** @var string|null $text_new */
    public $text_new;

    // For attachments:
    public $filename;
    public $mime;
    public $filesize;
    public $cx;
    public $cy;
    public $hidden;

    public $special;

    const A_FIELDS = [ 'i',
                       'field_id',
                       'fieldname',
                       'tblname',
                       'what',
                       'chg_uid',
                       'login',
                       'longname',
                       'chg_dt',
                       'value_1',
                       'value_2',
                       'value_str',
                       'comment',
                       'workflow_id',
                       'int_old',
                       'int_new',
                       'filename',
                       'mime',
                       'filesize',
                       'cx',
                       'cy',
                       'hidden',
                       'special',
                       'text_old',
                    ];

    public function __construct(array $dbrow)
    {
        foreach ( self::A_FIELDS as $key)
        {
            if ($key === 'hidden')
                $this->$key = $dbrow[$key] == 't';
            else
                $this->$key = $dbrow[$key] ?? NULL;
        }
    }

    /**
     *  Finds the ticket id a given changelog row belongs to.
     *
     *  @return int|null
     */
    public static function FindTicketIDForRow(int $rowId)
    {
        $res = Database::DefaultExec("SELECT what FROM changelog WHERE i = $1", [ $rowId ]);
        $aRow = Database::GetDefault()->fetchNextRow($res);
        if (!$aRow)
            return NULL;
        return (int)$aRow['what'];
    }
}
