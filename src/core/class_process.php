<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Process class
 *
 ********************************************************************/

/**
 *  Simple wrapper around some operating system process helpers.
 */
class Process
{
    /**
     *  Executes the given command and captures its output. Returns the error code
     *  of the given command, which should be 0 on success; typically the shell returns
     *  127 if the command could not be found.
     */
    public static function Spawn(string $cmd,
                                 bool $fBackground = FALSE)
        : int
    {
        $rc = 0;
        if ($fBackground)
            $cmd .= " > /dev/null &";

        exec($cmd,
             $aOutput,
             $rc);
        Debug::Log(Debug::FL_JOBS, __FUNCTION__."($cmd): rc=$rc");
        return $rc;
    }

    /**
     *  Like Spawn(), but this doesn't return the int code, but instead throws if it is != 0.
     *
     * @return void
     */
    public static function SpawnOrThrow(string $cmd,
                                        bool $fPrint = FALSE)
    {
        if ($fPrint)
            Globals::EchoIfCli("Spawning ".Format::UTF8Quote($cmd)."...");
        if ($rc = self::Spawn($cmd))
            throw new DrnException("Failed to launch command \"$cmd\": received error code $rc");
    }

    /**
     *  Tries to escape characters for the shell by prefixing them with backslashes.
     */
    public static function EscapeBash(string $str)
        : string
    {
        return preg_replace('/([;<>\*\|`&\$!#\(\)\[\]\{\}\'"% ])/', '\\\\$1', $str);
    }

    /**
     *  Launches "kill $pid, $signal" because posix_kill is typically not defined when
     *  PHP runs from within Apache.
     *
     *  Note that this will ordinarily fail when called from within Apache unless the
     *  target process runs under the same user account.
     */
    public static function KillPID($pid)
    {
        if (!isInteger($pid))
            throw new DrnException("Invalid pid \"$pid\"");

        $cmd = "kill $pid";
        $rc = exec($cmd);
        Debug::Log(Debug::FL_JOBS, "Executed command \"$cmd\", rc: \"$rc\" (user and group: \"".Process::GetProcessUserAndGroupString()."\"");
        if ($rc)
            throw new DrnException("Failed to send kill signal to pid $pid");
    }

    private static $systemUser = NULL;
    private static $systemGroup = NULL;

    /*
     *  Returns a list of the user and group under which PHP runs. For example, if PHP is running
     *  as mod_php under Apache on Debian, this will return 'www-data' and 'www-data'.
     *
     *  This creates a temp file so it comes at a price but it doesn't rely on Apache running.
     *  The result is cached.
     *
     *  Courtesy of http://stackoverflow.com/questions/7771586/how-to-check-what-user-php-is-running-as.
     */
    public static function GetProcessUserAndGroup()
    {
        # This is expensive so cache it.
        if (self::$systemUser === NULL)
        {
            $fname = tempnam(sys_get_temp_dir(), 'testtest');
            file_put_contents($fname, "test");
            self::$systemUser = posix_getpwuid(fileowner($fname))['name'];
            self::$systemGroup = posix_getgrgid(filegroup($fname))['name'];
            unlink($fname);
        }
        return [ self::$systemUser, self::$systemGroup ];
    }

    /**
     *  Greps /etc/password for the given user.
     */
    public static function DoesSystemUserExist($user)
    {
        if (strlen(exec("grep \"^$user:\" /etc/passwd")) > 0)
            return TRUE;
        return FALSE;
    }

    /**
     *  Returns self::GetProcessUserAndGroup() as a "user:group" string.
     */
    public static function GetProcessUserAndGroupString()
    {
        list($sysuser, $sysgroup) = Process::GetProcessUserAndGroup();
        return "$sysuser:$sysgroup";
    }

    private static $apacheSystemUser = NULL;

    /**
     *  Returns the system user account under which Apache runs, typically 'apache' or
     *  (on Debian) 'www-data'.
     */
    public static function GetApacheSystemUserOrThrow()
    {
        if (!self::$apacheSystemUser)
        {
            foreach ( [ 'www-data', 'apache' ] as $user)
                if (Process::DoesSystemUserExist($user))
                    self::$apacheSystemUser = $user;
        }

        if (!self::$apacheSystemUser)
            throw new DrnException("Error: cannot detect system user account under which apache runs (is apache installed?)");

        return self::$apacheSystemUser;
    }

    /**
     *  Returns TRUE if the current process is running under the apache system user
     *  account (see GetApacheSystemUserOrThrow()).
     *  This works both from within apache and on the CLI; sometimes the CLI must
     *  run under that account to be able to read files such as attachments.
     */
    public static function IsRunningAsApacheSystemUser()
    {
        $apacheUser = self::GetApacheSystemUserOrThrow();
        /** @noinspection PhpUnusedLocalVariableInspection */
        list($sysUser, $sysGroup) = Process::GetProcessUserAndGroup();
        if ($apacheUser == $sysUser)
            return TRUE;

        return FALSE;
    }

    public static function AssertRunningAsApacheSystemUser()
    {
        if (!Process::IsRunningAsApacheSystemUser())
        {
            $apacheUser = Process::GetApacheSystemUserOrThrow();
            cliDie("Error: you must run this command as 'sudo -u $apacheUser' or else we can't access attachments.");
        }
    }

    /**
     *  Returns TRUE if the current process is running under the 'root' system user account.
     */
    public static function IsRunningAsRoot()
    {
        list($sysUser, $sysGroup) = Process::GetProcessUserAndGroup();
        return ($sysUser == 'root');
    }

    public static function AssertRunningAsRoot($strReason = '')
    {
        if (!self::IsRunningAsRoot())
            cliDie("Error: you must run this command as root$strReason.");
    }

}
