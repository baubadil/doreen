<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  Abstract class to help implementing command-line parsing. A plugin that implements CLI modes can
 *  derive from this class for easier command-line parsing, ensuring that multiple plugins won't
 *  fight with each other when consuming arguments.
 *
 *  The one requirement is that the derived class override GetArgsData() and return an array that describes
 *  the command line arguments and options that the plugin understands.
 *
 *  The plugin still needs to implement the three methods prescribed by the
 *  ICommandLineHandler interface, but it can call helper methods from there:
 *
 *   -- ICommandLineHandler::parseArguments() can call CliBase::ParseArguments(), and it will work magically;
 *
 *   -- ICommandLineHandler::addHelp() can call CliBase::AddHelp(), and it will work magically;
 *
 *   -- ICommandLineHandler::processCommands() still needs to be implemented fully by the plugin,
 *      but it can inspect the CliBase members, which have been filled magically.
 */
abstract class CliBase
{
    public static $mode = NULL;

    protected static $cExtraArgs = 0;
    protected static $cRemaining = 0;
    protected static $llMainArgs = [];
    protected static $aInts = [];
    protected static $aStrings = [];
    protected static $aFlags = [];

    const TYPE_MODE = 1;        // Sets self::$mode, can only be used once.
    const TYPE_INT = 2;         // Expects an integer afterwards, stored in self::$aInts.
    const TYPE_STRING = 3;      // Expects a string afterwards, stored in self::$aStrings.
    const TYPE_BOOL = 4;        // Expects a true or false string afterwards, stored in self::$aStrings.
    const TYPE_FLAG = 5;        // If specified, sets self::$aFlags[<opt>] to 1.

    /**
     *  Required method to be implemented by subclasses; this must return an array of command line argument
     *  definitions, where each key must have another array as a value.
     *
     *  The top-level key must be a mode argument (without '--'), or an option name (with '--').
     *  To avoid conflicts, every plugin should prefix mode and option names with the plugin name.
     *
     *  The sub-array in the value of the key must be a flat list with the following indices:
     *
     *   -- 0: one of TYPE_MODE (for mode arguments), TYPE_INT (for integer --options) or TYPE_STRING (for
     *          string --options);
     *
     *   -- 1: the no. of required extra arguments following the mode or option (0 or higher).
     *
     *   -- 2: a help string to be displayed with the "help" mode or NULL if the mode or option should
     *         remain undocumented.
     *
     * @return array
     */
    protected static function GetArgsData()
    {
        return [];
    }

    public static function AddHelp(&$aHelpItems)
    {
        $a = static::GetArgsData();
        foreach ($a as $cmd => $a2)
        {
            $type = $a2[0];
            if (    ($type == 0)
                 || ($type == self::TYPE_MODE)
               )
                if ($str = getArrayItem($a2, 2))
                    $aHelpItems[$cmd] = $str;
        }
    }

    /**
     *  Public getopt-like function that handles argument parsing in the way that CLI plugins are expected
     *  to do it. This receives the argv array from \ref ICommandLineHandler::parseArguments() and picks out
     *  the ones that are recognized by the plugin.
     *
     *  This calls GetArgsData(), which must be implemented by the CliBase subclass.
     *  Either the first arg is recognized as a CLI mode handled by this plugin, and then all arguments
     *  should be munched by this function, or nothing is modified here, and the global CLI handler will
     *  try the next plugin.
     *
     *  For modes, the required extra arguments are accepted free form, that is, any non-option that follows
     *  the mode argument in the command line is pushed onto self::$llMainArgs. For options, that doesn't
     *  work; integer options are pushed onto self::$aInts[$option] or self::$aStrings[$option].
     *  Error handling is as follows:
     *
     *   -- If arguments are required by a mode of this plugin but not given, this throws an error message here.
     *
     *   -- If arguments are required but of the wrong type, this also throws here.
     *
     *   -- If extraneous arguments are given, they are not munched from the $aArgv array, and the global CLI
     *      error will print an error if other plugins are not munching them either.
     */
    public static function ParseArguments(&$aArgv)
    {
        $aDefs = static::GetArgsData();

        $rcCommandLineHandled = FALSE;

        # Make a constant count because we might remove items from $aArgv here.
        $cArgs = count($aArgv);
        for ($i = 0;
             $i < $cArgs;
             ++$i)
        {
            $fArgHandled = TRUE;
            $iUnset = $i;

            # This works because when items are unset in a PHP array, the integer keys do not change.
            # Try php -r '$a = [ 0 => "a", 1 => "b", 2 => "c" ]; unset($a[1]); print_r($a);'.

            if (!self::HandleOneArg($aDefs, $aArgv, $i))
                $fArgHandled = FALSE;

            if ($fArgHandled)
            {
                $rcCommandLineHandled = TRUE;
                unset($aArgv[$iUnset]);
            }
        }

        if (self::$mode && self::$cRemaining)
            self::dieSyntax("Error: not enough command-line arguments for ".Format::UTF8Quote(self::$mode)." mode");

        return $rcCommandLineHandled;
    }

    public static function SetMode(string $mode,
                                   int $cExtraArgs)
    {
        if (self::$mode)
            throw new DrnException("Too many mode arguments ".self::$mode." and $arg");

        self::$mode = $mode;
        self::$cExtraArgs = self::$cRemaining = $cExtraArgs;
        $GLOBALS['cliCommand'] = $mode;
    }

    /**
     *  Implementation for HandleArguments(). Parses one of the arguments given.
     *
     * @return bool
     */
    protected static function HandleOneArg($aDefs,          //!< in: const argument definitions array from HandleArguments()
                                           &$aArgv,
                                           &$i)
    {
        $arg = $aArgv[$i] ?? NULL;

        if ($a2 = $aDefs[$arg] ?? NULL)
        {
            $type = $a2[0];

            if ($type == self::TYPE_MODE)
            {
                self::SetMode($arg, $a2[1]);
                return TRUE;
            }
            else if ($type == self::TYPE_FLAG)
            {
                self::$aFlags[$arg] = 1;
                return TRUE;
            }
            else if (self::$mode)
            {
                if (    ($type == self::TYPE_INT)
                     || ($type == self::TYPE_STRING)
                   )
                {
                    if ($cExtraArgs = $a2[1])
                    {
                        if (!($nextArg = cliFetchNextArg($aArgv, $i)))
                            die("Error: missing argument after $arg\n");
                        if ($type == self::TYPE_INT)
                        {
                            if (!isInteger($nextArg))
                                throw new DrnException("Value of $arg must be an integer, but \"$nextArg\" is not");
                            self::$aInts[$arg] = $nextArg;
                        }
                        else if ($type == self::TYPE_BOOL)
                        {
                            self::$aStrings[$arg] = parseBoolOrThrow($nextArg);
                        }
                        else
                            self::$aStrings[$arg] = $nextArg;
                    }

                    return TRUE;
                }
            }
        }
        else if (self::$cRemaining)
        {
            self::$llMainArgs[] = $arg;
            --self::$cRemaining;
            return TRUE;
        }

        return FALSE;
    }

    /**
     *  Looks up the given command line argument in the list of integer values (command
     *  line options declared with TYPE_INT) and returns the value, or throws if it
     *  has not been given.
     *  Does not need to check the type as this has already been done by the TYPE_* verification.
     */
    protected static function FetchIntArgOrThrow($key)
    {
        if (!($int = getArrayItem(self::$aInts, $key)))
            self::dieSyntax("Error: missing numeric argument ".Format::UTF8Quote($key));
        return $int;
    }

    /**
     *  Looks up the given command line argument in the list of integer values (command
     *  line options declared with TYPE_INT) and returns the value, or returns $iDefault
     *  if it has not been given.
     *  Does not need to check the type as this has already been done by the TYPE_* verification.
     */
    protected static function FetchIntArgOrDefault($key, $iDefault)
    {
        if (!($int = getArrayItem(self::$aInts, $key)))
            $int = $iDefault;
        return $int;
    }

    /**
     *  Does not check the type as this has already been done by the TYPE_* verification.
     */
    protected static function FetchStringArgOrThrow($key)
    {
        if (!($str = getArrayItem(self::$aStrings, $key)))
            self::dieSyntax("Error: missing string argument ".Format::UTF8Quote($key));
        return $str;
    }

    /**
     *  This function splits the given array of tickets into chunks of
     *  $cTicketsPerFork and then forks up to $cMaxJobs times, handing each
     *  child process an array of up to $cTicketsPerFork tickets. In each
     *  child process, the caller-supplied function is handed the
     *  tickets chunk for processing. This function keeps spawning
     *  children until all tickets have been processed, making sure
     *  that a maximum of $cMaxJobs child processes are running in
     *  parallel.
     *
     *  This serves two purposes:
     *
     *   1) Processing hundreds of thousands of tickets can take
     *      hours, consume huge amounts of memory and bring the
     *      server down. By running Ticket::Populate() in the child
     *      processes only with, say, 1000 tickets at a time, PHP doesn't
     *      bring the system down. But each child inherits the complete
     *      state of the parent.
     *
     *   2) Since processing tickets isn't terribly fast in PHP there
     *      may be a speed gain by running the processing in parallel,
     *      depending on the job.
     *
     *  The Callable must have the following prototype:
     *
     *      function($aTicketsSlice)
     *
     *  and return an exit code (0 for success, probably 2 for error).
     *  A non-zero exit code will cause the fork server to stop.
     *
     *  Optionally, you can specify a progress callback which will get
     *  called in the parent process every time a child has ended. The
     *  prototype is:
     *
     *      function($pidEnded, $cCurrent, $cMax)
     *
     *  which allows you to compute and report a percentage to the user.
     *
     * @return integer The total no. of tickets processed.
     */
    public static function ForkServer($aTickets,                    //!< in: entire tickets array (unpopulated)
                                      $cTicketsPerFork,             //!< in: no. of tickets per forked child
                                      $cMaxJobs,                    //!< in: no. of parallel processes (e.g. 4)
                                      Callable $fnProcessor,
                                      Callable $fnProgress = NULL,
                                      int $throttleSecs = 0)
    {
        $cTotal = count($aTickets);

        $aSuper = array_chunk($aTickets,
                              $cTicketsPerFork,
                              TRUE); # preserve keys

        $aJobs = [];

        $cJobsStartedTotal = 0;
        $cChunks = count($aSuper);

        /* Sharing the database with the child doesn't work (file handle confusion),
           so close the instance here and reopen it in every child process below. */
        Database::GetDefault()->disconnect();

        $cProcessedTotal = 0;
        while (count($aJobs) < $cMaxJobs)
        {
            # Start another job while there are tickets to reparse.
            $fTicketsLeft = ($cJobsStartedTotal < $cChunks);
            if ($fTicketsLeft)
            {
                $aTicketsSlice = $aSuper[$cJobsStartedTotal];

                $pidStarted = pcntl_fork();
                if ($pidStarted == -1)
                    throw new DrnException('fork() failed');
                else if (!$pidStarted)
                {
                    // THIS IS THE CHILD:

                    # Open another default connection to the DB, we can't share this.
                    Database::GetDefault()->connect();

                    /*
                     *  Call the caller's function on the slice!
                     */
                    $rc = $fnProcessor($aTicketsSlice);

                    Database::GetDefault()->disconnect();

                    exit($rc);
                }

                // THIS IS THE PARENT:
                Database::GetDefault()->connect();
                // store the PID
                ++$cJobsStartedTotal;
                echo "Spawned subtask $cJobsStartedTotal/$cChunks (PID $pidStarted)\n";
                $aJobs[$pidStarted] = 1;

                $cProcessedTotal += count($aTicketsSlice);

                // While we're starting up, give the new processes some time to settle.
                // Once we've reached $cChunks jobs, don't wait any more.
                if ($cJobsStartedTotal < $cMaxJobs)
                {
                    echo "Waiting 2 seconds before starting next...\n";
                    sleep(2);
                }
                // If we're reindexing with ES, we need to give ES some space.
                else if ($throttleSecs > 0)
                    sleep($throttleSecs);
            }

            $cRunning = count($aJobs);
            if (    ($cRunning == $cMaxJobs)
                 || (!$fTicketsLeft)
               )
            {
                # If we have reached the max no. of jobs, then wait for a child to end.
                $pidEnded = pcntl_wait($status);
                unset($aJobs[$pidEnded]);

                if ($fnProgress)
                    $fnProgress($pidEnded, $cProcessedTotal, $cTotal);
            }

            if (!$fTicketsLeft && $cRunning <= 1)
                break;
        }

        return $cProcessedTotal;
    }

    protected static function dieSyntax(string $msg)
    {
        cliDie("$msg; use ".Format::UTF8Quote('help')." for syntax hints");
    }
}
