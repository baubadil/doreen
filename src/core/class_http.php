<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  Represents a single HTTP query via cURL.
 *
 *  Pass the URL (and optionally the HTTP method) in the constructor and then run exec().
 *  Optionally, before exec(), you can set other HTTP parameters by calling additional methods.
 */
class HTTP
{
    public $ch;

    private $method;
    private $aHeaders = [];

    const POST = 1;
    const GET = 2;
    const PUT = 3;
    const DELETE = 4;

    /**
     *  Constructor. Expects the URL and the HTTP method, which defaults to POST.
     */
    public function __construct($url,
                                $method = self::POST)
    {
        $this->ch = curl_init($url);
        $this->method = $method;

        switch ($method)
        {
            case self::POST:
                curl_setopt($this->ch, CURLOPT_POST, TRUE);
            break;

            case self::PUT:
                curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, "PUT");
            break;

            case self::DELETE:
                curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
        }
    }

    public function __destruct()
    {
        if ($this->ch)
        {
            curl_close($this->ch);
            $this->ch = NULL;
        }
    }

    /**
     *  Sets a request body. If the given string starts with '<?xml', the
     *  'Content-Type' HTTP header is set to 'text/xml' automatically.
     *
     *  Optional; to be called in between constructing the object and exec().
     *
     *  This is useful, for example, for POST requests that expect request data
     *  in the body.
     */
    public function body($body)
    {
        if (    ($this->method == self::POST)
             || ($this->method == self::PUT)
             || ($this->method == self::GET)
           )
        {
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $body);      # works for GET and PUT as well
            if (substr($body, 0, 6) === '<?xml ')
                $this->aHeaders[] = 'Content-Type: text/xml';
        }
    }

    /**
     *  Executes the command that has been piling up in the member variables.
     *
     *  This also automatically collects cookies from the response data and stores
     *  them in $g_aEVCookies.
     *
     *  If $pfnErrorCallback is given, it gets called on CURL errors. Otherwise
     *  an exception is thrown.
     */
    public function exec(Callable $pfnErrorCallback = NULL)
    {
        global  $g_aEVCookies;

        # Now finally set the HTTP header fields, and then we'll set more specific fields below.
        if (count($this->aHeaders))
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->aHeaders);

        curl_setopt($this->ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.3; Trident/7.0; rv:11.0) like Gecko");

        $aCookieStrings = [];
        if (is_array($g_aEVCookies))
        {
            foreach ($g_aEVCookies as $key => $value)
                $aCookieStrings[] = "$key=$value";

            curl_setopt($this->ch, CURLOPT_COOKIE, implode('; ', $aCookieStrings));
        }

        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, TRUE);

        curl_setopt($this->ch, CURLOPT_HEADER, TRUE);
        curl_setopt($this->ch, CURLINFO_HEADER_OUT, TRUE);

        #
        # GO!
        #
        $res = curl_exec($this->ch);

        if ($res === FALSE)
            if ($pfnErrorCallback)
                $pfnErrorCallback($this->ch);
            else
            {
                $errno = curl_errno($this->ch);
                throw new DrnCurlException($errno, "curl error $errno: ".curl_error($this->ch));
            }

        # Parse the HTTP headers until we get an empty line.
        $res2 = '';
        $state = 0;
        $fIgnoreNextEmptyLine = FALSE;
        foreach (preg_split("/((\r?\n)|(\r\n?))/", $res) as $line)
        {
//            Debug::Log(0, "curl line: $line");
            if ($state == 0)
            {
                if (preg_match("/Set-Cookie: ([^=]+)=([^;]+); path=(.*)/i", $line, $aMatches))
                    $g_aEVCookies[$aMatches[1]] = $aMatches[2];
                else if (preg_match("/HTTP.1\.1 100 Continue/", $line))
                {
                    $fIgnoreNextEmptyLine = TRUE;
                }
                else if (!strlen($line))
                {
                    if (!$fIgnoreNextEmptyLine)
                        $state = 1;
                    $fIgnoreNextEmptyLine = FALSE;
                }
            }
            else if ($state == 1)
            {
                if ($res2)
                    $res2 .= "\n";
                $res2 .= $line;
            }
        }

//         echo "curl: ".print_r(curl_getinfo($this->ch), TRUE);

        curl_close($this->ch);
        $this->ch = NULL;

        return $res2;
    }

    /**
     *  Returns a string describing the given HTTP::POST etc. constant.
     */
    public static function DescribeMethod($method)
    {
        switch ($method)
        {
            case HTTP::POST:
                return 'POST';

            case HTTP::GET:
                return 'GET';

            case HTTP::DELETE:
                return 'DELETE';

            case HTTP::PUT:
                return 'PUT';
        }

        return '?!?!?';
    }
}


