<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Email class
 *
 ********************************************************************/

/**
 *  \page mail_daemon_sync Mail queue daemon synchronization
 *
 *  The Email class implements an easy to use mail queue for sending out mail via
 *  a mail daemon process. PHP code can simply call \ref Email::Enqueue(), and the
 *  class will automatically launch the mail daemon if necessary and add the mail
 *  to the queue of mails to be sent out. The mail daemon will then do that
 *  asynchronously by running as a Doreen CLI process ('mail-daemon' argument).
 *
 *  Since there are possibly many parallel PHP processes (HTTP requests) that
 *  put mail into the queue, but there should only be one mail queue daemon per
 *  Doreen database, a bit of synchronization is necessary. Callers need not worry
 *  about this, this is only documentation for those interested in the details.
 *
 *  There is one global lock that the participating PHP processes can request and
 *  release via Email::Lock() and Email::Unlock(). See remarks there.
 *
 *  We define three operations that need concurrency protection:
 *
 *   1. Enqueueing mail (Email::Enqueue()). This must request the lock, insert
 *      the new mail into the emailq table and give it the 'new' status, and then
 *      either launch the mail daemon or notify it that new mail is in the queue.
 *
 *   2. The daemon then needs to request the lock, fetch all mails from the queue
 *      that have 'new' status, mark them as 'sending' and release the lock.
 *
 *   3. For each of those mails, after sending, the daemon needs to request the
 *      lock, mark the mail as 'sent', and release the lock again. This is done
 *      individually for each of the mails fetched under 2.
 *
 *  The notification of the mail queue daemon is implemented with a System V message
 *  queue (standard Unix IPC mechanism), which PHP supports. A random integer message
 *  queue ID is generated at Doreen install time so that multiple Doreen instances
 *  on the server can have their separate mail daemons. (Multiple daemons are needed
 *  because the mail queue is in each installation's database.)
 */

/**
 *  Specialized exception thrown by the EMail class.
 */
class DrnMailException extends DrnException
{
    public $aTrace;

    public function __construct($msg,           //!< in: exception message forwarded to MyException
                                $aTrace)        //!< in: debug trace from PHPMailer
    {
        $this->aTrace = $aTrace;
        parent::__construct($msg);
    }
}

/**
 *  This provides two things: a thin wrapper around PHPMailer which translates Doreen configuration
 *  variables into PHPMailer instance variables, and a mail queue with a daemon process that is
 *  managed automatically. See \ref mail_daemon_sync for details.
 *
 *  Regarding configuration, by default, this will configure PHPMailer to use sendmail on the server
 *  and let that deal with the mess.
 *
 *  Alternatively, Doreen can have PHPMailer connect to an SMTP server, set the following in
 *  doreen-optional-vars.inc.php:

        define('MAIL_SMTP_HOSTS',      'mail.SERVERNAME.COM');
        define('MAIL_SMTP_USER',       'USER@SERVERNAME.COM');
        define('MAIL_SMTP_PASS',       'CLEARTEXTPASS');
        define('MAIL_SMTP_SSL_OR_TLS', 'tls');
        define('MAIL_SMTP_PORT',       587);
        define('MAIL_SMTP_INSECURE',   1);          # to relax certificate checks -- not recommended!
 */
class Email
{
    const MAIL_NEW                  = 0;
    const MAIL_SENDING              = 1;
    const MAIL_SENT                 = 2;
    const MAIL_FAILED               = 3;

    const SERVICEID_MAILDAEMON      = 'MAILQDAEMON';
    const LONGTASK_MAILDAEMON       = 'Mail queue daemon task';


    /********************************************************************
     *
     *  Public static methods
     *
     ********************************************************************/

    /**
     *  Enqueues an email for sending and starts the mail daemon if it is not running,
     *  or notifies it that new mail is available. See \ref mail_daemon_sync for details.
     *
     *  The daemon will then eventually call \ref SendOne() in the daemon process. See remarks there.
     */
    public static function Enqueue($aTo,               //!< in: email addresses to send to or NULL
                                   $aBCC,              //!< in: email addresses to blind-carbon-copy or NULL
                                   $mailsubj,          //!< in: mail subject
                                   $mailbodyHTML,      //!< in: HTML body or NULL for plain-text only
                                   $mailbodyPlain,     //!< in: plain-text HTML body
                                   $fromAddr = NULL,
                                   $fromName = NULL)
    {
        if (    (!is_array($aTo))
             && (!is_array($aBCC))
           )
            throw new DrnException("Nobody to send anything to in 'to' or 'bcc' arguments");

        $json = json_encode([ 'to' => $aTo,
                              'bcc' => $aBCC,
                              'subject' => $mailsubj,
                              'bodyHTML' => $mailbodyHTML,
                              'bodyPlain' => $mailbodyPlain,
                              'fromAddr' => $fromAddr,
                              'fromName' => $fromName
                            ]);

        ServiceBase::Lock();
        Database::DefaultExec(<<<EOD
INSERT INTO emailq ( insert_dt,       status,          data ) VALUES
                   ( $1,              $2,              $3 )
EOD
                 , [ Globals::Now(),  self::MAIL_NEW,  $json ] );

        if ($a = LongTask::FindRunning( [ self::LONGTASK_MAILDAEMON ] ))
        {
            # There can only be one such LongTask running so take the first instance.
            $oLongTask = $a[0];
            $pid = $oLongTask->process_id;
            Debug::Log(Debug::FL_JOBS, "Mail daemon running, PID $pid");

            # The daemon creates the SysV message queue with this ID and blocks on it,
            # so write into it, which will wake up the daemon.
            $idSysvMsgQ = GlobalConfig::Get(GlobalConfig::KEY_EMAILQ_ID);
            $msgQ = msg_get_queue($idSysvMsgQ, 0666);
            if (!msg_send($msgQ,
                          1,        # type
                          'Mail',   # Message
                          true,     # serialize?
                          true,     # blocking?
                          $msg_err))
                throw new DrnException("Failed to notify mail queue: $msg_err");
        }
        else
        {
            # Create the LongTask instance.
            /* $oLongTask = */ LongTask::Launch(self::LONGTASK_MAILDAEMON,
                                                [ 'mail-daemon' ]);
            Debug::Log(Debug::FL_JOBS, "Launched new mail daemon");

            # On startup, the daemon processes all mail in the emailq table, so there's
            # no need to write into the message queue here.
        }

        ServiceBase::Unlock();
    }

    /**
     *  Returns the email queue for inspection. Used by the global settings to allow the
     *  administrator to see which mail has been sent recently.
     *
     *  Returns a flat list where each item is another flat list, with i, insert_dt, status, data, error
     *  fields, in that order.
     */
    public static function GetQueue($limit = 100)
    {
        $aReturn = [];
        if ($res = Database::DefaultExec(<<<EOD
SELECT i, insert_dt, status, data, error FROM emailq ORDER BY insert_dt DESC LIMIT $limit
EOD
                                 ))
            while ($row = Database::GetDefault()->fetchNextRow($res))
                $aReturn[] = [ $row['i'],
                               $row['insert_dt'],
                               $row['status'],
                               $row['data'],
                               $row['error']
                             ];
        if (count($aReturn))
            return $aReturn;
        return NULL;
    }

    /**
     *  Synchronously sends an email. This normally only gets called in the mail queue
     *  daemon process but it can be called by anyone to send mail synchronously.
     *
     *  This operates in one of two modes:
     *
     *   -- If an instance of SMTPHost is given, then we will configure PHPMailer
     *      to connect to that server via SMTP.
     *
     *   -- If $oHost is NULL, then we configure PHPMailer to use a local
     *      sendmail instance, which better work.
     *
     *  If the mailer uses SMTP this call can take a couple of seconds to complete.
     *
     *  If $aBCC contains at least one address but $aTo is NULL, we set $aTo to
     *  'Undisclosed Recipients<...>" automatically.
     *
     *  If fromAddr or fromName are not specified, they are substituted with
     *  Globals::$SMTPFromAddr and Globals::$SMTPFromName, respectively.
     *
     *  Calling this requires no locking.
     *
     *  Throws on error messages, in particular on errors from PHPMailer.
     */
    public static function SendOne(SMTPHost $oHost = NULL,
                                   $aTo,            //!< in: email addresses to send to or NULL
                                   $aBCC,           //!< in: email addresses to blind-carbon-copy or NULL
                                   $mailsubj,
                                   $mailbodyHTML,      //!< in: HTML body or NULL for plain-text only
                                   $mailbodyPlain,    //!< in: plain-text HTML body
                                   $fromAddr = NULL,
                                   $fromName = NULL)
    {
        if (!Globals::$fEmailEnabled)
            throw new DrnException(L("{{L//Sending mail has been disabled for this installation by the administrator}}"));

        $mail = new \PHPMailer();
        $mail->CharSet = "utf-8";

        $aTrace = [];

        if ($oHost)
        {
            Debug::FuncEnter(Debug::FL_JOBS, "SENDING MAIL IN SMTP MODE");

            $mail->isSMTP();
            $mail->Host         = $oHost->Host;
            $mail->SMTPAuth     = $oHost->SMTPAuth;
            $mail->Username     = $oHost->Username;
            $mail->Password     = $oHost->Password;
            $mail->SMTPSecure   = $oHost->SMTPSecure;
            $mail->Port         = $oHost->Port;

            if (!extension_loaded('openssl'))
                throw new DrnException("Mail SMTP mode requires the PHP openssl extension, but it is not installed.");

            if ($oHost->fAllowInsecure)
                $mail->SMTPOptions = [ 'ssl' => [   'verify_peer' => false,
                                                    'verify_peer_name' => false,
                                                    'allow_self_signed' => true ]];
            $mail->SMTPDebug = 2;
            /** @noinspection PhpUnusedParameterInspection */
            $mail->Debugoutput = function($str, $level) use(&$aTrace)
            {
                Debug::Log(Debug::FL_JOBS, "Trace: ".trim($str));
                $aTrace[] = $str;
            };
        }
        else
        {
            Debug::FuncEnter(Debug::FL_JOBS, "SENDING MAIL IN SENDMAIL MODE");
            $mail->isSendmail();
        }

        if (!$fromAddr)
            $fromAddr = Globals::$SMTPFromAddr;
        if (!$fromName)
            $fromName = Globals::$SMTPFromName;
        $mail->setFrom($fromAddr, $fromName);
        $mail->Subject  = $mailsubj;

        if ($mailbodyHTML)
        {
            $mail->isHTML(TRUE);
            $mail->Body    = $mailbodyHTML;
            $mail->AltBody = $mailbodyPlain;
        }
        else
            $mail->Body     = $mailbodyPlain;

        $cTo = $cBCC = 0;
        if (is_array($aTo))
            foreach ($aTo as $address)
            {
                $mail->addAddress($address);
                ++$cTo;
            }

        if (is_array($aBCC))
            foreach ($aBCC as $address)
            {
                $mail->addBCC($address);
                ++$cBCC;
            }

        if (($cBCC) && (!$cTo))
            $mail->addAddress('"Undisclosed recipients" <'.$fromAddr.'>');

        if (!$mail->send())
            throw new DrnMailException($mail->ErrorInfo, $aTrace);

        Debug::FuncLeave("Mail sent to $cTo addresses, $cBCC on BCC");
    }

    public static function DescribeStatus(int $iStatus)
    {
        switch ($iStatus)
        {
            case Email::MAIL_NEW:
                return [ 'NEW', 'bg-primary' ];
            break;

            case Email::MAIL_SENDING:
                return [ 'SENDING', 'bg-warning' ];
            break;

            case Email::MAIL_SENT:
                return [ 'SENT', 'bg-success' ];
            break;

            case Email::MAIL_FAILED:
                return [ 'FAIL', 'bg-danger' ];
            break;
        }

        return NULL;
    }


    /********************************************************************
     *
     *  Mail daemon entry point
     *
     ********************************************************************/

    /**
     *  This function implements the CLI 'mail-daemon' mode. It never returns.
     *
     *  See \ref mail_daemon_sync for details how this works.
     * @throws DrnException
     */
    public static function MailDaemon()
    {
        # We get here from cli.php which automatically calls LongTask::PickUpSession()
        # if --session-id was given on the command line. That is the case if the daemon
        # was started from Enqueue().
        # For debugging however we also want to be able to start the daemon in a terminal
        # without having a session ID, so handle that case here.
        if (!$GLOBALS['g_idSession'])
            LongTask::RegisterWithoutSessionID(self::LONGTASK_MAILDAEMON, []);

        $idSysvMsgQ = GlobalConfig::Get(GlobalConfig::KEY_EMAILQ_ID);
        $msgQ = msg_get_queue($idSysvMsgQ, 0666);

        Debug::Log(Debug::FL_JOBS, "MAILDAEMON: started, msgQ ID: $msgQ");

        # We loop forever.
        while(1)
        {
            # We arrive at this point:
            #
            #  a) After daemon startup, we must immediately check for new mail because Enqueue()
            #     inserts mail with status MAIL_NEW before starting or pinging the daemon. So when
            #     we start up, there is most likely new mail in the table.
            #
            #  b) After we have sent out all the fetched mail with status MAIL_NEW. Before going
            #     to sleep, check the mail queue again because in between the last check and us
            #     sending out the mail, there could have been new mail in the queue.

            # Fetching mail from the queue must be protected by the global lock.
            ServiceBase::Lock();
            Debug::Log(Debug::FL_JOBS, "MAILDAEMON: got lock, fetching new mail from the queue...");
            $status = Database::MakeInIntList( [ self::MAIL_NEW, self::MAIL_SENDING ] );
            $res = Database::DefaultExec(<<<EOD
SELECT
    i,
    insert_dt,
    status,
    data
FROM emailq
WHERE status IN ($status)
EOD
                                     );

            ServiceBase::Unlock();
            $cNewMails = Database::GetDefault()->numRows($res);
            Debug::Log(Debug::FL_JOBS, "MAILDAEMON: released lock, got $cNewMails new mail(s)");

            if ($cNewMails)
            {
                # Now we have a DB result with at least one MAIL_NEW mail. Send these out via PHPMailer.
                while ($row = Database::GetDefault()->fetchNextRow($res))
                {
                    $i = $row['i'];
                    $aData = json_decode($row['data'], TRUE);
                    foreach ( [ 'to', 'bcc' ] as $key)
                        if (    ($aData[$key] !== NULL)
                             && (!is_array($aData[$key]))
                           )
                            throw new DrnException("Invalid data format, field ".Format::UTF8Quote($key));

                    # Marking the mail as 'SENDING' must be protected by the global lock, for every mail individually.
                    self::MarkMailStatus($i, self::MAIL_SENDING);

                    # If there is an error sending mail, SendOne will throw an exception.
                    try
                    {
                        # Send the mail without holding the lock.
                        $fromName = $aData['fromName'] ?? NULL;
                        Debug::Log(Debug::FL_JOBS, "Sending mail, fromName: $fromName");
                        self::SendOne(SMTPHost::CreateFromDefines(),
                                      $aData['to'],
                                      $aData['bcc'],
                                      $aData['subject'],
                                      $aData['bodyHTML'],
                                      $aData['bodyPlain'],
                                      $aData['fromAddr'] ?? NULL,
                                      $fromName);

                        self::MarkMailStatus($i, self::MAIL_SENT);
                    }
                    catch(DrnMailException $e)
                    {
                        self::MarkMailStatus($i,
                                             self::MAIL_FAILED,
                                             $e->aTrace);
                    }
                    # On other exceptions, fail and exit.
                }

                # Now do not go to sleep immediately, but check for new mail again because maybe
                # new mail arrived in the queue while we were busy sending.
            }
            else
            {
                # Only if there is nothing in the database, go to sleep. If QueueMail() has something to
                # do for us, it write into this SysV message queue. The next call will block until that
                # happens, which can take days.
                Debug::Log(Debug::FL_JOBS, "MAILDAEMON: nothing to do, going to sleep");

                if (!msg_receive($msgQ,
                                 1,              // desired msgtype
                                 $msg_type,      // receives message type
                                 16384,          // maxsize
                                 $msg,           // receives message (should be "Mail" string)
                                 true,           // unserialize
                                 0,              // flags (we do want to block)
                                 $msg_error))    // error
                {
                    $msg_error = Format::CError($msg_error);
                    throw new DrnException("msg_receive() reported error $msg_error");
                }

                Debug::Log(Debug::FL_JOBS, "MAILDAEMON: Received msg $msg, woke up from sleep!");

                # Now go check for mails again.
            }
        } # end while (1)
    }


    /********************************************************************
     *
     *  Static private methods
     *
     ********************************************************************/

    /**
     *  Updates the emailq row with the given index with a new mail status with proper locking.
     */
    private static function MarkMailStatus($i,              //!< in: email ID (row primary index)
                                           $status,         //!< in: mail status (MAIL_SENDING, MAIL_SENT, MAIL_FAILED)
                                           $aError = NULL)  //!< in: SMTP trace on MAIL_FAILED or NULL
    {
        $error = ($aError) ? json_encode($aError) : NULL;
        ServiceBase::Lock();
        list($str, $clr) = self::DescribeStatus($status);
        Debug::Log(Debug::FL_JOBS, "MAILDAEMON: got lock, marking mail $i as status $status (".$str.")");
        Database::DefaultExec("UPDATE emailq SET status = $1, error = $2 WHERE i = $3",
                                                              [ $status,    $error,      $i ] );
        ServiceBase::Unlock();
        Debug::Log(Debug::FL_JOBS, "MAILDAEMON: released lock");
    }

}

/**
 *  Specialized class used in global settings view to report on status.
 */
class EmailService extends ServiceLongtask
{
    public function __construct()
    {
        parent::__construct(Email::SERVICEID_MAILDAEMON,
                            Email::LONGTASK_MAILDAEMON,
                            NULL,       # cliStartArg
                            NULL,       # plugin
                            L('{{L//Doreen email daemon (started automatically on demand)}}'));
    }

    public function getActionLinks()
    {
        return [
            L("{{L//Show recently sent mail}}").Format::HELLIP => 'mail-queue',
        ];
    }

    public function canAutostart()
    {
        return FALSE;
    }
}
