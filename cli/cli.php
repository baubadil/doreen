<?php

namespace Doreen;

/*
 *  Doreen has three entry points: htdocs/index.php, htdocs/api/api.php,
 *  cli/cli.php.
 *
 *  This is cli/cli.php, the Doreen command line.
 */


/* The below code is necessary because __FILE__ (and apparently __DIR__
   as well) automatically run realpath() internally, which messes up
   our logic if secondary repositories symlink into the doreen-share repo.
   So please don't touch the below magic even if it looks terrible. */
$g_scriptfile = $_SERVER['SCRIPT_FILENAME'];
if ($g_scriptfile[0] !== '/')
    $g_scriptfile = getcwd().'/'.$g_scriptfile;
$g_scriptdir = dirname($g_scriptfile);
define('DOREEN_ROOT', $g_scriptdir.'/..');
define('TO_HTDOCS_ROOT', $g_scriptdir.'/../htdocs');
define('INCLUDE_PATH_PREFIX', DOREEN_ROOT.'/src'); # realpath(DOREEN_ROOT.'/src'));
define('IS_API', FALSE);

$g_aPlugins = [];

require INCLUDE_PATH_PREFIX.'/core/class_globals.php';


/********************************************************************
 *
 *  Global constants and variables
 *
 ********************************************************************/

$g_mode = $g_idSession = NULL;

$g_fExecute = FALSE;
$g_adminLogin = NULL;
$g_importMode = NULL;
$g_aOtherArgs = [];
$g_resetPassword = NULL;
$g_throttle = 2;
$g_idTicketCli = NULL;
$g_optForce = FALSE;
$g_installDir = NULL;
$g_lang = NULL;
$g_fReinstall = FALSE;


/********************************************************************
 *
 *  Helpers
 *
 ********************************************************************/

/**
 *  Do not use die() in the CLI because it exits with a code of 0, signalling no error.
 */
function cliDie($msg,
                $code = 1)
{
    Globals::EndWithDot($msg);
    fwrite(STDERR, "$msg\n");
    exit($code);
}

function fetchArg(&$argv, &$i)
{
    if (!isset($argv[++$i]))
        cliDie("Error: missing command line argument.");

    return $argv[$i];
}

/**
 *  Fetches next arg and consumes it. Helper for plugins.
 */
function cliFetchNextArg(&$aArgv,
                         &$i)
{
    if ($v = getArrayItem($aArgv, ++$i))
        unset($aArgv[$i]);

    return $v;
}

/*
 *  Sets the installation directory. The given dir MUST exist.
 */
function processInstallDir(string $dir)
{
    global  $g_installDir;

    $g_installDir = $dir;
    Globals::InitInstallDir($g_installDir);
}

/********************************************************************
 *
 *  Entry point
 *
 ********************************************************************/

/*
 *  Note that this still has a lot of legacy switch/case statements below.
 *  Please don't add any here; newer code is in class_clicore.php, and
 *  sooner or later all of the below will move there to take advantage
 *  of the newer command line parser.
 */

try
{
    $fDebug = FALSE;
    $g_installDir = NULL;
    $healthMail = NULL;

    for ($i = 1; $i < $argc; ++$i)
    {
        $arg = $argv[$i];
        switch ($arg)
        {
            case 'reindex-all':
            case 'import-xt':
            case 'reset':
            case 'reinstall':
            case 'delete-all-tickets':
            case 'mail-daemon':
            case 'init-crypt':
            case 'autostart-services':
            case 'autostart-systemd':
            case 'help':
            case 'flush-caches':
            case 'run-tests':
            case 'test-and-die':
            case 'install-switch':      // implemented in cli core, but needed in g_mode below
                $g_mode = $arg;
            break;

            case 'reindex-one':
                $g_mode = $arg;
                $g_idTicketCli = fetchArg($argv, $i);
            break;

            case 'reset-password':
                $g_mode = $arg;
                $g_resetPassword = fetchArg($argv, $i);
            break;

            case '--install-dir':
                $dir = fetchArg($argv, $i);
                if (!is_dir($dir))
                    cliDie("Error: the given --install-dir \"$dir\" is not a directory.");
                processInstallDir($dir);
            break;

            case '--debug':
                $fDebug = TRUE;
            break;

            case '--session-id':
                $g_idSession = fetchArg($argv, $i);
            break;

            case '--admin-login':
                $g_adminLogin = fetchArg($argv, $i);
            break;

            case '--throttle':
                $g_throttle = fetchArg($argv, $i);
                if (!(preg_match('/^[0-9]+$/', $g_throttle)))
                    cliDie("Error: argument to --throttle must be an integer");
            break;

            case '--execute':
            case '-x':
                $g_fExecute = TRUE;
            break;

            case '--force':
                $g_optForce = TRUE;
            break;

            case '--lang':
                $g_lang = fetchArg($argv, $i);
            break;

            case '--reinstall':
                $g_fReinstall = TRUE;
            break;

            default:
                $g_aOtherArgs[] = $arg;
            break;
        }
    }

    $fWarn = FALSE;
    if (!$g_installDir)
        if (is_dir('/var/www/localhost'))
        {
            processInstallDir('/var/www/localhost');
            $fWarn = TRUE;
        }

    if ($g_mode != "reinstall")
    {
        if (!file_exists(Globals::$fnameInstallVars))
            cliDie("Error: the file \"".Globals::$fnameInstallVars."\" does not exist.\nHave you run the install in the browser yet?.");
        if (!is_readable(Globals::$fnameInstallVars))
            cliDie("Error: the file \"".Globals::$fnameInstallVars."\" exists, but is not readable.");

        require Globals::$fnameInstallVars;
    }

    if ((!Globals::$fnameInstallVars) && ($g_mode != 'help'))
        cliDie("Missing argument --install-dir <dir>.");

    # Check for pcntl extension. This is only available in the CLi so we must check here.
    if (!extension_loaded('pcntl'))
        cliDie('The pcntl PHP extension is required for the CLI to work but missing. Please install the extension or recompile your PHP.');

    // Prologue includes the optional include file for the plugins, so include this only after we know its filename.
    require INCLUDE_PATH_PREFIX.'/core/prologue.php';

    if ($fWarn)
        echo "WARNING: using /var/www/localhost as the install dir, use --install-dir option to override\n";

    Debug::$fLog = $fDebug;

    if ($g_mode == "reinstall")
    {
        Process::AssertRunningAsRoot();
        Install::Reinstall();
        exit(0);
    }

    require INCLUDE_PATH_PREFIX.'/core/class_config.php';

    JWT::Init();

    if (!empty($g_lang))
        DrnLocale::Set($g_lang, FALSE);

    if ($g_mode == 'help')
    {
        echo "Loaded plugins: ".implode(', ', $g_aPlugins)."\n";

        CliCore::AddHelp($aHelpItems);

        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_COMMANDLINE) as $oImpl)
        {
            /** @var ICommandLineHandler $oImpl */
            $oImpl->addHelp($aHelpItems);
        }

        echo "Doreen (C) 2015--2016 Baubadil GmbH. All rights reserved.\nAvailable modes:\n";
        ksort($aHelpItems);
        foreach ($aHelpItems as $key => $help)
        {
            if (strpos($help, ':') === FALSE)
                $help = ": $help";
            else if ($help[0] != ':')
                $help = " $help";
            echo "  $key$help\n";
        }
        if (!Globals::$fnameInstallVars)
            echo "Additional commands may be available with plugins, but those are only loaded with --install-vars specified.\n";
        exit(0);
    }

    if (    ($g_mode != 'reset')
         && ($g_mode != 'install-switch')
       )
    {
        Globals::InitDatabase();
        GlobalConfig::Init();
    }

    if ($g_idSession)
        LongTask::PickUpSession($g_idSession);
                # this throws on invalid session IDs

    if ($g_adminLogin)
        if (!($GLOBALS['g_ouserCurrent'] = User::FindByLogin($g_adminLogin)))
            throw new \Exception("Cannot find user $g_adminLogin");

    $fCore = FALSE;
    $g_opluginCLI = NULL;
    if ($g_aOtherArgs)
    {
        if ($g_mode == 'install-switch')
            CliCore::SetMode($g_mode, 1);

        if (CliCore::ParseArguments($g_aOtherArgs))
            $fCore = TRUE;
        else
            foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_COMMANDLINE) as $oImpl)
            {
                /** @var ICommandLineHandler $oImpl */
                if ($oImpl->parseArguments($g_aOtherArgs))
                {
                    $g_mode = 'plugin';
                    $g_opluginCLI = $oImpl;
                    break;
                }
            }
    }

    if (count($g_aOtherArgs))
    {
        cliDie("Error: don't know what to do with command line argument(s) ".Format::UTF8QuoteImplode(', ', $g_aOtherArgs).".");
    }

    if ($fCore)
        CliCore::ProcessCommands($g_installDir);
    else if (!$g_mode)
        cliDie("Error: missing mode argument on command line. Use ".Format::UTF8Quote('help')." to list available modes.");

    # For JSON progress reports.
    if ( ($g_mode) && (!isset($GLOBALS['cliCommand'])) )
        $GLOBALS['cliCommand'] = $g_mode;

    switch ($g_mode)
    {
        case 'reindex-all':
            SearchEngineBase::ReindexAllCli($g_throttle, $g_mode);
        break;

        case 'reindex-one':
            if (!isPositiveInteger($g_idTicketCli))
                throw new DrnException("missing ticket ID");
            SearchEngineBase::ReindexOneCli($g_throttle, $g_mode, $g_idTicketCli);
        break;

        case 'reset':
            # dropdb -U postgres doreen; dropuser -U postgres doreen; rm /var/www/localhost/doreen-install-vars.inc.php
            Globals::InitDatabase();
            if (Database::$defaultDBType != "postgres")
                throw new DrnException("Error: 'reset' command only supports postgres.");

            if ($g_fExecute)
            {
                Process::AssertRunningAsRoot();
                $dbpass = FileHelpers::PromptPassword("PostgreSQL administrator password: ");
                $db = Database::GetDefault();

                $db->connectAdmin(Database::$defaultDBHost, $dbpass);
                echo "Dropping database ".Database::$defaultDBName."\n";
                echo "Dropping database user ".Database::$defaultDBUser."\n";
                $db->delete(Database::$defaultDBName, Database::$defaultDBUser);
                echo "Removing file ".Globals::$fnameInstallVars."\n";
                if (!(@unlink(Globals::$fnameInstallVars)))
                    echo "Error: failed to unlink ".Globals::$fnameInstallVars." file\n";
                if ($oSearch = Plugins::GetSearchInstance())
                {
                    if ($oSearch->getStatus() == SearchEngineBase::RUNNING)
                    {
                        echo "Deleting search index\n";
                        $oSearch->deleteAll();
                    }
                    else
                        echo "WARNING: search engine not running, please delete search index manually\n";
                }

                if ($g_fReinstall)
                    Install::Reinstall($dbpass);
            }
            else
            {
                echo "Would drop database ".Database::$defaultDBName."\n";
                echo "Would drop database user ".Database::$defaultDBUser."\n";
                echo "Would remove file ".Globals::$fnameInstallVars."\n";
                if ($oSearch = Plugins::GetSearchInstance())
                    echo "Would delete search index\n";
                echo "No harm done; re-run with --execute (-x) to actually delete everything and re-trigger the install routines.\n";
            }
        break;

        case 'delete-all-tickets':
            $cTickets = Ticket::CountAll();
            echo "There are ".Format::Number($cTickets)." tickets in the database.\n";
            if (!$g_fExecute)
                echo "To really delete them all, re-run $g_mode with the --execute (-x) option.\n";
            else
            {
                Ticket::Nuke(function($cCurrent, $cTotal) use($g_mode)
                {
                    echo "Progress: $cCurrent, $cTotal\n";
                    $fDone = FALSE;
                    if ($cCurrent >= $cTotal)
                        $fDone = TRUE;
                    LongTask::ReportJSON(200,
                                         NULL,
                                         [ 'command' => $g_mode,
                                           'cCurrent' => $cCurrent,
                                           'cTotal' => $cTotal
                                         ],
                                         $fDone);
                });
            }
        break;

        case 'mail-daemon':
            Email::MailDaemon();
        break;

        case 'init-crypt':
            if (file_exists(Globals::$fnameEncryptionKey))
                cliDie("Error: encryption key file ".Globals::$fnameEncryptionKey." exists already, refusing to overwrite since this may disable an existing installation. Delete the file manually and re-run this command!");
            DrnCrypto::CreateKeyFile();
        break;

        // Special internal mode set above when a plugin has said it supports a mode command.
        case 'plugin':
            $g_opluginCLI->processCommands($g_idSession);
        break;

        case 'reset-password':
            if (!($oUser = User::FindByLogin($g_resetPassword)))
                cliDie("Error: there is no user account for \"$g_resetPassword\" in this Doreen installation.");
            echo "Resetting password for Doreen user \"$g_resetPassword\"...\n";
            $pass        = FileHelpers::PromptPassword("Please enter the new password (will not be echoed):    ");
            $passConfirm = FileHelpers::PromptPassword("Please enter the same password again for confirmation: ");
            if ($pass != $passConfirm)
                cliDie("Sorry, passwords do not match.");
            $oUser->update($pass);      # this validates the password and throws on errors
            echo "OK, the password was changed.\n";
        break;

        case 'autostart-services':
            ServiceBase::AutostartAll($g_fExecute);
        break;

        case 'autostart-systemd':
            ServiceBase::AutostartConfigureSystemd($g_fExecute);
        break;

        case 'flush-caches':
            Process::AssertRunningAsRoot();

            echo "Flushing file system buffers... ";
            Process::SpawnOrThrow("sync");
            echo "OK\n";

            echo "Clearing all file system caches... ";
            Process::SpawnOrThrow("echo 3 > /proc/sys/vm/drop_caches");
            echo "OK\n";

            echo "Restarting database... ";
            Database::GetDefault()->restartServer();
            echo "OK\n";
        break;

        case 'run-tests':
            TestCase::FindAndRun();
        break;

        case 'test-and-die':
        break;
    }
}
catch(APIException $e)
{
    LongTask::ReportJSON(400, $e->dlgfield, $e->getMessage());
    echo "Error: ".$e."\n";
    exit(2);
}
catch(\Exception $e)
{
    LongTask::ReportJSON(400, NULL, $e->getMessage());
    echo "Error: ".$e."\n";
    exit(2);
}
