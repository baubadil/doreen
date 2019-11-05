<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Login base class
 *
 ********************************************************************/

/**
 *  Class that manages logins. This works together with the User class, which contains the login names
 *  and (salted & encrypted) passwords, of which only the Authenticate() function is called.
 *
 *  The LoginSession involves some cooperation between PHP sessions and possibly cookies. The main
 *  entry points are:
 *
 *   -- \ref Login() gets called via the GUI login form and creates login data in the current PHP
 *      session; optionally it sets a cookie.
 *
 *   -- \ref Logout() is the reverse, for when the user requests a logout explicitly.
 *
 *   -- \ref TryRestore() gets called most frequently, when neither an explicit login nor an explicit
 *      logout are requested. We then try to restore the current user from either the session data
 *      or, if that has expired, from a cookie.
 */
class LoginSession
{
    const COOKIE_CONSENT = 'CookieConsent';
    const COOKIE_DOREEN  = 'doreen';

    # Currently logged in user (as User object) or NULL.
    /** @var User */
    public static $ouserCurrent = NULL;

    # When impersonating, $ouserCurrent has the regular user who is being impersonated, and this has the UID of the admin.
    private static $uidImpersonator = NULL;

    /**
     *  Performes the login. This is the main entry point and gets called from the login form.
     *
     *  If successful, sets Globals::$ouserCurrent and stores the user in the session.
     *  $fSetCookie is TRUE if the user selected "remember me"; if so, we set a cookie in addition to the session data.
     *
     *  This returns TRUE if User::Authenticate succeeded, FALSE otherwise.
     */
    public static function Create($login,                //!< in: login name (must be matched case-insensitively)
                                  $plainPassword,        //!< in: plain-text password as entered by the user
                                  $fSetCookie)           //!< in: if TRUE, we remember the user in a cookie
        : bool
    {
        if (!( self::$ouserCurrent = User::Authenticate($login,
                                                        $plainPassword,
                                                        true)))          # password is plaintext
        {
            self::Log($login, NULL, $plainPassword);
            return FALSE;
        }

        self::StoreInSession(self::$ouserCurrent,
                             $fSetCookie
                                 ? Globals::$cookieLifetimeDays
                                 : 0);

        self::SendUserToken(true);

        self::Log($login,
                  self::$ouserCurrent->uid,
                  NULL);        # do not log valid passwords
        Debug::Log(0, "User $login logged in successfully");
        return TRUE;
    }

    /**
     *  Unsets Globals::$ouserCurrent and kills the cookie. This gets called when the user
     *  explicitly requests to be logged out.
     *
     *  Normally this gets called before TryRestore() was called so oUserCurrent is probably not even set,
     *  but this handles all cases.
     */
    public static function LogOut()
    {
        self::$ouserCurrent = NULL;
        self::StoreInSession(NULL, NULL);     # logout
    }


    const JWT_USER_LOGIN = 'log';
    const CLAIM_LOGIN = 'drl';
    const CLAIM_PASSWORD = 'drw';

    private static function RegisterLoginToken()
    {
        JWT::RegisterType(self::JWT_USER_LOGIN,
                          function() {},
                          [
                              self::CLAIM_LOGIN,
                              self::CLAIM_PASSWORD
                          ]);
    }

    /**
     *  Looks in session data and for a cookie if a user is already logged in; if so, sets
     *  Globals::$ouserCurrent again accordingly.
     *
     *  This closes the session to avoid PHP deadlocks. No more writing to session data after this!
     */
    public static function TryRestore()
    {
        try
        {
            if (!self::TryRestoreFromSession())
            {
                # Do we have it in the cookie?
                if (($cookiedata = @$_COOKIE[self::COOKIE_DOREEN]) && JWT::IsInited())
                {
                    self::RegisterLoginToken();
                    $token = JWT::VerifyToken($_COOKIE[self::COOKIE_DOREEN], self::JWT_USER_LOGIN);
                    self::$ouserCurrent = User::Authenticate($token->getClaim(self::CLAIM_LOGIN),
                                                             $token->getClaim(self::CLAIM_PASSWORD),
                                                             false);         # password is encrypted

                    if ($token->getClaim(JWT::CLAIM_SUBJECT) !== self::$ouserCurrent->uid)
                        throw new DrnException("Token was not issued for restored user");

                    # This may have returned NULL if the cookie was invalid.
                    # So, either store the good object in the cookie, or delete the cookie.
                    self::StoreInSession(self::$ouserCurrent,
                                         Globals::$cookieLifetimeDays);     # set cookie
                    self::SendUserToken(true);
                }

                # Unlock the session. No more writing to session data after this!
                session_write_close();  # see http://konrness.com/php5/how-to-prevent-blocking-php-requests/
            }
        }
        catch(\Exception $e)
        {
            // Reset cookies and reload.
            self::KillCookies();
            Debug::Log(0, "Error while reading doreen cookie: ".$e->getMessage());
            WebApp::Reload();
        }
    }

    const CLAIM_IMPERSONATOR = 'dri';
    const CLAIM_SESSION = 'drs';

    /**
     *  Checks if the request contains a valid API access token. Sets ouserCurrent
     *  and ouserImpersonator and restores the session for API tokens from the
     *  frontend. Locks the session afterward.
     *
     *  @return bool
     */
    public static function AuthenticateJWT()
        : bool
    {
        try
        {
            $rawToken = JWT::GetFromHeader();
            $token = JWT::VerifyToken($rawToken,
                                      JWT::TYPE_USER_GRANT,
                                      JWT::API_AUDIENCE);
        }
        catch(DrnException $e)
        {
            return FALSE;
        }

        if ($token->hasClaim(self::CLAIM_SESSION))
        {
            session_id($token->getClaim(self::CLAIM_SESSION));
            session_start();
            Debug::Log(0, 'Session '.session_id().' restored from API access token');
            self::$ouserCurrent = User::LoadFromSession();
        }
        else
            self::$ouserCurrent = User::Find($token->getClaim(JWT::CLAIM_SUBJECT));

        if ($token->hasClaim(self::CLAIM_IMPERSONATOR))
            self::$uidImpersonator = (int)$token->getClaim(self::CLAIM_IMPERSONATOR);

        if ($token->hasClaim(JWT::DRN_PARENT_ID))
            Debug::Log(0, 'Access authorized for token generated from '.$token->getClaim(JWT::DRN_PARENT_ID));

        // Lock the session. No more writing to session data after this!
        session_write_close(); // see http://konrness.com/php5/how-to-prevent-blocking-php-requests/

        return isset(self::$ouserCurrent);
    }

    /**
     *  Gets the life time a session has with the current configuration.
     *
     *  @return int
     */
    public static function GetSessionLifetime()
        : int
    {
        $lifetime = (ini_get('session.gc_maxlifetime') ?? 1440);
        $meta = session_get_cookie_params();
        if ($meta['lifetime'] > 0 && $meta['lifetime'] < $lifetime)
            $lifetime = $meta['lifetime'];
        return (int)$lifetime;
    }

    /**
     *  Generates an API access token for the current user for the frontend,
     *  including a reference to the current session.
     *
     *  @return string
     */
    private static function GetToken(int $uid, $data = [])
        : string
    {
        if (!($frontendToken = GlobalConfig::Get(GlobalConfig::KEY_JWT_FRONTEND_TOKEN)))
            return '';

        // Save session id so we can modify the current session from API calls.
        if (isset($_SESSION))
            $data[self::CLAIM_SESSION] = session_id();

        // Get assumed session lifetime.
        $lifetime = self::GetSessionLifetime();

        $token = JWT::GetUserAuth($frontendToken,
                                  $uid,
                                  $lifetime,
                                  $data);

        return (string)$token;
    }

    /**
     *  Generates an API access token for impersonating users before entering
     *  impersonated state (ouserCurrent is still the impersonator).
     *
     *  @return string
     */
    private static function GetImpersonatorToken(int $uid, $data = [])
        : string
    {
        $data[self::CLAIM_IMPERSONATOR] = self::$ouserCurrent->uid;
        return self::GetToken($uid, $data);
    }

    /**
     *  Set the API access token cookie for the front end when needed.
     */
    private static function SendUserToken(bool $fForce = false)
    {
        if (IS_API)
            throw new DrnException("Can only send token in frontend");

        if (!JWT::IsInited())
            return;

        if (isset($_COOKIE['drn-jwt']) && !$fForce)
        {
            try {
                $token = JWT::VerifyToken($_COOKIE['drn-jwt'],
                                          JWT::TYPE_USER_GRANT,
                                          JWT::API_AUDIENCE);
                if (   !empty($token)
                    && (   !$token->hasClaim('exp')
                        || $token->getClaim('exp') > time())
                    && (   !isset($_SESSION)
                        || !$token->hasClaim(self::CLAIM_SESSION)
                        || $token->getClaim(self::CLAIM_SESSION) === session_id())
                    && (   !$token->hasClaim(JWT::CLAIM_ISSUER)
                        || $token->getClaim(JWT::CLAIM_ISSUER) == Globals::GetHostname().Globals::$rootpage))
                    return;
            }
            catch(DrnException $e)
            {
                // carry on, we need a new token!
            }
        }

        $data = [];
        $uid = self::$ouserCurrent->uid;
        if (self::IsImpersonating())
            $data[self::CLAIM_IMPERSONATOR] = self::$uidImpersonator;

        $token = self::GetToken($uid, $data);

        //TODO properly set cookie domain?
        $meta = session_get_cookie_params();
        $cookieLifetime = $meta['lifetime'];
        if ($cookieLifetime != 0)
            $cookieLifetime += time();

        setcookie('drn-jwt',
                  (string)$token,
                  $cookieLifetime,
                  Globals::$rootpage.'/',
                  '',
                  self::SecureCookie(),
                  FALSE); // Needs to be read by our JS API client.
    }

    /**
     *  Stores the given user in the global session data and, if $cookieLifetimeDays != 0,
     *  also as a cookie.
     *
     *  If $oUser is NULL, deletes that data accordingly.
     */
    private static function StoreInSession(User $oUser = NULL,
                                           $cookieLifetimeDays)
    {
        if ($oUser)
        {
            $oUser->writeToSession();

            if ($cookieLifetimeDays)
            {
                /* bool setcookie ( string $name
                                 [, string $value = ""
                                 [, int $expire = 0
                                 [, string $path = ""
                                 [, string $domain = ""
                                 [, bool $secure = false
                                 [, bool $httponly = false ]]]]]] ) */
                $ttl = 3600 * 24 * $cookieLifetimeDays;
                $expire = time() + $ttl;
                $path = Globals::$rootpage.'/';
                $domain = '';
                $fHTTPSOnly = self::SecureCookie();
                $fHTTPNotJavaScript = TRUE;
                self::RegisterLoginToken();
                // Using a JWT here means that this can not be mis-used for login attempts
                // It does not protect the encrypted password, however.
                $token = JWT::GetToken($oUser->uid,
                                       self::JWT_USER_LOGIN,
                                       '',
                                       $ttl,
                                       [ self::CLAIM_LOGIN => $oUser->login,
                                         self::CLAIM_PASSWORD => $oUser->cryptPassword ] );
                setcookie(self::COOKIE_DOREEN,
                          $token,
                          $expire,
                          $path,
                          $domain,
                          $fHTTPSOnly,
                          $fHTTPNotJavaScript);
            }
        }
        else
        {
            session_destroy();
            self::KillCookies();
        }
    }

    /**
     *  Tries to restore self::$ouserCurrent from the session data. Calls session_write_close on success!
     */
    public static function TryRestoreFromSession()
        : bool
    {
        if (self::$ouserCurrent = User::LoadFromSession())
        {
            self::$uidImpersonator = getArrayItem($_SESSION, 'uidImpersonator');
            self::SendUserToken();

            # Unlock the session. No more writing to session data after this!
            session_write_close();  # see http://konrness.com/php5/how-to-prevent-blocking-php-requests/

            return TRUE;
        }

        // Destroy the JWT cookie if the session is no longer active.
        self::KillCookie('drn-jwt');

        return FALSE;
    }

    private static function KillCookie(string $key)
    {
        if (isset($_COOKIE[$key]))
            # to delete a cookie, also set a time in the past
            setcookie($key, "", time() - 3600);
    }

    public static function KillCookies()
    {
        foreach (array('doreen-login', 'doreen-password', 'data', 'doreen', 'drn-jwt') as $key)
            self::KillCookie($key);
    }

    /**
     *  Implements impersonating another user. This works only if the current user is an administrator.
     *
     *  Impersonation operates on session data only; we do not modify the user's cookie. If the user
     *  has selected "remember me" when logging in as the administrator, the cookie data will still
     *  represent the admin account.
     *
     *  As a special case, if $oUser === NULL and an impersonation is currently active, we stop
     *  impersonating and restore the admin account.
     *
     *  Returns token to impersonate user or empty string, when ending impersonating.
     */
    public static function Impersonate(User $oUser = NULL)
        : string
    {
        if ($oUser === NULL)
        {
            if (!($ouserImpersonator = self::GetImpersonator()))
                throw new DrnException("Cannot stop impersonating when no such thing is in progress");

            if (isset($_SESSION))
            {
                // re-open session
                @session_start();
                unset($_SESSION['uidImpersonator']);
                self::StoreInSession($ouserImpersonator,
                                     NULL);
                @session_write_close();
            }
            return '';
        }
        else
        {
            if (!self::IsCurrentUserAdmin())
                throw new NotAuthorizedException;

            if (!$oUser->canBeImpersonated())
                throw new APIException('uid', "Cannot impersonate user ID $oUser->uid");


            $data = [];
            if (isset($_SESSION))
            {
                // re-open session
                @session_start();
                $_SESSION['uidImpersonator'] = self::$ouserCurrent->uid;
                self::StoreInSession($oUser,
                                     NULL);     // do not set cookie
                @session_write_close();
            }

            //TODO log
            Debug::Log(0, "Impersonating UID $oUser->uid");

            return self::GetImpersonatorToken($oUser->uid);
        }
    }

    /**
     *  Returns TRUE only if an impersonation is ongoing.
     */
    public static function IsImpersonating()
    {
        if (self::$uidImpersonator)
            return TRUE;
        return FALSE;
    }

    /**
     *  While impersonation is ongoing, the current user is the regular user who is being impersonated,
     *  and only this function returns the User object of the administrator who initiated the impersonation.
     *  Otherwise this returns NULL.
     *
     * @return User|null
     */
    public static function GetImpersonator()
    {
        if (self::$uidImpersonator)
            return User::Find(self::$uidImpersonator);

        return NULL;
    }

    /*
     *  Called from Create() to log login attempts. If the login was sucessful,
     *  $plainPassword is NULL because we don't want to log correct passwords.
     *  For failed attempts, however, we log them so we can see if a script
     *  kiddie tries lots of them.
     */
    private static function Log($login,
                                $uid,
                                $plainPassword)
    {
        /*  Only log if the database is current. We don't want to have an update
            ready and then the admin can't log in to install it. */
        if (GlobalConfig::$installStatus == GlobalConfig::STATUS99_ALL_GOOD)
        {
            $login2 = substr($login, 0, LEN_LOGINNAME - 1);
            $dtNow = Globals::Now();
            Database::DefaultExec(<<<EOD
INSERT INTO logins_log ( login,    uid,   failed_pass,     dt ) VALUES
                       ( $1,       $2,    $3,              $4 )
EOD
                     , [ $login2,  $uid,  $plainPassword,  $dtNow ] );
        }
    }

    const CURRENT_USER_IS_ADMIN = 2;
    const CURRENT_USER_IS_GURU = 1;
    const CURRENT_USER_IS_EDITOR = -1;

    /**
     *  Returns a User instace if a user is currently logged in (i.e. is not a guest),
     *  or NULL otherwise
     *
     * @return User | null
     */
    public static function IsUserLoggedIn()
    {
        if (    (!self::$ouserCurrent )
             || ( self::$ouserCurrent->uid == User::GUEST)
           )
            return NULL;

        return self::$ouserCurrent;
    }

    /**
     *  Returns the currently logged in User object or the one
     *  for the guest user. Never returns NULL.
     */
    public static function GetCurrentUserOrGuest()
        : User
    {
        if (self::$ouserCurrent)
            return self::$ouserCurrent;

        return User::Find(User::GUEST);
    }

    /**
     *  Returns TRUE if the current user is a member of the "Administrators" group.
     */
    public static function IsCurrentUserAdmin()
        : bool
    {
        if (self::$ouserCurrent)
            if (self::$ouserCurrent->isAdmin())
                return TRUE;
        return FALSE;
    }

    /**
     *  Returns 2 if the current user is a member of the 'Administrators' grup, or 1 if a member of 'Gurus',
     *  or FALSE otherwise.
     */
    public static function IsCurrentUserAdminOrGuru()
        : int
    {
        if (self::$ouserCurrent)
        {
            if (self::$ouserCurrent->isAdmin())
                return self::CURRENT_USER_IS_ADMIN;
            if (self::$ouserCurrent->isGuru())
                return self::CURRENT_USER_IS_GURU;
        }

        return FALSE;
    }

    /**
     *  Returns a value > 0 if the current user is a member of the "Administrators" or "Editors" groups
     *  or FALSE otherwise.
     */
    public static function IsCurrentUserAdminOrEditor()
        : int
    {
        if (self::$ouserCurrent)
        {
            if (self::$ouserCurrent->isAdmin())
                return self::CURRENT_USER_IS_ADMIN;
            if (self::$ouserCurrent->isGuru())
                return self::CURRENT_USER_IS_EDITOR;
        }

        return FALSE;
    }

    public static function CanSeeTicketDebugInfo()
        : bool
    {
        return    self::IsCurrentUserAdmin()
               || self::IsImpersonating();
    }

    /**
     *  Returns the date format to use in the current session. Defaults to relative
     *  dates.
     */
    public static function GetUserDateFormat()
        : int
    {
        if ( LoginSession::$ouserCurrent )
            return LoginSession::$ouserCurrent->getExtraValue(User::DATEFORMAT) ?? User::DATEFORMAT_RELATIVE;

        return User::DATEFORMAT_RELATIVE;
    }

    public static $fCookieConsent = FALSE;

    /**
     *  Called from the prologue for all three entry points to secure the
     *  session cookie sent by doreen when not in the command line.
     *
     *  This marks the cookie to not be exposed to JS and if connected via HTTPS,
     *  only to be sent via HTTPS.
     *
     *  Also this starts the global session, but only if cookie consent has been
     *  given. See \ref ConsentToCookies().
     */
    public static function Secure()
    {
        if (php_sapi_name() == 'cli')
            return;

        $sessionCookieParams = session_get_cookie_params();
        session_set_cookie_params($sessionCookieParams['lifetime'],
                                  $sessionCookieParams['path'],
                                  $sessionCookieParams['domain'],
                                  WebApp::isHTTPS(),
                                  true);

        /* CookieConsent is the explicit consent with the button.
         * However, if we have a 'doreen' cookie, then the user
         * has implicitly granted consent with an older version, so
         * let's not annoy the user with a message then. */
        if (    (isset($_COOKIE[self::COOKIE_CONSENT]))
             || (isset($_COOKIE[self::COOKIE_DOREEN]))
           )
        {
            self::$fCookieConsent = TRUE;
            session_start();
        }
    }

    /**
     *  Returns true if the "CookieConsent" button was found. Only then
     *  are we allowed to set other cookies. See \ref ConsentToCookies().
     */
    public static function HasCookieConsent()
        : bool
    {
        return self::$fCookieConsent;
    }

    /**
     *  Called from the POST /cookies-ok GUI request to set the "CookieConsent"
     *  cookie.
     *
     *  Before cookie consent has been given, the user sees a respective
     *  button on any page emitted by WholePage, and no other cookies can be set.
     *  The button in that form sents a GET /cookies-ok GUI request, which
     *  calls this function, after which we permit ourselves to
     *  set additional cookies.
     *
     *  The consequence is that anything that depends on session data (language,
     *  login) is non-functional until cookie consent has been given.
     */
    public static function ConsentToCookies()
    {
        setcookie(self::COOKIE_CONSENT,
                  (string)"yes",
                  time()+60*60*24*365,
                  Globals::$rootpage.'/',
                  '',
                  self::SecureCookie(),
                  FALSE);
    }

    public static function KeepSessionAlive()
    {
        // Force a new JWT token when logged in.
        if (LoginSession::IsUserLoggedIn())
            self::SendUserToken(TRUE);
        // Ensure session is opened to ensure its surival.
        @session_start();
    }

    /**
     *  If cookies should be marked as secure. Disabled for development
     *  environments, since those rarely have good HTTPS setups.
     */
    private static function SecureCookie()
        : bool
    {
        return !Globals::IsLocalhost() && !WebApp::IsFromPublicIp();
    }
}
