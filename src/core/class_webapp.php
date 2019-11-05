<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;

use \Netmask\Netmask;

/**
 *  Representation of a request filter as created by WebApp::Get(), WebApp::Put() etc.
 */
class RequestFilter
{
    public $method;           # 'GET', 'POST' etc.
    public $filter;         # /command or /path/command
    public $aArgs;          # list of arguments, if any, each without ':'
    public $aArgTypes;      # list of argument type restrictions, if any (in $arg => $type format), with $type being one of 'int', or ""
    public $fn;

    public function __construct($method,
                                $expr,      # in: must start with '/', additional '/' after that separate parameters
                                $fn)
    {
        $this->method = $method;

        if ($expr[0] !== '/')
            throw new DrnException("RequestFilter::__construct(): \$expr must start with '/')");

        $aParts = explode('/', substr($expr, 1));
        $filter = '/'.array_shift($aParts);
        # remaining elements can be arguments
        foreach ($aParts as $part)
        {
            $aMatches = [];
            if (preg_match('/^:([^:]+):([a-z]+)$/', $part, $aMatches))
            {
                $argname = $aMatches[1];
                $type = $aMatches[2];
                $this->aArgs[] = $argname;
                $this->aArgTypes[$argname] = $type;
//                 Debug::Log("new filter $method \"$expr\" => $argname, $type");
            }
            else if (preg_match('/^:([^:]+)$/', $part, $aMatches))
            {
                $argname = $aMatches[1];
                $this->aArgs[] = $argname;
            }
            else
                $filter .= strtolower("/$part");
        }

        $this->filter = $expr;
        $this->fn = $fn;

        $key = $method.$filter;
//         Debug::Log("storing filter $key");
        if (isset(WebApp::$aFilters[$key]))
            throw new DrnException("Double booking for request filter $key");
        WebApp::$aFilters[$key] = $this;
    }
}

/**
 *  The WebApp class helps with organizing RESTful request handlers. In the main code, e.g. index.php,
 *  you can simply do something like WebApp::Get('/command', function() {...} ); and your callable
 *  will get called when an HTTP GET /command request comes in. Only the first segment of the command
 *  is considered for routing, all other URL segments are parameters to the command.
 *
 *  Note that the WebAppclass is static: it has no instance member variables or methods.
 *
 *  Also note that the WebApp class requires access to the $_SERVER superglobal to parse URLs etc.
 *  As opposed to the Globals class, it can therefore only be used in the web server context and NOT in the CLI.
 *
 *  Using this class, a PHP application will first register its request handlers via the Get() etc. methods
 *  and then call Run(), which will parse the HTTP request and call the appropriate request handler, if found.
 *
 *  All of Doreen's request handlers, both in index.php and in api/api.php, use this functionality.
 *  It also makes it easier for plugins to provide additional request handlers that the code need
 *  not be aware of.
 */
abstract class WebApp
{
    // Based on https://superuser.com/questions/1053845/what-are-the-valid-public-ip-address-ranges#1053854
    const PUBLIC_IPV4_RANGES = [
        // "0.0.0.0/8",
        "10.0.0.0/8",
        "100.64.0.0/10",
        "127.0.0.0/8",
        "169.254.0.0/16",
        "172.16.0.0/12",
        "192.0.0.0/24",
        "192.0.0.0/29",
        "192.0.0.8/32",
        "192.0.0.9/32",
        "192.0.0.170/32",
        "192.0.0.171/32",
        "192.0.2.0/24",
        "192.31.196.0/24",
        "192.52.193.0/24",
        "192.88.99.0/24",
        "192.168.0.0/16",
        "192.175.48.0/24",
        "198.18.0.0/15",
        "198.51.100.0/24",
        "203.0.113.0/24",
        "240.0.0.0/4",
        "255.255.255.255/32",
        "224.0.0.0/24",
        "239.0.0.0/8",
    ];

    public static $aFilters;                # Array of RequestFilter instances that have been registered via get() etc.

    public static $command = '';            # Has the command part of the REST call without arguments, with a leading but not trailing slash, e.g. "/ticket".
    public static $aArgValues = [];

    public static $aResponse        = [ 'status' => 'OK' ];
    public static $httpResponseCode = 200;

    /**
     *  Adds a RequestFilter for an HTTP GET request.
     */
    public static function Get($filter,            //!< in: filter command
                               Callable $fn)       //!< in: code
    {
        new RequestFilter('GET', $filter, $fn);
    }

    /**
     *  Adds a RequestFilter for an HTTP POST request.
     */
    public static function Post($filter,            //!< in: filter command
                                Callable $fn)       //!< in: code
    {
        new RequestFilter('POST', $filter, $fn);
    }

    /**
     *  Adds a RequestFilter for an HTTP PUT request.
     */
    public static function Put($filter,            //!< in: filter command
                               Callable $fn)       //!< in: code
    {
        new RequestFilter('PUT', $filter, $fn);
    }

    /**
     *  Adds a RequestFilter for an HTTP DELETE request.
     */
    public static function Delete($filter,            //!< in: filter command
                                  Callable $fn)       //!< in: code
    {
        new RequestFilter('DELETE', $filter, $fn);
    }

    /**
     *  Returns a true boolean for the given value, which might be 0, 1, "true", "false",
     *  or a boolean.
     */
    private static function Truthify($value)
        : bool
    {
        if (    ($value === 0)
             || ($value === FALSE)
             || (strtoupper($value) === 'FALSE')
             || ($value === '0')
           )
            return FALSE;

        if (    ($value === 1)
             || ($value === TRUE)
             || (strtoupper($value) === 'TRUE')
             || ($value === '1')
           )
            return TRUE;

        throw new DrnException("Invalid parameter: argument ".Format::UTF8Quote($value)." is not a boolean");
    }

    /**
     *  Returns the parameter with the given name. This typically gets called
     *  from within a request handler callable to fetch additional parameters.
     *
     *  This distinguishes between empty parameters (present, but "") and
     *  missing parameters (which are returned as NULL).
     *
     *  If fRequired is TRUE and the argument is missing (but not empty), then
     *  this throws an exception. This should only be used for API errors which
     *  are not likely to be caused by wrong user input (e.g. forgetting to fill
     *  out an input field in a form); in those cases, the caller should rather
     *  handle the problem with a better error message.
     */
    public static function FetchParam($name,                   //!< in: parameter name
                                      $fRequired = TRUE)       //!< in: if TRUE, throw exception if param is missing
    {
        if (isset(self::$aArgValues[$name]))
            return self::$aArgValues[$name];        # can be empty ("")

        if ($fRequired)
            throw new DrnException("Missing argument \"$name\" in request");

        return NULL;
    }

    /**
     *  Like FetchParam(), but returns a bool always, or throws.
     */
    public static function FetchBoolParam($name, $fRequired)
        : bool
    {
        return self::Truthify(self::FetchParam($name, $fRequired));
    }

    /**
     *  Like FetchParam(), but returns a string with at least the given length always, or throws.
     */
    public static function FetchStringParam(string $name,
                                            int $cMinimumLength = 1)
        : string
    {
        $str = self::FetchParam($name);
        if (strlen($str) < $cMinimumLength)
        {
            if ($cMinimumLength == 1)
                throw new APIException($name, L("{{L//This field cannot be left empty.}}"));
            else
                throw new APIException($name, L("{{L//Please enter at least %MIN% characters here}}", [ '%MIN%' => $cMinimumLength ]));
        }
        return $str;
    }

    /**
     *  The application's main entry point. This looks at the current HTTP request,
     *  extracts parameters, calls the appropriate request handler, if found, or
     *  fails with an error if not.
     */
    public static function Run()
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'];        # GET, POST, ...

        # Combining the arguments is a two-step process.
        # All request types can have specifiers in the URI, like PUT ticket/123.
        # We support that with the 'ticket/:id' syntax, which was parsed by RequestFilter.
        # In addition, there can be additional data in the body in JSON format, but
        # not for all request types, but only those that support sending data.

        self::$aArgValues = [];

        self::ParseUrl(Globals::GetRequestOnly(),
                       self::$command,
                       self::$aArgValues);

        # The '/api' bit has already been stripped by the htaccess.
        $aURIArgs = explode('/', self::$command);
        self::$command = '/'.strtolower($aURIArgs[0]);

        switch ($requestMethod)
        {
            case 'GET':
            case 'DELETE':
                # We are not expecting arguments in the body for GET or DELETE
            break;

            case 'POST':
            case 'PUT':
                if ($rawdata = file_get_contents("php://input"))
                    if ($aParams = json_decode($rawdata))
                    {
                        foreach ($aParams as $key2 => $value2)
                            self::$aArgValues[$key2] = $value2;

//                        Debug::Log(0, "aParams: ".print_r($aParams, TRUE));
                    }
            break;

            default:
                throw new DrnException("Request method ".Format::UTF8Quote($requestMethod)." not supported");
            break;
        }

        $key = $requestMethod.self::$command;

        // Do we have a handler for this request?
        if ($oFilter = WebApp::$aFilters[$key] ?? NULL)
        {
            $c = 1;
            if (is_array($oFilter->aArgs))
                foreach ($oFilter->aArgs as $argname)
                {
                    $value = $aURIArgs[$c] ?? NULL;

                    if (    ($value === NULL)
                         || (!strlen($value))
                       )
                    {
                        $cOpen = DrnLocale::GetItem(DrnLocale::OPENQUOTE);
                        $cClose = DrnLocale::GetItem(DrnLocale::CLOSEQUOTE);
                        throw new DrnException("Missing parameter: command ".Format::UTF8Quote(self::$command)." requires argument(s) $cOpen".implode("$cClose, $cOpen", $oFilter->aArgs).$cClose);
                    }

                    if (isset($oFilter->aArgTypes[$argname]))
                    {
                        switch ($requiredType = $oFilter->aArgTypes[$argname])
                        {
                            case 'int':
                                if (!(preg_match('/^-?[0-9]+$/', $value)))
                                    throw new DrnException("Invalid parameter: argument ".Format::UTF8Quote($value)." is not an integer");
                            break;

                            case 'bool':
                                $value = self::Truthify($value);
                            break;

                            default:
                                throw new DrnException("Internal error: invalid arg type ".Format::UTF8Quote($requiredType)." in request spec ".Format::UTF8Quote($argname));
                            break;
                        }

                    }
                    self::$aArgValues[$argname] = $value;
                    ++$c;
                }

            /*
             * Call the request handler!
             */
            Debug::FuncEnter(Debug::FL_URLHANDLERS, "request handler for $requestMethod ".self::$command);
            $fn = $oFilter->fn;
            $fn();
            Debug::FuncLeave();
        }
        else
        {
            $withoutExt = preg_replace('/\\.[^.\\s]{3,4}$/', '', Globals::$entryFile);
            throw new DrnException("Invalid $requestMethod request ".Format::UTF8Quote("/$withoutExt".self::$command)."");
        }
    }

    /**
     *  Returns TRUE if the server has been contacted through HTTPS since $_SERVER['HTTPS'] is pretty odd.
     *  Courtesy of http://stackoverflow.com/questions/1175096/how-to-find-out-if-youre-using-https-without-serverhttps#2886224
     *
     * @return bool
     */
    public static function isHTTPS()
    {
        return     ( !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' )
                || $_SERVER['SERVER_PORT'] == 443;
    }

    /**
     *  Forces a client-side reload of the page by emitting a Location HTTP header. Does not return.
     *
     *  $target can be NULL for the main page or any GUI GET command after that.
     *  http(s):// and Globals::$rootpage is prepended automatically.
     */
    public static function Reload($target = NULL)
    {
        $loc = self::isHTTPS() ? 'https://' : 'http://';
        $loc .= $_SERVER['HTTP_HOST'].Globals::$rootpage.'/'.$target;
        header('Location: '.$loc);
        exit;
    }

    /**
     *  Invokes a command-line instance of PHP with Doreen's cli.php and the parameters from the given array.
     *
     *  The given session ID is stored in self::$aJSON['session_id']. As a result, if this gets called
     *  in response from a REST API call, the session API is automatically returned from he call as JSON.
     *
     *  This calls LongTask::Launch() in turn.
     */
    public static function SpawnCli($description,          //!< in: description string (only appears in database for debugging, not used otherwise)
                                    $aArgs,                //!< in: array of command line parameters to add to "php cli.php" command
                                    $forceSingletonDescription = NULL)
    {
        $oLongTask = LongTask::Launch($description,
                                      $aArgs,
                                      $forceSingletonDescription);

        # Return the session ID to the caller of the current JSON API, if any.
        WebApp::$aResponse['session_id'] = $oLongTask->idSession;
    }


    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Produces an HTTP or HTTPS link to the given link tail, taking current
     *  server settings into account.
     *
     *  Must not be called in CLI context because $_SERVER is not well defined then.
     */
    public static function MakeUrl($urlTail)      //!< in: specific link part, MUST START WITH '/'
    {
        if (php_sapi_name() == 'cli')
            return 'https://'.GlobalConfig::Get('hostname').Globals::$rootpage.$urlTail;

        $server = $_SERVER['SERVER_NAME'];
        # Use https only if we're running on https. (Otherwise testing on localhost breaks.)
        $s = ($_SERVER['HTTPS'] ?? NULL) ? 's' : '';
        return "http$s://$server".Globals::$rootpage.$urlTail;
    }

    /**
     *  Produces a Doreen GUI link with the given parameters.
     */
    public static function MakeLinkWithArgs(string $command,        //!< in: e.g. /tickets or /api/tickets
                                            array $aArgs)           //!< in: array of string parameters, e.g. [ 'sortby' => 'score' ]
    {
        $llParticles = [];
        foreach ($aArgs as $key => $value)
            $llParticles[] = "$key=".urlencode($value);

        $tail = $command;
        if (count($llParticles))
            $tail .= '?'.implode('&', $llParticles);

        return self::MakeUrl($tail);
    }

    /**
     *  Parses the given URL string and splits its arguments into $aArgs as key => value pairs.
     *
     *  Sort of like the built-in PHP function parse_str() but I don't trust that one.
     *  It is said to decode to an array but it seems to create an object instead when the
     *  argument contains multiple values. Also I'm not sure whether it uses urldecode and
     *  what other side effects it has.
     */
    public static function ParseUrl($url,               //!< in: complete URL
                                    &$base,             //!< out: part before '?'
                                    &$aArgs)            //!< out: components after '?', as split by '&'
    {
        $aArgs = [];
        if (preg_match('/(.*)\?(.*)/', $url, $aMatches))
        {
            $base = $aMatches[1];

            foreach (explode('&', $aMatches[2]) as $arg)
                if (preg_match('/(.*)=(.*)/', $arg, $aMatches2))
                {
                    $param = $aMatches2[1];
                    if (isset($aArgs[$param]))
                        throw new DrnException("Duplicate argument value for key '$param'");
                    $aArgs[$param] = urldecode($aMatches2[2]);
                }
        }
        else
            $base = $url;
    }

    /**
     *  Check if the current request comes from an IP in the public range,
     *  meaning from the local network.
     */
    public static function IsFromPublicIp()
        : bool
    {
        //TODO ipv6?
        // If this code should ever run behind a reverse proxy, conditionally
        // set the ip to $_SERVER['HTTP_X_FORWARDED_FOR']. This is not enabled
        // by default since any client could else fake its IP.
//        $ip = $_SERVER['REMOTE_ADDR'];
//        foreach (self::PUBLIC_IPV4_RANGES as $netmask)
//        {
//            $block = new Netmask($netmask);
//            if ($block->contains($ip))
//                return TRUE;
//        }
        return FALSE;
    }
}
