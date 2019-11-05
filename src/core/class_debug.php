<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  All functions related to debug printing are grouped here. Static methods and variables only.
 */
abstract class Debug
{
    const FL_INSTALL       = 1 <<  0;
    const FL_TICKETFIND    = 1 <<  1;
    const FL_JOBS          = 1 <<  2;         # includes mail queue
    const FL_USERS         = 1 <<  3;
    const FL_TICKETUPDATE  = 1 <<  4;
    const FL_AUTOLOADER    = 1 <<  5;
    const FL_PROFILE       = 1 <<  6;
    const FL_THUMBNAILER   = 1 <<  7;
    const FL_AWAKETICKETS  = 1 <<  8;
    const FL_STATUS        = 1 <<  9;         # includes workflows
    const FL_SQL           = 1 << 10;
    const FL_ELASTIC       = 1 << 11;
    const FL_HTTP          = 1 << 12;
    const FL_HTTP_COOKIES  = 1 << 13;
    const FL_HTTP_RAW      = 1 << 14;
    const FL_TICKETMAIL    = 1 << 15;
    const FL_IMAP          = 1 << 16;
    const FL_IMAP_WIRE     = 1 << 17;
    const FL_PLUGIN1       = 1 << 18;
    const FL_PLUGIN2       = 1 << 19;
    const FL_PLUGIN3       = 1 << 20;
    const FL_TICKETJSON    = 1 << 21;
    const FL_TICKETDISPLAY = 1 << 22;
    const FL_MANAGEDTABLE  = 1 << 23;          # Low-level, very noisy.
    const FL_LANG          = 1 << 24;          # Low-level, very noisy.
    const FL_DRILLDETAILS  = 1 << 25;
    const FL_TICKETACLS    = 1 << 26;
    const FL_URLHANDLERS   = 1 << 27;          # Prints out a funcenter/funcleave pair around every HTTP request.

    public static $flDebugPrint = 0
                                | Debug::FL_TICKETFIND
                                | Debug::FL_JOBS
//                                | Debug::FL_USERS
                                | Debug::FL_TICKETUPDATE
//                                | Debug::FL_AUTOLOADER
                                | Debug::FL_PROFILE
//                                | Debug::FL_THUMBNAILER
//                                | Debug::FL_AWAKETICKETS
//                                | Debug::FL_STATUS
                                | Debug::FL_SQL
                                | Debug::FL_ELASTIC
                                | Debug::FL_PLUGIN1
                                | Debug::FL_PLUGIN2
                                | Debug::FL_PLUGIN3
                                | Debug::FL_HTTP
                                | Debug::FL_HTTP_COOKIES
//                                | Debug::FL_HTTP_RAW
//                                | Debug::FL_TICKETMAIL
                                | Debug::FL_IMAP
                                | Debug::FL_IMAP_WIRE
//                                | Debug::FL_INSTALL
//                                | Debug::FL_TICKETJSON
//                                | Debug::FL_TICKETDISPLAY
//                                | Debug::FL_MANAGEDTABLE
//                                | Debug::FL_LANG
//                                | Debug::FL_TICKETACLS
                                | Debug::FL_URLHANDLERS
                                  ;

    public static $fLog = TRUE;      # Can be set to FALSE to disable debug printing altogether. Happens in the CLI during startup, for example.

    public static $fDebugCreateTicket = FALSE;
        /* If set to TRUE in doreen-optional-vars, then Doreen doesn't reload the ticket details page after create or
           update so that one can inspect POST data in the browser debugger. */
    public static $fDebugSearch = FALSE;
        /* If set to TRUE in doreen-optional-vars, then the search plugin may have some additional features. */

    /**
     *  Called once on startup from index.php or api.php.
     */
    static function PrintHeader($entryFile)       //!< in: e.g. 'index.php'
    {
        Globals::$entryFile = $entryFile;
        $req = $_SERVER['REQUEST_METHOD']." /$entryFile/".Globals::GetRequestOnly();
        $rpt2 = max(5, 70 - strlen($req));
        self::Log(0, str_repeat('-', 20)." $req ".str_repeat('-', $rpt2));
    }

    /**
     *  Also called on startup from index.php or api.php.
     */
    static function LogUserInfo()
    {
        self::Log(0, "User: ".LoginSession::$ouserCurrent->login." -- Database: ".Database::$defaultDBName);
    }

    /**
     *  Prints a message to PHP's error log, if Debug::$fDebugPrint is TRUE
     *  and the given flag is also enabled. If $fl is 0, then the message is printed
     *  regardless of enabled debug flags.
     */
    static function Log($fl,
                        $msg)
    {
        if (self::$fLog)
            if ( ($fl == 0) || (self::$flDebugPrint & $fl) )
                error_log(self::Indent($msg));
    }

    /**
     *  Convenience function that calls Log() with print_r($var).
     */
    static function LogR($fl, $msg, $var)
    {
        self::Log($fl, $msg.': '.print_r($var, TRUE));
    }

    static $indent = 0;

    static function Indent($msg)
    {
        if (self::$indent < 0)
        {
            self::$indent = NULL;
            throw new DrnException("Internal error: debug indent cannot be \"".self::$indent."\", probably a mismatch?");
        }

        if (self::$indent !== NULL)
            if (self::$indent > 0)
            {
                $strIndent = str_repeat(' ', self::$indent * 2);
                return '.'.$strIndent.str_replace("\n",
                                                  "\n.$strIndent",
                                                  $msg);
            }

        return $msg;
    }

    static private $aStack = [];

    static function FuncEnter($fl,
                              $func,
                              $msg = NULL)
    {
        $msg2 = "Entering $func";
        if ($msg)
            $msg2 .= " ($msg)";
        if ( ($fl == 0) || (self::$flDebugPrint & $fl) )
        {
            Debug::Log($fl, $msg2);
            ++self::$indent;
        }

        self::$aStack[] = [$fl, $func, microtime(TRUE)];
    }

    static function FuncLeave($msg = NULL)
    {

        list($fl, $func, $timeEnter) = array_pop(self::$aStack);

        if ( ($fl == 0) || (self::$flDebugPrint & $fl) )
        {
            --self::$indent;

            $timeLeave = microtime(TRUE);

            $msg2 = "Leaving $func";
            if ($msg)
                $msg2 .= " ($msg)";
            $msg2 .= " -- Time in func: ".Format::TimeTaken($timeLeave - $timeEnter);
            Debug::Log($fl, $msg2);
        }
    }
}
