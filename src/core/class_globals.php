<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Required globals
 *
 ********************************************************************/


foreach ( [ 'TO_HTDOCS_ROOT',           # Must have the relative path from the entry point (e.g. cli.php) to the htdocs directory.
            'INCLUDE_PATH_PREFIX'       # Must have the path to get to the src/ directory with all the PHP files.
          ] as $var)
    if (!defined($var))
        trigger_error("$var is not defined", E_USER_ERROR);

/********************************************************************
 *
 *  Error handler
 *
 ********************************************************************/

/*
 *  Make all warnings fatal. This is only activated if Globals::SetErrorHandler()
 *  is called.
 */
function DrnGlobalErrorHandler($errno,
                               $errstr,
                               $errfile,
                               $errline,
                               $errcontext)
{
    /* Custom error handlers get called EVEN IF AN @ COMMAND FAILS so make
       sure this is not an @ command like @file_get_contents.
       http://php.net/manual/en/language.operators.errorcontrol.php */
    if (0 == error_reporting())
        // Error reporting is currently turned off or suppressed with @
        return;

    throw new DrnException("PHP error in $errfile".'['.$errline."]: $errstr");
}


/********************************************************************
 *
 *  Ticket view modes
 *
 ********************************************************************/

const MODE_CREATE = 1;                      //!< Ticket creation form.
const MODE_EDIT = 2;                        //!< Form for editing existing tickets.
const MODE_READONLY_DETAILS = 3;            //!< Details view of a single existing ticket.
const MODE_READONLY_LIST = 4;               //!< Ticket results list as table
const MODE_READONLY_GRID = 5;               //!< Ticket results list as cards
const MODE_READONLY_CHANGELOG = 6;          //!< Change log, either global or in ticket details.
const MODE_READONLY_FILTERLIST = 7;         //!< Possible filter value being printed as part of a drill-down filters list.
const MODE_TICKETMAIL = 8;                  //!< Ticket mail after ticket has been created.
const MODE_JSON = 9;                        //!< Generating JSON for ticket.
# Special modes for mail only
const MODE_COMMENT_ADDED = 10;
const MODE_FILE_ATTACHED = 11;


/********************************************************************
 *
 *  Ticket field constants
 *
 ********************************************************************/

# Ticket field flags
const FIELDFL_STD_CORE                         = (1 <<  0);
    /*  Ticket field data is part of core 'tickets' table; does not require ticket_ints or other tables. */
const FIELDFL_STD_DATA_OLD_NEW                 = (1 <<  1);
    /*  This is the "normal" setting for most ticket fields. If this is set, the Doreen core (in particular
     *  FieldHandler::writeToDatabase()) can handle create, update and delete automatically. In detail, setting
     *  this flag for a field effects the following:
     *   1) Ticket field has actual data, but it is not stored in 'tickets', but in the table listed in 'ticket_fields.tblname'
     *      (e.g. ticket_ints or ticket_texts).
     *   2) On ticket queries, a LEFT JOIN is produced at runtime to pull ticket data from that table for the ticket.
     *   3) Changelog entries are assumed to have value_1 and value_2 pointing to row IDs in that table.
     *      See See Changelog::AddTicketChange() for the standard ticket changelog formats.
     *   4) All the above data rows are to be deleted automatically when a ticket is deleted.
     *  If this flag is not set, FieldHandler::writeToDatabase() and others must be overridden to create, update and
     *  delete data properly. */
const FIELDFL_REQUIRED_IN_POST_PUT             = (1 <<  2);
    /*  This makes the default FieldHandler::writeToDatabase() method throw during ticket creation / update via
        HTTP POST or PUT if data for this field is missing. */
const FIELDFL_VIRTUAL_IGNORE_POST_PUT          = (1 <<  3);
    /*  If set, ignore data for this field in HTTP POST or PUT data. This is useful for auxiliary fields without
     *  table data and without a field handler when the data is really handled by another (child or parent) field handler.
     *  The field will also not be shown in the "create" or "edit" forms. */
const FIELDFL_ARRAY                            = (1 <<  4);
    /*  Field data is an array: there can be multiple rows in 'tblname' for the same ticket, and they need
     *  to be retrieved as a comma-separated list of values in the query. For POST and PUT input, the value
     *  needs to be a comma-separated string, and changelog etc. values are also processed for each list item
     *  separately. */
const FIELDFL_ARRAY_HAS_REVERSE                = (1 <<  5);
    /*  Optionally for a field that has FIELDFL_ARRAY, if this is also set, then a second field exists with
     *  FIELDFL_ARRAY_REVERSE set (see below). This is a special hacks and only works for ticket relations
     *  (parent/child relations between tickets), and also only if the field ID of the reverse array field
     *  is equal to the array field ID + 1. */
const FIELDFL_ARRAY_REVERSE                    = (1 <<  6);
    /*  For a field handler that acts as a companion to FIELDFL_ARRAY. */
const FIELDFL_FIXED_CREATEONLY                 = (1 <<  7);
    /*  Field data is set once at ticket creation only and cannot be changed afterwards. This is for references to
     *  other ticket IDs on import, for example, which the user should not be allowed to change. */
const FIELDFL_CHANGELOGONLY                    = (1 <<  8);
    /*  The field data is to be shown in changelogs only (i.e. the global changelog and the ticket changelog
     *  of a ticket details view), but not in ticket list views. It is therefore not part of the "create"
     *  (POST) or "update" (PUT) data either. Examples are FIELD_COMMENT and FIELD_ATTACHMENT. */
const FIELDFL_VISIBILITY_CONFIG                = (1 <<  9);
    /*  If this is set, the administrator GUI allows for configuring whether this field should be visible as a details field or not. */
const FIELDFL_DETAILSONLY                      = (1 << 10);
    /*  If this is set, the administrator GUI will restrict field visibility configuration to details view only, not list views.
     *  Examples are FIELD_COMMENT and FIELD_ATTACHMENT. */
const FIELDFL_HIDDEN                           = (1 << 11);
    /*  Field data is not to be shown anywhere. This is used for FIELD_OLDCOMMENT to be able to keep old
     *  comments in the database after they have been deleted. */
const FIELDFL_SORTABLE                         = (1 << 12);
    /*  If set (and the field is part of the ticket list columns), the search results table can be sorted by this field. */
const FIELDFL_DESCENDING                       = (1 << 13);
    /*  Only used with FIELDFL_SORTABLE. If set, then the initial sort order is descending, otherwise ascending. Useful
     *  for dates and priorities. */
const FIELDFL_EMPTYTICKETEVENT                 = (1 << 14);
    /*  Field represents no ticket data, but a ticket event for changelogs only. Examples are FIELD_TICKET_CREATED
     *  and FIELD_TICKET_TEMPLATE_CREATED. changelog.what still contains the ticket ID. */
const FIELDFL_EMPTYSYSEVENT                    = (1 << 15);
    /*  Field represents no ticket data, but a system event for changelogs only. Examples are FIELD_TICKET_CREATED
     *  and FIELD_TICKET_TEMPLATE_CREATED. */
const FIELDFL_SHOW_CUSTOM_DATA                 = (1 << 16);
    /*  Field has no real data in tables, but data is computed at run-time from other fields, and the field needs
        its own column even though FIELDFL_STD_DATA_OLD_NEW is not set. */
const FIELDFL_WORDLIST                         = (1 << 17);
    /*  Field has wordlist logic, e.g. for keywords. See Ticket::PopulateMany() and Ticket::$aFieldDataWordIDs. */
const FIELDFL_TYPE_INT                         = (1 << 18);
    /*  For fields with search boost value: Field should not be indexed as string, but as int. */
const FIELDFL_TYPE_TEXT_NATURAL                = (1 << 19);
    /*  For fields with search boost value: Field has natural language that the search plugin should index with an advanced analyzer according to Ticket::getLanguage(). */
const FIELDFL_TYPE_TEXT_LITERAL                = (1 << 20);
    /*  For fields with search boost value: Field has literal text with special characters that should not be analyzed at all by the search engine. */
const FIELDFL_ARRAY_COUNT                      = (1 << 21);
    /*  Only to be used with FIELDFL_ARRAY: allows for specifying counts with the array data (ticket_parents.count column). */
const FIELDFL_SUGGEST_FULL_VALUE               = (1 << 22);
    /*  Tells the search engine to suggest the full value of this field in addition to normal tokenization. */
const FIELDFL_TYPE_DATE                        = (1 << 23);
    /*  Tells the search engine that this should be indexed as a date field. */
const FIELDFL_SUPPORTS_SUBTOTALS               = (1 << 24);
    /*  Indicates to Doreen that this field contains amounts that can be summed up in ticket search results. */
const FIELDFL_CUSTOM_SERIALIZATION             = (1 << 25);
    /*  Causes Doreen to require a field handler for the field and call FieldHandler::makeFetchSql() when populating. */
const FIELDFL_MAPPED_FROM_PROJECT              = (1 << 26);
    /*  Map data in tickets.id_project to this field. At most one field per type can have this flag set. Useful for categories. */

# Ticket field IDs; all of these have FIELDFL_STD_DATA_OLD_NEW and FIELDFL_REQUIRED_IN_POST_PUT set
const FIELD_TITLE                            = -1;           # ticket_texts;                 TitleHandler       extends FieldHandler
const FIELD_DESCRIPTION                      = -2;           # ticket_texts;                 DescriptionHandler extends FieldHandler
const FIELD_PROJECT                          = -3;           # TICKET CORE; property;        ProjectHandler     extends CategoryHandlerBase
const FIELD_KEYWORDS                         = -4;           # ticket_ints -> keyword_defs;  KeywordsHandler    extends FieldHandler
const FIELD_CATEGORY                         = -5;           # ticket_categories;            CategoryHandler    extends PropertyHandlerBase
            const CAT_UNDEF             = -1;
            const CAT_DEFECT            = -2;
            const CAT_FEATREQ           = -3;
const FIELD_VERSION                          = -6;           # ticket_categories;            VersionHandler     extends PropertyHandlerBase
            const VERSION_UNDEF         = -4;
const FIELD_PRIORITY                         = -7;           # ticket_ints;                  PriorityHandler    extends SelectFromSetHandlerBase
const FIELD_SEVERITY                         = -8;           # ticket_ints;                  SeverityHandler    extends SelectFromSetHandlerBase
const FIELD_STATUS                           = -9;           # ticket_ints;                  StatusHandler      extends SelectFromSetHandlerBase
            const STATUS_OPEN                   =  -1;
            const STATUS_REMINDER               =  -2;
            const STATUS_CLOSED                 =  -3;

            const STATUS_NEW                    =  -4;
            const STATUS_DUNNO                  =  -5;
            const STATUS_WORKSFORME             =  -6;
            const STATUS_CONFIRMED              =  -7;
            const STATUS_FEEDBACK               =  -8;
            const STATUS_RESOLVED               =  -9;
            const STATUS_VERIFIED               = -10;
            const STATUS_REOPENED               = -11;
            const STATUS_TESTING                = -12;      # new 2017-11-22

            const STATUS_PAID                   = -13;      # new 2018-04-13
            const STATUS_RESCINDED              = -14;      # new 2018-04-13

            const WORKFLOW_TASK                 =  -1;
            const WORKFLOW_BUG                  =  -2;

const FIELD_CHILD_RESOLUTION                = -10;           # FIELDFL_STD_DATA_OLD_NEW set, but not FIELDFL_REQUIRED_IN_POST_PUT.
            const RESOL_FIXED       = 1;
            const RESOL_NOTABUG     = 2;
            const RESOL_WONTFIX     = 3;
            const RESOL_DEFERRED    = 4;
            const RESOL_REMIND      = 5;
            const RESOL_DUPLICATE   = 6;
            const RESOL_OBSOLETE    = 7;
# The following has FIELDFL_STD_DATA_OLD_NEW set, but not FIELDFL_REQUIRED_IN_POST_PUT.
const FIELD_CHILD_IDDUPLICATE               = -11;           # Ticket ID of duplicate (used only with RESOL_DUPLICATE; parent field is FIELD_STATUS plugin
const FIELD_UIDASSIGN                       = -12;           # ticket_ints -> users;         AssigneeHandler extends SelectFromSetHandlerBase
const FIELD_CHANGELOG                       = -13;
const FIELD_COMMENT                         = -14;           # FIELDFL_CHANGELOGONLY; changelog.value_1 points to a row in ticket_texts with the comment text, which is in ticket_texts.value
const FIELD_OLDCOMMENT                      = -15;           # On edit/delete, old comment value is changed to FIELD_OLDCOMMENT so it's no longer found by the changelog. Same format.
const FIELD_ATTACHMENT                      = -16;           # FIELDFL_CHANGELOGONLY

const FIELD_IMPORTEDFROM                    = -17;           # To be used by plugins.
const FIELD_IMPORTEDFROM_PERSONID           = -18;           # To be used by plugins.

const FIELD_FILED_AS                        = -19;           # ticket_ints                  FiledAsHandler

const FIELD_REMOVED_PARENT                  = -30;
    const FIELD_REMOVED_PARENTS                 = -31;
    const FIELD_REMOVED_CHILDREN                = -32;

const FIELD_PARENTS                         = -34;           # ParentsHandler
const FIELD_CHILDREN                        = -33;           # ChildrenHandler, must be FIELD_PARENTS + 1

const FIELD_ATTACHMENT_RENAMED              = -40;           // Changelog entry when an attachment is renamed. what is the attachment id, while old value is the ticket id and value_str is the old name.
const FIELD_ATTACHMENT_DELETED              = -41;           // Changelog entry when an attachment is permanently hidden in a ticket.

# Additional fields for core ticket data. These were added later and thus have weird IDs.
const FIELD_TYPE                            = -97;           # Ticket types. Used with drill-down arrays from Ticket::FindMany().
const FIELD_SCORE                           = -98;           # Search score for full-text search. Cannot be configured, but is added dynamically after full-text searches.
const FIELD_CREATED_DT                      = -96;           # Ticket creation date.
const FIELD_LASTMOD_DT                      = -95;           # Ticket last modification date.
const FIELD_CREATED_UID                     = -94;           # Ticket creation user ID (owner)
const FIELD_LASTMOD_UID                     = -93;           # Ticket last modification user ID

const FIELD_SYS_FIRST                       = -99;
const FIELD_SYS_LAST                        = -150;

const FIELD_IGNORE                          = -99;           # Special value for WorkflowHandlers that doesn't get stored in field handler arrays.

# Items <= -100 are system items and all have FIELDFL_EMPTYSYSEVENT set.
const FIELD_SYS_USER_CREATED                = -101;   // what = UID
const FIELD_SYS_USER_PASSWORDCHANGED        = -102;   // what = UID
const FIELD_SYS_USER_LONGNAMECHANGED        = -103;   // what = UID, value_str = old longname.
const FIELD_SYS_USER_EMAILCHANGED           = -104;   // what = UID, value_str = old email.
const FIELD_SYS_USER_ADDEDTOGROUP           = -105;   // what = UID, value_1 = GID
const FIELD_SYS_USER_REMOVEDFROMGROUP       = -106;   // what = UID, value_1 = GID
const FIELD_SYS_USER_DISABLED               = -107;   // what = UID
const FIELD_SYS_USER_FTICKETMAILCHANGED     = -108;   // what = UID, value_1 = new fTicketMail
const FIELD_SYS_USER_PERMITLOGINCHANGED     = -109;   // what = UID, value_1 = new fPermitLogin
const FIELD_SYS_USER_LOGINCHANGED           = -110;   // what = UID, value_str = old login.

const FIELD_SYS_GROUP_CREATED               = -121;   // what = GID, value_str = group name (at the time)
const FIELD_SYS_GROUP_NAMECHANGED           = -122;   // what = GID, value_str = old group name
const FIELD_SYS_GROUP_DELETED               = -123;   // what = GID, value_str = group name (at the time)

const ACL_SYS_TICKETTYPES                           = -130;
const FIELD_SYS_TICKETTYPE_CREATED          = -131;   // TODO changelog
const FIELD_SYS_TICKETTYPE_CHANGED          = -132;   // TODO changelog
const FIELD_SYS_TICKETTYPE_DELETED          = -133;

const ACL_SYS_TEMPLATES                             = -140;
# const FIELD_SYS_TEMPLATE_CHANGED                     = -142;   // TODO changelog NEEDED?!?
const FIELD_SYS_TEMPLATE_DELETED                    = -143;  # what = ticket ID (invalid), value_1 = type_id, value_2 = aid, value_str = template name

const ACL_SYS_IMPORT                                = -150;

const FIELD_TICKET_CREATED                  = -200;   // FIELDFL_EMPTYTICKETEVENT; what = ticket_id
const FIELD_TICKET_TEMPLATE_CREATED         = -201;   // FIELDFL_EMPTYTICKETEVENT; what = ticket_id, value_str = template name
const FIELD_OTHERDUPLICATE                  = -202;   // FIELDFL_EMPTYTICKETEVENT; what = id of ticket still open, value_1 = id of ticket resolved as a duplicate
const FIELD_TICKET_IMPORTED                 = -203;   // FIELDFL_EMPTYTICKETEVENT; what = id of newly created ticket, value_1 = id of source ticket
const FIELD_TEMPLATE_UNDER_TICKET_CHANGED   = -204;   // FIELDFL_EMPTYTICKETEVENT; what = id of ticket, value_1 = id of old template, value_2 = id of new template
const FIELD_COMMENT_UPDATED                 = -205;   // FIELDFL_EMPTYTICKETEVENT; what = id of ticket, value_1 = id of old comment text, value_2 = id of new comment text, value_str = creation date of original comment
const FIELD_COMMENT_DELETED                 = -206;   // FIELDFL_EMPTYTICKETEVENT; what = id of ticket, TODO

const ACL_SYS_TOKEN             = -250; // Can create API tokens
const FIELD_SYS_TOKEN_CHANGED   = -251; // what = UID, value_str = new id of token

const TYPE_PARENT_SELF      = -5;


/********************************************************************
 *
 *  Access flags (used in ACLs)
 *
 ********************************************************************/

# The CRUD basics. These values are in the database AND MUST NOT BE CHANGED.
const ACCESS_CREATE      = 0x04;     # For templates: user may create an instance of it. For other tickets: user may comment and upload files.
const ACCESS_READ        = 0x01;     # User may see the ticket.
const ACCESS_UPDATE      = 0x02;     # User may change the ticket.
const ACCESS_DELETE      = 0x08;     # User may delete the entire ticket without trace. Should be for admins only.
const ACCESS_MAIL        = 0x10;     # User may receive ticket mail. (This used to be implied by ACCESS_READ but is seperate as of 2018-12).


/********************************************************************
 *
 *  Misc other constants
 *
 ********************************************************************/

const LEN_LOGINNAME     = 70;       # Raised from 20 to match email 2018-12-13
const LEN_PASSWORD      = 128;
const LEN_USERSALT      = 20;
const LEN_LONGNAME      = 255;      # used for both user longname and group names and project names
const LEN_EMAIL         = 70;
const LEN_IDENTIFIER    = 25;       # plugin names, ticket types etc.
const LEN_MIME          = 100;      # length of MIME type in binaries table (xTracker has 30 but ODF types can be longer)

# Required for fixing botched installs in version 62.
const USER_LOCALDB_CONFIGKEY_VERSION = "db-version-user_localdb";


/********************************************************************
 *
 *  Globals class
 *
 ********************************************************************/

/**
 *  Contains mostly static variables.
 *
 *  This is to avoid to awkward syntax of the PHP $GLOBALS[] and 'global' keyword mechanism,
 *  which is very error-prone when copying and pasting. Additionally some global methods have
 *  been added that are used all over the place.
 */
abstract class Globals
{
    public static $doreenName = 'Doreen';           # Replacement value for %DOREEN% in L strings.
    public static $doreenWordmark = NULL;
    public static $doreenLogo = NULL;
    public static $doreenLogoSizes = [ 16 ];
    public static $fCapitalizeLogins = FALSE;
    public static $entryFile;

    public static $cPerPage = 20;           # Result items per page.
    public static $cPerPageGrid = 12;           # Result items per page in grid view
    public static $thumbsize = 100;
    public static $cookieLifetimeDays = 20;         # TODO override in config
    public static $cMinimumPasswordLength = 8;

    public static $cDefaultTicketsChunk = 3000;     # Default size for FindResults::fetchChunk()

    public static $fEmailEnabled = TRUE;    # Enable or disable email globally. TODO make this configurable
    public static $SMTPFromAddr = 'doreen-noreply@baubadil.de';
    public static $SMTPFromName = 'Doreen';

    public static $defaultTicketsView = 'grid';

    /** Required PHP extensions. This is used by Globals::CheckRequiredPHPExtensions() and is in a global variable so plugins can add to it. */
    private static $aRequiredPHPExtensions = [
            'iconv',
            'session',
            'curl',
            'sysvmsg',
            'xmlreader',
            'mcrypt',
            'mbstring'
//            'pcntl'       # Cannot check for this as it's only available in the CLI.
    ];

    private static $aClassIncludes =         # Global array of class names with include file names. Plug-ins can add to this array via RegisterInstance().
        [   'DrnACL' => 'core/class_access.php',
            'GlobalConfig' => 'core/class_config.php',
            'TicketContext' => 'core/class_fieldhandler.php',
            'TicketPageBase' => 'core/class_fieldhandler.php',
            'MonetaryAmountHandlerBase' => 'core/class_fieldh_amount.php',
            'DateHandler' => 'core/class_fieldh_date.php',
            'SelectFromSetHandlerBase' => 'core/class_fieldh_select.php',
            'CategoryHandlerBase' => 'core/class_fieldh_cat.php',
            'ParentsHandler' => 'core/class_fieldh_parents.php',
            'ChildrenHandler' => 'core/class_fieldh_parents.php',
            'JsonHandlerBase' => 'core/class_fieldh_json.php',
            'TicketPickerHandlerBase' => 'core/class_fieldh_ticket.php',
            'TitleHandler' => 'core/class_fieldhandlers2.php',
            'KeywordsHandler' => 'core/class_fieldh_keywords.php',
            'DrnThumbnailer' => 'core/class_thumbnailer.php',
            'DrnCrypto' => 'core/class_crypto.php',
            'ServiceLongtask' => 'core/class_servicebase.php',
            'EmailService' => 'core/class_email.php',
            'DrnMailException' => 'core/class_email.php',
        ];

    public static $rootpage = NULL;         # URL to append particles to when constructing links.
    private static $requestOnly = NULL;     # The request part of the URL: everything after server and possibly /subdir/ (WITHOUT the leading slash);
                                            # this can simply be passed to myReload() for reloading the page
    public static $doreenDocumentRoot;      # Directory name of where the script entry point (index.php or cli.php or api.php) resides.

    public static $fnameInstallVars = NULL;
    public static $fnameOptionalVars = NULL;
    public static $fnameEncryptionKey = NULL;

    private static $timeInit;

    const CSS_SPINNER_CLASS = 'drn-spinner';                        # Used in both Icon::Get and in JavaScript code to create and identify spinner icons.

    private static $now = NULL;

    public static $fImportingTickets = FALSE;       # HACK around strict checks in SelectFromSetHandlerBase

    public static function InitInstallDir($installDir)
    {
        if (substr($installDir, -1) != '/')
            $installDir .= '/';
        self::$fnameInstallVars = $installDir.'doreen-install-vars.inc.php';
        self::$fnameOptionalVars = $installDir.'doreen-optional-vars.inc.php';
        self::$fnameEncryptionKey = $installDir.'doreen-key.inc.php';
    }

    public static function GetInstallDir()
    {
        return dirname(self::$fnameInstallVars);
    }

    /**
     *  To be called once on startup. This needs to be an explicit call because the CLI makes
     *  some manual adjustments. This gets called from the prologue only.
     */
    public static function Init()           //!< in: absolute path of directory where index.php resides.
    {
        # Record the startup time so we can do measurements.
        self::$timeInit = microtime(true);

        # The following may have been set explicitly by a CLI command line argument, so don't overwrite.
        if (!self::$fnameInstallVars)
        {
            $fnameDocumentRoot = $_SERVER['DOCUMENT_ROOT'];
            $fnameIncludeDir = substr($fnameDocumentRoot, 0, strrpos($fnameDocumentRoot, '/'));
            self::InitInstallDir($fnameIncludeDir);
        }

        self::$doreenDocumentRoot = realpath(INCLUDE_PATH_PREFIX);

        /**
         * @page autoloader The Doreen class autoloader
         *
         *  Doreen will automatically try to locate PHP include files under
         *  the /include/ directory, according to the following rules:
         *
         *   -- Class names starting with Api* are assumed to be in a file called
         *      /include/api_class.php.
         *
         *   -- Class names starting with View* are assumed to be in a file called
         *      /include/view_class.php.
         *
         *   -- Any other class names are assumed to be in a file called
         *      /include/class.php.
         */
        spl_autoload_register(function($class)
        {
            if (strncmp("Doreen\\", $class, 7) !== 0)
                return;
            $class = substr($class, 7);

            Debug::Log(Debug::FL_AUTOLOADER, "self::\$aClassIncludes: ".print_r(self::$aClassIncludes, TRUE));

            if (isset(self::$aClassIncludes[$class]))
                $file = '/'.self::$aClassIncludes[$class];
            else if (substr($class, 0, 3) == 'Api')
                $file = '/core/api_'.strtolower(substr($class, 3)).'.php';
            else if (substr($class, 0, 4) == 'View')
            {
                $view = strtolower(substr($class, 4));
                $file = "/core/view_$view/view_$view.php";
            }
            else
                $file = '/core/class_'.strtolower($class).'.php';
            Debug::Log(Debug::FL_AUTOLOADER, "======== Autoloader invoked for class $class => ".INCLUDE_PATH_PREFIX.$file);

            # Do a manual test here for whether the include file exists. Otherwise, if it doesn't,
            # we'll get a PHP warning without a stack trace, and we can't find out who invoked the class.
            if (!@file_exists($test = self::$doreenDocumentRoot."$file"))
                throw new DrnException("Cannot find include file $test");

            require_once self::$doreenDocumentRoot."$file";
        });
    }

    public static function SetErrorHandler()
    {
        set_error_handler('Doreen\\DrnGlobalErrorHandler', E_ALL & ~E_DEPRECATED);
    }

    /**
     *  Called explicitly from index.php and api.php after the prologue was included (which calles Init())
     *  to initialize the request variables. This differs between the two entry points and must be a
     *  separate call as a result.
     */
    public static function InitRequest($requestOnly)
    {
        self::$requestOnly = $requestOnly;
    }

    /**
     *  Returns the request bit of the URL the script was called with.
     *
     *  If Doreen is running in the main web server directory, this is everything after the domain name.
     *
     *  If Doreen is running in a subdirectory (e.g. '/doreen/', this is everything after '/subdir/'.
     *
     *  If this is api.php, then /api/ is also removed.
     *
     *  The leading slash is never included.
     */
    public static function GetRequestOnly()
    {
        return self::$requestOnly;
    }

    /**
     *  Attempts to return the hostname on which this server is running. This returns
     *  the variable from $_SERVER unless we're running on the CLI, in which case the
     *  value should have been cached.
     */
    public static function GetHostname()
        : string
    {
        if (php_sapi_name() != 'cli')
            return $_SERVER['SERVER_NAME'];

        if (Install::$tempHostname)
            return Install::$tempHostname;

        return GlobalConfig::Get('hostname');
    }

    public static function IsLocalhost()
        : bool
    {
        return self::GetHostname() == 'localhost';
    }

    /**
     *  Initializes the global database object according to which database plugin has been
     *  configured. After this, Database::Get() can be used.
     *
     *  Called from both index.php and api.php.
     */
    public static function InitDatabase()
    {
        Database::InitDefault();
        define('DOREEN_ATTACHMENTS_DIR', DOREEN_ATTACHMENTS_PARENT_DIR.'/'.Database::$defaultDBName);
    }

    /**
     *  Adds the given class name => include file pairs to the global array for the autoloader.
     */
    public static function RegisterClassIncludes($aClassIncludes)
    {
        Debug::Log(Debug::FL_AUTOLOADER, "Registering class includes ".print_r($aClassIncludes, TRUE));
        self::$aClassIncludes += $aClassIncludes;
    }

    /**
     *  To be called by plugins if they want to add PHP extensions to the list of requirements.
     */
    public static function RequirePHPExtensions($ll)     //!< in: flat list of PHP extension names
    {
        foreach ($ll as $e)
            self::$aRequiredPHPExtensions[] = $e;
    }

    /**
     *  Called fom the GET '/' GUI handler to throw an exception if a PHP extension is missing.
     *  We only do it there for performance reasons.
     *
     *  Doreen plugins can add required PHP extensions by called Globals::RequirePHPExtensions()
     *  in their init code.
     */
    public static function CheckRequiredPHPExtensions()
    {
        # Check installation only on the main page.
        $aMissing = [];
        foreach (self::$aRequiredPHPExtensions as $ext)
        {
            if (!extension_loaded($ext))
                $aMissing[] = $ext;
        }
        $msg = NULL;
        if (count($aMissing) > 1)
            $msg = "The \"".implode('", "', $aMissing)."\" PHP extensions are required but missing. ";
        else if (count($aMissing) == 1)
            $msg = "The \"".$aMissing[0]."\" PHP extension is required but missing. ";
        if ($msg)
            myDie("$msg Please install the extension or recompile your PHP.");
    }

    public static function EchoIfCli($msg)
    {
        if (php_sapi_name() == 'cli')
            echo "$msg\n";
        else
            Debug::Log(0, $msg);
    }

    /**
     *  Disables the limits that PHP imposes on execution time and memory allocations.
     *  Call this only for long-running scripts such as imports.
     */
    public static function DisableExecutionLimits()
    {
        ini_set('max_execution_time', 0);       # no time limit
        set_time_limit(0);
        ini_set('memory_limit', '-1');          # no memory limit
    }

    /**
     *  Returns a string with a rough estimate of how long the script has been running so far.
     *  Append " seconds" for display to the user.
     */
    public static function TimeSoFar()
    {
        return microtime(true) - self::$timeInit;
    }

    /**
     *  Calls \ref Debug::Log() with the Debug::FL_PROFILE and a profiling message, measuring the
     *  time that has passed since the script started.
     */
    public static function Profile($descr)
    {
        $time = microtime(true);
        Debug::Log(Debug::FL_PROFILE, "~~~~~~ Profile $descr => ".Format::TimeTaken($time - self::$timeInit));
    }

    /**
     *  Returns a UTC timestamp string of the current date and time (including seconds) suitable for
     *  database consumption.
     */
    public static function Now()
    {
        if (!self::$now)
            self::$now = gmdate('Y-m-d H:i:s');
        return self::$now;
    }

    const URL_HTMLENCODE = 0x01;
    const URL_URLENCODE = 0x02;
    const URL_DEFAULT = 0x03;

    /**
     *  Rebuilds a URL string from the given base and args array.
     *
     *  If $fEncodeHTML == TRUE, the result is automatically HTML-encoded, which particularly
     *  affects ampersand characters. As per the HTML specs, '&' has to be encoded as '&amp;' even
     *  in the attributes such as href.
     */
    public static function BuildURL($base,
                                    $aArgs,
                                    $fl = self::URL_DEFAULT)
    {
        if ($aArgs && count($aArgs))
        {
            $base .= '?';
            $c = 0;
            foreach ($aArgs as $key => $value)
            {
                if ($c++ > 0)
                    $base .= '&';
                $base .= "$key=";
                if ($fl & self::URL_URLENCODE)
                    $base .= rawurlencode($value);
                else
                    $base .= $value;
            }
        }
        if ($fl & self::URL_HTMLENCODE)
            return toHTML($base);
        return $base;
    }

    public static function EndWithDot(&$msg)
    {
        if ($msg)
        {
            $lastchar = $msg[strlen($msg) - 1];
            if (( $lastchar != '.' )
                && ( $lastchar != '!' )
                && ( $lastchar != '?' )
            )
            {
                $msg .= '.';
            }
        }
    }

    private static $version = NULL;

    /**
     *  Returns the Doreen version number, which is kept in a file in the sources tree.
     */
    public static function GetVersion()
        : string
    {
        if (self::$version === NULL)
            self::$version = trim(FileHelpers::GetContents(INCLUDE_PATH_PREFIX.'/../version.inc'));

        return self::$version;
    }
}
