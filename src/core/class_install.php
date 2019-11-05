<?php
/*
 *PLUGIN    Name: config_tickettypes
 *PLUGIN    URI: http://www.baubadil.org/doreen
 *PLUGIN    Description: Provides an administration interface to view and configure ticket types and templates
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
 *  Install class
 *
 ********************************************************************/

/**
 *  Install combines install-related actions and data. This operates in two modes:
 *
 *   1) As an instance, Install represents one of the available installations from
 *      the Apache WWW data directory to support the install-list etc. commands.
 *      Use the static Get() method to have a list of those instantiated.
 *
 *   2) Additionally this has a bunch of static methods that are now (2018) used
 *      during the GUI initial install process, and it collects the install items
 *      from the core and plugins in the static class data.
 */
class Install
{
    public        $fullpath;
    public        $fIsCurrent;
    public        $dbname;

    public static $aNeededInstallsPrio1 = [];       # html => function or SQL pairs
    public static $aNeededInstallsPrio2 = [];       # html => function or SQL pairs
    public static $aNeededInstalls      = [];       # html => function or SQL pairs

    public static $tempHostname;                    # Used only during CLI reinstall.

    public function __construct($installDir,
                                $fname,
                                $fIsCurrent)
    {
        $this->fullpath = FileHelpers::MakePath($installDir, $fname);
        $this->fIsCurrent = $fIsCurrent;

        if (!($fh = fopen($this->fullpath, "r")))
            throw new DrnException("Cannot read file $this->fullpath for reading");

        while (($line = fgets($fh)) !== false)
        {
            if (preg_match("/^\s*define\(\"(.*)\",\s*\"(.*)\"\)/", $line, $aMatches))
            {
                $key = $aMatches[1];
                $value = $aMatches[2];
                switch ($key)
                {
                    case 'DBNAME':
                        $this->dbname = $value;
                    break;
                }
            }
        }

        fclose($fh);
    }

    /**
     *
     * @return Install[]
     */
    public static function Get($installDir)
    {
        $ll = [];
        FileHelpers::ForEachFile(   $installDir,
                                    "/doreen-install-vars.inc.php(?:_(.*))?/",
                                    function($name, $aMatches) use($installDir, &$ll)
                                    {
                                        $fIsCurrent = getArrayItem($aMatches, 1) === NULL;
                                        $o = new self($installDir, $name, $fIsCurrent);
                                        $ll[] = $o;
                                    });

        return count($ll) ? $ll : NULL;
    }

    /**
     * @param $ll Install[]
     */
    public static function FindCurrent($ll)
    {
        foreach ($ll as $o)
            if ($o->fIsCurrent)
                return $o;

        return NULL;
    }

    private static function RenameFiles($installDir,
                                        $fromSuffix,        //!< in: "from" suffix including underscore, or NULL
                                        $toSuffix)          //!< in: "to" suffix including underscore, or NULL
    {
        foreach ( [ 1, 2 ] as $loop)
        {
            foreach ( [ 'doreen-install-vars.inc.php',
                        'doreen-key.inc.php',
                        'doreen-optional-vars.inc.php'
                      ] as $file)
            {
                $from = $file.$fromSuffix;
                $fullfile = FileHelpers::MakePath($installDir, $from);
                if (!@file_exists($fullfile))
                    throw new DrnException("Cannot find file $fullfile");

                $target = $file.$toSuffix;
                $fulltarget = dirname($fullfile)."/".$target;

                if ($loop == 1)
                {
                    if (@file_exists($fulltarget))
                        throw new DrnException("Refusing to rename $fullfile: target $fulltarget exists");
                }
                else
                {
                    rename($fullfile, $fulltarget);
                    echo "Renamed $file to $target\n";
                }
            }
        }

    }

    /**
     *  Backs up the current installation under the given name.
     */
    private static function DeactivateCurrent($installDir,
                                              Install $oCurrent)
    {
        if (!$oCurrent->fIsCurrent)
            throw new DrnException("$oCurrent->dbname is not the current installation");

        Globals::EchoIfCli("Backing up current install \"$oCurrent->fullpath\"");

        self::RenameFiles($installDir,
                          NULL,             // from suffix -> current
                          "_".$oCurrent->dbname);   // "to" suffix
    }

    public static function ListCli($installDir)
    {
        if ($ll = self::Get($installDir))
            foreach ($ll as $oInstall)
            {
                $current = $oInstall->fIsCurrent ? "          <--- CURRENTLY ACTIVE" : "";
                Globals::EchoIfCli($oInstall->dbname.$current);
            }
    }

    /**
     *  Backs up the current installation and creates a new, blank one.
     */
    public static function CreateBlank($installDir)
    {
        Process::AssertRunningAsRoot();

        $oCurrent = NULL;
        if ($ll = self::Get($installDir))
            $oCurrent = self::FindCurrent($ll);
        if (!$oCurrent)
            throw new DrnException("Cannot find current installation");

        self::DeactivateCurrent($installDir, $oCurrent);
        Globals::EchoIfCli("OK, the current installation has been backed up and is inactive.\n"
                          ."You must now run the Doreen install in your browser; until then your installation won't work.");
    }

    public static function SwitchTo($installDir, $name)
    {
        $oCurrent = NULL;
        if ($ll = self::Get($installDir))
            $oCurrent = self::FindCurrent($ll);
        if (!$oCurrent)
            throw new DrnException("Cannot find current installation");

        $oSwitchTo = NULL;
        foreach ($ll as $o)
            if ($o->dbname == $name)
            {
                $oSwitchTo = $o;
                break;
            }
        if (!$oSwitchTo)
            throw new DrnException("Cannot find any installation named \"$name\", use 'install-list' to inspect");
        if ($oSwitchTo->fIsCurrent)
            throw new DrnException("Installation named \"$name\" is already current");

        self::DeactivateCurrent($installDir, $oCurrent);

        Globals::EchoIfCli("Restoring install \"$name\"");
        self::RenameFiles($installDir,
                          "_".$oSwitchTo->dbname,   // "from" suffix
                          NULL);
    }

    const A_VARIABLES = [
        'PREFILL_DOREEN_DB_NAME' => "Doreen database name (prompted for during install)",
        'PREFILL_ADMIN_LOGIN' => "Administrator login name (prompted for during install)",
        'PREFILL_ADMIN_PWD' => "Administrator password (prompted for during install)",
        'PREFILL_ADMIN_EMAIL' => "Administrator email (prompted for during install)",
    ];

    /**
     *  Implementation for the 'install-vars' command. If $fQuery == TRUE, then the user is
     *  prompted for new values (--query option), and the caller has ensured that we're
     *  running as root.
     */
    public static function ShowVariables($fQuery)
    {
        $aReplacements = [];

        foreach (self::A_VARIABLES as $varname => $descr)
        {
            if (defined($varname))
                echo "$varname = ".Format::UTF8Quote(constant($varname))."\n";
            else
                echo "$varname: not set\n";

            if ($fQuery)
                if ($v = FileHelpers::PromptPassword("New value (leave blank to unset): "))
                {
                    $re = "^\\s*define\\('$varname',\\s*'.*'\\);\\s*\$";
                    $repl = "define('$varname', '$v');";
                    $aReplacements[$re] = [ $repl, TRUE ];
                }
        }

        if ($fQuery)
        {
            FileHelpers::ReplaceInFile(Globals::$fnameOptionalVars,
                                       $aReplacements);
        }
    }

    /**
     *  Returns an array of all plugins by traversing the Doreen source code directories
     *  under src/plugins. Each key is the plugin name (directory name under src/plugins/),
     *  with the value being a sub-array with the filename, descr, author, defaults, enabled
     *  sub-keys.
     */
    public static function GetAllPlugins()
    {
        $aPlugins = [];
        /** @noinspection PhpUnusedParameterInspection */
        FileHelpers::ForEachFile(
                    INCLUDE_PATH_PREFIX.'/plugins',
                    '/(.*)/',
                    function($name, $aMatches) use(&$aPlugins)
                    {
                        if (    (is_dir(INCLUDE_PATH_PREFIX."/plugins/$name"))
                             && ($fh = fopen(INCLUDE_PATH_PREFIX."/plugins/$name/$name.php", 'r'))
                           )
                        {
                            $cLines = 0;
                            $descr = $author = $defaults = NULL;
                            while ($line = fgets($fh))
                            {
                                if (preg_match('/^ \*PLUGIN\s+Description:\s+(.*)/', $line, $a))
                                    $descr = $a[1];
                                else if (preg_match('/^ \*PLUGIN\s+Author:\s+(.*)/', $line, $a))
                                    $author = $a[1];
                                else if (preg_match('/^ \*PLUGIN\s+Defaults:\s+(.*)/', $line, $a))
                                    $defaults = $a[1];
                                if (++$cLines > 10)
                                    break;
                            }
                            fclose($fh);

                            $fEnabled = in_array($name, $GLOBALS['g_aPlugins']);

                            if ($descr)
                                $aPlugins[$name] = [ 'filename' => $name,
                                                     'descr' => $descr,
                                                     'author' => $author,
                                                     'defaults' => $defaults,
                                                     'enabled' => $fEnabled
                                                   ];
                        }
                    });
        ksort($aPlugins);
        return $aPlugins;
    }

    /**
     *  Implementation for the plugin-clone CLI command.
     *
     * @return void
     */
    public static function CliPluginClone(string $gitRepoUrl,
                                          bool $fForce)
    {
        if (    (!(preg_match('/doreen-([-a-zA-Z_0-9]+)(?:\.git)?$/', $gitRepoUrl, $aMatches)))
             || (!($pluginName = $aMatches[1]))
           )
            throw new DrnException("Failed to find a valid plugin name starting with doreen- in URL ".Format::UTF8Quote($gitRepoUrl));

        $pluginsDir = FileHelpers::RealPath(DOREEN_ROOT.'/src/plugins');
        if (!is_dir($pluginsDir))
            throw new DrnException("Doreen plugins dir ".Format::UTF8Quote($pluginsDir)." does not exist");
        $pluginSymlink = "$pluginsDir/$pluginName";
        if (file_exists($pluginSymlink))
            throw new DrnException("Target plugin dir ".Format::UTF8Quote($pluginSymlink)." exists already, have you installed this before?");
        // This is a private .gitignore file, to which we'll add the repo symlink so it doesn't show up in git status all the time.
        $gitInfoExclude = '.git/info/exclude';
        if (!(file_exists($gitInfoExclude)))
            throw new DrnException("Cannot find $gitInfoExclude, please call this from the Doreen git repo root");

        $pluginRepoDir = FileHelpers::RealPath(DOREEN_ROOT."/../doreen-$pluginName");

        if (file_exists($pluginRepoDir))
        {
            // Before allowing to use this for this install, make sure it's a git repo and it has PHP files. Very basic checks.
            if (!file_exists("$pluginRepoDir/.git"))
                throw new DrnException("Repo directory ".Format::UTF8Quote($pluginRepoDir)." exists but has no .git subdirectory, cannot handle this");
            if (!FileHelpers::GlobRE($pluginRepoDir, '/.*\.php$/'))
                throw new DrnException("Repo directory ".Format::UTF8Quote($pluginRepoDir)." exists but has no *.php files, cannot handle this");
            if (!file_exists("$pluginRepoDir/.git"))
                throw new DrnException("Repo directory ".Format::UTF8Quote($pluginRepoDir)." exists but has no .git subdirectory, cannot handle this");

            if (!$fForce)
                throw new DrnException("Repo directory ".Format::UTF8Quote($pluginRepoDir)." exists already, use --force to symlink to it from this repo");
        }
        else
        {
            $prettyPluginDir = Format::UTF8Quote($pluginRepoDir);
            Globals::EchoIfCli("Installing plugin from ".Format::UTF8Quote($gitRepoUrl)." into $prettyPluginDir");

            FileHelpers::MakeDirectory($pluginRepoDir);
            Process::SpawnOrThrow("git clone ".Process::EscapeBash($gitRepoUrl).' '.Process::EscapeBash($pluginRepoDir), TRUE);
        }

        FileHelpers::CreateSymlink($pluginRepoDir, $pluginSymlink);

        $bashPluginSymlink = Process::EscapeBash('src/plugins/'.$pluginName);
        Process::SpawnOrThrow("if ! grep $bashPluginSymlink $gitInfoExclude ; then echo $bashPluginSymlink >> $gitInfoExclude; fi", TRUE);
    }

    /**
     *  Implementation for the plugin-list CLI command.
     *
     * @return void
     */
    public static function CliPluginsList()
    {
        $aPlugins = self::GetAllPlugins();
        foreach ($aPlugins as $name => $a2)
        {
            $fEnabled = $a2['enabled'] ?? FALSE;
            Globals::EchoIfCli(($fEnabled ? '[X] ' : '    ')."$name: ".$a2['descr']);
        }
    }

    /**
     *  Creates the Doreen database and its database user and writes the install vars file.
     *  This is used from both the GUI install and the "reinstall" CLI command.
     */
    public static function CreateDatabase(string $dbtype,               //<! in: mysql or postgres
                                          string $dbhost,
                                          string $dbadminpwd,
                                          string $dbname,
                                          string $dbuser,
                                          string $dbpassword)
    {
        $dbplugin = "db_$dbtype";
        if (!(Plugins::A_DB_PLUGINS[$dbplugin]))
            myDie(toHTML($dbtype, 'code')." is not a valid database plugin");
        # Load and initialize the database plugin.
        require_once INCLUDE_PATH_PREFIX."/plugins/$dbplugin.php";
        Database::SetDefault(Plugins::InitDatabase($dbplugin));

        # Preparations: Make sure the admin password works AND we can write to the params
        # file before making any destructive changes.
        Database::GetDefault()->connectAdmin($dbhost, $dbadminpwd);
        if (!($fh = @fopen(Globals::$fnameInstallVars, 'w')))
            myDie("failed to open ".toHTML(Globals::$fnameInstallVars, 'code')." for writing");
        fwrite($fh, <<<EOD
<?php
    // This is how Doreen connects to your database:
    define("DBTYPE", "$dbtype");            // Either 'postgres' or 'mysql', depending on what you use.
    define("DBHOST", "$dbhost");            // The host you want to connect to.
    define("DBNAME", "$dbname");            // The database name.
    define("DBUSER", "$dbuser");            // The database username.
    define("DBPASSWORD", "$dbpassword");    // The password for that database user.

EOD
        );
        fclose($fh);

        # Create the encryption key file.
        DrnCrypto::CreateKeyFile();

        # create the new database and the user
        Database::GetDefault()->createUserAndDB($dbname, $dbuser, $dbpassword);

        # Disconnect admin again to make sure we don't accidentally write into the main postgres DB.
        Database::GetDefault()->disconnect();
    }

    /**
     *  Returns all install pairs added via AddInstall().
     */
    public static function GetRoutines()
    {
        return Install::$aNeededInstallsPrio1 + Install::$aNeededInstallsPrio2 + Install::$aNeededInstalls;
    }

    /**
     *  Loads all install items for the initial Doreen installation, both for the core and all plugins.
     *  This is used from both the GUI install and the "reinstall" CLI command.
     */
    public static function LoadAllRoutines(HTMLChunk $oHTML = NULL)
    {
        GlobalConfig::AddPrio1Install(
        /**
         *  Global configuration table, which is a simple list of key / value pairs. This is used by the GlobalConfig class.
         */
            'Create configuration table', <<<EOD
CREATE TABLE config (
    key         VARCHAR(40) PRIMARY KEY,
    value       TEXT NOT NULL
)
EOD
        );

        Plugins::TestInstalls();

        GlobalConfig::$databaseVersion = 1;

        if ($oHTML)
            $oHTML->flush();

        require INCLUDE_PATH_PREFIX.'/core/install/install5_update-data.php';

        GlobalConfig::AddInstall('Try to create search index', function()
        {
            if ($oSearch = Plugins::GetSearchInstance())
            {
                // Do not cause this to fail the install.
                try
                {
                    $oSearch->onInstall();
                    # We're doing a fresh install, the database is empty, and this flag
                    # has been set before. We're done so we can unset the flag.
                    GlobalConfig::FlagNeedReindexAll(FALSE);
                }
                catch (\Exception $e)
                {
                }
            }
        });
    }

    /**
     *  Executes all install items in our static class data. These will have been
     *  added by \ref GlobalConfig::AddInstall() and friends.
     *
     *  On execution, Prio1 installs will be executed first, Prio2 installs next,
     *  then regular installs added with \ref AddInstall().
     *
     *  This is used from both the GUI install and the "reinstall" CLI command.
     *
     *  This is ALSO used from the GUI "upgrade" process, for which there is no
     *  CLI equivalent yet.
     */
    public static function ExecuteRoutines()
    {
        $fIsCli = (php_sapi_name() == 'cli');

        /*
         *  GetInstalls() gives us an array of install instructions (key = description, value = SQL command or function).
         *
         *  After the include, caller must echo the Doreen footer and exit.
         */
        if (!$fIsCli)
        {
            echo "<p>Installing tables...</p>";
            echo "\n<table class=\"table table-striped\">\n";
        }

        $cOperations = 0;
        $cErrors = 0;
        foreach (self::GetRoutines() as $descr => $queryOrCallable)
        {
            $htmlError = '';

            if ($fIsCli)
                echo "$descr...";
            else
            {
                echo "\n  <tr><td>$descr</td>";
                ob_flush();
            }
            flush();

            if (is_callable($queryOrCallable))
            {
                try
                {
                    $htmlError = $queryOrCallable();
                }
                catch(\Exception $e)
                {
                    $htmlError = toHTML($e->getMessage());
                }
            }
            else
            {
                # query string
                $res = Database::GetDefault()->tryExec($queryOrCallable);
                if ($err = Database::GetDefault()->isError($res))
                {
                    error_log("Database error in command \"$queryOrCallable\": $err");
                    $htmlError = "The database reported an error: ".toHTML($err);
                }
            }

            if ($htmlError)
            {
                if ($fIsCli)
                    echo $htmlError;
                else
                    echo "<td class=\"danger\">$htmlError</td></tr>\n";
                ++$cErrors;
            }
            else
                if ($fIsCli)
                    echo " OK\n";
                else
                    echo "<td class=\"success\">OK</td></tr>\n";

            if (!$fIsCli)
                ob_flush();
            flush();

            ++$cOperations;

            if ($htmlError)
                break;
        }

        if (!$fIsCli)
        {
            echo "\n</table>\n";

            echo <<<EOD

        <div class="alert alert-info" role="alert">
        <p>All finished, $cOperations operations done, $cErrors errors.</p>
        </div>

EOD;

            if (!$cErrors)
            {
                    echo L(<<<EOD
        <form>
          <button type="submit" class="btn btn-primary">{{L//Proceed}}</button>
        </form>
EOD
                      );
            }
        }
    }

    /**
     *  Last step of the installation: create the admin user and write the database version.
     *  This is used from both the GUI install and the "reinstall" CLI command.
     */
    public static function CreateAdminAndFinish($login,
                                                $password,
                                                $longname,
                                                $email)
    {
        # Create the first user, which will have the magic UID 1.
        $oUser = User::Create($login,
                              $password,
                              $longname,
                              $email,
                              User::FLUSER_TICKETMAIL);

        # Make the admin a member of all the default groups.
        $aGroups = Group::GetAll();
        $oUser->addToGroup($aGroups[Group::ADMINS]);
        $oUser->addToGroup($aGroups[Group::GURUS]);
        $oUser->addToGroup($aGroups[Group::EDITORS]);

        # Finally, write a line with the database version into the "config" table. This is our
        # marker which causes us not to branch into this install file again.
        GlobalConfig::Set('database-version', DATABASE_VERSION);
        GlobalConfig::Save();
    }

    /**
     *  Implementation for the 'reinstall' CLI command.
     *
     *  This also gets called from the 'reset' CLI command with --reinstall, in which case
     *  $dbpass is already set.
     */
    public static function Reinstall($dbpass = NULL)
    {
        if (!$dbpass)       # don't complain with --reinstall
            if (defined('DBNAME'))
                throw new DrnException("constant DBNAME is defined, which probably means you shouldn't run reinstall.");

        foreach ( [ 'PREFILL_DOREEN_DB_NAME',
                    'PREFILL_ADMIN_LOGIN',
                    'PREFILL_ADMIN_PWD',
                    'PREFILL_ADMIN_EMAIL',
                    'PREFILL_ADMIN_EMAIL'
                  ] as $var)
            if (!defined($var))
                myDie("cannot reinstall without $var being defined in doreen-optional-vars.inc.php.");

        if (!$dbpass)
            $dbpass = FileHelpers::PromptPassword("PostgreSQL administrator password: ");

        $dbname = constant('PREFILL_DOREEN_DB_NAME');
        require_once INCLUDE_PATH_PREFIX.'/3rdparty/class_pwdgen.inc.php';
        $userpass = \PasswordGenerator::getAlphaNumericPassword(20);
        self::CreateDatabase($dbtype = 'postgres',
                             $dbhost = 'localhost',
                             $dbpass,
                             $dbname,
                             $dbname,
                             $userpass);

        # Plugins remain defined in doreen-optional-vars, no need to change.
        define('DBTYPE', $dbtype);
        define('DBNAME', $dbname);
        define('DBHOST', $dbhost);
        define('DBUSER', $dbname);
        define('DBPASSWORD', $dbpass);
        Globals::InitDatabase();

        # Set localhost as the hostname or else JWT crashes.
        self::$tempHostname = 'localhost/doreen';

        Install::LoadAllRoutines(NULL);
        Install::ExecuteRoutines();

        if (!($longname = constant('PREFILL_ADMIN_LONGNAME')))
            $longname = constant('PREFILL_ADMIN_LOGIN');

        Install::CreateAdminAndFinish(constant('PREFILL_ADMIN_LOGIN'),
                                      constant('PREFILL_ADMIN_PWD'),
                                      $longname,      # use for longname
                                      constant('PREFILL_ADMIN_EMAIL'));

        if (defined('AUTO_INSTALL_TITLEPAGE_ITEMS'))
            if ($items = constant('AUTO_INSTALL_TITLEPAGE_ITEMS'))
                Blurb::SetAll(explode(',', $items));
    }
}
