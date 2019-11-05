<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  DrnService class
 *
 ********************************************************************/

/**
 *  An array of these is to be returned by ICommandLineHandler::reportServices().
 */
abstract class ServiceBase
{
    public $pluginName;             # or NULL if core
    public $serviceAPIID;
    public $nlsDescription;

    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($serviceAPIID,
                                $pluginName,
                                $nlsDescription)
    {
        $this->serviceAPIID = $serviceAPIID;
        $this->pluginName = $pluginName;
        $this->nlsDescription = $nlsDescription;
    }


    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Queries all plugins with CAPSFL_SERVICE to get a list of all services
     *  implemented by all plugins.
     *
     * @return ServiceBase[]
     */
    public static function GetAll()
    {
        $llAllServices = [];
        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_SERVICE) as $oImpl)
        {
            /** @var IServicePlugin $oImpl */
            if ($aServices = $oImpl->reportServices())
                foreach ($aServices as $oService)
                    $llAllServices[] = $oService;
        }

        $llAllServices[] = new EmailService();

        return $llAllServices;
    }

    /**
     *  Returns the service with the given API ID, or NULL if not found.
     */
    public static function Find($serviceAPIID)
    {
        foreach (self::GetAll() as $oService)
            if ($oService->serviceAPIID == $serviceAPIID)
                return $oService;

        return NULL;
    }

    /**
     *  Implementation of the CLI "autostart-services" command.
     */
    public static function AutostartAll($fExecute)
    {
        Process::AssertRunningAsApacheSystemUser();

        if ($fExecute)
        {
            Globals::EchoIfCli("Sleeping five seconds...");
            sleep(5);
        }

        $cCouldStart = $cStarted = 0;
        foreach (ServiceBase::GetAll() as $oService)
        {
            if ($oService->canAutostart())
            {
                $descr = "Service {$oService->serviceAPIID} ($oService->nlsDescription)";
                if ($oService->isRunning())
                    Globals::EchoIfCli("$descr: already running");
                else if (!$oService->hasAutostart())
                    Globals::EchoIfCli("$descr: autostart is disabled");
                else if (!$fExecute)
                {
                    ++$cCouldStart;
                    Globals::EchoIfCli("$descr: would start ".Format::UTF8Quote($oService->describeCommandLine()));
                }
                else
                {
                    Globals::EchoIfCli("$descr: STARTING ".Format::UTF8Quote($oService->describeCommandLine()).Format::HELLIP);
                    $oService->start();
                    Globals::EchoIfCli("$descr: started.");
                    ++$cStarted;
                }
            }
        }
        if ($cCouldStart)
            Globals::EchoIfCli("Re-run with --execute (-x) to launch $cCouldStart missing service(s).");
        else if ($cStarted)
            Globals::EchoIfCli("$cStarted service(s) were started.");
    }

    /**
     *  Implementation of the CLI "autostart-systemd" command.
     */
    public static function AutostartConfigureSystemd($fExecute)
    {
        Process::AssertRunningAsRoot();

        $runAs = Process::GetApacheSystemUserOrThrow();
        Globals::EchoIfCli("Will use system user account \"$runAs\" as user for systemd unit files");

        $etcSystemdSystem = "/etc/systemd/system";

        # Doreen autostart unit file
        $absPathCliPhp = FileHelpers::RealPath(TO_HTDOCS_ROOT.'/../cli/cli.php');
        $wd = FileHelpers::RealPath(TO_HTDOCS_ROOT.'/..');
        $unitFileDoreen = "$etcSystemdSystem/doreen.service";
        $installDir = Globals::GetInstallDir();

        /* IMPORTANT: without Type=forking, systemd kills our forked processes without warning or notice.
           This must have changed some time in 2016 because it used to work without. */
        $contentsDoreen = <<<EOD
[Unit]
Description=doreen-autostart
After=apache2.service postgresql.service doreen-elasticsearch.service

[Service]
User=$runAs
ExecStart=/usr/bin/php $absPathCliPhp --install-dir $installDir autostart-services -x
WorkingDirectory=$wd
Type=forking

[Install]
WantedBy=multi-user.target
EOD;

        $aEntries = [ $unitFileDoreen => $contentsDoreen ];

        if ($oSearch = Plugins::GetSearchInstance())
            $oSearch->configureSystemD($etcSystemdSystem, $aEntries);

        foreach ($aEntries as $unitFile => $contents)
        {
            if ($fExecute)
            {
                Process::AssertRunningAsRoot();

                file_put_contents($unitFile, $contents);
                echo "Wrote file $unitFile\n";

                $unitOnly = basename($unitFile);

                echo "Run \"systemctl enable $unitOnly\" to start the service at boot time.\n";
                echo "Run \"systemctl status $unitOnly\" for service details.\n";
            }
            else
            {
                echo "Would have written unit file $unitFile with the following contents:\n\n$contents\n\n";
            }
        }

        if (!$fExecute)
            echo "Re-run with -x (as root) to have Doreen create these files for you.\n";
    }

    /**
     *  Implements a global lock on the services facility across all PHP processes of
     *  this Doreen instance.
     *
     *  This is implemented by an SQL transaction and a hard table lock on the emailq
     *  table. (Could be any table, so long as everyone is using this function.)
     *
     *  As a result, it is not a system-wide lock, but only global to the Doreen
     *  instance operating on this database (in case multiple Doreen instances are
     *  running on the same server).
     *
     *  Must be followed by Unlock(), but since this is really a database operation,
     *  the lock will be released automatically if the PHP process ends.
     */
    public static function Lock()
    {
        Debug::Log(Debug::FL_JOBS, "Locking...");
        Database::GetDefault()->beginTransaction();
        Database::GetDefault()->lockTable('emailq');
        Debug::Log(Debug::FL_JOBS, "Locked!");
    }

    /**
     *  Releases the lock acquired by Unlock().
     */
    public static function Unlock()
    {
        Database::GetDefault()->commit();
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    public function getPluginName()
    {
        return $this->pluginName;
    }

    /**
     *  Must return TRUE if the service is running.
     */
    abstract public function isRunning();

    /**
     *  Must return TRUE if the service should be configurable to be auto-started.
     */
    abstract public function canAutostart();

    /**
     *  Should return the command line used in \ref start(), but only for description
     *  purposes.
     *
     * @return string
     */
    abstract public function describeCommandLine();

    /**
     *  Must launch the service.
     */
    abstract public function start();

    /**
     *  This can return an array of HTML -> URL pairs to be shown in global settings.
     */
    public function getActionLinks()
    {
        return NULL;
    }

    /**
     *  Returns TRUE if the service has the autostart flag set.
     */
    public function hasAutostart()
    {
        $a = self::GetAutostarts();
        if (isset($a[$this->serviceAPIID]))
            return TRUE;

        return FALSE;
    }

    /**
     *  Adds or removes $this from the list of services to be autostarted.
     */
    public function setAutostart($f)
    {
        $a = self::GetAutostarts();
        if ($f)
            $a[$this->serviceAPIID] = 1;
        else
            unset($a[$this->serviceAPIID]);

        GlobalConfig::Set(GlobalConfig::KEY_AUTOSTART_SERVICES, implode(',', array_keys($a)));
        GlobalConfig::Save();
    }


    /********************************************************************
     *
     *  Private helpers
     *
     ********************************************************************/

    /**
     *  Returns an array where the keys are all the services that have been configured
     *  to auto-start. Returns an empty array if there are none.
     */
    private static function GetAutostarts()
    {
        $a = [];

        if ($str = GlobalConfig::Get(GlobalConfig::KEY_AUTOSTART_SERVICES))
            foreach (explode(',', $str) as $service)
                $a[$service] = 1;

        return $a;
    }
}

/**
 *  DrnServiceBase subclass that is based on LongTask implementation.
 */
class ServiceLongtask extends ServiceBase
{
    public $longtaskDescription;
    public $cliStartArg;

    public function __construct($serviceAPIID,
                                $longtaskDescription,
                                $cliStartArg,
                                $pluginName,
                                $nlsDescription)
    {
        parent::__construct($serviceAPIID, $pluginName, $nlsDescription);
        $this->longtaskDescription = $longtaskDescription;
        $this->cliStartArg = $cliStartArg;
    }

    /**
     *  Checks if the service is running by calling LongTask::FindRunning and
     *  returning the LongTask instance or NULL.
     */
    public function isRunning()
    {
        if ($ll = LongTask::FindRunning( [ $this->longtaskDescription] ))
            return TRUE;

        return FALSE;
    }

    /**
     *  Implementation of the abstract parent. All longtasks can autostart.
     */
    public function canAutostart()
    {
        return TRUE;
    }

    /**
     *  Implemenation of the abstract parent.
     *  Should return the command line used in \ref start(), but only for description purposes.
     *
     * @return string
     */
    public function describeCommandLine()
    {
        $cliPHP = LongTask::MakeCliArgs();
        return "php $cliPHP ".$this->cliStartArg;
    }

    /**
     *  Implementation of the abstract parent. Must launch the service.
     */
    public function start()
    {
        if (!$this->cliStartArg)
            throw new DrnException("Cannot start service without CLI parameters provided");

        $cmd = $this->describeCommandLine();

        // Do NOT use the lock here, which starts a transaction, or else we'll never see
        // the new process started below.
//        ServiceBase::Lock();

        if ($rc = Process::Spawn($cmd,
                                 TRUE))      # background
            throw new DrnException("Process::Spawn returned $rc for command \"$cmd\"");

        # Now wait up to 5 seconds for the longtask to appear in the database.
        $c = 0;
        while ($c < 5)
        {
            Debug::Log(Debug::FL_JOBS, "Waiting 1 second for ".Format::UTF8Quote($this->longtaskDescription).Format::HELLIP);
            sleep(1);
            if ($ll = LongTask::FindRunning( [ $this->longtaskDescription] ))
                return;

            ++$c;
        }

        throw new DrnException("Service {$this->serviceAPIID} failed to start: command \"$cmd\" appears to have failed");
    }
}
