<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Format class
 *
 ********************************************************************/

/**
 *  Format encapsulates a number of formatting helpers.
 *
 *  As opposed to the HtmlChunk class, all functions here are static and
 *  have no object with state information. They are only grouped together for clarity.
 */
abstract class Format
{
    /**
     *  Converts all whitespace in the given HTML into single spaces. This handles some bizzare
     *  input cases like `&nbsp;`.
     */
    public static function NormalizeWhitespace($html)
    {
        # The next bizarre line is to replace ASCII 160 (&nbsp;)
        # with an ordinary space first. "\xC2\xA0" is UTF-8 for ASCII 160.
        $html = str_replace([ "\xC2\xA0", '&nbsp;' ],
                            [  ' ',       ' ' ],
                            $html);
        return preg_replace('/\s+/', ' ', $html);
    }

    /**
     *  Puts the given HTML string into pretty quotes according to the current locale;
     *  see DrnLocale.
     */
    public static function HtmlQuotes($html)
    {
        return DrnLocale::GetItem(DrnLocale::OPENQUOTE)
              .$html
              .DrnLocale::GetItem(DrnLocale::CLOSEQUOTE);
    }

    /**
     *  Encloses the given HTML string in a span that adds the given
     *  background color.
     *
     *  To hightlight something in yellow, use \ref MakeYellow() instead,
     *  which is a11y-friendly.
     */
    public static function Colorize($html,
                                    $color,
                                    $otherClasses = NULL)
    {
        if ($otherClasses)
            $otherClasses = " class=\"$otherClasses\"";
        $attrs = "style=\"color: $color;\"$otherClasses";
        return "<span $attrs>$html</span>";
    }

    /**
     *  Encloses the given HTML string in `<mark>`...`</mark>` tags, which
     *  render it yellow according to the current theme and is a11y-friendly.
     */
    public static function MakeYellow($html)
    {
        # return "<span style=\"background: yellow;\">$html</span>";
        return "<mark>$html</mark>";
    }

    /**
     *  Hightlights the given array of words within the given HTML string
     *  by enclosing them in `<mark>`...`</mark>` tags, as with \ref MakeYellow(),
     *  but ignores the words in HTML tags correctly and avoids nested highlighting.
     *  Use this for highlighting search results.
     *
     *  Returns the no. of replacements made.
     *
     * @return int
     */
    public static function HtmlHighlight(&$htmlData,                //!< in/out: HTML to highlight words in.
                                         $llHighlightWords,         //!< in: flat list of strings to highlight
                                         $fCaseSensitive = TRUE)    //!< in: if FALSE, we search case-insensitively
    {
        $cReplaced = 0;

        // Tokenize the string to make sure we don't mess in HTML.
        if (count($llHighlightWords))
        {
            foreach ($llHighlightWords as $word)
            {
                // Hit us with the tokenizer overhead only if the word is actually present.
                if ($word)
                    if (    ($fCaseSensitive && (strpos($htmlData, $word) !== FALSE))
                         || (!$fCaseSensitive && (strpos(strtoupper($htmlData), strtoupper($word)) !== FALSE))
                       )
                    {
                        $oTokenizer = new HTMLTokenizer($htmlData);
                        $fInMark = FALSE;       // Avoid nested <mark> tags.
                        $rpl = self::MakeYellow(toHTML($word)); // replace;
                        foreach ($oTokenizer->llChunks as $oChunk)
                            if ($oChunk->type == TokenChunk::TYPE_TEXT)
                            {
                                if (!$fInMark)
                                {
                                    if ($fCaseSensitive)
                                        $oChunk->chunk = str_replace($word,      // search
                                                                     $rpl,
                                                                     $oChunk->chunk);     // subject
                                    else
                                        $oChunk->chunk = str_ireplace($word,      // search
                                                                      $rpl,
                                                                      $oChunk->chunk);     // subject
                                    ++$cReplaced;
                                }
                            }
                            else
                            {
                                // HTML: just copy
                                $llChunks[] = $oChunk->chunk;
                                if ($oChunk->chunk == '<mark>')
                                    $fInMark = TRUE;
                                else if ($oChunk->chunk == '</mark>')
                                    $fInMark = FALSE;
                            }

                        $htmlData = '';
                        foreach ($oTokenizer->llChunks as $oChunk)
                            $htmlData .= $oChunk->chunk;
                    }
            }

            // Join adjacent markings.
            if ($cReplaced)
                $htmlData = preg_replace('/<\/mark>\s+<mark>/', ' ', $htmlData);
        }

        return $cReplaced;
    }

    /**
     *  Draws a rounded box around the given HTML. Sort of like a mini-alert, but without paragraph breaks.
     */
    public static function HtmlPill($html,
                                    $colorclass)          //!< in: one of 'bg-success', 'bg-info', 'bg-warning', 'bg-danger', 'bg-primary' etc.
    {
        return "<span class=\"drn-round-background $colorclass\">$html</span>";
    }

    /**
     *  Strips HTML tags from the given string. If $fDecode = TRUE,
     *  this also converts all HTML entities to UTF-8.
     */
    public static function HtmlStrip($html,
                                     $fDecode = FALSE)
    {
        $str = preg_replace('/<[^>]+>/', '', $html);

        if ($fDecode)
            return mb_convert_encoding($str,
                                       'UTF-8',
                                       'HTML-ENTITIES');

        return $str;
    }

    /** @var \HTMLPurifier */
    private static $oHTMLPurifier = NULL;

    /**
     *  Wrapper around all of HTMLPurifier.
     */
    public static function HtmlSanitize($html)
    {
        # Instantiate an instance on the first call, in case we do this several times.
        if (!self::$oHTMLPurifier)
        {
            require INCLUDE_PATH_PREFIX.'/3rdparty/htmlpurifier/HTMLPurifier.includes.php';
            $oConfig = \HTMLPurifier_Config::createDefault();
            $oConfig->set('Cache.SerializerPath', '/var/lib/doreen');
            $oConfig->set('HTML.Allowed', 'p,b,a[href],i,br');
            $oConfig->set('Cache.DefinitionImpl', null); // TODO: remove this later!
            self::$oHTMLPurifier = new \HTMLPurifier($oConfig);
        }

        return self::$oHTMLPurifier->purify($html);
    }

    /**
     *  Truncates an HTML string up to a number of characters while preserving whole words and HTML tags.
     *
     *  Based on http://alanwhipple.com/2011/05/25/php-truncate-string-preserving-html-tags-words/ which
     *  is a modified variant of the function in the CakePHP framework (MIT license).
     *
     * @return string Trimmed string.
     */
    static function HtmlTruncate($htmlIn,                   //!< in: string to truncate
                                 $length = 100,             //!< in: desired length of returned string, including ellipsis
                                 $ending = Format::HELLIP,  //!< in: desired ending to be appended to the string if trimmed
                                 $fExact = TRUE,            //!< in: if FALSE, $text will not be cut mid-word
                                 $fPreserveHTML = TRUE)     //!< in: if TRUE, HTML tags would be handled correctly
    {
        $open_tags = [];

        if ($fPreserveHTML)
        {
            // if the plain text is shorter than the maximum length, return the whole text
            if (strlen(preg_replace('/<.*?>/', '', $htmlIn)) <= $length)
                return $htmlIn;

            // splits all html-tags to scanable lines
            preg_match_all('/(<.+?>)?([^<>]*)/s', $htmlIn, $lines, PREG_SET_ORDER);
            $total_length = strlen($ending);
            $truncate = '';
            foreach ($lines as $line_matchings)
            {
                // if there is any html-tag in this line, handle it and add it (uncounted) to the output
                if (!empty($line_matchings[1]))
                {
                    // if it's an "empty element" with or without xhtml-conform closing slash
                    if (preg_match('/^<(\s*.+?\/\s*|\s*(img|br|input|hr|area|base|basefont|col|frame|isindex|link|meta|param)(\s.+?)?)>$/is', $line_matchings[1]))
                        // do nothing if tag is a closing tag
                        ;
                    else if (preg_match('/^<\s*\/([^\s]+?)\s*>$/s', $line_matchings[1], $tag_matchings))
                    {
                        // delete tag from $open_tags list
                        $pos = array_search($tag_matchings[1], $open_tags);
                        if ($pos !== false)
                            unset($open_tags[$pos]);
                        // if tag is an opening tag
                    }
                    else if (preg_match('/^<\s*([^\s>!]+).*?>$/s', $line_matchings[1], $tag_matchings))
                        // add tag to the beginning of $open_tags list
                        array_unshift($open_tags, strtolower($tag_matchings[1]));

                    // add html-tag to $truncate'd text
                    $truncate .= $line_matchings[1];
                }
                // calculate the length of the plain text part of the line; handle entities as one character
                $content_length = strlen(preg_replace('/&[0-9a-z]{2,8};|&#[0-9]{1,7};|[0-9a-f]{1,6};/i', ' ', $line_matchings[2]));
                if ($total_length + $content_length > $length)
                {
                    // the number of characters which are left
                    $left = $length - $total_length;
                    $entities_length = 0;
                    // search for html entities
                    if (preg_match_all('/&[0-9a-z]{2,8};|&#[0-9]{1,7};|[0-9a-f]{1,6};/i', $line_matchings[2], $entities, PREG_OFFSET_CAPTURE))
                        // calculate the real length of all entities in the legal range
                        foreach ($entities[0] as $entity)
                            if ($entity[1]+1-$entities_length <= $left)
                            {
                                $left--;
                                $entities_length += strlen($entity[0]);
                            }
                            else
                                // no more characters left
                                break;
                    $truncate .= substr($line_matchings[2], 0, $left+$entities_length);
                    // maximum lenght is reached, so get off the loop
                    break;
                }
                else
                {
                    $truncate .= $line_matchings[2];
                    $total_length += $content_length;
                }
                // if the maximum length is reached, get off the loop
                if ($total_length >= $length)
                    break;
            } # foreach
        }
        else
        {
            if (strlen($htmlIn) <= $length)
                return $htmlIn;
            else
                $truncate = substr($htmlIn, 0, $length - strlen($ending));
        }
        // if the words shouldn't be cut in the middle...
        if (!$fExact)
        {
            // ...search the last occurance of a space...
            $spacepos = strrpos($truncate, ' ');
            if (isset($spacepos))
                // ...and cut the text in this position
                $truncate = substr($truncate, 0, $spacepos);
        }
        // add the defined ending to the text
        $truncate .= $ending;
        if ($fPreserveHTML)
            // close all unclosed html-tags
            foreach ($open_tags as $tag)
                $truncate .= '</' . $tag . '>';

        return $truncate;
    }

    /**
     *  Attempts to extract HTML between the open and end tag of the given HTML element
     *  from the given string.
     *
     *  For example, to extract a whole HTML table, hand in 'table' with $element,
     *  and this will return everything between <table> and </table>. $ofs will receive
     *  the offset of the first character after the closing tag in $html, so you can
     *  call this repeatedly on the same HTML string to extract all such element
     *  contents.
     */
    public static function HTMLExtract($html,       //!< in: HTML haystack
                                       $element,    //!< in: name of element to extract, without brackets (e.g. 'table')
                                       &$ofs)       //!< in/out: offset to start searching, receives offset after closing tag
    {
        $len = strlen($element);
        if (    ( ( $pOpenTag = strpos($html, "<$element", $ofs) ) !== FALSE )
             && ( ( $pEndOfOpenTag = strpos($html, '>', $pOpenTag + $len + 1) ) !== FALSE )
             && ( ( $pCloseTag = strpos($html, "</$element", $pEndOfOpenTag + 1) ) !== FALSE )
             && ( ( $pEndOfCloseTag = strpos($html, '>', $pCloseTag + $len + 2) ) !== FALSE )
           )
        {
            $ofs = $pEndOfCloseTag + 1;

            return substr($html, $pEndOfOpenTag + 1, $pCloseTag - $pEndOfOpenTag - 1);
        }

        return NULL;
    }

    /**
     *  Wrapper around PHP's number_format, but with the decimal point and thousands
     *  separator characters taken from Doreen's locale settings; see DrnLocale.
     */
    static function Number($n,
                           $decimals = 0)
    {
        return number_format($n,
                             $decimals,
                             DrnLocale::GetItem(DrnLocale::DECIMAL),
                             DrnLocale::GetItem(DrnLocale::THOUSANDS));
    }

    /**
     *  Wrapper around PHP's number_format, but with the decimal point and thousands
     *  separator characters taken from Doreen's locale settings; see DrnLocale.
     */
    static function MonetaryAmount($v,
                                   $decimals = 0)
    {
        $num = number_format(abs($v),
                             $decimals,
                             DrnLocale::GetItem(DrnLocale::DECIMAL),
                             DrnLocale::GetItem(DrnLocale::THOUSANDS));
        $fmt = DrnLocale::GetItem(DrnLocale::MONETARYSTRING);
        $rc = str_replace( [ '%C', '%N',        '%V' ],
                           [ '€',  self::NBSP,  $num ],
                          $fmt);

        if ($v < 0)
            $rc = Format::MINUS.$rc;

        return $rc;
    }

    const FL_LINE_ABOVE_TOTAL = 1 <<  0;
    const FL_ALIGN_LEFT       = 1 <<  1;
    const FL_BOLD             = 1 <<  2;

    /**
     *  Even fancier wrapper arroung MonetaryMount(). This returns a <div> with
     *  attributes and the amount contained.
     */
    public static function MonetaryAmountAligned($v,
                                                 $fl = 0)  //!< in: FL_* flags above
    {
        if ($v)
            $v = Format::MonetaryAmount($v, 2);
        else
            $v = Format::MDASH;
        $aClasses = [];
        if (!($fl & self::FL_ALIGN_LEFT))
            $aClasses[] = 'pull-right';
        if ($fl & self::FL_LINE_ABOVE_TOTAL)
            $aClasses[] = 'drn-monetary-sub-sum';
        if ($fl & (self::FL_LINE_ABOVE_TOTAL | self::FL_BOLD))
            $v = "<b>$v</b>";

        $cls = implode(' ', $aClasses);
        $v = "<div class='$cls'>$v</div>";

        return $v;
    }

    /**
     *  Returns a string for uniform progress reporting in a terminal.
     *
     */
    static function CliProgress($iJob = NULL,
                                $cCurrent,
                                $cTotal)
    {
        $job = NULL;
        if ($iJob)
            $job = "Job $iJob: ";
        $percent = Format::Number($cCurrent * 100 / $cTotal, 2).'%';
        return "$job($cCurrent/$cTotal -- $percent)";
    }

    /**
     *  Formats the Timestamp according to the current locale and user preferences.
     *  This is a wrapper around Timestamp function.
     *
     *  This passes $fRelative to \ref Timestamp::toPrettyString(). However, if the
     *  currently logged in user does not want absolute date/time stamps, then
     *  $fRelative is ignored, and we pass FALSE always.
     */
    static function Timestamp2(Timestamp $oTS,
                               $fRelative = 1)        //!< in: if TRUE, make it relative to current time; if 2, make HTML with fly-over
    {
        $userFormat = LoginSession::GetUserDateFormat();
        if ($userFormat == User::DATEFORMAT_RELATIVE && !$fRelative)
            $userFormat = User::DATEFORMAT_LONG;

        $fFlyOver = ($fRelative == 2);

        return $oTS->toPrettyString($userFormat, TRUE, $fFlyOver);
    }

    /**
     *  Converts the given given UTC date/time string to a Timestamp and calls \ref Timestamp2().
     */
    static function Timestamp($strDateTime,          //!< in: UTC date/time string in YYYY-MM-DD HH:MM:SS format
                              $fRelative = 1)        //!< in: if TRUE, make it relative to current time; if 2, make HTML with fly-over
    {
        $oTS = Timestamp::CreateFromUTCDateTimeString($strDateTime);
        return self::Timestamp2($oTS, $fRelative);
    }

    /**
     *  Like \ref Timestamp(), but returns an HTMLChunk.
     */
    static function TimestampH($strDateTime,          //!< in: UTC date/time string in YYYY-MM-DD HH:MM:SS format
                               $fRelative = 1)        //!< in: if TRUE, make it relative to current time; if 2, make HTML with fly-over
        : HTMLChunk
    {
        $o = new HTMLChunk();
        $oTS = Timestamp::CreateFromUTCDateTimeString($strDateTime);
        $o->html = self::Timestamp2($oTS, $fRelative);
        return $o;
    }

    public static function TimeTaken($time)
    {
        if ($time < 0.0001)
            return "<0.0001";
        return number_format($time, 4);
    }

    /**
     *  Formats the given year, month and date according to the current locale settings.
     *  For example, this will return "October 15, 2015" for en_US, but "15. Oktober 2015" for de_DE.
     */
    static function Date($year,
                         $month,
                         $day)
    {
        $format = DrnLocale::GetItem(DrnLocale::DATEFMT);
        $unixTimestamp = strtotime("$year-$month-$day");
        return strftime($format, $unixTimestamp);
    }

    /**
     *  Formats the given year, month and date according to the current locale settings.
     *  For example, this will return "October 15, 2015" for en_US, but "15. Oktober 2015" for de_DE.
     */
    public static function DateTime($year,
                                    $month,
                                    $day,
                                    $hours,
                                    $minutes,
                                    $seconds = '00')
        : string
    {
        $format = DrnLocale::GetItem(DrnLocale::DATETIMEFMT);
        $unixTimestamp = strtotime("$year-$month-$day $hours:$minutes:$seconds");
        return strftime($format, $unixTimestamp);
    }

    public static function DateFromDateTimeString(string $str)
    {
        if (preg_match('/(\d\d\d\d)-(\d\d)-(\d\d)\s+\d\d:\d\d/', $str, $aMatches))
            return self::Date($aMatches[1], $aMatches[2], $aMatches[3]);
        return '';
    }

    /**
     *  Like PHP's implode(), but ignores array items that are empty.
     */
    private static function Implode2(string $glue,
                                     array $a,
                                     bool $fHTML = FALSE)
        : string
    {
        $rc = '';
        foreach ($a as $str)
            if ($str)
            {
                if ($fHTML)
                    $str = toHTML($str);
                $rc .= ($rc ? $glue : '').$str;
            }

        return $rc;
    }

    /**
     *  Formats the given address bits according to the given country's conventions.
     */
    public static function Address(string $glue,                 //!< in: ', ' or '\n' or '/'
                                   string $extended,
                                   string $street,
                                   string $city,
                                   string $region,
                                   string $zip,
                                   string $country = 'DE')
        : string
    {
        switch (strtoupper($country))
        {
            case 'DE':
            case 'DEUTSCHLAND':
            case 'GERMANY':
                if ($zip)
                    $zip = "D".Format::NDASH.$zip;
                $zipCityregion = self::Implode2(' ', [ $zip, $city ] );
                $zipCityregion = self::Implode2($glue, [ $zipCityregion, $region ] );
                $country = NULL;
            break;

            default:
                // U.S. style
                $zipCityregion = self::Implode2(' ', [ $city, $region, $zip ] );
            break;
        }

        return self::Implode2($glue, [ $extended, $street, $zipCityregion, $country ] );
    }

    public static function EmailAddress(string $address,
                                        bool $fAddToClipboard,          //!< in: if TRUE, an "add to clipboard" button is added
                                        $llHighlightWords = NULL)
        : HTMLChunk
    {
        $oHTML = NULL;

        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_MAILCLIENT) as $oImpl)
        {
            /** @var IMailClientPlugin $oImpl */
            if ($oHTML = $oImpl->formatMailAddress($address, $fAddToClipboard))
                return $oHTML;
        }

        // No plugin provided an address:

        $oLink = HTMLChunk::FromString($address);
        if ($llHighlightWords)
            Format::HtmlHighlight($oLink->html, $llHighlightWords);

        $idCopyFrom = HTMLChunk::MakeCopyFromId();

        $o = HTMLChunk::MakeElement('a',
                                    [ 'href' => 'mailto:'.$address,
                                      'id' => $idCopyFrom
                                    ],
                                    $oLink);
        if ($fAddToClipboard)
            $o->addCopyToClipboardButton($idCopyFrom);

        return $o;
    }

    /*
     *  Returns a string representation of the given $size integer formatted as KB, MB and so on, depending
     *  on its size. If $fBase1024 == FALSE, we return "bytes", "kB", "MB" etc. with a base of 1000; otherwise we
     *  return "KiB", "MiB" etc. with a base of 1024.
     *
     *  GTK's GLib says: "IEC units should only be used for reporting things with a strong "power of 2" basis,
     *  like RAM sizes or RAID stripe sizes. Network and storage sizes should be reported in the normal SI units."
     *  https://developer.gnome.org/glib/stable/glib-Miscellaneous-Utility-Functions.html#GFormatSizeFlags
     */
    static function Bytes($size,
                          $decimals = 2,
                          $fBase1024 = FALSE)
    {
        $bytes = L('{{L//bytes}}');

        if ($fBase1024)
        {
            $unit = array(  '0' => $bytes,
                            '1' => 'KiB',
                            '2' => 'MiB',
                            '3' => 'GiB',
                            '4' => 'TiB',
                            '5' => 'PiB',
                            '6' => 'EiB',
                            '7' => 'ZiB',
                            '8' => 'YiB' );
            $base = 1024;
        }
        else
        {
            $unit = array(  '0' => $bytes,
                            '1' => 'kB',
                            '2' => 'MB',
                            '3' => 'GB',
                            '4' => 'TB',
                            '5' => 'PB',
                            '6' => 'EB',
                            '7' => 'ZB',
                            '8' => 'YB' );
            $base = 1000;
        }

        for ($i = 0;
             $size >= $base && $i <= count($unit);
             $i++)
            $size /= $base;

        return round($size, $decimals).' '.$unit[$i];
    }

    /**
     *  Returns a UTF-8 sequence for the given HTML entity.
     */
    static function UTF8FromEntity($htmlEntity) //!< in: e.g. &ndash;
    {
        return mb_convert_encoding($htmlEntity,
                                   'UTF-8',
                                   'HTML-ENTITIES');
    }

    /**
     *  Returns a UTF-8 sequence for the given Unicode codepoint. It looks like PHP has
     *  nothing like this.
     */
    static function UTF8Char($u)
    {
        return self::UTF8FromEntity('&#'.intval($u).';');
    }

    static $cNBSP      = NULL;

    /**
     *  Encloses the given string in UTF-8 pretty quotes, which can be used in non-HTML contexts
     *  like the CLI.
     */
    static function UTF8Quote($str)
    {
        return DrnLocale::GetItem(DrnLocale::OPENQUOTE)
              .$str
              .DrnLocale::GetItem(DrnLocale::CLOSEQUOTE);
    }

    /**
     *  Like PHP's implode(), but additionally calls UTF8Quote() on every array item before
     *  imploding the string array.
     */
    static function UTF8QuoteImplode($strGlue, $aIn)
    {
        $aQuoted = [];
        foreach ($aIn as $in)
            $aQuoted[] = self::UTF8Quote($in);
        return implode($strGlue, $aQuoted);
    }

    const HELLIP = "\u{2026}";          # Single-glyph ellipse (..., &hellip;), see https://en.wikipedia.org/wiki/Ellipsis
    const MDASH  = "\u{2014}";          # Single-glyph very long dash (&mdash;), see https://en.wikipedia.org/wiki/Dash
    const NDASH  = "\u{2013}";          # Single-glyph long dash (&ndash;), see https://en.wikipedia.org/wiki/Dash
    const MINUS  = "\u{2212}";          # Mathematical "minus" character (&minus;), see https://en.wikipedia.org/wiki/Plus_and_minus_signs
    const NBSP   = "\u{00A0}";          # Simple non-breaking space character (&nbsp;), see https://en.wikipedia.org/wiki/Non-breaking_space

    /**
     *  Trims the given string to the given maximum length, if it is longer; adds an UTF-8
     *  ellipse to it in that case. Otherwise the string is left unchanged.
     */
    static function UTF8Trim(&$str, $maxLen)
    {
        if (strlen($str) > $maxLen)
            $str = mb_strimwidth($str, 0, $maxLen, self::HELLIP);
    }

    /**
     *  Returns TRUE if the given string is valid UTF-8.
     */
    static function IsValidUtf8($str)
    {
        return mb_check_encoding($str, 'UTF-8');
    }

    /**
     *  Attempts to describe a raw C error number, which some PHP functions like msg_receive() return.
     *  If the error is unknown to us, we return the error number again.
     */
    static function CError($errno)
    {
        switch ($errno)
        {
//             case MSG_EPERM: return "EPERM - Operation not permitted"; # 1
//             case MSG_ENOENT: return "ENOENT - No such file or directory"; # 2
//             case MSG_ESRCH: return "ESRCH - No such process"; # 3
//             case MSG_EINTR: return "EINTR - Interrupted system call"; # 4
//             case MSG_EIO: return "EIO - I/O error"; # 5
//             case MSG_ENXIO: return "ENXIO - No such device or address"; # 6
//             case MSG_E2BIG: return "E2BIG - Argument list too long"; # 7
//             case MSG_ENOEXEC: return "ENOEXEC - Exec format error"; # 8
//             case MSG_EBADF: return "EBADF - Bad file number"; # 9
//             case MSG_ECHILD: return "ECHILD - No child processes"; # 10
            case MSG_EAGAIN: return "EAGAIN - Try again"; # 11
//             case MSG_ENOMEM: return "ENOMEM - Out of memory"; # 12
            case 13: return "EACCES - Permission denied"; # 13
//             case MSG_EFAULT: return "EFAULT - Bad address"; # 14
//             case MSG_ENOTBLK: return "ENOTBLK - Block device required"; # 15
//             case MSG_EBUSY: return "EBUSY - Device or resource busy"; # 16
//             case MSG_EEXIST: return "EEXIST - File exists"; # 17
//             case MSG_EXDEV: return "EXDEV - Cross-device link"; # 18
//             case MSG_ENODEV: return "ENODEV - No such device"; # 19
//             case MSG_ENOTDIR: return "ENOTDIR - Not a directory"; # 20
//             case MSG_EISDIR: return "EISDIR - Is a directory"; # 21
//             case MSG_EINVAL: return "EINVAL - Invalid argument"; # 22
//             case MSG_ENFILE: return "ENFILE - File table overflow"; # 23
//             case MSG_EMFILE: return "EMFILE - Too many open files"; # 24
//             case MSG_ENOTTY: return "ENOTTY - Not a typewriter"; # 25
//             case MSG_ETXTBSY: return "ETXTBSY - Text file busy"; # 26
//             case MSG_EFBIG: return "EFBIG - File too large"; # 27
//             case MSG_ENOSPC: return "ENOSPC - No space left on device"; # 28
//             case MSG_ESPIPE: return "ESPIPE - Illegal seek"; # 29
//             case MSG_EROFS: return "EROFS - Read-only file system"; # 30
//             case MSG_EMLINK: return "EMLINK - Too many links"; # 31
//             case MSG_EPIPE: return "EPIPE - Broken pipe"; # 32
//             case MSG_EDOM: return "EDOM - Math argument out of domain of func"; # 33
//             case MSG_ERANGE: return "ERANGE - Math result not representable"; # 34
        }
        return "Error number $errno";
    }

    /**
     *  Produces an link to our image thumbnailer for the given binary.
     */
    public static function Thumbnail($idBinary, $thumbsize)
    {
        $size = NULL;
        if ($thumbsize != Globals::$thumbsize)
            $size = "?size=$thumbsize";
        return "<img src=\"".Globals::$rootpage."/thumbnail/$idBinary$size\"/>";
    }

    /**
     *  Convert a pure ascii normalized version of a string. Useful for search
     *  normalization.
     *
     *  @return string
     */
    public static function ASCIIFromUTF8(string $str)
        : string
    {
        // iconv's translit will just strip the accents, manually expand
        $str = str_replace([
            'ä',
            'ö',
            'ü',
            'Ä',
            'Ö',
            'Ü',
        ], [
            'ae',
            'oe',
            'ue',
            'AE',
            'OE',
            'UE',
        ], $str);

        return iconv('UTF-8', 'ASCII//TRANSLIT', $str);
    }

}
