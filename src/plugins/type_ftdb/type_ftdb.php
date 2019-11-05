<?php
/*
 *PLUGIN    Name: type_ftdb
 *PLUGIN    URI: http://www.baubadil.org/doreen
 *PLUGIN    Description: Provides ticket types and templates for the Fischertechnik Community database
 *PLUGIN    Version: 0.1.0
 *PLUGIN    Author: Baubadil GmbH
 *PLUGIN    License: Proprietary
 */

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Global constants
 *
 ********************************************************************/

/* The name of this plugin. This is passed to the plugin engine and must be globally unique. */
const FTDB_PLUGIN_NAME = 'type_ftdb';

/* The database tables version. If this is higher than the stored version for this plugin in
   the global config, the plugin install routine gets triggered to upgrade. */
const FTDBPLUGIN_VERSION = 17;

/* Field IDs defined by this plugin. These add to the field IDs defined by the core.  */
const FIELD_FT_ARTICLEVARIANT_UUID      = 101;
const FIELD_FT_CONTAINS                 = 103;  # like parents; has FIELDFL_ARRAY
const FIELD_FT_CONTAINEDIN              = 104;       # like children, must be parents + 1; has FIELDFL_ARRAY_REVERSE
const FIELD_FT_ARTICLENOS               = 105;
const FIELD_FT_ICON                     = 106;
const FIELD_FT_CATEGORY_ROOT_OBSOLETE   = 107;
const FIELD_FT_CATEGORY_ALL             = 108;
const FIELD_FT_WEIGHT                   = 109;

/* Attachment types for 'special' JSON table column. */
const ATTACHTYPE_UNDEFINED = 1;                 #   Not an image.
const ATTACHTYPE_IMAGE_DEFAULT = 2;             #   Standardansicht
const ATTACHTYPE_IMAGE_KIT_EXTERIOR = 3;        #   Baukasten-Außenansicht
const ATTACHTYPE_IMAGE_KIT_INTERIOR = 4;        #   Baukasten-Innenansicht
const ATTACHTYPE_IMAGE_KIT_SORTPLAN = 5;        #   Baukasten-Sortierplan
const ATTACHTYPE_IMAGE_PART_PHOTO = 6;          #   Bauteil-Foto
const ATTACHTYPE_IMAGE_PART_CAD = 7;            #   CAD-Zeichnung oder älteres Foto eines Bauteils

/* List of class names and include files for the autoloader. */
const FT_CLASS_INCLUDES = [
    'FTCategory'               => 'plugins/type_ftdb/ftdb_category.php',
    'FTImportFile'             => 'plugins/type_ftdb/ftdb_import_image.php',
    'FTImportImage'            => 'plugins/type_ftdb/ftdb_import_image.php',
    'FTIconHandler'            => 'plugins/type_ftdb/ftdb_fieldh_icon.php',
    'FTTicket'                 => 'plugins/type_ftdb/ftdb_ticket.php'
];

const FTDB_TICKET_URL_PARTICLE_FT = 'ft-article';

/* Register the "init" function. This call gets executed when the plugin is loaded by the plugin engine. */
Plugins::RegisterInit(FTDB_PLUGIN_NAME, function()
{
    Plugins::RegisterInstance(new PluginFTDB(),
                              FT_CLASS_INCLUDES);

    TicketType::AddTicketLinkAlias(FTDB_TICKET_URL_PARTICLE_FT);
});

/* Register the "install" function. This checks whether the plugin needs an database tables upgrade. */
Plugins::RegisterInstall(FTDB_PLUGIN_NAME, function()
{
    $keyPluginVersion = "db-version-".FTDB_PLUGIN_NAME;
    if (!($pluginDBVersionNow = GlobalConfig::Get($keyPluginVersion)))
        $pluginDBVersionNow = 0;

    if ($pluginDBVersionNow < FTDBPLUGIN_VERSION)
    {
        require_once INCLUDE_PATH_PREFIX.'/plugins/type_ftdb/ftdb_install.php';
        typeFTDBInstall($keyPluginVersion, $pluginDBVersionNow, FTDBPLUGIN_VERSION);
    }
});


/********************************************************************
 *
 *  Plugin interface classes
 *
 ********************************************************************/

/**
 *  The FTDB plugin interface. This implements the necessary methods to hook
 *  the plugin into the core system. See \ref plugins for how this works.
 *
 *  This plugin has code that manages all parts and kits ever produced by
 *  fischertechnik, a German construction toy. This also serves as a show
 *  case for what Doreen plugins can do.
 */
class PluginFTDB implements ITypePlugin,
                            ISpecializedTicketPlugin,
                            IURLHandlerPlugin,
                            IMainMenuPlugin,
                            ICommandLineHandler,
                            IMainPagePlugin
{
    // GlobalConfig keys where the ticket and template IDs of this plugin are stored.
    const CONFIGKEY_PART_TYPE_ID     = 'id_ftarticle-type';
    const FT_TYPENAME_PART           = "fischertechnik article";

    const CONFIGKEY_PART_TEMPLATE_ID = 'id_ftarticle-template';

    const CONFIGKEY_INFO_TICKET_ID   = 'id_ft-info-ticket';

    # Constant for getSpecializedClassNames().
    const A_SPECIALIZED_CLASSES = [
        self::FT_TYPENAME_PART             => 'FTTicket',
    ];

    /**
     *  This must return the plugin name.
     */
    public function getName()
    {
        return FTDB_PLUGIN_NAME;
    }

    /*
     *  Implementation of the IPlugin interface function. See remarks there.
     */
    public function getCapabilities()
    {
        return    self::CAPSFL_TYPE
                | self::CAPSFL_TYPE_SPECIALTICKET
                | self::CAPSFL_MAINMENU
                | self::CAPSFL_URLHANDLERS
                | self::CAPSFL_COMMANDLINE
                | self::CAPSFL_MAINPAGE;
    }

    /**
     *  This gets called by the main page to have all main page plugins return their chunks
     *  to be displayed. This must return an array of Blurb instances.
     *
     * @return Blurb[]
     */
    public function makeBlurbs()
    {
        require_once INCLUDE_PATH_PREFIX.'/plugins/type_ftdb/ftdb_blurb.php';
        return [ new FTBlurb ];
    }

    /**
     *  This ITypePlugin method gets called lazily on every type plugin when the ticket
     *  fields engine is initialized to give all such plugins the chance to report
     *  the ticket fields it introduces. See \ref intro_ticket_types for an introduction
     *  of how ticket fields and ticket types work. This must return an array of
     *  field ID => FIELDFL_* flags pairs. The plugin must be able to create a field
     *  handler in \ref createFieldHandler() for each field ID reported here.
     *
     * @return int[]
     */
    public function reportFieldsWithHandlers()
    {
        return [
            FIELD_FT_ARTICLENOS                 =>                                FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_TEXT_LITERAL,
            FIELD_FT_ARTICLEVARIANT_UUID        => FIELDFL_REQUIRED_IN_POST_PUT | FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_TEXT_LITERAL, //  | FIELDFL_FIXED_CREATEONLY,
            FIELD_FT_CONTAINS                   =>                                FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_ARRAY | FIELDFL_ARRAY_HAS_REVERSE | FIELDFL_ARRAY_COUNT,
            FIELD_FT_CONTAINEDIN                =>                                FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_ARRAY_REVERSE | FIELDFL_ARRAY_COUNT,
            FIELD_FT_ICON                       =>                                FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG,
            FIELD_FT_CATEGORY_ROOT_OBSOLETE     =>                                FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_INT,
            FIELD_FT_CATEGORY_ALL               =>                                FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_TYPE_INT | FIELDFL_ARRAY,
            FIELD_FT_WEIGHT                     =>                                FIELDFL_STD_DATA_OLD_NEW | FIELDFL_VISIBILITY_CONFIG | FIELDFL_SORTABLE,
               ];
    }

    /**
     *  This ITypePlugin method gets gets called by \ref TicketField::GetDrillDownIDs() to
     *  give all plugins a chance to add to the global list of ticket field IDs for which
     *  drill-down should be performed. See \page drill_down_filters for an introduction.
     *  This must either return NULL or a an array of field IDs with L() strings that
     *  describe the field as a filter class (starting in lower case).
     *
     * @return string[] | null
     */
    public function reportDrillDownFieldIDs()
    {
        return [ FIELD_FT_CATEGORY_ALL => L("{{L//category}}") ];
    }

    /**
     *  This ITypePlugin method gets called by \ref TicketField::GetSearchBoostFields() to
     *  give all plugins a chance to add to the global list of ticket field IDs for which
     *  full-text search is supported. This must either return NULL or a an array of
     *  field ID / search boost value pairs.
     *
     *  Note that during ticket POST/PUT (create/update), boost values are checked only
     *  for whether they are non-NULL. Only during full-text searches are the actual
     *  boost values (e.g. 1 or 5) sent to the search engine. It is therefore possible
     *  to change a non-null boost value to another non-null bost value without reindexing.
     *
     * return int[]
     */
    public function reportSearchableFieldIDs()
    {
        return [
            FIELD_FT_ARTICLEVARIANT_UUID    => 1,
            FIELD_FT_ARTICLENOS             => 5
        ];
    }

    /**
     *  This ITypePlugin method must be implemented by all plugins that want to provide
     *  custom field handlers to Doreen. This gets called from \ref FieldHandler::Find()
     *  to give every type plugin a chance to instantiate a field handler for a given
     *  field ID if the plugin supplies one.
     *
     *  All field handlers must be derived from the FieldHandler base class. Note that the
     *  plugin must first report the field ID with \ref reportFieldsWithHandlers() for
     *  this to be called. See \ref intro_ticket_types for an introduction of how ticket
     *  fields and ticket types work.
     *
     *  This only gets called if no field handler has been created yet for the given
     *  $field_id. The plugin must simply call new() on the new field handler, which
     *  must call the the FieldHandler parent constructor, and that will register the
     *  handler correctly so that this plugin method will not get called again for that
     *  field ID. The return value of this function is ignored.
     *
     * @return void
     */
    public function createFieldHandler($field_id)
    {
        switch ($field_id)
        {
            case FIELD_FT_ARTICLEVARIANT_UUID:
                require_once INCLUDE_PATH_PREFIX."/plugins/type_ftdb/ftdb_fieldh_uuid.php";
                new FTArticleVariantUUIDHandler();
            break;

            case FIELD_FT_CONTAINEDIN:
                require_once INCLUDE_PATH_PREFIX."/plugins/type_ftdb/ftdb_fieldh_contain.php";
                new FTContainedInHandler();
            break;

            case FIELD_FT_CONTAINS:
                require_once INCLUDE_PATH_PREFIX."/plugins/type_ftdb/ftdb_fieldh_contain.php";
                new FTContainsHandler();
            break;

            case FIELD_FT_ARTICLENOS:
                require_once INCLUDE_PATH_PREFIX."/plugins/type_ftdb/ftdb_fieldh_artno.php";
                new FTArticleNosHandler();
            break;

            case FIELD_FT_ICON:
                new FTIconHandler();
            break;

            case FIELD_FT_CATEGORY_ALL:
                require_once INCLUDE_PATH_PREFIX."/plugins/type_ftdb/ftdb_fieldh_cat.php";
                new FTCategoryLeafHandler();
            break;

            case FIELD_FT_WEIGHT:
                require_once INCLUDE_PATH_PREFIX."/plugins/type_ftdb/ftdb_fieldh_weight.php";
                new FTWeightHandler();
            break;
        }
    }

    /**
     *  This is part of ISpecializedTicket (extending ITypePlugin) and gets called on
     *  all such plugins once the first time a ticket is instantiated in memory.
     *  This gives all such plugins a chance to report for which ticket types they
     *  implement specialized ticket classes that derive from Ticket. This must
     *  return either NULL or an array of ticket type => class name pairs.
     *
     * @return string[] | null
     */
    public function getSpecializedClassNames()
    {
        return self::A_SPECIALIZED_CLASSES;
    }

    /*
     *  This gets called by \ref WholePage::EmitHeader() after the main menu (at the top of the screen)
     *  has been built. This allows plugins to modify the menu, if needed.
     */
    public function modifyMainMenu(array &$aMainMenu)
    {
        $aFooter = [];

        if ($idTicketAddtl = GlobalConfig::Get(PluginFTDB::CONFIGKEY_INFO_TICKET_ID))
            if ($oTicketAddtl = Ticket::FindOne($idTicketAddtl))
            {
                $href = $oTicketAddtl->makeUrlTail();
                if ($href !== "/".Globals::GetRequestOnly())
                    $aFooter += [ $href => toHTML($oTicketAddtl->getTitle()) ];
            };

        if ($aFooter)
            WholePage::SetFooterItems($aFooter);
    }

    /**
     *  This gets called by \ref WholePage::EmitHeader() after the initial parts of the right side
     *  of the main menu have been built. This allows plugins to modify and add content in the
     *  menu strip. After the call, other items with fixed positions will be added.
     */
    public function modifyMainMenuRight(array &$aMainMenuRight)
    {
//        if (LoginSession::isCurrentUserAdmin())
//        {
//            $aGuruMenu = &$aMainMenuRight['GURUMENU'];
//            $aGuruMenu['800'] = "SEPARATOR";
//            $aGuruMenu['803-import-ftdb'] = "{{L//Import from FTDB}}".DOTS;
//        }
    }

    /**
     *  This gets called by index.php to allow plugins to register GUI request handlers.
     */
    public function registerGUIAppHandler()
    {
//        WebApp::Get('/import-ftdb', function()
//        {
//            DrnACL::CreateImportACL();
//            DrnACL::AssertCurrentUserAccess(ACL_SYS_IMPORT, ACCESS_UPDATE);      # or anything
//
//            require_once INCLUDE_PATH_PREFIX.'/plugins/type_ftdb/ftdb_import_gui.php';
//            ftdbImportGet();
//        });
//
//        WebApp::Post('/import-ftdb', function()
//        {
//            DrnACL::CreateImportACL();
//            DrnACL::AssertCurrentUserAccess(ACL_SYS_IMPORT, ACCESS_UPDATE);      # or anything
//
//            $fExecute = (getRequestArg('execute') == 1);
//
//            require_once INCLUDE_PATH_PREFIX.'/plugins/type_ftdb/ftdb_import_gui.php';
//            ftdbImportPost(getRequestArgOrDie('import-dbhost'),
//                           getRequestArgOrDie('import-dbname'),
//                           $fExecute);
//        });

        // Legacy URL handler for search by UUID.
        WebApp::Get('/web_document.php', function()
        {
            $uuid = UUID::NormalizeLong(WebApp::FetchParam('id'));
            ViewTicket::CreateAndEmit(MODE_READONLY_DETAILS, $uuid);
        });

        // Legacy URL handler for search by UUID.
        WebApp::Get('/details.php', function()
        {
            $uuid = UUID::NormalizeLong(WebApp::FetchParam('ArticleVariantId'));
            ViewTicket::CreateAndEmit(MODE_READONLY_DETAILS, $uuid);
        });

        // Legacy URL handler for full-text search.
        WebApp::Get('/search.php', function()
        {
            WebApp::Reload('tickets?fulltext='.WebApp::FetchParam('keyword'));
        });
    }

    /**
     *  This gets called by api.php to allow plugins to register API request handlers.
     */
    public function registerAPIAppHandler()
    {
        /**
         *  Fetches the parts list for the given ticket (which should be a kit).
         *
         *  Parameters:
         *
         *   -- 'idTicket' must be the ticket ID of a fischertechnik kit. (Others
         *      have no parts.)
         *
         *   -- 'page' must be the page to return; defaults to 1.
         *
         *  This returns:
         *
         *   -- 'status': 'OK' or error
         *
         *   -- 'results': an array of JSON ticket objects (base ticket data plus FT extensions),
         *      paginated
         *
         *   -- 'cTotal': total no. of items in array, for pagination. That's the no. of different
         *      part *types*, not the no. of parts in the kit.
         *
         *   -- 'cTotalFormatted': cTotal with NLS formatting according to the user's language
         *      setting.
         *
         *   -- 'nlsFoundMessage': something like "27 different parts, 100 parts in total".
         *
         *   -- 'page': the current page (starting with 1). As a special case, -1 shows all items
         *      without limitation.
         *
         *   -- 'cPages': the no. of pages that could be displayed, computed from cTotal.
         *
         *   -- 'htmlPagination': a chunk of HTML with Bootstrap pagination computed from page and cPages.
         *      See HTMLChunk::addPagination().
         *
         *   -- 'cTotalParts': the total no. of parts in the kit. That is, for every part *type* returned
         *      in the array, the total sum of each part type's item count in the kit.
         */
        WebApp::Get('/ft-partslist/:idTicket:int', function()
        {
            $idKit = WebApp::FetchParam('idTicket');
            if (!($page = WebApp::FetchParam('page', FALSE)))
                $page = 1;

            require_once INCLUDE_PATH_PREFIX."/plugins/type_ftdb/ftdb_fieldh_contain.php";
            WebApp::$aResponse += FTContainsHandler::GetPartsList($idKit,
                                                                  $page);
        });
    }

    public $aStorage = [];

    /**
     *  This is part of ICommandLineHandler and gets called from the CLI with an array of
     *  those command line arguments which have not yet been consumed by the default
     *  command line parser. The plugin must peek into the list of arguments and remove
     *  those array items that activate it as well as additional arguments that might be
     *  required in that case. After all plugins implementing this interface have been
     *  called, the CLI will fail with an "unknown command" if any arguments are left in
     *  the array. If the plugin returns TRUE, it is assumed that it wants to process
     *  the command line, and then processCommands() will get called as a second step.
     */
    public function parseArguments(&$aArgv)
    {
        require_once INCLUDE_PATH_PREFIX.'/plugins/type_ftdb/ftdb_cli.php';
        return ftdbcliParseArguments($aArgv,
                                     $this->aStorage);
    }

    /**
     *  Called by the CLI if the plugin returned TRUE from parseArguments().
     *  $idSession contains the session ID from the command line or NULL if there was none.
     *
     *  No return value.
     */
    public function processCommands($idSession)
    {
        require_once INCLUDE_PATH_PREFIX.'/plugins/type_ftdb/ftdb_cli.php';
        ftdbcliProcessCommands($this, $idSession);
    }

    /**
     *  Through this function command line plugins can add items to the 'help' output.
     */
    public function addHelp(&$aHelpItems)
    {
        require_once INCLUDE_PATH_PREFIX.'/plugins/type_ftdb/ftdb_cli.php';
        ftdbcliAddHelp($aHelpItems);
    }
}
