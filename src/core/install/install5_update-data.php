<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Active included code
 *
 ********************************************************************/

$timestamp = Database::GetDefault()->timestampUTC;
$lenIdentifier = LEN_IDENTIFIER;
$lenLongname = LEN_LONGNAME;
$lenLoginName = LEN_LOGINNAME;
$lenEmail = LEN_EMAIL;

#
#  This gets included from TWO places: install2+3.php for the initial install
#  (so that all required tables are created up-to-date for an initial install)
#  and from install5_update.php when index.php has determined that the database
#  needs updating because a new version of Doreen was copied onto the server.
#

if (GlobalConfig::$databaseVersion)
{
    #
    #  VERSION 2
    #

    if (GlobalConfig::$databaseVersion < 2)
    {
        GlobalConfig::AddPrio2Install('Create "All users" group', <<<EOD
INSERT INTO groups (gname)
VALUES             ('All users')
EOD
        );
        GlobalConfig::AddPrio2Install('Create "Administrators" group', <<<EOD
INSERT INTO groups (gname)
VALUES             ('Administrators')
EOD
        );
        GlobalConfig::AddPrio2Install('Create "Gurus" group', <<<EOD
INSERT INTO groups (gname)
VALUES             ('Gurus')
EOD
        );
        GlobalConfig::AddPrio2Install('Create "Editors" group', <<<EOD
INSERT INTO groups (gname)
VALUES             ('Editors')
EOD
        );
    /**
     *  The 'acls' table, together with \ref acl_entries, is part of the implementation of access control lists.
     *  These are mapped into PHP by the ACL class.
     *  The 'acls' table itself only has the numeric ACL ID (aid) and the ACL name, which is generated automatically.
     *  The m:n relation between groups, permissions and the ACL is contained in \ref acl_entries.
     *
     *  See \ref access_control for an introduction how Doreen implements access control.
     */
        GlobalConfig::AddPrio1Install('Create "acls" table', <<<EOD
CREATE TABLE acls (
    aid         SERIAL PRIMARY KEY,
    name        VARCHAR($lenLongname)
)
EOD
        );
    /**
     *  The 'acl_entries' table is the companion to the \ref acls table. See remarks there.
     *
     *  See \ref access_control for an introduction how Doreen implements access control.
     */
        GlobalConfig::AddPrio1Install('Create "acl_entries" table', <<<EOD
CREATE TABLE acl_entries (
    i           SERIAL PRIMARY KEY,
    aid         INTEGER NOT NULL REFERENCES acls(aid),
    gid         INTEGER NOT NULL,                   -- cannot reference groups.gid because GUESTS etc. are not in database
    permissions SMALLINT NOT NULL                   -- read, write, create, delete flags
)
EOD
        );
    /**
     *  The 'ticket_fields' table defines the ticket fields that are available for this Doreen installation. This table
     *  is written only at install time (and possibly updated during upgrade installations), but otherwise read-only.
     *  Every row defines one ticket field that can be linked to a ticket type via the \ref ticket_type_details table.
     *
     *  In the code, this is mapped to the TicketField class.
     */
        GlobalConfig::AddPrio1Install('Create "ticket_fields" table', <<<EOD
CREATE TABLE ticket_fields (
    i           SMALLINT PRIMARY KEY,
    parent      SMALLINT DEFAULT NULL,
    name        VARCHAR($lenIdentifier) NOT NULL,
    tblname     VARCHAR($lenIdentifier) NOT NULL,
    fl          INTEGER DEFAULT 0,
    ordering    FLOAT DEFAULT 0
)
EOD
        );

    /**
     *  Part of the ticket process implementation. See \ref workflows.
     */
        GlobalConfig::AddPrio1Install('Create "status_values" table', <<<EOD
CREATE TABLE status_values (
    i               SERIAL PRIMARY KEY,
    name            VARCHAR($lenIdentifier) NOT NULL,
    html_color      VARCHAR(30) NOT NULL
)
EOD
        );

    /**
     *  The 'workflows' table represents a ticket process. It works together with \ref status_values, \ref workflow_statuses and \ref state_transitions.
     *
     *  See TicketWorkflow for details.
     */
        GlobalConfig::AddPrio1Install('Create "workflows" table', <<<EOD
CREATE TABLE workflows (
    i               SERIAL PRIMARY KEY,
    name            VARCHAR($lenLongname) NOT NULL,
    initial         SMALLINT REFERENCES status_values(i)
)
EOD
        );
    /**
     *  Part of the ticket process implementation. See \ref workflows.
     */
        GlobalConfig::AddPrio1Install('Create "workflow_statuses" table', <<<EOD
CREATE TABLE workflow_statuses (
    i               SERIAL PRIMARY KEY,
    workflow_id     SMALLINT REFERENCES workflows(i),    -- if NULL, then no status field
    status_id       SMALLINT REFERENCES status_values(i)
)
EOD
        );
    /**
     *  Part of the ticket process implementation. See \ref workflows.
     */
        GlobalConfig::AddPrio1Install('Create "state_transitions" table', <<<EOD
CREATE TABLE state_transitions (
    i               SERIAL PRIMARY KEY,
    workflow_id     SMALLINT REFERENCES workflows(i),    -- if NULL, then no status field
    from_status     SMALLINT REFERENCES status_values(i),
    to_status       SMALLINT REFERENCES status_values(i)
)
EOD
        );

        GlobalConfig::AddPrio1Install('Create entries for "Task" ticket process', function()
        {
            Database::GetDefault()->insertMany('status_values',
                                               [ 'i',              'name',     'html_color' ],
                                               [ STATUS_OPEN,      'open',     'blue',
                                                  STATUS_REMINDER,  'reminder', '#a0a0a0',
                                                  STATUS_CLOSED,    'closed',   '#004000'
                                                ] );
            Database::GetDefault()->insertMany('workflows',
                                               [ 'i',              'name',                           'initial' ],
                                               [ WORKFLOW_TASK, "Open / reminder / closed cycle", STATUS_OPEN
                                                ] );

            Database::GetDefault()->insertMany('workflow_statuses',
                                               [ 'workflow_id', 'status_id' ],
                                               [ WORKFLOW_TASK, STATUS_OPEN,
                                                 WORKFLOW_TASK, STATUS_REMINDER,
                                                 WORKFLOW_TASK, STATUS_CLOSED
                                                ] );
            Database::GetDefault()->insertMany('state_transitions',
                                               [ 'workflow_id', 'from_status',   'to_status' ],
                                               [ WORKFLOW_TASK, STATUS_OPEN, STATUS_REMINDER,
                                                 WORKFLOW_TASK, STATUS_OPEN, STATUS_CLOSED,
                                                 WORKFLOW_TASK, STATUS_REMINDER, STATUS_OPEN,
                                                 WORKFLOW_TASK, STATUS_REMINDER, STATUS_CLOSED,
                                                 WORKFLOW_TASK, STATUS_CLOSED, STATUS_OPEN,
                                                ] );
        });

    /**
     *  This is mapped to TicketType instances.
     */
        GlobalConfig::AddPrio1Install('Create "ticket_types" table', <<<EOD
CREATE TABLE ticket_types (
    i               SERIAL PRIMARY KEY,
    name            TEXT NOT NULL,                      -- originally varchar, changed with version 78
    parent_type_id  SMALLINT REFERENCES ticket_types(i),    -- either NULL or same as i or other type
    workflow_id     SMALLINT REFERENCES workflows(i),    -- if NULL, then no status field
    fl              INT DEFAULT 0
)
EOD
        );

        /**
         *  This is mapped to Category instances.
         */
        GlobalConfig::AddPrio1Install('Create "categories" table', <<<SQL
CREATE TABLE categories (
    i           SERIAL PRIMARY KEY,
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    name        VARCHAR($lenLongname),
    parent      INTEGER REFERENCES categories(i) DEFAULT NULL,
    extra       TEXT
)
SQL
        );

    /**
     *  This is mapped to Keyword instances.
     */
        GlobalConfig::AddPrio1Install('Create "keyword_defs" table', <<<EOD
CREATE TABLE keyword_defs (
    i           SERIAL PRIMARY KEY,
    keyword     VARCHAR($lenLongname)
);
EOD
        );

    /**
     *  The 'ticket_type_details' table is used to represent the m:n relation between ticket fields and ticket types.
     *  If a ticket field should be visible in details views of a given ticket type, a row with both IDs is added here.
     *  If the field should also be visible in list views, then is_in_list is TRUE as well.
     *
     *  This is mapped to an array in TicketType.
     */
        GlobalConfig::AddPrio1Install('Create "ticket_type_details" table', <<<EOD
CREATE TABLE ticket_type_details (
    i           SERIAL PRIMARY KEY,
    type_id     SMALLINT NOT NULL REFERENCES ticket_types(i),
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    is_in_list  BOOLEAN                             -- in: if true, show this detail in list views as well
)
EOD
        );

    /**
     *  The 'tickets' table is the heart of Doreen's data management, even though it has only a few columns. Every ticket
     *  has a row in here, and the 'i' field is its ticket ID. Additional data is pulled from other tables depending
     *  on which ticket fields have been configured for the ticket's type, which is pulled out of the type_id column.
     *  Among those other tables are \ref ticket_ints, \ref ticket_texts, \ref ticket_parents.
     *
     *  See \ref intro_ticket_types for an introduction how data is pulled together.
     */
        GlobalConfig::AddPrio1Install('Create "tickets" table', <<<EOD
CREATE TABLE tickets (
    i               SERIAL PRIMARY KEY,
    template        VARCHAR($lenLongname) DEFAULT NULL,     -- if not NULL, this ticket is a template
    type_id         SMALLINT NOT NULL REFERENCES ticket_types(i),
    project_id      INTEGER REFERENCES categories(i),
    aid             INTEGER REFERENCES acls(aid),           -- access rights for this ticket ('read', 'write', 'delete'; 'create' only respected for templates)
    owner_uid       INTEGER,                                -- user ID, but can be outside database depending on plugin; NULL for templates
    created_dt      $timestamp,                             -- NULL for templates
    lastmod_uid     INTEGER,                                -- user ID, but can be outside database depending on plugin; NULL for templates
    lastmod_dt      $timestamp,                             -- NULL for templates
    created_from    INT REFERENCES tickets(i) DEFAULT NULL  -- NULL for templates
)
EOD
        );

        /**
         *  The 'ticket_categories' table is part of the ticket data implementation. The row for FIELD_CATEGORY in
         *  ticket_fields points to this table. This looks similar to ticket_ints, but an additional requirement
         *  is that the field_id must also be present in the \ref categories table. This works together with
         *  the Category and the CategoryHandlerBase classes so that when a ticket type has a field
         *  FIELD_CATEGORY (or some other field ID with categories defined), every ticket of that type will have
         *  a string value from the enumeration represented by that category.
         */
        GlobalConfig::AddPrio1Install('Create "ticket_categories" table', <<<SQL
CREATE TABLE ticket_categories (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER REFERENCES tickets(i) ON DELETE CASCADE,       -- can be NULL for values no longer in use but only referenced by changelog
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    value       INTEGER REFERENCES categories(i) NOT NULL
)
SQL
        );

    }

    if (GlobalConfig::$databaseVersion < 14)
    {
        /**
         *  The 'ticket_parents' table is part of the ticket data implementation. The row for FIELD_PARENT in \ref ticket_fields points to this table.
         */
        GlobalConfig::AddPrio1Install('Create "ticket_parents" table', <<<EOD
CREATE TABLE ticket_parents (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER REFERENCES tickets(i) ON DELETE CASCADE,       -- can be NULL for values no longer in use but only referenced by changelog
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    value       INTEGER NOT NULL REFERENCES tickets(i) ON DELETE CASCADE,
    count       INTEGER NOT NULL DEFAULT 1
)
EOD
        );

        # ticket_parents is in a separate table because we want to be able to get all tickets by their parent,
        # and we should thus have an index on 'value' (the parent ticket)
        GlobalConfig::AddPrio1Install(/** @lang text */
            'Create index on "ticket_parents" table',
            "CREATE INDEX ON ticket_parents (value)");
    }

    # Re-insert the whole table fields on every database version change because it pretty much changes every time and this function allows us to use PHP constants directly for ease of typing.
    GlobalConfig::AddPrio1Install('Refresh global ticket field values', function()
    {
        $a = [
        # i,                                 parent,  name (NO SPACES!),          tblname,           ordering, fl
        FIELD_TYPE                      => [ NULL,    'type',                     '',                   0.1  ],
        FIELD_CREATED_DT                => [ NULL,    'created',                  '',                   0.2  ],
        FIELD_CREATED_UID               => [ NULL,    'created_uid',              '',                   0.2  ],
        FIELD_LASTMOD_DT                => [ NULL,    'changed',                  '',                   0.3  ],
        FIELD_LASTMOD_UID               => [ NULL,    'changed_uid',              '',                   0.3  ],
        FIELD_TITLE                     => [ NULL,    'title',                    'ticket_texts',       1    ],
        FIELD_DESCRIPTION               => [ NULL,    'description',              'ticket_texts',       2    ],
        FIELD_PROJECT                   => [ NULL,    'project',                  'ticket_categories',  2.5  ],
        FIELD_CHILDREN                  => [ NULL,    'children',                 'ticket_parents',     2.81 ],
        FIELD_PARENTS                   => [ NULL,    'parents',                  'ticket_parents',     2.82 ],
        FIELD_KEYWORDS                  => [ NULL,    'keywords',                 'ticket_ints',        3    ],
                                                                                            # fields created by type_bug occupy orderings 4--8
        FIELD_PRIORITY                  => [ NULL,    'priority',                 'ticket_ints',        6    ],
        FIELD_UIDASSIGN                 => [ NULL,    'assignee',                 'ticket_ints',        9    ],
        FIELD_STATUS                    => [ NULL,    'status',                   'ticket_ints',        8    ],

        FIELD_IMPORTEDFROM              => [ NULL,    'importedfrom',             'ticket_ints',       21    ],
        FIELD_IMPORTEDFROM_PERSONID     => [ NULL,    'importedfromcontact',      'ticket_ints',       21    ],

        FIELD_CHANGELOG                 => [ NULL,    'changelog',                '',                  90    ],
        FIELD_COMMENT                   => [ NULL,    'comment',                  'ticket_texts',      91    ],
        FIELD_OLDCOMMENT                => [ NULL,    'old comment',              'ticket_texts',      92    ],
        FIELD_ATTACHMENT                => [ NULL,    'attachment',               'ticket_binaries',   93    ],
        FIELD_ATTACHMENT_RENAMED        => [ NULL,    'attachment renamed',       'ticket_binaries',    0    ],
        FIELD_ATTACHMENT_DELETED        => [ NULL,    'attachment deleted',       'ticket_binaries',    0    ],

        FIELD_SYS_USER_CREATED          => [ NULL,    'user account created',     'users',              0    ],
        FIELD_SYS_USER_PASSWORDCHANGED  => [ NULL,    'password changed',         'users',              0    ],
        FIELD_SYS_USER_LONGNAMECHANGED  => [ NULL,    'real name changed',        'users',              0    ],
        FIELD_SYS_USER_EMAILCHANGED     => [ NULL,    'email changed',            'users',              0    ],
        FIELD_SYS_USER_ADDEDTOGROUP     => [ NULL,    'user added to group',      'users',              0    ],
        FIELD_SYS_USER_REMOVEDFROMGROUP => [ NULL,    'user removed from group',  'users',              0    ],
        FIELD_SYS_USER_DISABLED         => [ NULL,    'user account disabled',    'users',              0    ],
        FIELD_SYS_USER_FTICKETMAILCHANGED
                                        => [ NULL,    'ticket mail changed',      'users',              0    ],
        FIELD_SYS_USER_PERMITLOGINCHANGED
                                        => [ NULL,    'login perm. changed',      'users',              0    ],
        FIELD_SYS_USER_LOGINCHANGED     => [ NULL,    'login name changed',       'users',              0    ],
        FIELD_SYS_GROUP_CREATED         => [ NULL,    'group created',            'groups',             0    ],
        FIELD_SYS_GROUP_NAMECHANGED     => [ NULL,    'group name changed',       'groups',             0    ],
        FIELD_SYS_GROUP_DELETED         => [ NULL,    'group deleted',            'groups',             0    ],
        FIELD_TICKET_CREATED            => [ NULL,    'ticket created',           'tickets',            0    ],
        FIELD_TICKET_TEMPLATE_CREATED   => [ NULL,    'ticket template created',  'tickets',            0    ],
        FIELD_SYS_TEMPLATE_DELETED      => [ NULL,    'ticket template deleted',  'tickets',            0    ],
        FIELD_SYS_TICKETTYPE_CREATED    => [ NULL,    'ticket type created',      'ticket_types',       0    ],
        FIELD_SYS_TICKETTYPE_CHANGED    => [ NULL,    'ticket type changed',      'ticket_types',       0    ],
        FIELD_SYS_TICKETTYPE_DELETED    => [ NULL,    'ticket type deleted',      'ticket_types',       0    ],
        FIELD_TEMPLATE_UNDER_TICKET_CHANGED => [ NULL, 'template changed',        '',                   0,   ],
        FIELD_COMMENT_UPDATED           => [ NULL,    'comment updated',          'tickets',            0,   ],
        FIELD_COMMENT_DELETED           => [ NULL,    'comment deleted',          'tickets',            0,   ],
             ];

        # Postgres doesn't have "INSERT OR UPDATE", so we need to do this by hand. We can't just nuke the table before re-inserting
        # every value because many other table rows depend on these and have foreign key restraints.
        $aExist = [];
        $res0 = Database::DefaultExec("SELECT i FROM ticket_fields");
        while ($row = Database::GetDefault()->fetchNextRow($res0))
            $aExist[$row['i']] = 1;

        foreach ($a as $field_id => $a2)
        {
            if (isset($aExist[$field_id]))
                Database::DefaultExec(
                      "UPDATE ticket_fields SET parent = $1, name = $2, tblname = $3, ordering = $4 WHERE i = $5",
                                                       [ $a2[0],    $a2[1],       $a2[2],        $a2[3],      $field_id ]);
            else
                Database::DefaultExec(
                        "INSERT INTO ticket_fields ( i,          parent,  name,    tblname,  ordering ) VALUES
                                                   ( $1,         $2,      $3,      $4,       $5       )",
                                                   [ $field_id,  $a2[0],  $a2[1],  $a2[2],   $a2[3]   ] );
        }
    });

    if (GlobalConfig::$databaseVersion < 2)
    {
        $dbBlob = Database::GetDefault()->blob;
/**
 *  The 'changelog' table is the one place that logs all system and ticket events. Both the system changelog and individual ticket
 *  changelogs are pulled from this table. See the Changelog class for details.
 */
        GlobalConfig::AddPrio1Install('Create "changelog" table', <<<EOD
CREATE TABLE changelog (
    i           SERIAL PRIMARY KEY,
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),  -- from field_id  we deduce the table of the old value
    what        INTEGER,                                        -- can be ticket ID or something else for system events
    chg_uid     INTEGER,                                        -- user ID, but can be outside database depending on plugin
    chg_dt      $timestamp,                                     -- NULL for templates
    value_1     INTEGER DEFAULT NULL,                           -- for ticket updates, row index of the old value in the table referenced by field_id
    value_2     INTEGER DEFAULT NULL,                           -- for ticket updates, row index of the new value in the table referenced by field_id
    value_str   TEXT DEFAULT NULL                               -- for system changes. arbitrary text
)
EOD
        );
/**
 *  The 'ticket_texts' table is part of the ticket data implementation. Many ticket fields that are defined with string data in \ref ticket_fields points to this table.
 */
        GlobalConfig::AddPrio1Install('Create "ticket_texts" table', <<<EOD
CREATE TABLE ticket_texts (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER REFERENCES tickets(i) ON DELETE CASCADE,          -- can be NULL for values no longer in use but only referenced by changelog
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    value       TEXT NOT NULL
)
EOD
        );
/**
 *  The 'ticket_ints' table is part of the ticket data implementation. Many ticket fields that are defined with integer data in \ref ticket_fields points to this table.
 */
        GlobalConfig::AddPrio1Install('Create "ticket_ints" table', <<<EOD
CREATE TABLE ticket_ints (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER REFERENCES tickets(i) ON DELETE CASCADE,          -- can be NULL for values no longer in use but only referenced by changelog
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    value       INTEGER
)
EOD
        );
/**
 *  The 'ticket_amounts' table is part of the ticket data implementation. Many ticket fields that are defined with monetary data in \ref ticket_fields points to this table.
 */
        GlobalConfig::AddPrio1Install('Create "ticket_amounts" table', <<<EOD
CREATE TABLE ticket_amounts (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER REFERENCES tickets(i) ON DELETE CASCADE,        -- can be NULL for values no longer in use but only referenced by changelog
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    value       DECIMAL(15,2)
)
EOD
        );
/**
 *  The 'ticket_binaries' table is part of the ticket data implementation.
 *  This is used for binary ticket attachments (uploaded files) and the Binary class.
 */
        GlobalConfig::AddPrio1Install('Create "ticket_binaries" table', <<<EOD
CREATE TABLE ticket_binaries (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER REFERENCES tickets(i) ON DELETE CASCADE,          -- can be NULL for values no longer in use but only referenced by changelog
    filename    TEXT,
    mime        TEXT NOT NULL,
    size        INT NOT NULL,
    data        $dbBlob
)
EOD
        );
    # TICKET TYPE 'wiki'
        GlobalConfig::AddInstall('Create ticket type "Wiki article"', function()
        {
            $GLOBALS['otypeWiki'] = TicketType::Create("Wiki article",
                                                       NULL,        # parent_type_id
                                                       implode(',', array(FIELD_TITLE, FIELD_DESCRIPTION)),
                                                       implode(',', array(FIELD_TITLE)),
                                                       NULL);       # workflow
            GlobalConfig::Set(GlobalConfig::KEY_ID_TYPE_WIKI, $GLOBALS['otypeWiki']->id);
        });
        GlobalConfig::AddInstall('Create ticket template "Wiki article"', function()
        {
            $oTemplate = Ticket::CreateTemplate(NULL,
                                                "Wiki article",
                                                $GLOBALS['otypeWiki'],      # just created
                                                NULL,                       # no project ID
                                                [ Group::ALLUSERS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_MAIL,
                                                  Group::ADMINS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE | ACCESS_MAIL
                                                ]);
            GlobalConfig::Set(GlobalConfig::KEY_ID_TEMPLATE_WIKI, $oTemplate->id);
            GlobalConfig::Save();       # for all the three above
        });
    } # end version 2

//    if (GlobalConfig::$databaseVersion < 87)
//    {
//        GlobalConfig::AddPrio1Install('Add lang column to "ticket_texts" table',
//                                      'ALTER TABLE ticket_texts ADD COLUMN lang TEXT DEFAULT NULL');
//    }

    # This is only to eliminate the multi-lang ticket-text fields which didn't work.
    if ( (GlobalConfig::$databaseVersion >= 87) && (GlobalConfig::$databaseVersion < 88) )
    {
        GlobalConfig::AddPrio1Install('Remove lang column from "ticket_texts" table',
                                      'ALTER TABLE ticket_texts DROP COLUMN lang');
    }

    if (GlobalConfig::$databaseVersion < 15)
    {
        GlobalConfig::AddPrio1Install(/** @lang text */
            'Create index on "tickets" table (template)',
            'CREATE INDEX ON tickets (template);');
        GlobalConfig::AddPrio1Install(/** @lang text */
            'Create index on "tickets" table (aid)',
            'CREATE INDEX ON tickets (aid);');
    }

    if (GlobalConfig::$databaseVersion < 17)
    {
        GlobalConfig::AddInstall('Create ticket type "Task"', function()
        {
            $oWorkflow = TicketWorkflow::Find(WORKFLOW_TASK,
                                              TRUE);        # throw if not found
            $GLOBALS['otypeTask'] = TicketType::Create("Task",
                                                       NULL,            # parent_type_id
                                                       implode(',', [ FIELD_TITLE, FIELD_DESCRIPTION, FIELD_PRIORITY, FIELD_UIDASSIGN, FIELD_CHANGELOG, FIELD_COMMENT, FIELD_ATTACHMENT, FIELD_KEYWORDS, FIELD_PROJECT, FIELD_PARENTS, FIELD_STATUS ] ),
                                                       implode(',', [ FIELD_TITLE,                    FIELD_PRIORITY, FIELD_UIDASSIGN,                                                   FIELD_KEYWORDS, FIELD_PROJECT, FIELD_PARENTS, FIELD_STATUS ]),
                                                       $oWorkflow->id
                                                      );
            GlobalConfig::Set('id_task-type', $GLOBALS['otypeTask']->id);
        });

        GlobalConfig::AddInstall('Create ticket template "Task"', function()
        {
            $oTemplate = Ticket::CreateTemplate(NULL,
                                                "Task",
                                                $GLOBALS['otypeTask'],      # just created
                                                NULL,                       # no project ID
                                                [ Group::ALLUSERS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_MAIL,
                                                  Group::ADMINS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE | ACCESS_MAIL
                                                ]);
            GlobalConfig::Set('id_task-template', $oTemplate->id);
            GlobalConfig::Save();       # for all the three above
        });
    }

    if (GlobalConfig::$databaseVersion < 21)
    {
        /**
         *  The ticket_uuids table can be used for ticket fields that store UUIDs. For example, PluginFTDB
         *  uses this table.
         */
        GlobalConfig::AddPrio1Install('Create "ticket_uuids" table', <<<EOD
CREATE TABLE ticket_uuids (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER REFERENCES tickets(i) ON DELETE CASCADE,          -- can be NULL for values no longer in use but only referenced by changelog
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    value       UUID
)
EOD
        );
    }

    if (GlobalConfig::$databaseVersion < 22)
    {
        $dbBlob = Database::GetDefault()->blob;
        GlobalConfig::AddPrio1Install('Create "thumbnails" table', <<<EOD
CREATE TABLE thumbnails (
    i           SERIAL PRIMARY KEY,
    binary_id   INT NOT NULL,
    thumbsize   SMALLINT NOT NULL,
    data        $dbBlob
)
EOD
        );
    }

    if (GlobalConfig::$databaseVersion < 23)
    {
        GlobalConfig::AddPrio1Install(/** @lang text */
            'Create index (ticket_id) on "ticket_parents" table',
            "CREATE INDEX ON ticket_parents (ticket_id)");
        GlobalConfig::AddPrio1Install(/** @lang text */
            'Create index (field_id) on "ticket_parents" table',
            "CREATE INDEX ON ticket_parents (field_id)");
    }

    if ((GlobalConfig::$databaseVersion > 2) && (GlobalConfig::$databaseVersion < 25))
    {
        GlobalConfig::AddPrio1Install('Convert single-parent tickets to parent array format', function()
        {
//            $FIELD_PARENTS          = FIELD_PARENTS;
//            $FIELD_REMOVED_PARENT   = FIELD_REMOVED_PARENT;
            Database::DefaultExec("UPDATE ticket_parents SET field_id = $1 WHERE field_id = $2",
                                         [ FIELD_PARENTS, FIELD_REMOVED_PARENT ]);
            Database::DefaultExec("UPDATE ticket_type_details SET field_id = $1 WHERE field_id = $2",
                                         [ FIELD_PARENTS, FIELD_REMOVED_PARENT ]);
            Database::DefaultExec("DELETE FROM changelog WHERE field_id = $1", [ FIELD_REMOVED_PARENT ]);
//                                 Database::Get()->exec("DELETE FROM ticket_fields WHERE i = $1", [ FIELD_REMOVED_PARENT ]);
            # This must NOT BE removed because FIELD_CHILDREN is now FIELD_REMOVED_PARENT and has just been inserted correctly above.
        });
        GlobalConfig::AddPrio1Install('Fix FIELD_CHILDREN', function()
        {
            Database::DefaultExec("UPDATE ticket_type_details SET field_id = $1 WHERE field_id = $2",
                                         [ FIELD_CHILDREN, FIELD_REMOVED_CHILDREN ] );
            # New FIELD_CHILDREN row was written above.
            foreach ( [ FIELD_REMOVED_PARENT, FIELD_REMOVED_PARENTS, FIELD_REMOVED_CHILDREN ] as $f )
                Database::DefaultExec("DELETE FROM ticket_fields WHERE i = $1",
                                             [ $f ] );
        });
    }

    if (GlobalConfig::$databaseVersion < 29)
    {
        GlobalConfig::AddPrio1Install('Add "cx" and "cy" columns to ticket_binaries', <<<EOD
ALTER TABLE ticket_binaries
ADD COLUMN cx SMALLINT DEFAULT NULL,
ADD COLUMN cy SMALLINT DEFAULT NULL
EOD
        );

        GlobalConfig::AddPrio1Install('Determine image widths & heights of all existing attachments', function()
        {
            foreach (Ticket::GetAllAttachments() as $row)
            {
                $idBinary = $row['binary_id'];
                $mimetype = $row['mimetype'];

                if (Ticket::IsImage($mimetype))
                {
                    $oBinary = Binary::Load($idBinary);
                    if ($oBinary->localFile)
                    {
                        $aSize = getimagesize($oBinary->localFile);
                        $cx = $aSize[0];
                        $cy = $aSize[1];
                        Database::DefaultExec('UPDATE ticket_binaries SET cx = $1, cy = $2 WHERE i = $3',
                                                                             [ $cx,     $cy,         $idBinary ]);
                    }
                }
            }
        });
    }
    if (    (GlobalConfig::$databaseVersion != 1)       # 1 == fresh install; don't do this then because then we have already created the correct table above.
         && (GlobalConfig::$databaseVersion < 32)
       )
    {
        GlobalConfig::AddPrio1Install('Rename "processes" to "workflows"', function()
        {
            Database::DefaultExec("ALTER TABLE process_statuses    RENAME COLUMN process_id TO workflow_id;");
            Database::DefaultExec("ALTER TABLE state_transitions   RENAME COLUMN process_id TO workflow_id;");
            Database::DefaultExec("ALTER TABLE ticket_types        RENAME COLUMN process_id TO workflow_id;");
            Database::DefaultExec("ALTER TABLE process_statuses    RENAME TO workflow_statuses;");
            Database::DefaultExec("ALTER TABLE processes           RENAME TO workflows;");
        });
    }

    if (GlobalConfig::$databaseVersion < 33)
    {
        $timestamp = Database::GetDefault()->timestampUTC;

/**
 *  Table for the LongTask class.
 */
        GlobalConfig::AddPrio1Install('Create "longtasks" table', <<<EOD
CREATE TABLE longtasks (
    i           SERIAL PRIMARY KEY,
    description VARCHAR(200),
    command     TEXT,
    process_id  INTEGER,
    status      SMALLINT,       -- 0 = spawning, 1 = running, 2 = ended
    updated_dt  $timestamp,
    json_data   TEXT DEFAULT NULL
)
EOD
        );
    }

    if (GlobalConfig::$databaseVersion < 34)
    {
        $timestamp = Database::GetDefault()->timestampUTC;

        GlobalConfig::AddPrio1Install('Add start date to "longtasks" table', <<<EOD
ALTER TABLE longtasks
ADD COLUMN started_dt $timestamp
EOD
        );
    }

    if (GlobalConfig::$databaseVersion < 35)
    {
        GlobalConfig::AddPrio1Install('Create field ID indices on ticket data tables', function()
        {
            foreach ( [ 'ticket_amounts',
                        'ticket_ints',
                        'ticket_parents',
                        'ticket_categories',
                        'ticket_texts',
                        'ticket_uuids'
                      ] as $tblname )
                Database::DefaultExec("CREATE INDEX ON $tblname (field_id);");
        });
    }

    if (GlobalConfig::$databaseVersion < 36)
    {
        GlobalConfig::AddPrio1Install('Create ticket ID indices on ticket data tables', function()
        {
            foreach ( [ 'ticket_amounts',
                        'ticket_ints',
                        'ticket_parents',
                        'ticket_categories',
                        'ticket_texts',
                        'ticket_uuids'
                      ] as $tblname )
                Database::DefaultExec("CREATE INDEX ON $tblname (ticket_id);");
        });
    }

    if (GlobalConfig::$databaseVersion < 39)
    {
        GlobalConfig::AddPrio1Install(/** @lang text */
            'Create index on "value" in "ticket_ints" table for drill-down',
            "CREATE INDEX ON ticket_ints (value)");
    }

    if (GlobalConfig::$databaseVersion < 42)
    {
        GlobalConfig::AddPrio1Install('Remove ticket field flags from database', "ALTER TABLE ticket_fields DROP COLUMN fl;");
    }

    if (GlobalConfig::$databaseVersion < 45)
    {
        GlobalConfig::AddPrio1Install('Create "pwdresets" table', <<<EOD
CREATE TABLE pwdresets (
    i           SERIAL PRIMARY KEY,
    email       VARCHAR($lenEmail) NOT NULL,
    resettoken  VARCHAR(80) NOT NULL,
    insert_dt   $timestamp NOT NULL,
    max_age_minutes INTEGER NOT NULL DEFAULT 120
);
EOD
        );
    }

    if (GlobalConfig::$databaseVersion < 46)
    {
        GlobalConfig::AddPrio1Install(/** @lang text */
            'Create table for email queue', <<<EOD
CREATE TABLE emailq (
    i           SERIAL PRIMARY KEY,
    insert_dt   $timestamp NOT NULL,
    status      SMALLINT NOT NULL,
    data        TEXT                        -- JSON really
);
EOD
        );
    }

    if (GlobalConfig::$databaseVersion < 47)
    {
        GlobalConfig::AddPrio1Install('Extend table for email queue', <<<EOD
ALTER TABLE emailq
ADD COLUMN error TEXT DEFAULT NULL;
EOD
        );
    }

    if (GlobalConfig::$databaseVersion < 50)
    {
        GlobalConfig::AddPrio1Install("Generate random number as email queue identifier", function()
        {
            GlobalConfig::Set(GlobalConfig::KEY_EMAILQ_ID, mt_rand(999, 2147483647));
            GlobalConfig::Save();
        });
    }

    if (    (GlobalConfig::$databaseVersion > 2)        # not an initial install
         && (GlobalConfig::$databaseVersion < 51)
       )
    {
        GlobalConfig::AddPrio1Install("Add flags to ticket types", <<<EOD
ALTER TABLE ticket_types
ADD COLUMN fl INT DEFAULT 0
EOD
        );
    }

    if (GlobalConfig::$databaseVersion < 52)
    {
        GlobalConfig::AddPrio1Install('Extend ticket_binaries table', <<<EOD
ALTER TABLE ticket_binaries
ADD COLUMN special TEXT DEFAULT NULL;
EOD
        );
    }

    if (    (GlobalConfig::$databaseVersion > 2)        # not an initial install
         && (GlobalConfig::$databaseVersion < 53)
       )
    {
        GlobalConfig::AddPrio1Install('Add created_from to tickets table', <<<EOD
ALTER TABLE tickets
ADD COLUMN created_from INT REFERENCES tickets(i) DEFAULT NULL
EOD
        );
    }

    if (GlobalConfig::$databaseVersion < 55)
    {
        GlobalConfig::AddPrio1Install(/** @lang text */
            'Create index on tickets.type_id', "CREATE INDEX ON tickets (type_id)");
    }

    if (GlobalConfig::$databaseVersion < 58)
    {
        GlobalConfig::AddPrio1Install('Drop old unused login_attempts table', "DROP TABLE IF EXISTS login_attempts");
        GlobalConfig::AddPrio1Install('Create new login_logs table', <<<EOD
CREATE TABLE logins_log
(
    i           SERIAL PRIMARY KEY,
    login       VARCHAR($lenLoginName) NOT NULL,
    uid         INTEGER,
    failed_pass TEXT,
    dt          $timestamp NOT NULL
)
EOD
        );
    }

    if (    (GlobalConfig::$databaseVersion > 2)        # not an initial install
         && (GlobalConfig::$databaseVersion < 61)
       )
    {
        GlobalConfig::AddInstall('Add ON DELETE CASCADE to all ticket data tables', function()
        {
            Database::GetDefault()->beginTransaction();
            foreach ( [ 'ticket_parents',
                        'ticket_texts',
                        'ticket_texts',
                        'ticket_ints',
                        'ticket_categories',
                        'ticket_amounts',
                        'ticket_binaries',
                        'ticket_uuids'
                      ] as $tblname)
            {
                $constraint = $tblname.'_ticket_id_fkey';
                Database::DefaultExec("ALTER TABLE $tblname DROP CONSTRAINT IF EXISTS $constraint, ADD CONSTRAINT $constraint FOREIGN KEY (ticket_id) REFERENCES tickets(i) ON DELETE CASCADE");
            }
            Database::GetDefault()->commit();
        });
    }

    if (GlobalConfig::$databaseVersion < 63)
    {
        GlobalConfig::AddInstall('Add "tickets" index for queries with the most common sort order',
                                 "CREATE INDEX common_default_sort_idx ON tickets(created_dt DESC, i ASC);");
    }

    if (GlobalConfig::$databaseVersion < 64)
    {
        GlobalConfig::AddInstall('Add multicolumn indices to ticket data tables (can take a while)', function()
        {
            foreach ( [ 'ticket_amounts',
                        'ticket_ints',
                        'ticket_parents',
                        'ticket_categories',
                        'ticket_texts',
                        'ticket_uuids'
                      ] as $tblname )
                Database::DefaultExec("CREATE INDEX ON $tblname(ticket_id, field_id);");

            # ticket_parents should have 'value' as well to be able to find children.
            Database::DefaultExec("CREATE INDEX ON ticket_parents(value, field_id);");
        });
    }

    if (    (GlobalConfig::$databaseVersion > 2)
         && (GlobalConfig::$databaseVersion < 62)
       )
    {
        GlobalConfig::AddInstall('Fix user_localdb version number to adjust for old install routine', function()
        {
            GlobalConfig::Set(USER_LOCALDB_CONFIGKEY_VERSION, USER_LOCALDB_VERSION);
            GlobalConfig::Save();
        });
    }

    if (    (GlobalConfig::$databaseVersion > 2)
         && (GlobalConfig::$databaseVersion < 65)
       )
    {
        GlobalConfig::AddInstall("Add ON DELETE CASCADE to ticket_parents(value)", <<<SQL
ALTER TABLE ticket_parents
DROP CONSTRAINT ticket_parents_value_fkey,
ADD CONSTRAINT  ticket_parents_value_fkey FOREIGN KEY (value) REFERENCES tickets(i) ON DELETE CASCADE
SQL
        );
    }

    if (GlobalConfig::$databaseVersion < 66)
    {
        /**
         *  The 'ticket_times' table is part of the ticket data implementation and stores time stamps, e.g.
         *  for tasks and appointments.
         */
        GlobalConfig::AddPrio1Install('Create "ticket_times" table', <<<SQL
CREATE TABLE ticket_times (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER REFERENCES tickets(i) ON DELETE CASCADE,       -- can be NULL for values no longer in use but only referenced by changelog
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    value       $timestamp NOT NULL
)
SQL
        );
    }

    if (GlobalConfig::$databaseVersion < 71)
    {
        GlobalConfig::AddInstall('Create ticket ID indices on more ticket data tables', function()
        {
            foreach ( [ 'ticket_binaries',
                        'ticket_times',
                        'ticket_categories'
                      ] as $tblname )
            {
                Database::DefaultExec("CREATE INDEX ON $tblname (ticket_id);");
                if ($tblname != 'ticket_binaries')
                    Database::DefaultExec("CREATE INDEX ON $tblname(ticket_id, field_id);");
            }
        });
    }

    if ( (GlobalConfig::$databaseVersion > 2) && (GlobalConfig::$databaseVersion < 67) )
    {
        GlobalConfig::AddPrio1Install('Remove "rel" column from ticket_fields table', <<<SQL
ALTER TABLE ticket_fields DROP COLUMN rel;
SQL
        );
    }

    if ( (GlobalConfig::$databaseVersion > 2) && (GlobalConfig::$databaseVersion < 68) )
    {
        GlobalConfig::AddPrio1Install('Remove "datatype" column from ticket_fields table', <<<SQL
ALTER TABLE ticket_fields DROP COLUMN datatype;
SQL
        );
    }

    if (GlobalConfig::$databaseVersion < 69)
    {
        GlobalConfig::AddInstall("Create index on ticket_uuids(value)",
                                 'CREATE INDEX ticket_uuids_value_idx ON ticket_uuids (value);');
    }

    if (GlobalConfig::$databaseVersion < 72)
    {
        GlobalConfig::AddInstall("Create lower-case index on users(logins)",
                                 'CREATE UNIQUE INDEX lower_case_username ON users ((LOWER(login)));');
    }

    if (GlobalConfig::$databaseVersion < 73)
    {
        GlobalConfig::AddInstall("Add arbitrary JSON column to users table",
                                 <<<SQL
ALTER TABLE users
ADD COLUMN data TEXT
SQL
                                );
    }

    if ( (GlobalConfig::$databaseVersion > 2) && GlobalConfig::$databaseVersion < 74)
    {
        GlobalConfig::AddInstall("Extend categories and merge properties into categories table", function()
        {
            Database::GetDefault()->beginTransaction();

            Database::DefaultExec(<<<SQL
ALTER TABLE tickets
DROP CONSTRAINT tickets_project_id_fkey,
ADD CONSTRAINT  tickets_project_id_fkey FOREIGN KEY (project_id) REFERENCES categories(i) ON DELETE CASCADE
SQL
            );

            Database::DefaultExec(<<<SQL
ALTER TABLE categories
ADD COLUMN extra TEXT
SQL
            );

            // Build a list of legacy property definitions.
            $res = Database::DefaultExec(/** @lang SQL */'SELECT i, for_project_id, field_id, name FROM property_defs;');
            $aProperties = [];
            while ($row = Database::GetDefault()->fetchNextRow($res))
                $aProperties[$row['i']] = [ $row['for_project_id'], $row['field_id'], $row['name'] ];

            // Create new category definitions.
            $aOldNewMap = [];
            foreach ($aProperties as $idProperty => $a2)
            {
                list($forProjectID, $field_id, $name) = $a2;
                if (!($oField = TicketField::Find($field_id)))
                    throw new DrnException("Invalid field id $field_id in property_defs to be imported");
                $extra = ($forProjectID) ? [ Category::EXTRAKEY_FOR_PROJECT_ID => $forProjectID ]: NULL;

                $aOldNewMap[$idProperty] = Category::CreateBase($oField,
                                                                $name,
                                                                $extra);
            }

            // Fix ticket fields
            Database::DefaultExec(<<<SQL
UPDATE ticket_fields SET tblname = 'ticket_categories' where tblname = 'ticket_properties';
SQL
            );
            TicketField::ForceRefresh();

            // Move data
            $res = Database::DefaultExec(<<<SQL
SELECT i, ticket_id, field_id, value FROM ticket_properties;
SQL
            );
            $aCategoryTicketValues = [];
            while ($row = Database::GetDefault()->fetchNextRow($res))
            {
                $aCategoryTicketValues[] = $row['ticket_id'];
                $aCategoryTicketValues[] = $row['field_id'];
                $value = $row['value'];
                if (!($oCategory = getArrayItem($aOldNewMap, $value)))
                    throw new DrnException("Invalid property value $value in row {$row['i']} when converting properties");
                $aCategoryTicketValues[] = $oCategory->id;
            }
            Database::GetDefault()->insertMany('ticket_categories',
                                               [ 'ticket_id', 'field_id', 'value' ],
                                               $aCategoryTicketValues);

            Database::DefaultExec('DROP TABLE ticket_properties');
            Database::DefaultExec('DROP TABLE property_defs');

            Database::GetDefault()->commit();
        });
    }

    if (GlobalConfig::$databaseVersion < 76)
    {
        GlobalConfig::AddInstall("Create sub-amount categories", function()
        {
            /**
             *  The subamounts table contains partial amounts several of which form an amount
             *  in ticket_amounts. No entry here can exist without a parent row in ticket_amounts.
             *
             *  The definitions (names) for subamount categories (the 'cat' row) must be contained
             *  in the \ref categories table.
             */
            Database::DefaultExec(<<<SQL
CREATE TABLE subamounts (
    i           SERIAL PRIMARY KEY,
    cat         INTEGER REFERENCES categories(i) ON DELETE CASCADE,
    amount_id   INTEGER REFERENCES ticket_amounts(i) ON DELETE CASCADE,
    value       DECIMAL(15,2)
)
SQL
            );

        });
    }

    if (GlobalConfig::$databaseVersion < 77)
    {
        GlobalConfig::AddPrio1Install('Create "ticket_floats" table', function()
        {
            /**
             *  The 'ticket_floats' table is part of the ticket data implementation and stores floating-point
             *  values. Both MySQL and PostgreSQL understand the "double precision" data type, even though it's
             *  not standard SQL, apperarently.
             */
            Database::DefaultExec(<<<SQL
CREATE TABLE ticket_floats (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER REFERENCES tickets(i) ON DELETE CASCADE,       -- can be NULL for values no longer in use but only referenced by changelog
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    value       DOUBLE PRECISION NOT NULL
)
SQL
            );
            Database::DefaultExec("CREATE INDEX ON ticket_floats(ticket_id);");
            Database::DefaultExec("CREATE INDEX ON ticket_floats(ticket_id, field_id);");
        });
    }

    if (    (GlobalConfig::$databaseVersion > 2)            // upgrade only
         && (GlobalConfig::$databaseVersion < 78)
       )
    {
        GlobalConfig::AddPrio1Install('Allow for longer ticket type names', <<<SQL
ALTER TABLE ticket_types
ALTER COLUMN name TYPE TEXT,
ALTER COLUMN name SET NOT NULL
SQL
        );
    }

    if (GlobalConfig::$databaseVersion < 79)
    {
        GlobalConfig::AddPrio1Install('Create "ticket_vcards" table', function()
        {
            /**
             *  The 'ticket_vcards' table is part of the ticket data implementation and stores references
             *  to vcard (contact) tickets. The additional "type" and "extra" columns allow for storing
             *  plugin-specific data.
             */
            Database::DefaultExec(<<<SQL
CREATE TABLE ticket_vcards (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER REFERENCES tickets(i) ON DELETE CASCADE,       -- can be NULL for values no longer in use but only referenced by changelog
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i),
    value       INTEGER REFERENCES tickets(i) ON DELETE CASCADE,
    type        SMALLINT DEFAULT NULL,
    extra       TEXT DEFAULT NULL
)
SQL
            );
            Database::DefaultExec("CREATE INDEX ON ticket_vcards(ticket_id);");
            Database::DefaultExec("CREATE INDEX ON ticket_vcards(ticket_id, field_id);");
        });
    }

    if (    (GlobalConfig::$databaseVersion > 2)        // upgrade only
         && (GlobalConfig::$databaseVersion < 80)
       )
    {
        GlobalConfig::AddPrio1Install('Alter "ticket_binaries" table to no longer use varchars', <<<SQL
ALTER TABLE ticket_binaries
ALTER COLUMN filename TYPE TEXT,
ALTER COLUMN mime TYPE TEXT,
ALTER COLUMN mime SET NOT NULL
SQL
        );
    }

    if (GlobalConfig::$databaseVersion < 82)
        GlobalConfig::AddPrio2Install("Need to re-index all tickets", function() {
            GlobalConfig::FlagNeedReindexAll();
        });

    if (GlobalConfig::$databaseVersion < 85)
    {
        GlobalConfig::AddInstall("Set up JWT signing", function()
        {
            JWT::Init();

            $secret = bin2hex(random_bytes(128));
            GlobalConfig::Set(GlobalConfig::KEY_JWT_SECRET, $secret);

            $frontendToken = JWT::GetToken(JWT::FRONTEND_UID,
                                           JWT::TYPE_API_CLIENT,
                                           '',
                                           0);
            GlobalConfig::Set(GlobalConfig::KEY_JWT_FRONTEND_TOKEN, $frontendToken);
            GlobalConfig::Save();
        });
    }

    if (GlobalConfig::$databaseVersion > 84 && GlobalConfig::$databaseVersion < 86)
    {
        GlobalConfig::AddInstall("Refresh JWT configuration", function()
        {
            JWT::Init();

            $frontendToken = JWT::GetToken(JWT::FRONTEND_UID,
                                           JWT::TYPE_API_CLIENT,
                                           '',
                                           0);
            GlobalConfig::Set(GlobalConfig::KEY_JWT_FRONTEND_TOKEN, $frontendToken);
            GlobalConfig::Save();
        });
    }

    if (GlobalConfig::$databaseVersion < 90)
    {
        GlobalConfig::AddInstall("Add hidden flag to binaries", <<<SQL
ALTER TABLE ticket_binaries
ADD COLUMN hidden BOOLEAN NOT NULL DEFAULT FALSE
SQL
        );
    }

    if (    (GlobalConfig::$databaseVersion < 91)
         && (GlobalConfig::$databaseVersion > 1))
    {
        GlobalConfig::AddInstall("Migrate date format preference", function()
        {
            $aUsers = User::GetAll();
            foreach ($aUsers as $oUser)
            {
                if ($oUser->fl & User::FLUSER_DATEFORMAT_ABSOLUTE)
                    $oUser->setKeyValue(User::DATEFORMAT, User::DATEFORMAT_LONG);
            }
        });
    }

    if (    (GlobalConfig::$databaseVersion < 92)
         && (GlobalConfig::$databaseVersion > 1)
       )
    {
        GlobalConfig::AddInstall("Upgrade existing access permission to include ticket mail", function()
        {
            if ($res = Database::DefaultExec(<<<SQL
SELECT i, permissions FROM acl_entries;
SQL
               ))
            {
                $a = [];
                while ($row = Database::GetDefault()->fetchNextRow($res))
                {
                    $i = $row['i'];
                    $permissions = $row['permissions'];
                    if ($permissions & ACCESS_READ)
                    {
                        $permissions |= ACCESS_MAIL;

                        Database::DefaultExec(<<<SQL
UPDATE acl_entries SET permissions = $1 WHERE i = $2
SQL
                            , [ $permissions, $i ]);

                    }
                }
            }
        });
    }

    if (    (GlobalConfig::$databaseVersion < 93)
         && (GlobalConfig::$databaseVersion > 1)
       )
    {
        # Raising the length of the login name from 20 to 70 to allow for using emails as logins.
        GlobalConfig::AddInstall("Raise user login length limit", <<<SQL
ALTER TABLE users
ALTER COLUMN login TYPE VARCHAR($lenLoginName)
SQL
        );
    }

    if (    (GlobalConfig::$databaseVersion < 93)
         && (GlobalConfig::$databaseVersion > 1)
       )
    {
        GlobalConfig::AddInstall("Add maxage to pwdresets", <<<SQL
ALTER TABLE pwdresets
ADD COLUMN max_age_minutes INTEGER NOT NULL DEFAULT 120
SQL
        );

    }

}
