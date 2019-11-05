<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Plugin interfaces
 *
 ********************************************************************/

/**
 *  \page plugins Plugins
 *
 *  Doreen has been designed from the ground up to be extensible through "plugins", which
 *  are software modules that can be written by third parties without the core code needing
 *  to be aware of them.
 *
 *  To test the software interfaces that have been designed to achieve this goal, even some
 *  core functionality has been implemented through plugins. In src/plugins, you find:
 *
 *   -- two database plugins for MySQL and PostgreSQL;
 *
 *   -- two user/group plugins, one for hard-coded user and group IDs, and one for
 *      variable ones in the database;
 *
 *   -- the "ftdb" plugin in the type_ftdb subdirectory, with the code that manages all
 *      parts and kits ever produced by fischertechnik, a German construction toy. This
 *      also serves as a show case for what Doreen plugins can do. See PluginFTDB for more.
 *
 *  Here are some fundamental concepts that help to understand how Doreen plugins work.
 *
 *   -- Every plugin must implement at least the IPlugin interface, which only has two
 *      methods. \ref IPlugin::getName() must return the plugin name, and
 *      \ref IPlugin::getCapabilities() must return a bitset of CAPSFL_* flags, from which
 *      the system determines what the plugin is capable of doing.
 *
 *   -- Depending on what CAPSFL_* were returned, in addition to the base IPlugin interface, the
 *      plugin must implement additional interfaces. For example, if the plugin returns
 *      the CAPSFL_TYPE flag, it says "I can implement additional ticket types", and
 *      must also implement the ITypePlugin interface. See the IPlugin documentation for
 *      the list of additional interfaces (children of the IPlugin interface).
 *
 *   -- The plugin must provide a main code file with two callbacks, which get called as
 *      necessary. One of those functions must instantiate the plugin object that implements
 *      the interfaces as described above; for details, see below.
 *
 *  You can look at src/plugins/type_ftdb.php for the PluginFTDB sample code. That's a
 *  fairly advanced plugin that implements several interfaces.
 *
 *  For all three Doreen entry points (index.php, api.php and cli.php), the prologue includes
 *  `/var/www/.../doreen-optional-vars.inc.php`, which was written during Doreen installation.
 *  That include file sets the global $g_aPlugins variable depending on which plugins you
 *  enabled during installation.
 *
 *  The prologue then calls \ref Plugins::Init() which calls PHP `require_once` for every
 *  plugin listed in $g_aPlugins. For this to work, the main plugin file must be called
 *  `src/plugins/<NAME>/<NAME>.php` with `<NAME>` being the name listed in $g_aPlugins.
 *
 *  That main plugin file must do exactly two function calls:
 *
 *   -- it must call \ref Plugins::RegisterInit() to register an "init" function;
 *
 *   -- it must call \ref Plugins::RegisterInstall() to register an "install" function.
 *
 *  Again, this code is executed on every single page requests, so it must be quick and
 *  not do anything else, especially not cause any side effects.
 *
 *  (Note that during installation, the main plugin file will be included even if it hadn't
 *  been enabled, since at that point no plugins have been enabled at all. So the code must
 *  be prepared to be loaded at any time and, again, NOT CAUSE ANY SIDE EFFECTS except calling
 *  those two functions.)
 */

/**
 *  This class acts as the global plugin manager. See \ref plugins.
 */
abstract class Plugins
{
    private static $aFailedPlugins = [];

    /** @var IPlugin[] */
    private static $aLoadedPlugins = [];           # Array of all instantiated plugins (implementing some variant of the IPlugin interface).

    private static $aLoadedPluginPaths = [];        # plugin name => path for all loaded

    /** @var Callable[] */
    private static $apfnCanActivate = [];

    /** @var Callable[] */
    private static $apfnInit = [];

    /** @var Callable[] */
    private static $apfnInstalls = [];

    const A_DB_PLUGINS                              # Available database plugins. These are hard-coded because obviously we can't store them in a database.
        = [ 'db_mysql' => 'MySQL',
            'db_postgres' => 'PostgreSQL'
          ];

    /**
     *  Called from the prologue to load and initialize all plugins.
     */
    public static function Init($aPlugins)
    {
        # Load (but do not initialize) the database plugins.
        foreach (Plugins::A_DB_PLUGINS as $plugin => $dbname)
        {
            require_once INCLUDE_PATH_PREFIX."/plugins/$plugin.php";
        }

        if (!is_array($aPlugins))
            trigger_error("aPlugins is not set", E_USER_ERROR);

        # Initialize all plugins.
        foreach ($aPlugins as $plugin)
        {
            if ($plugin == 'config_ev')
                $plugin = 'type_ev';
            else if (    ($plugin == 'imap_mail')
                      || ($plugin == 'config_imap')
                    )
                $plugin = 'mail_imap';

            # Include returns 1 if it succeeds but testing requires a bit of special syntax, see http://php.net/manual/en/function.include.php
            $incfile = "plugins/$plugin/$plugin.php";
            if ((@include_once INCLUDE_PATH_PREFIX."/$incfile") != TRUE)
            {
                # Failed:
                self::$aFailedPlugins[$plugin] = $incfile;
            }
            else
            {
                /*
                 *  Included: then call the init function!
                 */
                self::$aLoadedPluginPaths[$plugin] = $incfile;
                if ($pfnInit = getArrayItem(self::$apfnInit, $plugin))
                    $pfnInit();
            }

        }
    }

    /**
     *  To be called optionally by plugins that need additional configuration testing
     *  before they can allow Doreen to activate them.
     */
    public static function RegisterCanActivate($name,
                                               Callable $pfn)
    {
        self::$apfnCanActivate[$name] = $pfn;
    }

    /**
     *  Calls the "can activate" function for the given plugin and returns its error,
     *  if any. If there is no error, or the plugin has no such function, then this
     *  returns NULL.
     */
    public static function TestCanActivate($plugin)
    {
        if ($pfn = getArrayItem(self::$apfnCanActivate, $plugin))
            return $pfn();

        return NULL;
    }

    /**
     *  To be called optionally by plugins that need additional installation testing,
     *  such as installing database tables, right after the init function got called.
     *
     *  Since this gets called on every page request, the recommended way to keep this
     *  lean is have a single "plugin version" value in the global config table, which
     *  gets loaded on every page request anyway.
     */
    public static function RegisterInstall($pluginName,
                                           Callable $pfn)
    {
        self::$apfnInstalls[$pluginName] = $pfn;
    }

    /**
     *  Helper wrapper around \ref RegisterInstall() to reduce boilerplate code in plugins.
     *
     *  $pfnInstantiateInstall has no parameters but must return an instance of InstallBase.
     */
    public static function RegisterInstall2(string $pluginName,
                                            int $constPluginDBVersionNeeded,
                                            Callable $pfnInstantiateInstall)
    {
        self::RegisterInstall(  $pluginName,
                                function() use($pluginName, $constPluginDBVersionNeeded, $pfnInstantiateInstall)
                                {
                                    $keyPluginVersion = "db-version-".$pluginName;
                                    if (!($pluginDBVersionNow = GlobalConfig::Get($keyPluginVersion)))
                                        $pluginDBVersionNow = 0;

                                    Debug::Log(0, "Testing install for plugin $pluginName: keyPluginVersion=$keyPluginVersion, versionNow=$pluginDBVersionNow, versionExpected=$constPluginDBVersionNeeded");
                                    if ($pluginDBVersionNow < $constPluginDBVersionNeeded)
                                    {
                                        /** @var InstallBase $o */
                                        $o = $pfnInstantiateInstall();

                                        $o->init($pluginName,
                                                 $keyPluginVersion,
                                                 $pluginDBVersionNow,
                                                 $constPluginDBVersionNeeded);

                                        if ($pluginDBVersionNow < 1)
                                        {
                                            // Initial install:
                                            GlobalConfig::AddInstall("$o->htmlPlugin: Store plugin version $constPluginDBVersionNeeded in config $keyPluginVersion", <<<EOD
INSERT INTO config (key, value) VALUES ('$keyPluginVersion', $constPluginDBVersionNeeded)
EOD
                                            );

                                            $o->fRefreshTypes = TRUE;
                                        }

                                        $o->doInstall();

                                        if ($pluginDBVersionNow > 0)
                                            GlobalConfig::AddInstall("$o->htmlPlugin: Update plugin version to $constPluginDBVersionNeeded in config $keyPluginVersion", <<<EOD
UPDATE config SET value = '$constPluginDBVersionNeeded' WHERE key = '$keyPluginVersion'
EOD
                                            );

                                        # To be safe, in case the plugin updated the global config.
                                        GlobalConfig::Save();
                                    }
                                });
    }

    /**
     *  Called from \ref GlobalConfig::Init() and from install2+3.php to test all plugins if they
     *  have any installation requests.
     */
    public static function TestInstalls()
    {
        Debug::Log(Debug::FL_INSTALL, "Test installs apfnInstalls: ".print_r(self::$apfnInstalls, TRUE));

        foreach (self::$apfnInstalls as $plugin => $pfn)
            $pfn();
    }

    /**
     *  To be called as pretty much the first thing in a plugin's main PHP file, so that
     *  it gets called as soon as the plugin PHP file is loaded by the Doreen core.
     *
     *  This must provide a callable which creates the IPlugin instance and calls
     *  RegisterInstance with it, so that the plugin can be initialized as a second
     *  step and separately from loading. (This is necessary because when installing
     *  Doreen, plugins get loaded
     *  but are not initialized.) Like so:
     *
        ```php

        Plugins::RegisterInit(MY_PLUGIN_NAME, function()
        {
            Plugins::RegisterInstance(new MyPluginClass());
        });

       ```
     */
    public static function RegisterInit($pluginName,
                                        Callable $pfn)
    {
        self::$apfnInit[$pluginName] = $pfn;
    }

    /**
     *  To be called from a plugin's init function (the closure passed to \ref RegisterInit()).
     *
     *  If ($fl & REGISTER_SEARCH), then the plugin is also registered as the global search engine.
     *
     *  $aClassIncludes is added to the autoloader array in Globals.
     */
    public static function RegisterInstance(IPlugin $oPlugin,
                                            $aClassIncludes = NULL)     //!< in: array of class name => include file pairs or NULL
    {
        $name = $oPlugin->getName();
        self::$aLoadedPlugins[$name] = $oPlugin;

        if ($aClassIncludes)
            Globals::RegisterClassIncludes($aClassIncludes);
    }

    /**
     *  Returns an array of plugin name => include file pairs for plugins that have failed to load, or NULL if all is OK.
     */
    public static function GetFailed()
    {
        return count(self::$aFailedPlugins) ? self::$aFailedPlugins : NULL;
    }

    /**
     *  Returns a list of (object, path) of the given plugin. Works only for those that have loaded successfully.
     */
    public static function Get($name)
    {
        $o = getArrayItem(self::$aLoadedPlugins, $name);
        $path = getArrayItem(self::$aLoadedPluginPaths, $name);
        return [$o, $path];
    }

    /**
     *  Returns a flat list of plugin objects (implementing some variant of IPlugin) that have reported the
     *  given CAPSFL_* capability.
     *
     *  If multiple CAPSFL_* are ORed together in $fl, then only plugins are returned which support all of them.
     *
     *  Returns an empty array, not NULL, if nothing was found, so the result can safely be used in a foreach() loop.
     *
     *  For the result set to be useful, the caller should cast it to an IPlugin sub-interface. For example,
     *  if you request plugins for CAPSFL_URLHANDLERS, then all results implement the IURLHandlerPlugin interface,
     *  and you can safely call its methods on the result objects.
     *
     * @return IPlugin[]
     */
    public static function GetWithCaps($fl)
        : array
    {
        $a = [];
        foreach (self::$aLoadedPlugins as $plugin => $oImpl)
            if (($oImpl->getCapabilities() & $fl) == $fl)       # in case caller requests more than one cap
                $a[] = $oImpl;

        return $a;
    }

    /**
     *  Convenience function for GetWithCaps(IPlugin::CAPSFL_USER).
     *
     * @return IUserPlugin[]
     */
    public static function GetWithUserCaps()
    {
        /** @var IUserPlugin[] $v */
        $v = self::GetWithCaps(IUserPlugin::CAPSFL_USER);
        return $v;
    }

    /**
     * @return IUserPluginMutable
     */
    public static function GetWithMutableUserCaps()
    {
        /** @var IUserPluginMutable[] $a */
        $a = self::GetWithCaps(IUserPlugin::CAPSFL_USER_MUTABLE);
        if (count($a) > 1)
            myDie("Internal configuration error: too many plugins provide mutable user functionality.");
        if (count($a) == 0)
            myDie("Internal configuration error: no plugin provides mutable user functionality.");
        return $a[0];
    }

    /**
     *  Provides a human-readable description of the given plugin capabilities flags.
     *  Used by \ref ViewGlobalSettings::DescribePlugins().
     */
    public static function DescribeFlags($fl)
    {
        $a = [];
        foreach ( [
                      IPlugin::CAPSFL_URLHANDLERS => "Provides URL handlers",
                      IPlugin::CAPSFL_MAINMENU => "Can modify the menu",
                      IPlugin::CAPSFL_GLOBALSETTINGS => "Can modify the global settings",
                      IPlugin::CAPSFL_MAINPAGE => "Can create main page contents",
                      IPlugin::CAPSFL_TYPE => "Provides ticket types",
                      IPlugin::CAPSFL_TYPE_SPECIALTICKET => "Provides specialized ticket classes",
                      IPlugin::CAPSFL_PRECOOK_SEARCH => "Can pre-process search requests",
                      IPlugin::CAPSFL_SEARCH => "Provides a search engine",
                      IPlugin::CAPSFL_COMMANDLINE => "Provides command line interfaces",
                      IPlugin::CAPSFL_MAILCLIENT => "Provides email client functionality",
                      IPlugin::CAPSFL_MAILHELPER => "Provides mail content scanners",
                      IPlugin::CAPSFL_SERVICE => "Provides background services",
                      IPlugin::CAPSFL_USER => "Provides user accounts",
                      IPlugin::CAPSFL_USER_MUTABLE => "Provides editable user accounts",
                      IPlugin::CAPSFL_HELPTOPICS => "Provides additional topics for the help API",
                      IPlugin::CAPSFL_USERMANAGEMENT => "Modifies user management",
                  ] as $f => $str)
        {
            if ($fl & $f)
                $a[] = $str;
        }

        return implode("<br>", $a);
    }

    /**
     *  Calls the database init function of the given plugin, which returns a Database instance.
     *
     * @return Database
     */
    public static function InitDatabase($dbplugin)
    {
        if (!(Plugins::A_DB_PLUGINS[$dbplugin]))
            myDie("Configuration error: ".toHTML($dbplugin, 'code')." is not a valid database plugin");
        # Load and initialize the database plugin.
        require_once INCLUDE_PATH_PREFIX."/plugins/$dbplugin.php";
        if (!($pfn = getArrayItem(self::$apfnInit, $dbplugin)))
            myDie("Configuration error: cannot instantiate database with $dbplugin plugin");
        # Instantiate
        return $pfn($dbplugin);
    }

    private static  $fSearchInited  = FALSE;
    /** @var SearchEngineBase $oSearch */
    private static  $oSearch        = NULL;

    /**
     *  Returns a SearchBase instance representing the one and only search engine on the system,
     *  or NULL if no plugin with search capability is installed.
     *
     *  On the first call, this tries to find a plugin with CAPSFL_SEARCH (of which there can be
     *  only one) and calls its instantiateSearch() method to have the SearchBase instance
     *  created. On subsequent calls the cached SearchBase is returned.
     *
     *  @return SearchEngineBase|null
     */
    public static function GetSearchInstance()
    {
        if (!self::$fSearchInited)
        {
            /** @var ISearchPlugin */
            $oSearchPlugin = NULL;
            foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_SEARCH) as $oImpl)
            {
                /** @var ISearchPlugin $oImpl */
                if ($oSearchPlugin)
                    myDie("Too many search plugins active! There can be only one!");

                $oSearchPlugin = $oImpl;
            }

            if ($oSearchPlugin)
                self::$oSearch = $oSearchPlugin->instantiateSearchEngine();

            self::$fSearchInited = TRUE;
        }

        return self::$oSearch;      // can be NULL
    }
}

/**
 *  The minimal plugin interface that plugins must implement. Additional interfaces derive from this.
 *  See \ref plugins.
 */
interface IPlugin
{
    const CAPSFL_URLHANDLERS            = 1 <<  0;              //!< Plugin implements IURLHandlerPlugin
    const CAPSFL_MAINMENU               = 1 <<  1;              //!< Plugin implements IMainMenuPlugin and modifies the main menu
    const CAPSFL_GLOBALSETTINGS         = 1 <<  2;              //!< Plugin implements IGlobalSettingsPlugin and adds to the global settings table
    const CAPSFL_MAINPAGE               = 1 <<  3;              //!< Plugin implements IMainPagePlugin
    const CAPSFL_TYPE                   = 1 <<  4;              //!< Plugin implements ITypePlugin and thus adds additional ticket types
    const CAPSFL_TYPE_SPECIALTICKET     = 1 <<  5;              //!< Plugin implements ISpecializedTicketPlugin
    const CAPSFL_WORKFLOW               = 1 <<  6;              //!< Plugin implements IWorkflowPlugin
    const CAPSFL_PRECOOK_SEARCH         = 1 <<  7;              //!< Plugin implements IPrecookSearchPlugin and can pre-process searches
    const CAPSFL_SEARCH                 = 1 <<  8;              //!< Plugin implements ISearchPlugin and thus full-text search
    const CAPSFL_COMMANDLINE            = 1 <<  9;              //!< Plugin implements ICommandLineHandler
    const CAPSFL_MAILCLIENT             = 1 << 10;              //!< Plugin implements IMailClientPlugin
    const CAPSFL_MAILHELPER             = 1 << 11;              //!< Plugin implements IMailScannerPlugin
    const CAPSFL_SERVICE                = 1 << 12;              //!< Plugin implements IServicePlugin
    const CAPSFL_USER                   = 1 << 13;              //!< Plugin implements IUserPlugin
    const CAPSFL_USER_MUTABLE           = 1 << 14;              //!< Plugin implements IUserPluginMutable on top of IUserPlugin
    const CAPSFL_HELPTOPICS             = 1 << 15;              //!< Plugin implements IHelpTopicsPlugin
    const CAPSFL_USERMANAGEMENT         = 1 << 16;              //!< Plugin implements IUserManagement
    const CAPSFL_HEALTHCHECK            = 1 << 17;              //!< Plugin implements IHealthCheck
    const CAPSFL_FORMAT_CHANGELOG       = 1 << 18;              //!< Plugin implements IFormatChangelog
    const CAPSFL_MAILSUGGESTER          = 1 << 19;              //!< Plugin implements IMailSuggesterPlugin

    /**
     *  This must return the plugin name.
     */
    public function getName();

    /**
     *  This must return the capabilities that the plugin provides, with the
     *  IPlugin::CAPSFL_* flags ORed together.
     */
    public function getCapabilities();
}

/**
 *  Plugins which report CAPSFL_URLHANDLERS to add additional GUI and API URL handlers
 *  must implement this interface
 */
interface IURLHandlerPlugin extends IPlugin
{
    /**
     *  This gets called by index.php to allow plugins to register GUI request handlers.
     */
    public function registerGUIAppHandler();

    /**
     *  This gets called by api.php to allow plugins to register API request handlers.
     */
    public function registerAPIAppHandler();
}

/**
 *  Plugins which report CAPSFL_MAINMENU to modify the main menu must implement this interface.
 */
interface IMainMenuPlugin extends IPlugin
{
    /**
     *  This gets called by \ref WholePage::EmitHeader() after the left portion of the main menu
     *  (at the top of the screen) has been built. This allows plugins to modify the menu, if needed.
     */
    public function modifyMainMenu(array &$aMainMenu);

    /**
     *  This gets called by \ref WholePage::EmitHeader() after the initial parts of the right side
     *  of the main menu have been built. This allows plugins to modify and add content in the
     *  menu strip. After the call, other items with fixed positions will be added.
     *
     *  If the result contains a GURUMENU entry, the Administration menu is displayed.
     */
    public function modifyMainMenuRight(array &$aMainMenuRight);
}

/**
 *  Plugins which report CAPSFL_GLOBALSETTINGS to add to the global settings page must implement this interface.
 */
interface IGlobalSettingsPlugin extends IPlugin
{
    /**
     *  This gets called by the global settings menu handler to allow plugins to add rows
     *  to the global settings table. Every row must be a label / contents pair; the contents
     *  can either be a string or a callable which returns such a string.
     */
    public function addToSettingsTable(&$aRows);
}

/**
 *  Plugins which report CAPSFL_MAILMASTER to provide email must implement this interface.
 */
interface IMailClientPlugin extends IPlugin
{
    /**
     *  Similar to IMainMenuPlugin::modifyMainManu(), this gets called by WholePage::EmitHeader()
     *  when the main menu is being built. The "Mail" menu only gets added if any plugins
     *  report CAPSFL_MAILMASTER, and then this function gets called for every one of them.
     */
    public function modifyMailMenu(&$aMainMenu);

    /**
     *  This IMailClientPlugin method gets called whenever an email address is about to be
     *  displayed by Doreen, and it gives the plugins a chance to add HTML code to it
     *  in order to active its own "Compose" feature, if desired. If any plugin returns
     *  a non-NULL HTMLChunk from this function, that is assumed to be HTML code, and the
     *  address gets replaced with it.
     *
     * @return HTMLChunk | null
     */
    public function formatMailAddress(string $address,
                                      bool $fAddToClipboard);
}

/**
 *  Plugins which report CAPSFL_MAINPAGE to modify the main page must implement this interface.
 */
interface IMainPagePlugin extends IPlugin
{
    /**
     *  This gets called by the main page to have all main page plugins return their chunks
     *  to be displayed. This must return an array of Blurb instances.
     *
     * @return Blurb[]
     */
    public function makeBlurbs();
}

/**
 *  Plugins which report CAPSFL_TYPE to implement additional ticket fields must implement this interface.
 */
interface ITypePlugin extends IPlugin
{
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
    public function reportFieldsWithHandlers();

    /**
     *  This ITypePlugin method gets gets called by \ref TicketField::GetDrillDownIDs() to
     *  give all plugins a chance to add to the global list of ticket field IDs for which
     *  drill-down should be performed. See \page drill_down_filters for an introduction.
     *  This must either return NULL or a an array of field IDs with L() strings that
     *  describe the field as a filter class (starting in lower case).
     *
     * @return string[] | null
     */
    public function reportDrillDownFieldIDs();

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
    public function reportSearchableFieldIDs();

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
    public function createFieldHandler($field_id);
}

/**
 *  Plugins which report CAPSFL_TYPE_SPECIALTICKET to provide specialized subclasses of
 *  Ticket for certain ticket types must implement this interface on top of ITypePlugin.
 */
interface ISpecializedTicketPlugin extends ITypePlugin
{
    /**
     *  This is part of ISpecializedTicket (extending ITypePlugin) and gets called on
     *  all such plugins once the first time a ticket is instantiated in memory.
     *  This gives all such plugins a chance to report for which ticket types they
     *  implement specialized ticket classes that derive from Ticket. This must
     *  return either NULL or an array of ticket type => class name pairs.
     *
     * @return string[] | null
     */
    public function getSpecializedClassNames();
}

/**
 *  Plugins which report CAPSFL_WORKFLOW to provide workflows for FIELD_STATUS
 *  must implement this interface on top of ITypePlugin.
 */
interface IWorkflowPlugin extends ITypePlugin
{
    /**
     *  This is part of IWorkflowPlugin and gets called from \ref WorkflowHandler::InitWorkflowHandlers()
     *  to give all plugins that implement this interface a chance to report the workflows they implement.
     *  This must return a flat list of integer workflow IDs. The plugin must instantiate an instance
     *  of a WorkflowHandler subclass for that ID in \ref createWorkflowHandler().
     *
     * @return int[]
     */
    public function registerWorkflowHandlers();

    /**
     *  This is part of IWorkflowPlugin and gets called from \ref WorkflowHandler::FindWorkflowHandler()
     *  to give plugins the chance to add a workflow handler for the given workflow ID if the
     *  plugin supplies one. See TicketWorkflow as well.
     *
     *  Workflow handlers must be derived from the WorkflowHandler base class, which is
     *  a specialized FieldHandler.
     *
     *  This only gets called if no workflow handler was found for the given $workflow_id. The
     *  plugin must simply call new() on the new workflow handler, which must call the the
     *  WorkflowHandler parent constructor, and that will register the handler correctly.
     *  The return value of this function is ignored.
     */
    public function createWorkflowHandler($workflow_id);

    /**
     *  This is part of IWorkflowPlugin and gets called from TicketWorkflow::GetStatusDescription()
     *  when status descriptions are needed.
     *
     * @return void
     */
    public function provideTranslations(&$aTranslations);

    /**
     *  This is part of IWorkflowPlugin and gets called from the theme engine whenever the
     *  GUI theme is changed. This gives plugins a chance to update the status color values
     *  in the database based on the new theme.
     *
     *  $theme contains the theme that we are in the process of switching to, $aVariables
     *  contains a crudely parsed set of less variables from the .less theme file.
     *
     * @return void
     */
    public function onThemeChanged(string $theme,
                                   array $aVariables);
}

/**
 *  Plugins which report CAPSFL_SEARCH to provide search functionality must implement this interface.
 */
interface ISearchPlugin extends IPlugin
{
    /**
     *  Called by SearchBase::GetSearchInstance() to actually instance an instance
     *  that implements SearchBase.
     *
     * @return SearchEngineBase
     */
    public function instantiateSearchEngine()
        : SearchEngineBase;
}

/**
 *  Plugins which report CAPSFL_SERVICE to provide additional Doreen background services must implement this interface.
 */
interface IServicePlugin extends IPlugin
{
    /**
     *  Through this function a plugin can report an array of service instances.
     *
     * @return ServiceLongtask[]
     */
    public function reportServices();
}

/**
 *  Plugins which report CAPSFL_COMMANDLINE to to hook into the command line handler (cli.php)
 *  must implement this interface. This way plugins can implement additional command line
 *  modes.
 */
interface ICommandLineHandler extends IPlugin
{
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
    public function parseArguments(&$aArgv);

    /**
     *  Called by the CLI if the plugin returned TRUE from parseArguments().
     *  $idSession contains the session ID from the command line or NULL if there was none.
     *
     *  No return value.
     */
    public function processCommands($idSession);

    /**
     *  Through this function command line plugins can add items to the 'help' output.
     */
    public function addHelp(&$aHelpItems);
}

/**
 *  Plugins which report CAPSFL_MAILHELPER to provide additional mail functionality to an email plugin
 *  must implement this interface.
 */
interface IMailHelperPlugin extends IPlugin
{
    /**
     *  This gets called from IMAPMail::InvokeScanners() for every plugin that has announced
     *  that it has CAPSFL_MAILSCAN capability. It receives an array of IMAPMail instances,
     *  which the plugin can scan.
     *
     *  If the plugin finds anything, it must return an array with key/value pairs as follows:
     *
     *   [ mailid => [ 'fromTickets'     => [ ticket-json, ... ],
     *                 'subjectTickets'  => [ ticket-json, ... ] ],
     *     ... ]
     *
     *   The [ ticket-json, ... ] items are just as returned from \ref Ticket::GetManyAsArray().
     */
    public function scanMails($oFolder,
                              $aMails,
                              $fSingle);     //!< in: if TRUE, we're in single-mail mode, otherwise in mail-list mode

    const MODIFYPAGETYPE_LIST = 1;
    const MODIFYPAGETYPE_SINGLE = 2;
    const MODIFYPAGETYPE_THREAD = 3;

    /**
     *  Gets called from the backend when the mail view pages are being built, to give the plugin
     *  the chance to add additional dialogs to the page, if neeeded. The given HTML chunk contains
     *  almost the full page (with the folders DIV on the left and the empty mails DIV on the right),
     *  but the outer page DIV is still open and can be added to. For sophisticated HTML trickery
     *  it's probably better to add run-time JavaScript via WholePage::AddToDocumentReady() during
     *  the call instead of modifying the existing HTML string.
     */
    public function modifyPage(HTMLChunk $oHTML,
                               $idDialog,           //!< in: dialog ID and control ID prefix (e.g. 'imap-mail')
                               $type);              //!< in: MODIFYPAGETYPE_LIST when in list view, MODIFYPAGETYPE_SINGLE for single mails, MODIFYPAGETYPE_THREAD for a mail thread

    const GETINFO_SIGNATURE = 1;

    /**
     *  Gets called by the backend to give mail helpers a chance to provide additional information
     *  for the current user.
     *
     *  $type can currently only be GETINFO_SIGNATURE, and this can return a signature (footer) for
     *  the "send" facility and the given email address.
     *
     * @return mixed
     */
    public function getInfo($addr,
                            $type);
}

/**
 *  Plugins which report CAPSFL_PRECOOK_SEARCH to be able to modify search requests must implement this interface.
 */
interface IPrecookSearchPlugin extends IPlugin
{
    /**
     *  This gets called by SearchFilter::Fulltext() whenever a full-text search is being prepared.
     *  Plugins can then pre-process the fulltext query before it gets sent to the search engine.
     *
     *  If this returns a modified fulltext, then no further plugins are called. Otherwise this must return NULL.
     */
    public function precookSearch($fulltext);  //!< in/out: if ref to empty array on input, receives list of words to highlight
}

/**
 *  Plugins which report CAPSFL_HELPTOPICS to add additional help topics for the GET /help REST API must
 *  implement this interface.
 */
interface IHelpTopicsPlugin extends IPlugin
{
    /**
     *  This gets called by APIHelp::Get to return help for a topic. If the plugin provides help for the
     *  topic represented by the given string (which should have a plugin prefix), then it must return
     *  a plain list with two items: first a plain-text heading string to be displayed to the user, second
     *  an HTML block with the actual help text.
     *
     *  Otherwise it must return NULL.
     **/
    public function getHelpForTopic($topic);
}

/**
 *  Plugins which report CAPSFL_USERMANAGEMENT to add hooks for when user passwords are changed must
 *  implement this interface.
 */
interface IUserManagement extends IPlugin
{
    /**
     *  This gets called by \ref User::setKeyValue() when its $fValidate parameter has been set to TRUE to
     *  give all plugins which implement this interface a chance to greenlight the given key/value pair,
     *  which probably comes from a POST /userkey REST API. If the plugin wants to say OK, it must return TRUE,
     *  and no further plugins will be called. If no plugin returns TRUE, the key/value pair is rejected, and
     *  an exception is thrown so that the API returns an error.
     **/
    public function validateUserKeyValue($key, $value);

    /**
     *  This gets called for the "My account" page to allow a user management plugin to modify its behavior.
     *
     *  If a plugin returns TRUE here, no further plugins are called, and the given references are assumed
     *  to have been filled with data:
     *
     *   -- $htmlPasswordInfo can receive a replacement text for the help under the "Change password" entry
     *      field.
     *
     *   -- $htmlEmailInfo can receive a replacement text for the help under the "Email address" entry field.
     *
     * @return bool
     */
    public function modifyMyAccountView(&$htmlPasswordInfo,
                                        &$htmlEmailInfo,
                                        &$fCanChangeEmail);

    /**
     *  This function gets called when a user updates their password on the "My account" page. This only
     *  gets called after the values in the "change password" and "confirm password" fields have been tested
     *  for equality and allows plugins to reject a password by returning a string value.
     *
     *  If this returns NULL, the password is considered OK.
     *
     *  This only affects the "My account" page, not user accounts on the administration pages.
     *
     * @return string | NULL
     */
    public function validateChangeMyPassword($plainPassword);

    /**
     *  This function gets called after the current user has successfully changed their password on the "My account" page.
     *  This gets called after all the \ref validateChangeMyPassword() callbacks have NOT vetoed the change,
     *  and the password change has already been written to the database for the Doreen user account. Plugins
     *  should therefore NOT report errors at this time.
     *
     * @return void
     */
    public function onPasswordChanged($email, $plainPassword);

}

/**
 *  Plugins which report CAPSFL_HEALTHCHECK to perform health checks for Health::Check() must implement
 *  this interface.
 */
interface IHealthCheck extends IPlugin
{
    /**
     *  This gets called by Health::Check() for every plugin that reports CAPSFL_HEALTHCHECK capability to
     *  give it a chance to add items to the system health check. This must call Health::Add() for each
     *  notice, warning or error that should be shown.
     */
    public function checkHealth();
}

/**
 *  Plugins which report CAPSFL_FORMAT_CHANGELOG to provide additional changelog formatting must
 *  implement this interface.
 */
interface IFormatChangelog extends IPlugin
{
    /**
     *  This can get called from changelog formatters to try to format custom changelog rows
     *  that the core may not be aware of. If this returns an HTMLChunk != NULL, then that
     *  can get printed; otherwise the next plugin will be tried, or a default string will
     *  be printed.
     *
     * @return HTMLChunk | NULL
     */
    public function tryFormatChangelogRow(ChangelogRow $oRow);
}

/**
 *  Plugins which report CAPSFL_MAILSUGGESTER to provide suggested mail addresses must
 *  implement this interface.
 */
interface IMailSuggesterPlugin extends IPlugin
{
    /**
     *  Gets called to provide auto completion suggestions for mail addresses.
     *  Should return an array with objects containing the address and display
     *  name formatted as "display name <email>" in a value property. It can
     *  optionally have a score property, containing a score between 0 and 1.
     *  The array should have at most $max keys.
     *
     *  @return array
     */
    public function suggestMailAddresses(string $query,
                                         User $oUser,
                                         int $max)
        : array;
}
