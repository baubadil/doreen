<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Error handler for CLI
 *
 ********************************************************************/

function reportError($err)
{
    if (LongTask::$oRunning)
        LongTask::$oRunning->setStatus(400,
                                       NULL,
                                       $err,
                                       TRUE);
    else
        echoJSONErrorAndExit(NULL, $err);
}

function longTaskErrorHandler($errno,
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

    reportError("PHP runtime error in $errfile".'['.$errline."]: $errstr");
}

/*
 *  Handler for register_shutdown_function() for the CLI side.
 */
function longTaskShutdownHandler()
{
    $lasterror = error_get_last();
    switch ($type = $lasterror['type'])
    {
        case E_ERROR:
        case E_CORE_ERROR:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_RECOVERABLE_ERROR:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
        case E_PARSE:
            $errfile = $lasterror['file'];
            $errline = $lasterror['line'];
            reportError("Spawned task had PHP error (type $type) in $errfile".'['.$errline."]: ".$lasterror['message']);
    }
}

/**
 *  Signal handlers for the CLI side.
 */
function longTaskSignalHandler(int $signal, $signinfo)
{
    $fStore = TRUE;
    switch ($signal)
    {
        case SIGTERM:
            echo "Caught SIGTERM\n";
        break;

        case SIGKILL:
            echo "Caught SIGKILL\n";
        break;

        case SIGINT:
            echo "Caught SIGINT\n";
        break;

        default:
            $fStore = FALSE;
        break;
    }

    if ($fStore)
    {
        LongTask::$signalReceived = $signal;
        if (LongTask::$oRunning)
            LongTask::$oRunning->deleteSelf();
    }

    exit;
}

/********************************************************************
 *
 *  LongTask class
 *
 ********************************************************************/

/**
 *  A LongTask instance represents a long-running task that has been offloaded to
 *  a separate process and for which progress can be presented to the user.
 *
 *  Displaying a progress bar for a long-running task is not trivial to achieve in
 *  PHP because a PHP script normally only runs for the duration of an HTTP request
 *  and finishes when all the HTML has been output to the user. That's normally a
 *  fraction of a second. And if the request ends, e.g. because the browser window
 *  is closed, then the PHP script dies on the server side too.
 *
 *  So, in order to have a long-running task with a progress bar (for example,
 *  re-indexing all tickets with the search engine, which can take hours), we take
 *  a different approach. The actual long-running task runs in a command-line
 *  process (CLI, cli.php), and the \ref longtasks table in the database is used
 *  for communication between that background process and REST APIs to control the job.
 *
 *  In detail:
 *
 *   1) There should be a REST API for starting such a job (say, "POST /myjob/:data").
 *      This REST API must then spawn a second PHP process via the Doreen command-line
 *      interface (cli.php) and a special argument that processes what needs to be done
 *      (e.g. "cli.php myjob --data 123". For this to work smoothly, the REST API can
 *      create a LongTask instance via the WebApp::SpawnCLI(), which calls
 *      LongTask::Launch() in turn, which creates a new LongTask instance in memory and
 *      in the \ref longtasks table. The session ID contained therein (really the primary
 *      index of the new table row) is then returned to the caller by the REST API as
 *      JSON and can be used to track progress. The REST API will normally be called
 *      from client JavaScript after the user has pressed a button somewhere
 *      (e.g. "Re-index all tickets").
 *
 *   2) The newly spawned process, which automatically receives the session ID with a
 *      command-line argument, should first call LongTask::PickUpSession() to get its
 *      own instance of LongTask. It can can then load all other required  classes and
 *      do its work. It can then call LongTask::setStatus() periodically to store status
 *      information in the table row in \ref longtasks, for example to report progress.
 *
 *   3) The GUI can display a Bootstrap progress bar and query progress with the
 *      GET /progress REST API and the session ID returned by step 1). This waits for
 *      a certain amount of time and then calls LongTask::GetStatus() and returns the
 *      resulting JSON to the caller.
 *
 *  The LongTask instance gets created for management of a longtask both in the CLI process
 *  and for the controlling API side (which launches a job, or when progress is queried).
 *
 *  A job ends in one of the following ways:
 *
 *   -- The command-line job process has completed successfully or with an error and
 *      calls setStatus() with fDone = TRUE. A JavaScript client blocked in a GET /progress
 *      call will return with that result.
 *
 *   -- The command-line job exits less gracefully, e.g. because of a PHP error that
 *      wasn't suppressed. In that case, the GetStatus() call detects that the process
 *      is no longer running and reports that as well.
 *
 *   -- The user requests to stop the job (if this is permitted by the GUI), which
 *      results in a REST request and a Stop() call. This kills the command-line
 *      job process.
 *
 *  Each progress session has the following fields in the database:
 *
 *   -- Session ID, as created by Spawn().
 *
 *   -- Process ID, as added to the record by the task process calling PickUp().
 *
 *   -- A description string, which can be used with FindRunning() to find sessions if the
 *      session ID is not known.
 *
 *   -- Current JSON status including progress, as reported by the task process calling setStatus().
 *
 *   -- "Running" field as one of "spawning", "running", "ended", "crashed".
 *
 *  To add a functioning progress bar to an HTML dialog using LongTask, the following
 *  is required:
 *
 *   1. Make the job work in the CLI, with a progress callback, which should call Globals::ReportJSON().
 *      At the minimum, this must set the cCurrent and cTotal members in the JSON return structure.
 *      Picking up the (future) session ID from the --session-id  command line argument should always work.
 *
 *   2. Add a REST API that creates the LongTask, like so:
 *
* WebApp::Post('/myjob', function()
                * {
                    * WebApp::SpawnCLI("My job",
                                     * [ 'reindex-all' ]);
                * });
 *   3. Add a button somewhere and attach some JavaScript like the following to it:
 *
* var d = new Dialog( {   idDialog: 'myjob',
                                        * method: 'post',
                                        * cmd: 'api/myjob',
                                        * fields: '',
                                        * onSuccess: function(jsonData)
                                        * {
                                            * reportProgress( this.idDialog,
                                                            * jsonData.session_id,
                                                            * $('#' + this.idDialog + '-save'),
                                                            * '$nlsmyJob',
                                                            * 500,
                                                            * function(json)
                                                            * {
                                                            * });
                                        * }
                                 * } );
 */
class LongTask
{
    # If we're running from the CLI with a --session-id, this receives the corresponding LongTask instance.
    /** @var LongTask */
    public static $oRunning = NULL;
    public static $signalReceived = NULL;

    public        $idSession;
    public        $description;
    public        $commandLine;
    public        $process_id;            # only for LongTask instances returned from FindRunning
    public        $fInTaskProcess;         # TRUE if the LongTask was created in the task process (job), FALSE otherwise (e.g. from PrepareSpawn()).

    public        $started_dt;            # only valid in the child longtask process itself
    public        $channel;                 # set after channelNotifyStarted()

    const STATUS_SPAWNING       = 0;
    const STATUS_RUNNING        = 1;
    const STATUS_ENDED          = 2;

    const CHANNEL_SEARCH_REINDEX_ALL = 'search-reindex-all';   // Channel name for reindex-all and GET /listen REST API


    /********************************************************************
     *
     *  Public factory methods
     *
     ********************************************************************/

    /**
     *  Looks into the list of running jobs for whether any instances with
     *  the given description strings are already running. If at least
     *  one is found, a flat list of LongTask instances representing them
     *  is returned. Otherwise this returns NULL.
     *
     *  This is not race-free.
     *
     * @return LongTask[]
     */
    public static function FindRunning($aDescriptions,
                                       $fReturnCommandLine = FALSE)     //!< in: if TRUE, we return the full command line (may be a security hole)
    {
        $llTasks = [];
        $llToDeleteIDs = [];

        foreach ($aDescriptions as $descr)
            if ($res = Database::DefaultExec(<<<EOD
SELECT
    i,
    process_id,
    description,
    command,
    status,
    started_dt,
    updated_dt,
    json_data
FROM longtasks
WHERE description = $1
EOD
                , [ $descr ]))
            {
                while ($row = Database::GetDefault()->fetchNextRow($res))
                {
                    $ts = Timestamp::CreateFromUTCDateTimeString($row['updated_dt']);
                    if ($ts->ageInHours() > 24)
                        $llToDeleteIDs[] = $row['i'];
                    else if (    ($pid = $row['process_id'])
                              && (self::IsProcessRunning($pid, $row['status']))
                            )
                    {
                        Debug::Log(Debug::FL_JOBS, "FindRunning(): adding pid $pid, status ".$row['status']);
                        $oTask = new LongTask($row['i'],
                                             FALSE);            # not in task process
                        $oTask->description = $row['description'];
                        $oTask->commandLine = ($fReturnCommandLine) ? $row['command'] : '<hidden>';
                        $oTask->process_id = $row['process_id'];
                        $llTasks[] = $oTask;
                    }
                    else
                        Debug::Log(Debug::FL_JOBS, "FindRunning(): ignoring pid ".$row['process_id'].", status ".$row['status']);
                }
            }

        if (count($llToDeleteIDs))
            Database::DefaultExec("DELETE FROM longtasks WHERE i in (".Database::MakeInIntList($llToDeleteIDs).")");

        if (count($llTasks))
            return $llTasks;

        return NULL;
    }

    /**
     *  Returns TRUE if a LongTask with the given description AND command line is already running,
     *  FALSE otherwise. This uses FindRunning() so it ignores obsolete old rows in the table.
     */
    public static function IsRunning($description,
                                     $commandLine)
    {
        if ($llTasks = self::FindRunning( [ $description ],
                                          TRUE))
            foreach ($llTasks as $oTask)
            {
                Debug::Log(Debug::FL_JOBS, "Testing command line ".$oTask->commandLine);
                if ($oTask->commandLine == $commandLine)
                    return TRUE;
            }

        return FALSE;
    }

    /**
     *  Returns a usable path to cli.php and the install dir for launching a CLI process.
     */
    public static function MakeCliArgs()
    {
        $cliPHP = FileHelpers::RealPath(TO_HTDOCS_ROOT.'/../cli/cli.php');
        $installDir = dirname(Globals::$fnameInstallVars);

        return "$cliPHP --install-dir $installDir";
    }

    /**
     *  Creates a new LongTask instance and launches the process. Returns the LongTask.
     *
     *  This is intended for the most common case where a LongTask is launched from
     *  an HTTP request handled by PHP and the session ID of the new tasks is returned
     *  to a JavaScript client.
     *
     *  This creates an entry in the database with a JSON 'spawning' status,
     *  which should be overridden by the task process as soon as possible,
     *  and returns a new LongTask instance to the caller.
     *  The task's session ID is the value of the 'i' column (primary key)
     *  of the newly created database row.
     *
     *  Runtime / singleton control:
     *
     *   -- If $forceSingletonDescription is not NULL, this throws an exception if another
     *      LongTask with the given $description is already running. $forceSingletonDescription
     *      is then printed in the error message, so it should be a readable description of the
     *      job like "cronjob", which will be inserted into the message.
     *
     *  GUI connection behavior: The GUI is usually fine if a job is started with a button
     *  and then displays progress via a session ID. Problems arise if the web page is closed
     *  and then re-opened for some reason, since the client JavaScript doesn't know the
     *  session ID of the longtask that is already running in the background. One of the
     *  two needs to be implemented for critical longtasks:
     *
     *   -- The GUI should check if a job is already been running, by calling FindRunning()
     *      with a description string, which can return a session ID, which the GUI can
     *      then pick up.
     *
     *   -- If this is not desired, then the job should automatically terminate itself if
     *      no progress has been queried for a while, which should mean that the web page
     *      no longer exists. TODO
     */
    public static function Launch($description,
                                  $aArgs,
                                  $forceSingletonDescription = NULL)
    {
        $fLocked = FALSE;
        if ($forceSingletonDescription)
            $fLocked = self::EnsureSingleton($description,
                                             $forceSingletonDescription);

        list($idSession, $commandLine) = self::InsertRow($description,
                                                         $aArgs,
                                                         self::STATUS_SPAWNING);        # PID is NULL

        $cliPHP = self::MakeCliArgs();
        $lang = DrnLocale::Get();
        $commandLine = "php $cliPHP --session-id $idSession --lang $lang $commandLine";

        $oNew = new LongTask($idSession,
                             FALSE);            # not in task process
        $oNew->description = $description;
        $oNew->commandLine = $commandLine;

        if ($fLocked)
            ServiceBase::Unlock();

        $rc = Process::Spawn($commandLine,
                             TRUE);
        Globals::EchoIfCli("command line: $commandLine, rc: $rc");

        return $oNew;
    }

    /**
     *  To be called by the newly spawned CLI process that implements the long-running
     *  task. This needs the session ID that was passed on the command line with --session-id.
     *  The longtask's database row is then updated with the process ID of this new task.
     *
     *  This must ONLY be called by the owning task.
     */
    public static function PickUpSession($idSession)
    {
        $row = self::FetchRow($idSession);
        $status = $row['status'];
        if ($status != 0)
            throw new DrnException("Invalid status $status for session ID $idSession");

        $oTask = new LongTask($idSession,
                              TRUE);            # in task process
        $oTask->description = $row['description'];
        $oTask->commandLine = $row['command'];
        $oTask->started_dt = $row['started_dt'];

        $oTask->refresh(self::STATUS_RUNNING);       # status = 1: running; this inserts the PID

        set_error_handler('Doreen\\longTaskErrorHandler', E_ALL & ~E_DEPRECATED);
        register_shutdown_function('Doreen\\longTaskShutdownHandler');

        self::$oRunning = $oTask;
    }

    /**
     *  To be called by long-running CLI tasks that could be interrupted on the command line,
     *  to clean up the longtask if necessary.
     */
    public static function RegisterSignalHandlers()
    {
        declare(ticks = 1);

        pcntl_signal(SIGTERM, 'Doreen\\longTaskSignalHandler');
        pcntl_signal(SIGINT, 'Doreen\\longTaskSignalHandler');
    }

    /**
     *  As an alternative to PickUpSession(), this can be called by the newly spawned CLI
     *  process if the session ID is not known. This is used by Email::MailDaemon() for
     *  example, for testing.
     *
     *  If $forceSingletonDescription is not NULL, this throws an exception if another
     *  LongTask with the given $description is already running. $forceSingletonDescription
     *  is then printed in the error message, so it should be a readable description of the
     *  job like "cronjob", which will be inserted into the message.
     */
    public static function RegisterWithoutSessionID($description,
                                                    $aArgs,
                                                    $forceSingletonDescription = NULL)
        : int
    {
        $fLocked = FALSE;
        if ($forceSingletonDescription)
            $fLocked = self::EnsureSingleton($description,
                                             $forceSingletonDescription);

        list($idSession, $commandLine) = self::InsertRow($description,
                                                         $aArgs,
                                                         self::STATUS_RUNNING,
                                                         getmypid());

        $oNew = new LongTask($idSession,
                             TRUE);         # in task process
        $oNew->description = $description;
        $oNew->commandLine = $commandLine;
        $oNew->started_dt = Globals::Now();

        if ($fLocked)
            ServiceBase::Unlock();

        self::$oRunning = $oNew;

        return $idSession;
    }

    const REINDEXER_DESCRIPTION = "CORE_REINDEXER_LONGTASK";

    /**
     *  Special case of \ref RegisterWithoutSessionID(), which makes sure that only one
     *  instance of reindex-all is running.
     */
    public static function RegisterReindexer()
    {
        self::RegisterWithoutSessionID(self::REINDEXER_DESCRIPTION, [], "reindexer");
        // The above has set $oRunning.
        self::$oRunning->channelNotifyStarted(self::CHANNEL_SEARCH_REINDEX_ALL, []);

        // Set a GlobalConfig key so we don't hit the database on every single page view if nothing is running.
        GlobalConfig::Set(GlobalConfig::KEY_REINDEX_RUNNING, 1);
        GlobalConfig::Save();
    }

    public static function UnregisterReindexer()
    {
        GlobalConfig::Clear(GlobalConfig::KEY_REINDEX_RUNNING);
    }

    public static function IsReindexerRunning()
        : bool
    {
        if (GlobalConfig::Get(GlobalConfig::KEY_REINDEX_RUNNING, 0))
        {
            if ($a = self::FindRunning([ self::REINDEXER_DESCRIPTION ]))
                return TRUE;

            // If the GlobalConfig flag is set but no task is running then a previous
            // task probably died early. Clear the flag.
            self::UnregisterReindexer();
        }

        return FALSE;
    }


    /********************************************************************
     *
     *  Private functions
     *
     ********************************************************************/

    /**
     *  Private constructor, only to be called by static factory methods.
     */
    private function __construct($idSession,
                                 $fInTaskProcess)
    {
        $this->idSession = $idSession;
        $this->fInTaskProcess = $fInTaskProcess;
    }

    /**
     *  Returns TRUE if the global lock was acquired.
     */
    private static function EnsureSingleton($description,
                                            $forceSingletonDescription)
    {
        ServiceBase::Lock();
        if ($llTasks = self::FindRunning( [ $description ] ))
        {
            $llIDs = [];
            foreach ($llTasks as $oTask)
                $llIDs[] = $oTask->idSession;
            throw new DrnException("Cannot launch: another $forceSingletonDescription is already running (ID(s) ".implode(', ', $llIDs).')');
        }

        return TRUE;
    }

    /**
     *  Inserts a new LongTasks into the \ref longtasks table.
     *
     *  When called from Spawn(), $status must be STATUS_SPAWNING. Only when
     *  called from RegisterWithoutSessionID(), it may be STATUS_RUNNING.
     *
     *  Returns a list of session ID and command line, so you can do:
     *
     *      list($idSession, $commandLine) = InsertRow(...)
     */
    private static function InsertRow($description,
                                      $aArgs,
                                      $status,              //!< in: self::STATUS_SPAWNING or self::STATUS_RUNNING
                                      $pid = NULL)          //!< in: PID if self::STATUS_RUNNING
    {
        $commandLine = '';
        if (is_array($aArgs) && count($aArgs))
            $commandLine = implode(' ', $aArgs);
        $dtNow = Globals::Now();

        Database::DefaultExec(<<<EOD
INSERT INTO longtasks ( description,   command,       process_id,  status,   updated_dt,  started_dt ) VALUES
                      ( $1,            $2,            $3,          $4,       $5,          $6 )
EOD
                    , [ $description,  $commandLine,  $pid,        $status,  $dtNow,      $dtNow ]
                          );

        $idSession = Database::GetDefault()->getLastInsertID('longtasks', 'i');
        return [ $idSession, $commandLine ];
    }


    /********************************************************************
     *
     *  Public methods for owning process
     *
     ********************************************************************/

    /**
     *  Calls LongTask::setStatus() if a global LongTask object exists.
     *
     *  If Globals::$oLongTask is not set, this silently does nothing.
     */
    public static function ReportJSON($code,            //!< in: HTTP code (e.g. 200 for 'OK')
                                      $dlgfield,
                                      $msg,             //!< in: can be array of additional JSON fields, e.g. 'progress' => 50, or a single string to be stored in 'msg'
                                      $fDone = FALSE)
    {
        if (self::$oRunning)
            self::$oRunning->setStatus($code,
                                       $dlgfield,
                                       $msg,
                                       $fDone);
    }

    /**
     *  To be called from the owning long-running task process, to report its status.
     *  This gets written into the task record in the database and will thus indirectly
     *  be reported back to the caller of the /api/progress API via GetStatus().
     *
     *  $msg can be either an array with values for JSON, or a single string, in which
     *  case the string is copied to a 'msg' field in the JSON.
     *
     *  If $code == 200, the JSON receives a 'status' => 'OK' field. Otherwise it receives
     *  'status' => 'error'. The code is always written into the 'code' field.
     *
     *  For progress support via /api/progress, the JSON *must* have at least the
     *  'cCurrent' and 'cTotal' fields, and cTotal must not be 0 to avoid divisions by zero.
     *
     *  This must ONLY be called by the owning task.
     */
    public function setStatus($code,               //!< in: HTTP code (e.g. 200 for 'OK')
                              $dlgfield,           //!< in: dialog field for AJAX (e.g. from exception, if part of dialog) or NULL
                              $msg,                //!< in: can be array of additional JSON fields, e.g. 'progress' => 50, or a single string to be stored in 'msg'
                              $fDone = FALSE)
    {
        $a = [ 'code' => $code ];
        if (isset($GLOBALS['cliCommand']))
            $a['command'] = $GLOBALS['cliCommand'];

        $status = self::STATUS_RUNNING;

        if ($code == 200)
        {
            $a['status'] = 'OK';
            if (!is_array($msg))
                $a['message'] = $msg;
            else
                foreach ($msg as $key => $value)
                    $a[$key] = $value;

            if ($fDone)
                $status = self::STATUS_ENDED;

            if ($this->channel)
                self::ChannelNotifyImpl($this->channel,
                                        self::EVENT_PROGRESS,
                                        $this->idSession,
                                        $a);
        }
        else
        {
            $status = self::STATUS_ENDED;

            $a['status'] = 'error';
            $a['message'] = $msg;

            # If we're aborting with an error, for the status to actually arrive in the database,
            # we must abort the current transaction if we're in one. After this we die.
            if (Database::GetDefault()->isInTransaction())
                Database::GetDefault()->rollback();
                    # TODO not a good idea, open a second connection for this.

            if ($this->channel)
                self::ChannelNotifyImpl($this->channel,
                                        self::EVENT_ERROR,
                                        $this->idSession,
                                        $a);
        }

        if ($dlgfield)
            $a['field'] = $dlgfield;

        $this->refresh($status, json_encode($a, DEBUG_JSON_OUTPUT));
    }

    /**
     *  Helper that allows a longtask to delete itself. This is intended for jobs that
     *  have created a longtask for themselves via RegisterWithoutSessionID() so that
     *  they can clean up on exit. This also gets called if such a  job has registered
     *  a signal handler via RegisterSignalHandlers().
     */
    public function deleteSelf()
    {
        self::DeleteRow($this->idSession);
    }


    /********************************************************************
     *
     *  Public static methods for client APIs
     *
     ********************************************************************/

    /**
     *  This checks whether the session is still running and if so returns its
     *  JSON status decoded as a PHP array.
     *
     *  The client perspective is described with the GET /progress REST API,
     *  which calls this in turn.
     *
     *  This needs to handle the following situations:
     *
     *   -- The task is still running and reporting progress. In those cases, it will
     *      have set a code of 200 and a status of 1 == running and will have set progress
     *      fields in the JSON.
     *
     *   -- The task is done with its job and wants to say progress = 100%. In that case,
     *      it sets the progress fields, code = 200 and status = 2 for "done".
     *
     *   -- The task has failed with an error message, e.g. an exception, or the PHP
     *      error handler has caught a PHP error (e.g. for syntax errors). In that case,
     *      code will be != 200 and status = 2 for "done".
     *
     *   -- The task has crashed or was killed without reporting a "done" condition.
     *      This situation needs to be handled here by checking for the process ID.
     *      If the process has died, then it is marked as stopped and error 410 is
     *      returned as if the process had reported an error itself via setStatus().
     *
     *  The following fields will typically be set in the returned array:
     *
     *   -- 'code' => HTTP error code (200 unless an error occured)
     *
     *   -- 'status' => either 'OK' or 'error'
     *
     *   -- 'message' => error message, if status == 'error'
     *
     *   -- 'cCurrent', 'cTotal', 'progress' => progress fields, if supplied by owning task
     *
     *   -- 'fDone' => TRUE if task is done (status == 'error' or progress == 100 or ended otherwise)
     *
     *   -- 'cCurrentFormatted', 'cTotalFormatted' => the above formatted with thousands separators, if present
     *
     *   -- 'secondsPassed' => seconds passed since the task was started
     *
     *   -- 'secondsRemaining' => calculated estimate of the remaining seconds for the task to be completed,
     *      if the above are present and the task has been running for a long enough time to predict such things
     *
     *   -- 'timeRemaining' => a formatted string like "less than a minute remaining", based on the previous.
     *
     *  If the owning task has set additional fields, then they will be passed on without modification.
     *
     *  Note that this function REMOVES the task from the longtask table if 'finished' has been set to TRUE.
     *  As a result, subsequent calls will then return NULL for the same session ID.
     */
    public static function GetStatus($idSession,
                                     $timeoutSeconds = 10)
    {
        if (!($row = self::FetchRow($idSession, FALSE)))
            // Throw error 404 so that the front-end can react more specifically.
            throw new APIException('',
                                   "Invalid session ID $idSession",
                                   404);

        $iStatus = $row['status'];      # 0 = spawning, 1 = running, 2 = ended (explicitly by script)

        $fDone = FALSE;

        if ($iStatus != self::STATUS_SPAWNING)
        {
            if (!($pid = $row['process_id']))
            {
                Debug::Log(Debug::FL_JOBS, "Longtask data: ".print_r($row, TRUE));
                throw new DrnException("Session has no process ID");
            }

            # Check that the process ID is still valid, but only if the record status is still "running".
            # Otherwise we'd die with this error if the process exited successfully as well.
            if (    (!self::IsProcessRunning($pid, $iStatus))
                 && ($iStatus != 2)
               )
            {
                # no explicit error, but script is dead anyway:
                $a = [ 'code' => 410,     # Gone. Resource will not be available again.
                       'status' => 'error',
                       'message' => L('{{L//Spawned task with process ID %PID% seems to have died without notice}}', [ '%PID%' => $pid ])
                     ];

                $fDone = TRUE;
            }
        }

        if (!$fDone)
        {
            $cCurrent = 0;
            $cTotal = 0;
            if (!($a = json_decode($row['json_data'], TRUE)))
            {
                # Task has not yet set any JSON information: then set a 0% progress
                $a = [ 'code' => 200,
                       'progress' => 0,
                       'cCurrent' => 0,
                       'cTotal' => 1        # avoid division by zero
                ];
            }
            else if (    is_array($a)
                      && (isset($a['cTotal']))       # can be 0
                    )
            {
                $cTotal = $a['cTotal'];
                $cCurrent = $a['cCurrent'];
            }
            else if (    ($code = getArrayItem($a, 'code'))
                      && ($code != 200)
                    )
                $fDone = TRUE;

            self::ComputeProgress($row['started_dt'],
                                  $cCurrent,
                                  $cTotal,
                                  $fDone,
                                  $a);
        }

        # If the longtask is done, for any reason, delete the row.
        if ($a['fDone'] = (bool)$fDone)
            self::DeleteRow($idSession);

        return $a;       # can be NULL
    }

    const EVENT_STARTED  = 'started';
    const EVENT_PROGRESS = 'progress';
    const EVENT_ERROR    = 'error';
    const EVENT_TIMEOUT  = 'timeout';

    /**
     *  Implementation for the GET /listen REST API.
     *
     *  Returns the exact same binary data that was put into ChannelNotify(),
     *  which can be an array.
     *
     *  The returned array always has at least an 'event' key, which can be
     *  'timeout' if nothing happened in the defined timeout. Otherwise
     *  it will have an event name, and the 'data' key can also be present.
     *
     *  This expects $aJSON as a reference because we can't do the normal
     *  WebApp::$aJSON += function(); the "+" operator on arrays ignores
     *  values if the key already exists, and we might need to override
     *  the status fields.
     *
     * @return void
     */
    public static function GetChannelStatus(string $channel,
                                            &$aJSON,
                                            &$httpResponseCode)
    {
        Database::DefaultExec("LISTEN \"$channel\"");

//        Debug::Log(0, "LISTEN returned");

        $timeout = 30 * 1000;
        $interval = 300;
        // This returns FALSE if there is nothing in the channel.
        while (FALSE === ($notify = Database::GetDefault()->getNotify()))
        {
//            Debug::Log(0, "notify returned FALSE");
            $timeout -= $interval;
            if ($timeout < 0)
                break;
//            Debug::Log(0, "sleeping $interval secs");
            usleep($interval * 1000);
        }

        Debug::Log(0, "LISTEN getNotify() returned with: ".print_r($notify, TRUE));

        if (is_array($notify))
        {
            /* Format: Array
                (
                    [message] => <channelname>
                    [pid] => 8358
                    [payload] => whatever string notifier gave us
                ) */

            $a = json_decode($notify['payload'] ?? '',
                             TRUE);

            if (($a['event'] ?? NULL) == 'error')
            {
                // Move fields from data array to main array.
                if ($aData = $a['data'] ?? NULL)
                {
                    foreach ( [ 'status', 'message' ] as $key)
                        if ($val = $aData[$key] ?? NULL)
                        {
                            $a[$key] = $val;
                            unset($a['data'][$key]);
                        }

                    if ($code = $aData['code'])
                        $httpResponseCode = $code;
                }
            }

            $aJSON = array_merge($aJSON, $a);
        }
        else
            $aJSON += [ 'event' => self::EVENT_TIMEOUT ];

        Debug::Log(0, "Returning ".print_r($aJSON, TRUE));
    }

    /**
     *  To be called from the longtask after it has picked up or registered its new session,
     *  to notify a channel that a task was started. This posts EVENT_STARTED with the
     *  additional given (optional) data into the channel and stores the channel name
     *  with the longtask.
     */
    public function channelNotifyStarted(string $channel,
                                         $data)
    {
        $this->channel = $channel;
        self::ChannelNotifyImpl($channel,
                                LongTask::EVENT_STARTED,
                                $this->idSession,
                                $data);
    }

    /**
     *  Notifies listeners in a channel of an event of type self::EVENT_PROGRESS. This calls
     *  ChannelNotify() to produce a ListenResultProgress on the listener side and thus unblock
     *  all HTTP clients blocked in a GET /listen REST API on that channel.
     *
     *  Caller MUST have called channelNotifyStarted() first.
     *
     *  $aData is now expected to have at least the cCurrent and cTotal members as integers; all other
     *  fields will be copied for the listener.
     *
     *  $fDone must be set to TRUE if the task is completed, and only one such call must be made.
     *  This is copied to ListenResultProgress.fDone.
     */
    public function channelNotifyProgress($aData,
                                          bool $fDone)
    {
        if (!$this->channel)
            throw new DrnException("cannot call ".__METHOD__." without channelNotifyStarted() first");

        foreach ( [ 'cCurrent', 'cTotal' ] as $test)
            if (NULL === ($aData[$test] ?? NULL))
                throw new DrnException("channelNotifyProgress(): $test must not be NULL");

//        Globals::EchoIfCLI("started_dt: $this->started_dt");
        self::ComputeProgress($this->started_dt,
                           $aData['cCurrent'],
                           $aData['cTotal'],
                              $fDone,
                              $aData);

        $aData['fDone'] = $fDone;

        $this->setStatus(200, NULL, $aData, $fDone);
    }

    /*
     *  Stops the session identified by the given session ID by killing it.
     *
     *  This will fail if the given user account is not the user account who owns the session.
     */
    public static function Stop(User $oUser,
                                $idSession)
    {
        if (!($row = self::FetchRow($idSession)))
            throw new DrnException("Invalid session ID $idSession");

        if (    ($pid = $row['process_id'])
             && (self::IsProcessRunning($pid, $row['status']))
           )
        {
            Process::KillPID($pid);
            self::DeleteRow($idSession);
        }
    }


    /********************************************************************
     *
     *  Static private helpers
     *
     ********************************************************************/

    /**
     *  Fetches the database row for the given session ID. If not found, this either throws
     *  or returns NULL.
     */
    private static function FetchRow($idSession,
                                     bool $fThrow = TRUE)
    {
        if (    ($res = Database::DefaultExec("SELECT process_id, description, command, status, started_dt, updated_dt, json_data FROM longtasks WHERE i = $1",
                                                                                                                                                           [ $idSession ]))
             && ($row = Database::GetDefault()->fetchNextRow($res))
           )
            return $row;

        if ($fThrow)
            throw new DrnException("Cannot find session with ID $idSession");

        return NULL;
    }

    private static function DeleteRow($idSession)
    {
        Database::DefaultExec("DELETE FROM longtasks WHERE i = $1",
                                                             [ $idSession ]);
    }

    /**
     *  Internal helper which writes updated status to the database. Must ONLY be called by the owning task.
     *
     * @return void
     */
    private function refresh($status,           //!< in: STATUS_RUNNING, STATUS_ENDED
                             $json = NULL)
    {
        $dtNow = Globals::Now();
        $pid = getmypid();
        Database::DefaultExec("UPDATE longtasks SET process_id = $1, status = $2, updated_dt = $3, json_data = $4 WHERE i = $5",
                                     [ $pid,        $status,         $dtNow,         $json,       $this->idSession ] );
    }

    /**
     *  Returns TRUE if the given PID is both in the "running" state and actually running.
     */
    private static function IsProcessRunning($pid, $status)
        : bool
    {
        if (    ($status == self::STATUS_RUNNING)      // running
             && (file_exists("/proc/$pid"))
           )
            return TRUE;

        return FALSE;
    }

    /**
     *  Helper to compute additional progress fields and time remaining.
     *
     * @return void
     */
    private static function ComputeProgress($dtStarted,
                                            $cCurrent,
                                            $cTotal,
                                            &$fDone,
                                            array &$a)
    {
        if ($cTotal)
        {
            if ($cCurrent >= $cTotal)
            {
                $a['progress'] = 100;
                $fDone = TRUE;
            }
            else
                $a['progress'] = floor($cCurrent * 100 / $cTotal);
            foreach ( [ 'cCurrent', 'cTotal' ] as $key)
                $a[$key.'Formatted'] = Format::Number($a[$key]);
        }

        $tStartedUTC = strtotime($dtStarted);
        $tNowUTC = strtotime(gmdate('Y-m-d H:i:s')); # .' UTC';
        $secsPassed = $a['secondsPassed'] = $tNowUTC - $tStartedUTC;

        if (    ($cCurrent > 0)
             && ($cTotal > 0)
             && ($secsPassed > 5)
           )
        {
            $progress2 = ($cCurrent * 100 / $cTotal);       # not rounded
            $secsTotal = floor($secsPassed * 100 / $progress2);
            $secsRemaining = $a['secondsRemaining'] = $secsTotal - $secsPassed;
            $minutesRemaining = floor(($secsRemaining + 30)/ 60);

            if (!$fDone)
            {
                if ($secsRemaining < 60)
                    $a['timeRemaining'] = L('{{L//Less than a minute remaining}}');
                else if ($secsRemaining < 120)
                    $a['timeRemaining'] = L('{{L//Less than two minutes remaining}}');
                else
                    $a['timeRemaining'] = L('{{L//%MINS% minutes remaining}}',
                                            [ '%MINS%' => Format::Number($minutesRemaining) ]
                                           );
            }
        }
    }

    /**
     *  Notifies listeners of a channel of an event. Listeners are HTTP clients currently blocked in
     *  a GET /listen REST API call with the same channel name; this will unblock all listeners
     *  blocked on the same channel.
     *
     *  $data can be arbitrary data, including binary arrays, and will be json-encoded here. Reversely,
     *  GetChannelStatus will json-decode it, so it comes out the same as it came in.
     */
    private static function ChannelNotifyImpl(string $channel,
                                              string $event,
                                              int $idSession,
                                              $data,
                                              $errorMessage = NULL)
    {
        $payload =  [ 'event' => $event,
                      'idSession' => $idSession,
                      'data' => $data
                    ];

        $strPayload = Database::GetDefault()->escapeString(json_encode($payload));
        Database::DefaultExec("NOTIFY \"$channel\", ".$strPayload);
    }

}
