<?php
/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;

/**
 *  The Health class provides a framework for checking server health. Plugins can
 *  integrate into this to ensure that certain conditions are checked periodically.
 */
class Health
{
    const TYPE_INFO = 1;
    const TYPE_WARNING = 2;
    const TYPE_ERROR = 3;
    public $type;
    public $str;

    /** @var Health[] $aItems */
    private static $aItems = [];

    /**
     *  Performs a system health check by first calling CoreCheck() and then iterating
     *  over all plugins asking them to perform their own health checks.
     *
     * @return Health[]
     */
    public static function Check($fForce = FALSE)
    {
        self::CoreCheck($fForce);

        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_HEALTHCHECK) as $oImpl)
        {
            /** @var IHealthCheck $oImpl */
            $oImpl->checkHealth();
        }

        return self::$aItems;
    }

    /**
     *  Implementation for the 'health' CLI command.
     */
    public static function PrintCli()
    {
        if ($str = self::MakeString(FALSE))
            echo $str;
        else
            echo "System is healthy\n";
    }

    /**
     *  Implementation for the 'health-mail' CLI command.
     *
     *  This sends mail to $healthMail if any problems were found, or if $optForce == TRUE.
     */
    public static function SendAsMail($healthMail,
                                      $optForce)
    {
        if ($html = self::MakeString(TRUE, $optForce))
        {
            $hostname = GlobalConfig::Get('hostname');
            $subj = '['.Globals::$doreenName."] Health report for $hostname "
                .Format::MDASH.' '
                .Timestamp::Now()->toLocale(TRUE,
                                            FALSE);

            try
            {
                Email::SendOne(SMTPHost::CreateFromDefines(),
                               [ $healthMail ],
                               NULL,
                               $subj,
                               $html,
                               '');
            }
            catch(DrnMailException $e)
            {
                Globals::EchoIfCli("Mail exception: ".$e."\nDebug trace:\n".implode("\n", $e->aTrace));
            }
        }
    }


    public static function Add($type, $str)
    {
        $o = new self;
        $o->type = $type;
        $o->str = $str;

        self::$aItems[] = $o;
    }

    private static function CheckDiskSpace()
    {
        $datadir = Database::GetDefault()->getDataDirectory();
        $df = disk_free_space($datadir);
        $bytes = Format::Bytes($df, 2, TRUE);
        if ($df < 30 * 1024 * 1024 * 1024)
            self::Add(self::TYPE_WARNING,
                      "Disk with database directory ".Format::UTF8Quote($datadir)." is almost full: only $bytes left!");
        else
            self::Add(self::TYPE_INFO,
                      "Disk with database directory ".Format::UTF8Quote($datadir)." has $bytes free");
    }

    /**
     *  Performs the health check for the Doreen core.
     */
    private static function CoreCheck($fForce = FALSE)
    {
        if ($fForce)
            self::Add(self::TYPE_ERROR, "Forcing dummy error for testing");

        self::CheckDiskSpace();
    }

    private static function MakeString($fHTML,
                                       $fForce = FALSE)
    {
        $aStrings = [];
        $cWarnings = 0;
        $cErrors = 0;
        foreach (self::Check($fForce) as $oItem)
        {
            $str = '';
            switch ($oItem->type)
            {
                case self::TYPE_INFO:
                    $str = "Info:";
                break;
                case self::TYPE_WARNING:
                    $str = "WARNING:";
                    ++$cWarnings;
                break;
                case self::TYPE_ERROR:
                    $str = "!!! ERROR:";
                    ++$cErrors;
                break;
            }

            if ($fHTML)
                $str = "<b>$str</b>";

            $str .= " ".$oItem->str;
            $aStrings[] = $str;
        }

        $str = '';
        if (count($aStrings))
        {
            if ($fHTML)
                $str = "<html><ol><li>".implode("</li>\n<li>", $aStrings)."</li></ol>\n";
            else
                $str = implode("\n", $aStrings)."\n";

            if ($cErrors || $cWarnings)
            {
                if ($cErrors)
                    $str .= "$cErrors error(s)";
                if ($cWarnings)
                {
                    if ($cErrors)
                        $str .= ", ";
                    $str .= "$cWarnings warning(s)";
                }
                $str .= " found!\n";
            }

            if ($fHTML)
                $str .= "</html>\n";
        }

        return $str;
    }
}
