<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Our Exception extension
 *
 ********************************************************************/

/**
 *  DrnException is our own exception class derived from Exception. We always throw this one
 *  to be able to tell our own exceptions from the standard PHP ones.
 *
 *  The exception message must be PLAIN TEXT and will be HTML-escaped before printing. Do NOT
 *  use HTML in your message.
 *
 *  $msg2 can be specified in addition; it will not be passed to the parent PHP Exception
 *  constructor and therefore not be shown to the user in the browser, but it will be logged
 *  and, if this is running on the CLI, displayed to the user.
 */
class DrnException extends \Exception
{
    public $msgLogOnly;
    public $longMessage;

    public function __construct($msg,                   //!< in: publicly visible message
                                $msgLogOnly = NULL,     //!< in: message for syslog only
                                \Throwable $prevException = NULL) //!< in: previous exception, useful for re-throwing.
    {
        Globals::EndWithDot($msg);
        parent::__construct($msg, 0, $prevException);

        $this->msgLogOnly = $msgLogOnly;

        $this->longMessage = $msg;
        if ($msgLogOnly)
            $this->longMessage .= "\n$msgLogOnly";

        $file = $this->getFile();
        $line = $this->getLine();
        $this->longMessage .= "\nThrown at: $file($line)";
        $this->longMessage .= "\n".$this->getTraceAsString();
        Debug::Log(0, "Exception: ".$this->longMessage);
    }

    public function __toString()
    {
        return "DrnException: ".$this->longMessage;
    }

}

class DrnCurlException extends DrnException
{
    public $errno;

    public function __construct($errno, $msg)
    {
        $this->errno = $errno;
        parent::__construct($msg);
    }
}

class InvalidPageException extends DrnException
{
    public function __construct($page)
    {
        parent::__construct(L('{{L//Invalid page %PAGE% requested}}', [ '%PAGE%' => Format::UTF8Quote($page) ] ));
    }
}

/**
 *  APIException derives from MyException and has an extra "dlgfield" argument, which allows
 *  for specifying the ID of a dialog field in an HTML form so that the client JavaScript code
 *  can show the error message in a tooltip next to that field.
 *
 *  The exception message must be PLAIN TEXT and will be HTML-escaped before printing. Do NOT
 *  use HTML in your message.
 */
class APIException extends DrnException
{
    public $dlgfield;
    public $code;

    public function __construct($dlgfield,
                                $msg,
                                $code = 400,
                                $msgLogOnly = NULL)
    {
        $this->dlgfield = $dlgfield;
        $this->code = $code;
        parent::__construct($msg, $msgLogOnly);
    }
}

class InvalidFloatException extends APIException
{
    public function __construct($dlgfield, $amount, $dec)
    {
        parent::__construct($dlgfield, L("{{L//%AMOUNT% is not a valid number here. Please use only digits and the decimal point %DEC%}}",
                                         [ '%AMOUNT%' => Format::UTF8Quote($amount),
                                           '%DEC%' => Format::UTF8Quote($dec),
                                         ]));

    }
}

/**
 *  Handy helper class to quickly say that the user isn't supposed to be here.
 */
class NotAuthorizedException extends DrnException
{
    public function __construct()
    {
        parent::__construct(L('{{L//You are not authorized to make this request}}'));
    }
}

/**
 *  Handy helper class to quickly say that the user isn't supposed to be here.
 */
class NotAuthorizedCreateException extends DrnException
{
    public function __construct()
    {
        parent::__construct(L('{{L//You do not have permission to create this kind of ticket}}'));
    }
}

/**
 *  Message to be thrown when user tries to access a single invalid ticket ID.
 */
class BadTicketIDException extends DrnException
{
    public function __construct($idTicket)
    {
        parent::__construct(L('{{L//Either #%TICKET% is not a valid ticket ID, or you do not have permission to view that ticket}}',
                              [ '%TICKET%' => $idTicket ]));
    }
}


/********************************************************************
 *
 *  Miscellaneous helper functions
 *
 ********************************************************************/

/**
 *  Returns the time taken since the given timestamp.
 *
 *  Usage:
 *
 *      $time1 = microtime(true);
 *
 *      ... do stuff
 *
 *      $timeTaken = timeTaken($time1);
 */
function timeTaken($time1)
{
    $time2 = microtime(true);
    $time = $time2 - $time1;
    if ($time < 0.0001)
        return "<0.0001";
    return number_format($time, 4);
}

/**
 *  Initializes the member variables of a newly created object.
 *
 *  This reduces the typical constructor boilerplate code.
 *
 *  The following types are supported in $aTypes:
 *
 *   -- BOOLEAN: ensures the value is a boolean TRUE or FALSE
 *
 *   -- INT: casts the value to (int)
 */
function initObject($o,                 //!< in: object to initialize
                    $aArgNames,         //!< in: array of strings with the names of the object's member variables without '$' (e.g. [ 'var1', 'var2' ] )
                    $aArgs,             //!< in: array of variables in the same order; use PHP's func_get_args() to retrieve the args of the current function as an array
                    $aTypes = NULL)     //!< in: array of types; for every arg name in $aArgNames, it can specify a type
{
    if (count($aArgNames) != count($aArgs))
        throw new DrnException("Internal error: array mismatch in initObject -- argNames is ".count($aArgNames).", args is ".count($aArgs));

    for ($i = 0;
         $i < count($aArgNames);
         ++$i)
    {
        $name = $aArgNames[$i];
        $val = $aArgs[$i];

        if (    $aTypes
             && ($type = getArrayItem($aTypes, $name))
           )
        {
            switch ($type)
            {
                case 'BOOLEAN':
                    # Can't use switch have to use === explicitly
                    if (    ($val === 'f')
                         || ($val === FALSE)
                         || ($val === 0)
                       )
                        $val = (boolean)FALSE;
                    else
                        $val = (boolean)TRUE;
                break;

                case 'INT':
                    $val = (int)$val;
                break;

                case 'STRING':
                case 'HIDDEN':
                break;

                default:
                    throw new DrnException("Internal error: invalid type \"$type\" for argument \"$name\" in array");
            }
        }
        $o->$name = $val;
    }
}

/* Courtesy of https://stackoverflow.com/questions/834303/startswith-and-endswith-functions-in-php */
function startsWith($haystack, $needle)
{
    return substr($haystack,
                  0,
                  strlen($needle))
       === $needle;
}

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    if ($length == 0)
        return true;

    return (substr($haystack, -$length) === $needle);
}

/*
 *
 */
function isInteger($v)
{
    if (is_object($v))
        throw new DrnException("Internal error: object passed to isInteger() test");
    if (is_array($v))
        throw new DrnException("Internal error: array passed to isInteger() test");

    if (preg_match('/^-?[0-9]+$/', $v))
        return TRUE;

    return FALSE;
}

/*
 *  Asserts that $v is an integer >= 0.
 */
function isPositiveInteger($v)
{
    if (preg_match('/^[0-9]+$/', $v))
        return TRUE;

    return FALSE;
}

/*
 *
 */
function isSimpleNumber($v)
{
    if (preg_match('/^-?[.0-9]+$/', $v))
        return TRUE;

    return FALSE;
}

/**
 *  Returns TRUE or FALSE depending on the value of $v, or throws if
 *  $v is not a meaningful boolean value.
 *
 */
function parseBoolOrThrow($v)
{
    if (    ($v === TRUE)
         || ($v === 1)
         || ($v === "1")
       )
        return TRUE;

    if (    ($v === FALSE)
         || ($v === 0)
         || ($v === "0")
       )
        return FALSE;

    switch (strtoupper($v))
    {
        case "TRUE":
        case "ON":
            return TRUE;

        case "FALSE":
        case "OFF":
            return FALSE;
    }

    throw new DrnException("Invalid boolean value $v");
}

/**
 *  Assumes $str is a comma-separated list of integer values, validates it and
 *  returns it as a flat list of ints.
 *
 *  If $throwMsg is not NULL, this throws an exception if one of the values is not an
 *  integer.
 */
function explodeCommaIntList($str,
                             $throwMsg = "Value is not an integer")
{
    $a = [];
    foreach (explode(',', $str) as $int)
        if (isInteger($int))
            $a[] = (int)$int;
        else if ($throwMsg)
            throw new DrnException($throwMsg);
    return (count($a)) ? $a : NULL;
}

/**
 *  Assumes that $a is a PHP array of integers, validates and JSON-encodes them.
 *
 *  Throws if a value is not an int.
 */
function jsonEncodeIntsArray($a)
{
    if (!is_array($a))
        throw new DrnException("Expected array in ".__FUNCTION__);
    $a2 = [];
    foreach ($a as $i)
        if (isInteger($i))
            $a2[] = (int)$i;
        else
            throw new DrnException("Invalid integer in array");

    return json_encode($a2);
}

/**
 *  Helper for the usual annoying sequence if (isset($array[$key])) { $a = $array[$key] } else { $a = NULL }.
 */
function getArrayItem($array,
                      $key,
                      $default = NULL)
{
    if (!is_array($array))
        throw new DrnException("Internal error: invalid array given");
    if (is_array($key))
        throw new DrnException("Internal error: another array given as key to array");
    if (isset($array[$key]))
        return $array[$key];
    return $default;
}

/**
 *  Returns the given array item converted to int, or throws if it is not set.
 */
function getArrayItemIntOrThrow($array,
                                $key,
                                $except)
{
    $v = getArrayItem($array, $key);
    if ($v === NULL)
        throw new DrnException($except);
    return (int)$v;
}

/**
 *  Returns TRUE if the two arrays have the same values. The keys are ignored.
 *
 *  Courtesy of https://stackoverflow.com/questions/901815/php-compare-array.
 *
 *  ```php
        array_equal_values([1], []);            # FALSE
        array_equal_values([], [1]);            # FALSE
        array_equal_values(['1'], [1]);         # TRUE
        array_equal_values(['1'], [1, 1, '1']); # TRUE
 *  ```
 *
 */
function arrayEqualValues(array $a, array $b)
{
    return !array_diff($a, $b) && !array_diff($b, $a);
}

/**
 *  Helper that makes sure $data is part of a second-level array at $a[$key].
 *
 *  If $a[$key] is not set, this does $a[$key] = [ $data ].
 *
 *  Otherwise this adds $data to the array at $a[$key].
 */
function arrayMakeOrPush(&$a, $key, $data)
{
    if (!isset($a[$key]))
        $a[$key] = [ $data ];
    else
        $a[$key][] = $data;
}

/**
 *  Returns a copy of $ll with the key/value pairs whose value is boolean-equal to FALSE removed.
 *  That includes NULL and zerovalues and empty strings.
 */
function arrayCompact($ll)
{
    $ll2 = [];
    foreach ($ll as $key => $value)
        if ($value)
            $ll2[$key] = $value;

    return $ll2;
}

/**
 *  Merges $b onto $a. This uses array_merge, which, as opposed to the "+" operator,
 *  doesn't overwrite numeric indices.
 */
function arrayRealMerge(&$a, $b)
{
    $a = array_merge($a, $b);
}

/*
 *  Escapes HTML characters to prevent cross-site scripting attacks. Use this always before
 *  echoing HTML. As a convenience, if $enclose is specified, it is considered an HTML tag
 *  and the whole string is also enclosed in such opening and closing tags.
 */
function toHTML($string,
                $enclose = NULL,
                $maxlen = NULL)
{
    if (is_array($string))
        throw new DrnException("Internal error: an array was passed to toHTML() instead of a string");

    $html = '';
    $fEllipse = NULL;

    if ($maxlen)
        if (strlen($string) > $maxlen)
        {
            $string = substr($string, 0, $maxlen);
            $fEllipse = TRUE;
        }

    if ($enclose)
        $html = "<$enclose>";
    $html .= htmlspecialchars($string);
    if ($fEllipse)
        $html .= Format::HELLIP;
    if ($enclose)
        $html .= "</$enclose>";
    return $html;
}

/*
 *  Use this instead of die() to display fatal error messages.
 *  Best however is to use exceptions instead to get useful stack traces.
 */
function myDie($htmlMessage)            # in: message (MUST have been HTML-escaped)
{
    if (php_sapi_name() == 'cli')
    {
        echo "ERROR: $htmlMessage\n";
        exit(2);
    }

    $htmlError = L('{{L//Error}}');

    WholePage::EmitHeader($htmlError);

    echo <<<EOD
  <div class="container">
    <div class="jumbotron">
      <h1 class="bg-danger">$htmlError</h1>
      <p>$htmlMessage</p>
    </div>
  </div>

EOD;

    WholePage::EmitFooter();
    die();
}

/*
 *  Looks into the global GET and POST data ($_REQUEST superglobal) and returns
 *  the argument value for the given key $name, or NULL if not found. Does not
 *  fail.
 */

function getRequestArg($name)
{
    return (isset($_REQUEST[$name]) ? $_REQUEST[$name] : NULL);
}

function getRequestArgOrDie($name)
{
    $val = getRequestArg($name)
        or myDie("Missing required argument \"".toHTML($name)."\"");
    return $val;
}

function replaceMany(&$text,
                     $aReplacements)
{
    if ($aReplacements)
        foreach ($aReplacements as $find => $replace)
            $text = str_replace($find, $replace, $text);
}

function replaceSpecials($text)
{
    $opquo = DrnLocale::GetItem(DrnLocale::OPENQUOTE);
    $clquo = DrnLocale::GetItem(DrnLocale::CLOSEQUOTE);
    $aFind = [ '%DOREEN%',            '%NBSP%',      '%HELLIP%',      '%LDQUO%',  '%RDQUO%' ];
    $aRepl = [ Globals::$doreenName,  Format::NBSP,  Format::HELLIP,  $opquo,     $clquo ];
    $text = str_replace($aFind, $aRepl, $text);

    # %{prettyquotes)% shortcut
    return preg_replace('/%\{([^{]*)\}%/', $opquo.'$1'.$clquo, $text);
}

/*
 *  Localization function, which is also a wrapper around PHP's gettext (_).
 *
 *  See \ref drn_nls for more information.
 *
 *  This function expects HTML as input and outputs HTML. It does not perform
 *  HTML escaping.
 *
 *  The following special replacements are also performed, which can be used for
 *  XML dialog templates where direct HTML entities cannot be used:
 *
 *      --  %NBSP%
 *      --  %HELLIP%
 *      --  %LDQUO%
 *      --  %RDQUO%
 *
 *  The left and right double quotes are replaced according to the user's current
 *  locale, so you'll get different output for English compared to, say, German.
 *  See the DrnLocale class for details.
 *
 *  To save typing, the special %{string}% shortcut can also be used for double-quoting
 *  a string.
 *
 *  There is a second version of this, Ln(), for plurals support, which calls ngettext()
 *  instead of gettext().
 */
function L(string $text = NULL,
           $aReplacements = NULL)
{
    $p0 = 0;
    while ( ($p = strpos($text, '{{L/', $p0)) !== FALSE)
    {
        # <h1>{{l/INSTALL1/Welcome to Doreen, the software that can track almost anything.}}</h1>
        #     ^p          ^p2                                                             ^p3
        if (($p2 = strpos($text, '/', $p + 4)) === FALSE)
            throw new DrnException("Missing '/' in string to be localized");

        if (($p3 = strpos($text, '}}', $p2 + 1)) === FALSE)
            throw new DrnException("Missing '}}' in string to be localized");

//         Debug::Log("lengths: ".($p2 - $p - 4).' -- '.($p3 - $p2 - 1));

        $nls = substr($text, $p2 + 1, $p3 - $p2 - 1);
        $fGivenID = TRUE;
        if (!($id = substr($text, $p + 4, $p2 - $p - 4)))
        {
            $id = $nls;
            $fGivenID = FALSE;
        }

//         Debug::Log("gettext id: \"$id\", fGivenID=$fGivenID");
        $before = substr($text, 0, $p);
        $after = substr($text, $p3 + 2);

        $gettext = gettext($id);

        if (    ($fGivenID)
             && ($gettext == $id)
           )
            # string not found: then gettext returns the ID (and not the NLS)
            $gettext = $nls;

        $text = $before.$gettext.$after;
    }

    if ($aReplacements)
        replaceMany($text, $aReplacements);

    return replaceSpecials($text);
}

/*
 *  Like L(), but for plurals. This calls ngettext() instead of gettext(). Note that
 *  while the English input has two IDs for singular and plural, translations may have
 *  more than two versions (e.g. Slavic languages with singular, dual and plural).
 *
 *  We support replacements as well, but the placeholders and replacements must be
 *  identical in all versions.
 *
 *  Typical usage:
 *
 *       Ln("{{Ln//One item found//%COUNT% items found}}",
             $cTotal,
            [ '%COUNT%' => Format::Number($cTotal) ] );
 */
function Ln(string $text1 = NULL,           //!< in: message ID with strings for English singular and plural
            int $n,                         //!< in: count
            array $aReplacements = NULL)
{
    if (!preg_match('/^\{\{Ln\/\/(.*)\/\/(.*)\}\}$/', $text1, $aMatches))
        throw new DrnException("Invalid localization string ".Format::UTF8Quote($text1));

    $text = ngettext($aMatches[1], $aMatches[2], $n);

    if ($aReplacements)
        replaceMany($text, $aReplacements);

    return replaceSpecials($text);
}
