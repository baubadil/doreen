<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;

use Carbon\Carbon;


/********************************************************************
 *
 *  Timestamp class
 *
 ********************************************************************/

/**
 *  Our own Timestamp class, which is a wrapper around Carbon https://github.com/briannesbitt/Carbon
 *  for several reasons:
 *
 *   -- The C-style PHP procedural date/time interfaces are confusing and lacking.
 *
 *   -- The standard PHP DateTime class is not well documented.
 *
 *   -- Carbon is better, but its documentation is also not complete. Also we don't want to have to add
 *      "use Carbon" to every source file.
 *
 *  So I'm adding methods here for the stuff we use, and it works. This now (2016-11) replaces the older
 *  C-style function calls used here.
 *
 *  Note that Carbon always stores a timezone with every timestamp. We use a Carbon instance that has its
 *  timezone set fixed to UTC always, and we convert that to local time or other time zones as needed.
 *
 *  Both Carbon and this class use the default PHP DateTimeZone class. See GetLocalTimeZone() and
 *  GetUTCTimeZone() to get the most common ones.
 */
class Timestamp
{
    /** @var Carbon $ts */
    private $ts;

    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    /**
     *  Constructor made private. Use factory methods instead.
     */
    private function __construct()
    {
    }


    /********************************************************************
     *
     *  Static factory methods
     *
     ********************************************************************/

    /**
     *  Returns a Timestamp representing the current date and time in UTC.
     *  The instance is cached on the first call.
     *
     * @return Timestamp
     */
    public static function Now()
        : self
    {
        $o = new self;
        $o->ts = Carbon::now(self::GetUTCTimeZone());
        return $o;
    }

    /**
     *  Creates a new Timestamp from the given string in database format,
     *  which is YYYY-MM-DD HH:MM:SS in UTC.
     */
    public static function CreateFromUTCDateTimeString($strDateTime)       //!< in: UTC date/time string in YYYY-MM-DD HH:MM:SS format
        : self
    {
        if (!$strDateTime)
            throw new DrnException("Error: empty string handed to ".__METHOD__);
        $o = new self;
        $o->ts = Carbon::createFromFormat('Y-m-d G:i:s',
                                          $strDateTime,
                                          self::GetUTCTimeZone());

        return $o;
    }

    /**
     *  Creates a new UTC timestamp from the given string and time zone,
     *  converting them to UTC.
     *
     *  This returns NULL if the date/time string is invalid.
     */
    public static function CreateWithTimeZone($strDateTime,             //!< in: date/time string in YYYY-MM-DD HH:MM:SS format
                                              \DateTimeZone $oTZ)        //!< in: time zone of that string
        : self
    {
        $o = new self;
        $o->ts = Carbon::createFromFormat('Y-m-d G:i:s',
                                          $strDateTime,
                                          $oTZ);
        $o->ts->setTimezone(self::GetUTCTimeZone());

        return $o;
    }


    /********************************************************************
     *
     *  Public instance methods
     *
     ********************************************************************/

    public function getUnixTimestamp()
    {
        return $this->ts->getTimestamp();
    }

    public function getYear()
        : int
    {
        return $this->ts->year;
    }

    /**
     *  Adds the given no. of minutes to the internal timestamp. Substracts if negative.
     *
     *  @return self
     */
    public function addMinutes($c)
        : self
    {
        $this->ts->addMinutes($c);
        return $this;
    }

    /**
     *  Adds the given no. of hours to the internal timestamp. Substracts if negative.
     *
     *  @return self
     */
    public function addHours($c)
        : self
    {
        $this->ts->addHours($c);
        return $this;
    }

    /**
     *  Adds the given no. of days to the internal timestamp. Substracts if negative.
     *
     *  @return self
     */
    public function addDays($c)
        : self
    {
        $this->ts->addDays($c);
        return $this;
    }

    /**
     *  Returns self as a standard date/time string in "YYYY-MM-DD HH:MM:SS" format,
     *  in UTC.
     *
     *  @return string
     */
    public function toDateTimeString()
        : string
    {
        return gmdate('Y-m-d H:i:s',
                      $this->ts->getTimestamp());
    }

    /**
     *  Returns self as a standard date string (without the time) in "YYYY-MM-DD" format,
     *  in UTC.
     *
     *  @return string
     */
    public function toDateString()
        : string
    {
        return gmdate('Y-m-d',
                      $this->ts->getTimestamp());
    }

    /**
     *  Returns self as a compact date string (without the time) in "YYYYMMDD" format,
     *  in UTC.
     *
     *  @return string
     */
    public function toCompactDateString()
        : string
    {
        return gmdate('Ymd',
                      $this->ts->getTimestamp());
    }

    /**
     *  Returns self as a compact date string (including the time) in "YYYYMMDDHHMMSS" format,
     *  in UTC.
     *
     *  @return string
     */
    public function toCompactDateTimeString()
        : string
    {
        return gmdate('YmdHis',
                      $this->ts->getTimestamp());
    }

    /**
     *  Returns self as a locale-dependent date/time string, using the current locale
     *  from Globals.
     *
     *  If $fConvertToLocalTime == FALSE, it is printed as UTC. Otherwise the timestamp is converted to
     *  the local timezone.
     *
     *  @return string
     */
    public function toLocale($fConvertToLocalTime,
                             bool $fIncludeSeconds = TRUE,
                             bool $fShort = FALSE)
        : string
    {
        $ts = $this->ts->copy();

        if ($fConvertToLocalTime)
        {
            $ts->setTimezone(self::GetLocalTimeZone());
            $ts->addSeconds($ts->getOffset());
        }

        $time = $ts->getTimestamp();

        $strTime = $fIncludeSeconds ? gmdate('H:i:s', $time) : gmdate('H:i', $time);
        $format = $fShort ? DrnLocale::DATESHORTFMT : DrnLocale::DATEFMT;
        $currTimeLocale = setlocale(LC_TIME, 0);
        setlocale(LC_TIME, DrnLocale::Get().'.UTF-8');
        $str = $ts->formatLocalized(DrnLocale::GetItem($format))." $strTime";
        setlocale(LC_TIME, $currTimeLocale);

        if (!$fConvertToLocalTime)
            $str .= ' UTC';
        return $str;
    }

    public function toW3cString()
        : string
    {
        return $this->ts->toW3cString();
    }

    /**
     *  Performs additional formatting over toLocale().
     *
     *  The flyover is omitted when the format is the long date format and seconds are included,
     *  since it would contain the same information than the text it would be shown for.
     *
     *  @return string
     */
    public function toPrettyString(int $format = 0,        //!< in: user format preference (maps to User format constants)
                                   bool $fIncludeSeconds = TRUE,
                                   bool $fFlyOver = FALSE)
        : string
    {
        $strDateTimeFormatted = $this->toLocale(TRUE,       # convert to local time
                                                $fIncludeSeconds,
                                                $format == User::DATEFORMAT_SHORT);

        if ($format != User::DATEFORMAT_RELATIVE)
            $tag = HTMLChunk::MakeElement('time',
                                          [ 'datetime' => $this->toW3cString() ],
                                          HTMLChunk::FromEscapedHTML($strDateTimeFormatted));
        else
        {
            $tsNow = Carbon::now(self::GetUTCTimeZone());

            $secondsPassed = $this->ts->diffInSeconds($tsNow, FALSE); // $tsNow->unixTimestamp - $this->unixTimestamp;

            if ($secondsPassed < 0)
            {
                # In the future:
    //            if ($this->toDateString() == $tsNow->toDateString())
    //                $str = L("{{L//Today}}").' '.gmdate('H:i', $this->getLocalTime());
    //            else
                    $str = $strDateTimeFormatted;
            }
            else
            {
                # Up to ten minutes counts as "just now".
                if ($secondsPassed < 10*60)
                    $str = L("{{L//just now}}");
                # Avoid the plurals problem now by splitting at two hours, and two days.
                else if ($secondsPassed < 60*60)
                {
                    $minutes = floor($secondsPassed / 60);
                    $str = L("{{L//%MINUTES% minutes ago}}", [ '%MINUTES%' => Format::Number($minutes) ] );
                }
                else if ($secondsPassed < 60*60*2)
                    $str = L("{{L//Less than two hours ago}}");
                else
                {
                    $hours = floor($secondsPassed / 60 / 60);
                    if ($hours < 48)
                        $str = L("{{L//%HOURS% hours ago}}", [ '%HOURS%' => $hours ] );
                    else
                    {
                        $days = floor(($hours + 13) / 24);
                        if ($days < 69)
                            $str = L("{{L//%DAYS% days ago}}", [ '%DAYS%' => Format::Number($days) ] );
                        else
                        {
                            $months = $days / 30;
                            if ($months < 24)
                                $str = L("{{L//%MONTHS% months ago}}", [ '%MONTHS%' => Format::Number($months) ] );
                            else
                            {
                                $years = $months / 12;
                                $str = L("{{L//%YEARS% years ago}}", [ '%YEARS%' => Format::Number($years) ] );
                            }
                        }
                    }
                }
            }

            $tag = HTMLChunk::MakeElement('time',
                                           [ 'datetime' => $this->toW3cString() ],
                                           HTMLChunk::FromEscapedHTML($str));
        }

        if (    $fFlyOver
             && (    ($format != User::DATEFORMAT_LONG)
                  || !$fIncludeSeconds
                )
           )
        {
            $strFlyOver = $strDateTimeFormatted;
            // Ensure the fly over contains the long format.
            if (    ($format != User::DATEFORMAT_RELATIVE)
                 || !$fIncludeSeconds)
                $strFlyOver = $this->toLocale(TRUE,       # convert to local time
                                              TRUE,
                                              FALSE);
            return HTMLChunk::MakeFlyoverInfo($tag, $strFlyOver)->html;
        }

        return $tag->html;
    }

    /**
     *  Returns the age of the timestamp in seconds (meaning, the difference between the
     *  contained timestamp and now). The number will be negative if the timeestamp is
     *  in the future.
     */
    public function ageInSeconds()
    {
        $tsNow = Carbon::now(self::GetUTCTimeZone());
        return $this->ts->diffInSeconds($tsNow, FALSE);
    }

    /**
     *  Integer return value is rounded down.
     */
    public function ageInMinutes()
    {
        return floor($this->ageInSeconds() / 60);
    }

    /**
     *  Integer return value is rounded down.
     */
    public function ageInHours()
    {
        return $this->ageInMinutes() / 60;
    }

    /**
     *  Integer return value is rounded down.
     */
    public function ageInDays()
    {
        return $this->ageInHours() / 24;
    }


    /********************************************************************
     *
     *  Public static methods
     *
     ********************************************************************/

    public static $otzUTC = NULL;

    /**
     * Returns a static PHP DateTimeZone object for UTC.
     *
     * @return \DateTimeZone
     */
    public static function GetUTCTimeZone()
    {
        if (!self::$otzUTC)
            self::$otzUTC = new \DateTimeZone('UTC');

        return self::$otzUTC;
    }

    public static $otzLocal = NULL;

    /**
     * Returns a static PHP DateTimeZone object for the server local time (php.ini).
     *
     * @return \DateTimeZone
     */
    public static function GetLocalTimeZone()
    {
        if (!self::$otzLocal)
            self::$otzLocal = new \DateTimeZone(date_default_timezone_get());

        return self::$otzLocal;
    }

}
