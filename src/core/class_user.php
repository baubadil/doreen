<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  User base class
 *
 ********************************************************************/

/**
 *  The User class represents a Doreen user account: a login name and a password to which group
 *  memberships and access permissions are attached. User instances are critical to Doreen's operation,
 *  as ticket modifications are logged together with the user account who made the change, and all
 *  access permissions are based on the group memberships of a user account.
 *
 *  See \ref access_control for an introduction.
 */
class User
{
    /** @var IUserPluginMutable */
    public $oPlugin = NULL;         # The user plugin which created this User object and should own it from then on.

    public $uid = 0;
    public $login = '';
    public $cryptPassword = '';     # The encrypted password is needed so that we can store it in a cookie.
    public $salt = '';

    public $longname = '';
    public $email = '';
    public $fl = 0;

    const FLUSER_DISABLED               = 0x01;     # User account has been disabled, do not authenticate.
    const FLUSER_PSEUDO                 = 0x02;     # User account is not editable (e.g. GUEST)
    const FLUSER_TICKETMAIL             = 0x04;     # Send ticket mail to this user.
    const FLUSER_NOLOGIN                = 0x08;     # A lesser variant of disabled -- show the user, but do not allow logins
    const FLUSER_DATEFORMAT_ABSOLUTE    = 0x10;     # OBSOLETE: Always print absolute dates for this user.

    public $aKeyValuePairs = [];             # Arbitrary JSON data from 'data' column; never NULL

    public $groups = '';            # Group IDs as comma-separated list of numeric group IDs.
    public $aGroupIDs = [];         # Exploded array of numeric group IDs.

    # The special user created by the Doreen install always has UID 1.
    const ADMIN = 1;
    # The special user 'Guest' does not exist in the user database and represents
    # a user who is not signed in. A User instance is created for this in memory only.
    const GUEST = -1;

    /* Class-global array of objects that have been instantiated already, ordered by UID.
       This is to prevent instantiating the same user ID twice. */
    public static $aAwakenedUsers = [];

    const DATEFORMAT = 'dateFormat';
    const DATEFORMAT_RELATIVE = 0;
    const DATEFORMAT_SHORT = 1;
    const DATEFORMAT_LONG = 2;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    /**
     *  The one and only constructor.
     */
    public function __construct($oPlugin,           //!< in: the user plugin who should own the User object
                                $uid,               //!< in: numeric user ID
                                $login,             //!< in: login string
                                $cryptPassword,     //!< in: encrypted password (must have been encrypted by User::EncryptPassword())
                                $salt,              //!< in: user salt (for encrypting password)
                                $longname,          //!< in: long user name string
                                $email,             //!< in: user email string
                                $fl,                //!< in: user flags (presently 0 always)
                                $groups,            //!< in: groups string; must be comma-separated list of valid numeric group IDs (e.g. "1,2,3")
                                $aJSON)             //!< in: array or JSON string from 'data' column or NULL
    {
        # Fail if this constructor is getting called for a UID for which another object already exists.
        if (isset(User::$aAwakenedUsers[$uid]))
            throw new DrnException("User object for UID $uid instantiated twice!");

        # Initialize member variables.
        $this->oPlugin = $oPlugin;

        $this->uid = $uid;
        $this->login = $login;
        $this->cryptPassword = $cryptPassword;
        $this->salt = $salt;

        $this->longname = $longname;
        $this->email = $email;
        $this->fl = $fl;

        if (is_array($aJSON))
            $this->aKeyValuePairs = $aJSON;
        else if ($aJSON)
            $this->aKeyValuePairs = json_decode($aJSON, TRUE);
        else
            $this->aKeyValuePairs = [];

        if ($this->groups = $groups)
            foreach (explode(',', $groups) as $gid)
                $this->aGroupIDs[$gid] = 1;

        Debug::Log(Debug::FL_USERS, "%% instantiated user \"$longname\", fl=$fl");

        # Store ourselves in the class users hash so we don't instantiate the same user twice.
        User::$aAwakenedUsers[$uid] = $this;
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    public function isMember($gid)
    {
        return isset($this->aGroupIDs[$gid]);
    }

    public function isAdmin()
    {
        return isset($this->aGroupIDs[Group::ADMINS]);
    }

    public function isGuru()
    {
        return isset($this->aGroupIDs[Group::GURUS]);
    }

    public function isEditor()
    {
        return isset($this->aGroupIDs[Group::EDITORS]);
    }

    public function isDisabled()
    {
        return (($this->fl & User::FLUSER_DISABLED) != 0);
    }

    public function canLogin()
    {
        return (($this->fl & (User::FLUSER_DISABLED | User::FLUSER_NOLOGIN)) == 0);
    }

    /**
     *  Returns TRUE if the currently logged in user can impersonate this user.
     *  This requires that the current user is an admin; that $this user is not
     *  that admin; and that $this user can log in.
     */
    public function canBeImpersonated()
    {
        if (LoginSession::IsCurrentUserAdmin())
        {
            if ($this->uid != self::GUEST)
                if ($this->canLogin())
                    if ($this->uid != LoginSession::$ouserCurrent->uid)
                        return TRUE;
        }

        return FALSE;
    }

    const FL_CHANGED_PASSWORD       = (1 << 0);
    const FL_CHANGED_LONGNAME       = (1 << 1);
    const FL_CHANGED_EMAIL          = (1 << 2);
    const FL_CHANGED_TICKETMAIL     = (1 << 3);
    const FL_CHANGED_DATEFORMAT     = (1 << 4);
    const FL_CHANGED_COMMENTS       = (1 << 5);

    /**
     *  Updates those bits of the account data that the user can update themselves,
     *  and calls the plugin to update the permanent representation in the database.
     *
     *  Returns a nonzero value if the user data was updated (FL_CHANGED_* bitmask),
     *  or zero if it has not changed. Writes system changelog entries for items that were changed.
     *
     *  May throw on errors. In particular, this checks if the given arguments meet
     *  minimum requirements.
     *
     * @return int
     */
    public function update($plainPassword = NULL,           //!< in: new plaintext password, or NULL or '' if should not be changed
                           $longname = NULL,                //!< in: new real name, or NULL if it should not be changed
                           $email = NULL,                   //!< in: new email, or NULL if it should not be changed
                           $fTicketMail = FALSE,            //!< in: whether user should receive ticket mail (TRUE or FALSE)
                           int $dateFormat = NULL)          //!< in: new date format to use. (NULL is no change).
    {
        if (!($this->oPlugin->getCapabilities() & IUserPlugin::CAPSFL_USER_MUTABLE))
            throw new DrnException(L('{{L//User account cannot be modified}}'));

        $fPasswordChanged = FALSE;
        $cryptPassword = $this->cryptPassword;
        if ($plainPassword)
        {
            User::ValidatePassword($plainPassword);
            $cryptPassword = User::EncryptPassword($plainPassword, $this->salt);
            $fPasswordChanged = ($cryptPassword != $this->cryptPassword);
        }

        $oldlongname = $this->longname;
        if ($longname)
            User::ValidateLongname($longname);
        else
            $longname = $this->longname;

        $oldemail = $this->email;
        if ($email)
            User::ValidateEmail($email);
        else
            $email = $this->email;

        $fTicketMailOld = !!($this->fl & self::FLUSER_TICKETMAIL);
        $dateFormatOld  = $this->getExtraValue(self::DATEFORMAT) ?? self::DATEFORMAT_RELATIVE;

        $fLongnameChanged = ($longname != $this->longname);
        $fEmailChanged = ($email != $this->email);
        $fTicketMailChanged = ($fTicketMail != $fTicketMailOld);
        $fDateFormatChanged =    ($dateFormat !== NULL)
                              && ($dateFormat != $dateFormatOld);

        if (    ($fPasswordChanged)
             || ($fLongnameChanged)
             || ($fEmailChanged)
             || ($fTicketMailChanged)
             || ($fDateFormatChanged)
           )
        {
            $this->cryptPassword = $cryptPassword;
            $this->longname = $longname;
            $this->email = $email;

            if ($fTicketMail)
                $this->fl |= self::FLUSER_TICKETMAIL;
            else
                $this->fl &= ~(self::FLUSER_TICKETMAIL);

            if ($fDateFormatChanged)
                $this->setKeyValue(self::DATEFORMAT, $dateFormat);

            $this->oPlugin->updateUser($this);
            $this->updateCurrentUser();

            $flReturn = 0;

            if ($fPasswordChanged)
            {
                if ( ( LoginSession::$ouserCurrent ) && ($this->uid == LoginSession::$ouserCurrent->uid) )
                    LoginSession::KillCookies();

                Changelog::AddSystemChange(FIELD_SYS_USER_PASSWORDCHANGED,
                                           $this->uid);  # what
                $flReturn = self::FL_CHANGED_PASSWORD;
            }

            if ($fLongnameChanged)
            {
                Changelog::AddSystemChange(FIELD_SYS_USER_LONGNAMECHANGED,
                                           $this->uid, # what
                                           NULL,
                                           NULL,
                                           $oldlongname);
                $flReturn |= self::FL_CHANGED_LONGNAME;
            }

            if ($fEmailChanged)
            {
                Changelog::AddSystemChange(FIELD_SYS_USER_EMAILCHANGED,
                                           $this->uid, # what
                                           NULL,
                                           NULL,
                                           $oldemail);
                $flReturn |= self::FL_CHANGED_EMAIL;
            }

            if ($fTicketMailChanged)
            {
                Changelog::AddSystemChange(FIELD_SYS_USER_FTICKETMAILCHANGED,
                                           $this->uid, # what
                                           ($fTicketMailOld) ? 1 : 0);        # value_1
                $flReturn |= self::FL_CHANGED_TICKETMAIL;
            }

            if ($fDateFormatChanged)
                $flReturn |= self::FL_CHANGED_DATEFORMAT;

            return $flReturn;
        }

        return 0;       # not changed
    }

    /**
     *  Returns an array of key/value pairs that may have been set via \ref setKeyValue() for this user,
     *  or NULL if there are no such values.
     *
     *  See \ref setKeyValue() for details.
     */
    public function getValues()
    {
        return ($this->aKeyValuePairs && count($this->aKeyValuePairs)) ? $this->aKeyValuePairs : NULL;
    }

    /**
     *  Returns the value for the given string user key, or the given default value if it is not set.
     *
     *  See \ref setKeyValue() for details.
     */
    public function getExtraValue($key,
                                  $default = NULL)
    {
        if ($a = $this->getValues())
            return $a[$key] ?? $default;

        return $default;
    }

    /**
     *  Sets an arbitrary key/value pair for this user and writes it back to the database. This
     *  gets called from the implementation for the POST /userkey REST API but can be called
     *  by other code as needed.
     *
     *  Passing NULL for $value deletes the given key/value pair. No NULL values are stored for
     *  a given key.
     *
     *  The core uses this mechanism to store the following information:
     *     -- JWT information, see the JWT_* constants.
     *     -- Date format preference, see the DATETIME constant.
     *     -- Storing the prefered locale, see DrnLocale::USER_LOCALE constant.
     *
     *  The key/value mechanism is intended for plugins to be able to easily store additional per-user
     *  data without having to define their own tables.
     *
     *  Plugins should prefix their key names with a plugin abbreviation, as with config keys.
     *
     *  If $fValidate == TRUE (the default for the API call), then all plugins with CAPSFL_USERKEYVALUES
     *  are called to validate the key/value pair, and the call will fail with an exception if no plugin
     *  validates it.
     *
     *  Please do not use this for large amounts of data. This gets loaded automatically with each
     *  user's row data and should not consume more than a few bytes.
     */
    public function setKeyValue($key,
                                $value,
                                $fValidate = TRUE)
    {
        if (!($this->oPlugin->getCapabilities() & IUserPlugin::CAPSFL_USER_MUTABLE))
            throw new DrnException(L('{{L//User account cannot be modified}}'));

        if ($key)
        {
            if ($fValidate)
            {
                $fValid = FALSE;
                foreach (Plugins::GetWithCaps(IUserPlugin::CAPSFL_USERMANAGEMENT) as $oImpl)
                {
                    /** @var $oImpl IUserManagement */
                    if ($oImpl->validateUserKeyValue($key, $value))
                        $fValid = TRUE;

                }
                if ($key === self::JWT_KEY || $key === self::JWT_ID_KEY || $key === self::DATEFORMAT)
                    $fValid = TRUE;
                if (!$fValid)
                    throw new DrnException("Invalid key \"$key\" for userkey data");
            }

            $fWrite = FALSE;
            if ($value)
            {
                if (    (!isset($this->aKeyValuePairs[$key]))
                     || ( $this->aKeyValuePairs[$key] != $value)
                   )
                {
                    $this->aKeyValuePairs[$key] = $value;
                    $fWrite = TRUE;
                }
            }
            else
                if (isset($this->aKeyValuePairs[$key]))
                {
                    unset($this->aKeyValuePairs[$key]);
                    $fWrite = TRUE;
                }

            if ($fWrite)
            {
                $this->oPlugin->updateKeyValues($this);
                $this->updateCurrentUser();
            }
        }
    }

    /**
     *  If $f == TRUE, clears the FLUSER_NOLOGIN flag.
     *
     *  If $f == FALSE, sets the FLUSER_NOLOGIN flag.
     *
     *  Change is only written to the database if the flag has changed. Writes changelog then.
     */
    public function permitLogin($fPermit)
    {
        $fWrite = FALSE;
        if (    $fPermit
             && ($this->fl & self::FLUSER_NOLOGIN)
           )
        {
            $this->fl &= ~(self::FLUSER_NOLOGIN);
            $this->oPlugin->updateUser($this);
            $fWrite = TRUE;
        }
        else if (    (!$fPermit)
                  && (($this->fl & self::FLUSER_NOLOGIN) == 0)
                )
        {
            $this->fl |= self::FLUSER_NOLOGIN;
            $this->oPlugin->updateUser($this);
            $fWrite = TRUE;
        }

        if ($fWrite)
            Changelog::AddSystemChange(FIELD_SYS_USER_PERMITLOGINCHANGED,
                                       $this->uid,
                                       ($fPermit) ? 1 : 0);
    }

    /**
     *  Returns the group memberships as an array hash, where for each group that
     *  $this is a member of the key is a GID and the value is the Group object.
     *  Only the keys of those groups are set, all other keys are NULL.
     *
     *  As an example, if the user is only a member of "All users" (1), only
     *  one key/value pair will be returned.
     *
     *  May throw.
     */
    public function getMemberships()
    {
        $aReturn = [];
        $aGroups = Group::GetAll();
        foreach ($this->aGroupIDs as $gid => $dummy)
            $aReturn[$gid] = $aGroups[$gid];

        return $aReturn;
    }

    /**
     *  Adds $this to the given group.
     *
     *  If the user is already in the given group, this does nothing and reports no error.
     *
     *  May throw.
     *
     * @param Group $oGroup
     */
    public function addToGroup($oGroup)
    {
        if (!$oGroup)
            throw new DrnException("Internal error: NULL group given");

        if (!($this->isMember($oGroup->gid)))
        {
            $this->aGroupIDs[$oGroup->gid] = 1;
            $this->rebuildGroupVariables();
            $oGroup->aMemberIDs[$this->uid] = 1;
            $oGroup->rebuildMemberVariables();

            $oGroup->oPlugin->addUserToGroup($this, $oGroup);
            $this->updateCurrentUser();

            Changelog::AddSystemChange(FIELD_SYS_USER_ADDEDTOGROUP,
                                       $this->uid,  # what
                                       $oGroup->gid);
        }
    }

    /**
     *  Removes $this from the given group.
     *
     *  If the user is not in the given group, this does nothing and reports no error.
     *
     *  May throw.
     *
     * @param Group $oGroup
     */
    public function removeFromGroup($oGroup)
    {
        if ($this->isMember($oGroup->gid))
        {
            unset($this->aGroupIDs[$oGroup->gid]);
            $this->rebuildGroupVariables();
            unset($oGroup->aMemberIDs[$this->uid]);
            $oGroup->rebuildMemberVariables();

            $oGroup->oPlugin->removeUserFromGroup($this, $oGroup);
            $this->updateCurrentUser();

            Changelog::AddSystemChange(FIELD_SYS_USER_REMOVEDFROMGROUP,
                                       $this->uid,  # what
                                       $oGroup->gid);
        }
    }

    /**
     *  Disables the user account. The user is not actually deleted because other
     *  parts of Doreen (such as ticket data) may depend on the user account
     *  information, but the user will no longer be able to sign in, and will no
     *  longer be visible in the users dialog.
     *
     *  Must not be called for the currently logged in user.
     */
    public function disable()
    {
        if (!($this->oPlugin->getCapabilities() & IUserPlugin::CAPSFL_USER_MUTABLE))
            throw new DrnException(L('{{L//User account cannot be disabled}}'));

        if (LoginSession::$ouserCurrent === $this)
            throw new DrnException(L('{{L//You cannot disable your own user account}}'));

        $this->fl |= User::FLUSER_DISABLED;
        $this->oPlugin->updateUser($this);

        Changelog::AddSystemChange(FIELD_SYS_USER_DISABLED,
                                   $this->uid);  # what
    }

    public function rebuildGroupVariables()
    {
        $this->groups = implode(',', array_keys($this->aGroupIDs));
    }

    /**
     *  Serializes this User instance to $_SESSION['user2']. We don't use serialize
     *  but write only the fields we need into a JSON string.
     *
     *  Note that you MUST call session_start() before calling this if the session
     *  has already been closed.
     */
    public function writeToSession()
    {
        $a = [
            'plugin' => get_class($this->oPlugin),
            'uid' => (int)$this->uid,
            'login' => $this->login,
            'cryptPassword' => $this->cryptPassword,
            'salt' => $this->salt,
            'longname' => $this->longname,
            'email' => $this->email,
            'fl' => $this->fl,
            'groups' => $this->groups,
            'aKeyValuePairs' => $this->aKeyValuePairs];
        $j = json_encode($a);
        Debug::Log(0,"Writing to session: $j");
        $_SESSION['user2'] = $j;
    }

    /**
     *  Changes the username of a user. Should only be called very sparingly and
     *  can only be done on users that can not login.
     */
    public function changeLogin(string $newLogin)
    {
        if ($this->canLogin())
            throw new DrnException("Cannot change login of a user that is allowed to log in");

        $oldLogin = $this->login;
        $this->login = $newLogin;

        $this->oPlugin->changeUserLogin($this, $oldLogin);

        Changelog::AddSystemChange(FIELD_SYS_USER_LOGINCHANGED,
                                   $this->uid, // what
                                   NULL,
                                   NULL,
                                   $oldLogin); // value_str
    }

    // JSON data bag keys for JWT information.
    const JWT_KEY = 'jwtToken';
    const JWT_ID_KEY = 'jwtTokenID';

    public function generateAPIToken()
    {
        $token = JWT::GetToken($this->uid, JWT::TYPE_API_CLIENT, '', 0);
        $this->setKeyValue(self::JWT_KEY, (string)$token);
        $this->setKeyValue(self::JWT_ID_KEY, $token->getClaim(JWT::CLAIM_ID));

        Changelog::AddSystemChange(FIELD_SYS_TOKEN_CHANGED,
                                   $this->uid,
                                   NULL,
                                   NULL,
                                   $token->getClaim(JWT::CLAIM_ID));
    }

    /**
     *  Instantiates a User instance from the serialization that was previously
     *  written by \ref writeToSession(). Returns NULL if no valid session data
     *  was found.
     *
     * @return User
     */
    public static function LoadFromSession()
    {
        if (    (isset($_SESSION['user2']))
             && ($j = $_SESSION['user2'])
           )
        {
            $a = json_decode($j, TRUE);
            Debug::Log(0,"Loaded login data from session: ".Format::UTF8Quote($a['login']));
            if ($class = getArrayItem($a, 'plugin'))
                foreach (Plugins::GetWithUserCaps() as $oImpl)
                {
                    /** @var IUserPlugin $oImpl */
                    if (get_class($oImpl) == $class)
                        return $oImpl->makeAwakeUser($a['uid'],
                                                     $a['login'],
                                                     $a['cryptPassword'],
                                                     $a['salt'],
                                                     $a['longname'],
                                                     $a['email'],
                                                     $a['fl'],
                                                     $a['groups'],
                                                     $a['aKeyValuePairs']);
                }
        }

        return NULL;
    }

    /**
     *  Returns data for this instance as an array for JSON encoding.
     *
     *  The front-end provides the IUser interface for the result.
     */
    public function toArray()
        : array
    {
        return [ 'uid' => $this->uid,
                 'login' => $this->login,
                 'longname' => $this->longname,
                 'email' => $this->email,
                 'fTicketMail' => !!($this->fl & User::FLUSER_TICKETMAIL),
                 'groups' => $this->groups,
                 'fAdmin' => $this->isAdmin(),
                 'fGuru' => $this->isGuru(),
                 'fCanLogin' => $this->canLogin(),
                 'fCanImpersonate' => $this->canBeImpersonated(),
                 'fMayEdit' => (LoginSession::$ouserCurrent) && (LoginSession::$ouserCurrent->mayEditUser($this)),
                 'fIsLoggedIn' => (LoginSession::$ouserCurrent) && ($this->uid == (LoginSession::$ouserCurrent)->uid),
               ];
    }


    /********************************************************************
     *
     *  Private helper functions
     *
     ********************************************************************/

    private function updateCurrentUser()
    {
        // We flush the output during import so the below would fail.
        if (!Globals::$fImportingTickets)
            if (LoginSession::$ouserCurrent)
                # Update the user representation in the session data, if this user is currently logged in,
                # or else the login data will be displayed wrong.
                if (LoginSession::$ouserCurrent->uid == $this->uid && isset($_SESSION))
                {
                    @session_start();
                    $this->writeToSession();
                }
    }


    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /*
     *  Encrypts the given plaintext password using SHA-512 and the given salt.
     *  Results in a string with 128 bytes length.
     */
    public static function EncryptPassword($plainPassword,
                                           $salt)
    {
        return hash('sha512', $salt.$plainPassword);
    }

    public static function IsValidLogin($login)
    {
        if (!preg_match("/^[0-9A-Za-z_.@-]+$/", $login))
            # 0     => pattern does NOT match
            # FALSE => error occured
            return FALSE;

        return TRUE;
    }

    /**
     *  Ensures that the given plaintext password meets our minimum standards:
     *  it must be at least 8 characters long. We used to restrict the character
     *  set that could be used for passwords but as per
     *  https://stackoverflow.com/questions/1524330/what-characters-would-you-make-invalid-for-a-password
     *  that's not recommended.
     *
     *  Throws if the password is invalid.
     *
     * @return void
     */
    public static function ValidatePassword($plainPassword)
    {
        if (    (!$plainPassword)
             || (strlen($plainPassword) < Globals::$cMinimumPasswordLength)
           )
            throw new APIException('password', L('{{L//Passwords must be at least %MIN% characters long}}', [ '%MIN%' => Globals::$cMinimumPasswordLength ] ));
    }

    public static function ValidateLongname($longname)
    {
        if (!strlen($longname))
            throw new APIException('longname', L('{{L//The real name must not be empty}}'));
    }

    public static function IsValidEMail($email, $fMayBeEmpty)
        : bool
    {
        if ( (!$fMayBeEmpty) || strlen($email) )
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
                return FALSE;

        return TRUE;
    }

    /**
     *  Validates the given email and throws an APIException if it doesn't look good.
     *  Does not fail if $email is empty.
     */
    public static function ValidateEmail($email,
                                         $fieldname = 'email',          //!< in: field name for APIException
                                         bool $fMayBeEmpty = TRUE)
    {
        if (!(self::IsValidEMail($email, $fMayBeEmpty)))
            throw new APIException($fieldname, L('{{L//%MAIL% does not look like a valid email address.}}',
                                                 [ '%MAIL%' => Format::UTF8Quote($email) ]));
    }

    /**
     *  The static Authenticate() function goes through all authenticate functions
     *  registered by user plugins until one succeeds and returns a User object.
     *  If so, that User object is returned, otherwise NULL.
     *
     *  This must support authenticating against BOTH plaintext and encrypted passwords
     *  because it gets called in two contexts:
     *
     *   -- When authenticating a user who is logging in through the HTML web form,
     *      the password comes as plaintext.
     *
     *   -- However, the encrypted password is stored in the user's cookie, so when
     *      we validate against that, the password comes encrypted.
     */
    public static function Authenticate($login,         //!< in: login name (must be matched case-insensitively)
                                        $password,      //!< in: password (plaintext or encrypted)
                                        $fPlaintext)    //!< in: if true, $password is in plaintext
    {
        foreach (Plugins::GetWithUserCaps() as $oImpl)
            if ($oUser = $oImpl->authenticateUser($login,
                                                  $password,
                                                  $fPlaintext))
                return $oUser;

        return NULL;
    }

    /**
     *  Creates a new user account.
     *
     *  This does not add the user to any groups yet. The one exception is that
     *  the user is added to the required "All users" group, unless $fl includes
     *  the DISABLED or NOLOGIN flags.
     *
     *  Can throw exceptions!
     *
     * @return User
     */
    public static function Create($login,
                                  $password,
                                  $longname,
                                  $email,
                                  $fl,                          //!< in: User::FLUSER_* flags
                                  $cMinimumCharacters = 3)      //!< in: minimum length of login name; should be 3 but can be overridden for import
    {
        # Implement minimum standards regardless of plugins.
        if (strlen($login) < $cMinimumCharacters)
            throw new APIException('login', L('{{L//Login names must be at least %C% characters long, but %LOGIN% is not}}',
                                              [ '%C%' => $cMinimumCharacters,
                                                '%LOGIN%' => Format::UTF8Quote($login) ] ));
        if (!User::IsValidLogin($login))
            throw new APIException('login', L("{{L//The login name %LOGIN% contains invalid characters}}",
                                              [ '%LOGIN%' => Format::UTF8Quote($login) ]));

        User::ValidatePassword($password);
        User::ValidateLongname($longname);
        User::ValidateEmail($email);

        $oImplMutable = Plugins::GetWithMutableUserCaps();

        /** @var User $ouserNew */
        $ouserNew = $oImplMutable->createUser($login,
                                              $password,
                                              $longname,
                                              $email,
                                              $fl);

        if (0 == ($fl & self::FLUSER_DISABLED | self::FLUSER_NOLOGIN))
            $ouserNew->addToGroup(Group::Find(Group::ALLUSERS));

        Changelog::AddSystemChange(FIELD_SYS_USER_CREATED,
                                   $ouserNew->uid);  # what

        return $ouserNew;
    }

    private static $aLookedUp = [];

    /**
     *  Returns the User objects for the given array of user IDs. The objects are
     *  returned in $uid => $object format. Returns NULL only if no users were
     *  found at all.
     *
     *  For each UID, if a User object has already been instantiated for this user,
     *  it is returned. Otherwise the user plugins are consulted, which may have
     *  to hit the database.
     *
     *  May throw for unexpected failures in the plugin, but not if the user doesn't
     *  exist.
     *
     * @return User[]
     */
    public static function FindMany($llUIDs)     //!< in: flat array (list) of user IDs
    {
        $aUsers = [];

//        Debug::FuncEnter(Debug::FL_USERS, "***************** Looking up ".implode(", ", $llUIDs));

        $aNeedLookup = [];
        foreach ($llUIDs as $uid)
        {
            $uid = (int)$uid;
            if (isset(self::$aLookedUp[$uid]))
            {
                if ($oUser = getArrayItem(self::$aAwakenedUsers, $uid))
                    $aUsers[$uid] = $oUser;
            }
            else
            {
                $aNeedLookup[$uid] = 1;
                # And don't look it up again.
                self::$aLookedUp[$uid] = 1;
            }
        }

        if (count($aNeedLookup))
            # Go through all plugins until one can return a new User object for this UID.
            foreach (Plugins::GetWithUserCaps() as $oImpl)
                if ($aUsers2 = $oImpl->tryLoadUsers(array_keys($aNeedLookup)))
                    foreach ($aUsers2 as $uid => $oUser)
                        $aUsers[$uid] = $oUser;

//        Debug::FuncLeave();

        if (count($aUsers))
            return $aUsers;

        return NULL;
    }

    /**
     *  Returns the User object for the given user ID, or NULL if no such user exists.
     *
     *  If a User object has already been instantiated for this user, it is returned.
     *  Otherwise the user plugins are consulted, which may have to hit the database.
     *
     *  May throw for unexpected failures in the plugin, but not if the user doesn't exist.
     *
     * @return User | null
     */
    public static function Find($uid)
    {
        # If a User object has already been instanted for this UID, return it.
        if (isset(User::$aAwakenedUsers[$uid]))
            return User::$aAwakenedUsers[$uid];

        Debug::Log(Debug::FL_USERS, "calling FindMany() from Find()");
        # Else loop through all the plugins.
        if ($aUsers = User::FindMany(array($uid)))
            return $aUsers[$uid];

        return NULL;
    }

    /**
     *  Returns the User object for the given login name (by iterating over all user
     *  plugins), or NULL if no plugin returned one.
     *
     * @return User
     */
    public static function FindByLogin($login)
    {
        foreach (Plugins::GetWithUserCaps() as $oImpl)
            if ($o = $oImpl->findUserByLogin($login))
                return $o;

        return NULL;
    }

    /**
     *  Returns the User object for the given login name (by iterating over all user
     *  plugins), or NULL if no plugin returned one.
     *
     * @return User | null
     */
    public static function FindByEmail($email)
    {
        foreach (Plugins::GetWithUserCaps() as $oImpl)
            if ($o = $oImpl->findUserByEmail($email))
                return $o;

        return NULL;
    }

    /**
     *  Returns a hash of User objects, representing all users on the system. Every key
     *  is a user ID, every value is the matching User object.
     *
     *  This includes user accounts that have been disabled, so make sure to check User::$fl
     *  before presenting these.
     *
     * @return User[]
     */
    public static function GetAll()
    {
        foreach (Plugins::GetWithUserCaps() as $oImpl)
            $oImpl->loadAllUsers();

        $aUsers = [];
        # Filter out NULL values.
        foreach (User::$aAwakenedUsers as $uid => $oUser)
            if ($oUser)
                $aUsers[$uid] = $oUser;
        return $aUsers;
    }

    /**
     *  Returns an array of active administrators, as id => User object pairs,
     *  or NULL if there are none, which should really not happen.
     *
     * @return User[] | NULL
     */
    public static function GetActiveAdmins()
    {
        $aUsers = [];
        foreach (self::GetAll() as $id => $oUser)
            if (    $oUser->isAdmin()
                 && !$oUser->isDisabled()
               )
                $aUsers[$id] = $oUser;

        return count($aUsers) ? $aUsers : NULL;
    }

    /**
     *  Returns the first admin created on this system, who probably has a UID of 1,
     *  unless that was changed later.
     */
    public static function GetFirstAdminOrThrow()
        : User
    {
        if (!($aAdmins = self::GetActiveAdmins()))
            throw new DrnException("cannot find any administrators on this system");

        ksort($aAdmins);

        return array_shift($aAdmins);
    }

    /**
     *  Returns an array of active gurus who are NOT also admins, as
     *  id => User object pairs, or NULL if there are none, which
     *  should really not happen.
     *
     * @return User[] | NULL
     */
    public static function GetGurusNotAdmins()
    {
        $aUsers = [];
        foreach (self::GetAll() as $id => $oUser)
            if (    $oUser->isGuru()
                 && !$oUser->isAdmin()
                 && !$oUser->isDisabled()
               )
                $aUsers[$id] = $oUser;

        return count($aUsers) ? $aUsers : NULL;
    }

    /**
     *  Returs true if $this may edit the given other user, in particular
     *  if $this is an administrator.
     *
     * @param User $oUser
     */
    public function mayEditUser($oUser)
    {
        if (    ($this->isAdmin())         # Admin can edit everything.
             || (     $this->isGuru()
                   && !$oUser->isAdmin()
                   && !$oUser->isGuru()
                )     # Gurus can edit everything except admins and gurus.
           )
            return true;

        return false;
    }

    /**
     *  Like GetAll(), but returns all instances as a PHP array of arrays for JSON encoding.
     *
     *  The front-end provides the IUser interface, an array of which is returned here.
     *
     *  Caller must check access permissions before returning any such data!
     */
    public static function GetAllAsArray($fIncludeDisabled = FALSE)
    {
        $aUsers = User::GetAll();
        $aForJSON = [];
        foreach ($aUsers as $uid => $oUser)
        {
            if (    $fIncludeDisabled
                 || (!($oUser->fl & User::FLUSER_DISABLED))
               )
                $aForJSON[] = $oUser->toArray();
        }
        return $aForJSON;
    }

    /**
     *  Helper method that generates a new password for a user.
     */
    public static function GeneratePassword($length = 12)
        : string
    {
        require_once INCLUDE_PATH_PREFIX.'/3rdparty/class_pwdgen.inc.php';
        return \PasswordGenerator::getAlphaNumericPassword($length);
    }

    /**
     *  Adds an entry to the \ref pwdresets table for the given email. Gets
     *  called from \ref ApiUser::ResetPassword1() as part of
     *  POST /resetpassword1 REST API handling.
     *
     *  Note that we do NOT check for whether the given email is actually in use
     *  by a user account because we want to be able to log all reset attempts.
     *
     *  This returns the reset token for the link that should be sent to the user.
     */
    public static function RequestResetPassword(string $email,
                                                int $maxAgeMinutes,
                                                int $cRateLimit)            //!< in: how many requests are allowed per 10 minutes or 0 for no limit
        : string
    {
        User::ValidateEmail($email);            # this throws APIException as well

        $now = Globals::Now();
        $elapsedSeconds = Database::GetDefault()->makeTimestampDiff('insert_dt',
                                                                    "TIMESTAMP WITHOUT TIME ZONE '$now'");
        $c = Database::GetDefault()->execSingleValue("SELECT COUNT(i) AS c FROM pwdresets WHERE $elapsedSeconds < (10 * 60)",
                                                     [],
                                                     'c');
        if ($c > $cRateLimit)
            throw new DrnException(L("{{L//There have been too many password requests lately, please try again in a few minutes}}"));

        $resetToken = self::GeneratePassword(64);

        # Insert the request into pwdresets regardless of whether the email address is known to us.
        # We want to be able to detect script kiddies.
        Database::DefaultExec(<<<EOD
INSERT INTO pwdresets ( email,   resettoken,   insert_dt,  max_age_minutes ) VALUES
                      ( $1,      $2,           $3,         $4 )
EOD
                    , [ $email,  $resetToken,  $now,       $maxAgeMinutes ] );

        return $resetToken;
    }

    /**
     *  Validates and executes a password reset request.
     *  If login is not NULL, we make sure that the given email belongs to that account.
     *  Otherwise we assume that the email and login are the same.
     */
    public static function DoResetPassword(int $maxAgeMinutes,          //!< in: must match what was entered with RequestResetPassword()
                                           string $login = NULL,
                                           string $email,
                                           string $token,               //!< in: token from RequestResetPassword()
                                           string $password,
                                           string $confirmPassword)
    {
        # Give the user two hours (120 minutes) to update the password after the reset mail has been sent.
        $now = Globals::Now();
        $elapsedSeconds = Database::GetDefault()->makeTimestampDiff('insert_dt',
                                                                    "TIMESTAMP WITHOUT TIME ZONE '$now'");
        if (    (!($res = Database::DefaultExec(<<<EOD
SELECT * FROM pwdresets
WHERE (     ($elapsedSeconds < ($maxAgeMinutes * 60)) AND (resettoken = $1) AND (email = $2) AND (max_age_minutes = $3) )
EOD
                                                                   , [ $token,           $email,                    $maxAgeMinutes ])))
             || (!($row = Database::GetDefault()->fetchNextRow($res)))
           )
            throw new DrnException(L("{{L//No matching user found, or password reset request has expired.}}"));

//        $i = $row['i'];

        # Login must not be empty, and it must match the login in the User account we found by mail.
        if ($login)
        {
            if (    (!($oUser = User::FindByLogin($login)))
                 || ($oUser->email != $email)
               )
                throw new APIException('login', "Invalid login name");
        }
        else
            if (!($oUser = User::FindByEmail($email)))
                throw new APIException('email', "Invalid email");

        if ($password != $confirmPassword)
            throw new APIException('password-confirm', L('{{L//Passwords do not match}}'));

        # This will throw if the password does not meet our requirements.
        $oUser->update($password);

        Database::DefaultExec(<<<SQL
DELETE FROM pwdresets WHERE email = $1
SQL
                                , [ $email ]);
    }
}


/********************************************************************
 *
 *  Group base class
 *
 ********************************************************************/

/**
 *  The Group class represents a Doreen user group: a list of users for which access permissions can
 *  be defined.
 *
 *  There are five predefined groups with hard-coded group IDs after installation which are always
 *  present:
 *
 *   -- "All users" contains all users which have a login and a password. If a user logs into Doreen,
 *      the user is therefore automatically a member of "All users".
 *
 *   -- "Guests", by contrast, is a pseudo-group that consists only of the "Guest" user (somebody
 *      who is not logged in). This is useful if you want to make a certain ticket type publicly
 *      readable.
 *
 *   -- The "Administrators" group is the most powerful. Administrators can do everything, most importantly,
 *      create, modify and delete users, groups, ticket types and templates. The one user account created
 *      during install is made a member of that group.
 *
 *   -- "Gurus" and "Editors" are two additional groups for semi-powerful users. Gurus by default are
 *      allowed to manipulate user accounts so long as they're not administrators. This enables administrators
 *      to delegate user management to a degree. "Editors" are intended to be users which are allowed to
 *      edit tickets; this is used by the default "Wiki" ticket template.
 *
 *  Additional arbitrary groups can be created and used in ticket templates.
 */
class Group
{
    /** @var IUserPluginMutable */
    public $oPlugin = NULL;             # The user plugin which created this Group object and should own it from then on.

    public $gid = 0;
    public $gname = '';

    private $members = '';         # User IDs as comma-separated list of numeric group IDs.
    public $aMemberIDs = [];       # Exploded array of numeric user IDs, in $uid => 1 format.

    # The special "All users" group created by the Doreen install always has GID 1.
    const ALLUSERS = 1;
    # The special "Administrators" group created by the Doreen install always has GID 2.
    const ADMINS = 2;
    # The special "Gurus" group created by the Doreen install always has GID 3.
    const GURUS = 3;
    # The special "Editors" group created by the Doreen install always has GID 4.
    const EDITORS = 4;
    # The special "Guests" group does NOT exist in the "groups" table in the database.
    # It is only used with access permissions and represents users which have not signed in.
    const GUESTS = -1;

    # Class-global array of objects that have been instantiated already, ordered by GID.
    # This is to prevent instantiating the same group ID twice.
    public static $aAwakenedGroups = [];


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    /**
     *  The one and only constructor.
     */
    public function __construct($oPlugin,       //!< in: the user plugin who should own the Group object
                                $gid,           //!< in: numeric group ID
                                $gname,         //!< in: group name
                                $members)       //!< in: members string; must be comma-separated list of valid numeric user IDs (e.g. "1,2,3")
    {
        $this->oPlugin = $oPlugin;

        $this->gid = $gid;
        $this->gname = $gname;

        if ($this->members = $members)
            foreach (explode(',', $members) as $uid)
                $this->aMemberIDs[$uid] = 1;

        Group::$aAwakenedGroups[$gid] = $this;
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  Updates the group name.
     *
     *  Returns true if the group data was updated, or false if it has not changed.
     *
     *  May throw on errors. In particular, this checks if the given arguments meet
     *  minimum requirements.
     */
    public function update($gname)
    {
        if ($gname != $this->gname)
        {
            $oldname = $this->gname;

            $this->gname = $gname;

            $this->oPlugin->updateGroup($this);

            Changelog::AddSystemChange(FIELD_SYS_GROUP_NAMECHANGED,
                                       $this->gid,  # what
                                       NULL,
                                       NULL,
                                       $oldname);


            return true;
        }

        return false;       # not changed
    }

    /**
     *  Deletes this instance after removing the group's representation in the user
     *  plugin.
     *
     *  PHP has no delete operator for objects, so the PHP object ($this) probably
     *  continues to live until there are no more references to it, which might even
     *  be after this function returns.
     *
     *  This will delete ACL entries which reference the group, but might possibly leave
     *  (empty) ACLs intact.
     *
     *  This throws if the group cannot be deleted, e.g. because it still has members
     *  or is otherwise still in use.
     */
    public function delete()
    {
        if (    (is_array($this->aMemberIDs))
             && ($c = count($this->aMemberIDs))
           )
            throw new DrnException(L('{{L//Cannot delete group "%GROUP%" because it still has %C% members}}',
                                     array('%GROUP%' => $this->gname,
                                          '%C%' => $c)));

        Database::GetDefault()->beginTransaction();
        Database::DefaultExec("DELETE FROM acl_entries WHERE gid = $1",
                                     [ $this->gid ]);
        $this->oPlugin->deleteGroup($this);
        Database::GetDefault()->commit();

        Changelog::AddSystemChange(FIELD_SYS_GROUP_DELETED,
                                   $this->gid,  # what
                                   NULL,
                                   NULL,
                                   $this->gname);
    }

    public function rebuildMemberVariables()
    {
        $this->members = implode(',', array_keys($this->aMemberIDs));
//         Debug::Log("Group(\"".$this->gname."\")::rebuildMemberVariables(): ".$this->members);
    }

    const GETUSERFL_ONLY_WITH_LOGIN = 1 <<  0;
    const GETUSERFL_ONLY_WITH_EMAIL = 1 <<  1;

    /**
     *  Returns an array of UID => User instances of the members of this group, or NULL
     *  if the group has none.
     *
     *  If $fl has GETUSERFL_ONLY_WITH_LOGIN set, then users who cannot log in are filtered
     *  out. (This should have little relevance as those should not be members of any groups
     *  but this might go wrong.)
     *
     *  If $fl has GETUSERFL_ONLY_WITH_EMAIL set, then users without an email address are
     *  filtered out. Note that this still includes users who have an email but have the
     *  "ticket mail" flag off!
     *
     * @return User[] | NULL;
     */
    public function getMembers($fl = 0)
    {
        $aReturn = NULL;

        foreach ($this->aMemberIDs as $uid => $dummy)
            if ($oUser = User::Find($uid))
            {
                if (    (    (0 == ($fl & self::GETUSERFL_ONLY_WITH_EMAIL))
                          || ($oUser->email)
                        )
                     && (    (0 == ($fl & self::GETUSERFL_ONLY_WITH_LOGIN))
                          || ($oUser->canLogin())
                        )
                   )
                    $aReturn[$uid] = $oUser;
            }
            else
                throw new DrnException("Invalid user id $uid in group $this->gid");

        return count($aReturn) ? $aReturn : NULL;
    }

    /**
     *  Returns data for this instance as an array for JSON encoding.
     *
     *  The front-end provides the IGroup interface for the result.
     */
    public function toArray(array $aUsage = NULL)
        : array
    {
        if (!$aUsage)
            $aUsage = Group::GetAclUsage();

        return [ 'gid' => $this->gid,
                 'gname' => $this->gname,
                 'members' => $this->members,
                 'cUsedInACLs' => $aUsage[$this->gid] ?? 0 ];
    }


    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Creates a new user group by first finding a user plugin which implements
     *  IUserPluginMutable and then calling it.
     *
     *  The new group will be empty, no users are added to it.
     *
     *  This tests for whether an existing group with the same name exists and
     *  throws if that is the case.
     */
    public static function Create($gname)
        : Group
    {
        if (self::FindByName($gname))
            throw new DrnException("Group name $gname is already in use");

        $oImplMutable = Plugins::GetWithMutableUserCaps();
        # This can throw.
        $ogroupNew = $oImplMutable->createGroup($gname);

        Changelog::AddSystemChange(FIELD_SYS_GROUP_CREATED,
                                   $ogroupNew->gid,  # what
                                   NULL,
                                   NULL,
                                   $gname);

        return $ogroupNew;
    }

    /**
     *  Returns the Group object for the given group ID, or NULL if no such user exists.
     *
     *  If a Group object has already been instantiated for this group, it is returned.
     *  Otherwise the group plugins are consulted, which may have to hit the database.
     *
     *  May throw for unexpected failures in the plugin, but not if the group doesn't exist.
     *
     * @return Group
     */
    public static function Find($gid)
    {
        # If a User object has already been instanted for this UID, return it.
        if (isset(Group::$aAwakenedGroups[$gid]))
            return Group::$aAwakenedGroups[$gid];

        # Otherwise, go through all plugins until one can return a new User object for this UID.
        foreach (Plugins::GetWithUserCaps() as $oImpl)
            if ($oGroup = $oImpl->tryLoadGroup($gid))
                return $oGroup;

        # No plugin found a suitable user object:
        return NULL;
    }

    /**
     *  Returns the group with the given name or NULL if no such group exists.
     */
    public static function FindByName($gname)
    {
        foreach (self::GetAll() as $gid => $oGroup)
            if ($oGroup->gname == $gname)
                return $oGroup;

        return NULL;
    }

    /**
     *  Returns a hash of Group objects, representing all users on the system. Every key
     *  is a user ID, every value is the matching User object.
     *
     *  This includes user accounts that have been disabled, so make sure to check User::$fl
     *  before presenting these.
     *
     * @return Group[]
     */
    public static function GetAll()
    {
        foreach (Plugins::GetWithUserCaps() as $oImpl)
            $oImpl->loadAllGroups();

        # PHP arrays have copy semantics, so this will make a copy of the array,
        # but the objects contained therein are references, so the objects themselves
        # won't be copied.
        # http://stackoverflow.com/questions/1532618/is-there-a-function-to-make-a-copy-of-a-php-array-to-another
        $aGroups = Group::$aAwakenedGroups;
        return $aGroups;
    }

    /**
     *  Convenience function which returns the name of the group with the given numeric group ID,
     *  or a "[$gid]" string if it cannot be found.
     *
     *  This will not throw so it can be used from within exception constructors.
     */
    public static function GetName($gid)
    {
        $s = "[$gid]";
        try
        {
            Group::GetAll();
            if (isset(Group::$aAwakenedGroups[$gid]))
                $s = Group::$aAwakenedGroups[$gid]->gname;
        }
        catch(\Exception $e)
        {

        }

        return $s;
    }

    /**
     *  Like GetAll(), but returns all instances as a PHP array of arrays for JSON encoding.
     *
     *  The front-end provides the IGroup interface, an array of which is returned here.
     *
     *  Caller must check access permissions before returning any such data!
     */
    public static function GetAllAsArray()
    {
        $aGroups = Group::GetAll();
        $aReturn = [];

        $aUsage = Group::GetAclUsage();

        foreach ($aGroups as $gid => $oGroup)
            $aReturn[] = $oGroup->toArray($aUsage);

        return $aReturn;
    }

    /**
     *  Loads the ACL tables from the database and, for each group, figures out how
     *  many groups are actually used.
     *
     *  Returns an array of $gid -> cUsage entries.
     */
    public static function GetAclUsage()
    {
        $aACLs = DrnACL::GetAll();
        $aUsage = array();
        foreach ($aACLs as $aid => $oACL)
        {
            $aUsageInACL = array();
            foreach ($oACL->aPermissions as $gid => $fl)
                $aUsageInACL[$gid] = 1;

            foreach ($aUsageInACL as $gid => $dummy1)
                if (isset($aUsage[$gid]))
                    ++$aUsage[$gid];
                else
                    $aUsage[$gid] = 1;
        }

        return $aUsage;
    }
}


/********************************************************************
 *
 *  UserPlugin interface definition
 *
 ********************************************************************/

/**
 *
 */
interface IUserPlugin extends IPlugin
{
    /**
     *  Authentication handler. This gets called by \ref User::Authenticate()
     *  and must check login and password against the plugin's storage of
     *  them. If they match, the plugin must create and return a User
     *  object; otherwise, if NULL is returned, the user is not authenticated.
     *
     *  This must not throw or otherwise fail.
     *
     *  Note that \ref User::Authenticate() goes through all plugin implementations
     *  of this function, so even if one plugin returns NULL, the user may
     *  still get authenticated by another.
     *
     *  See \ref User::Authenticate() for the meaning of the arguments.
     */
    public function authenticateUser($login,              //!< in: login name (must be matched case-insensitively)
                                     $password,           //!< in: password (plaintext or encrypted)
                                     $fPlaintext);        //!< in: if true, $password is in plaintext

    public function findUserByLogin($login,
                                    $fAllowDisabled = FALSE);

    public function findUserByEmail($email,
                                    $fAllowDisabled = FALSE);

    /**
     *  Gets called by User::Find() for every plugin to find User objects for a given array
     *  of user IDs.
     *
     *  For each user ID, the plugin must check whether the User object has already been
     *  woken up before instantiating a new one.
     *
     *  For a "no users" result, this may return NULL or an empty array, the User class
     *  handles both.
     *
     *  This should throw only for exceptional errors; if the user plugin cannot find
     *  a user for this UID, it should simply return NULL.
     */
    public function tryLoadUsers($aUIDs);       # in: flat array (list) of user IDs

    /**
     *  Returns the User instance matching the given ID.
     *
     *  If an object has already been instantiated for that ID, it is returned.
     *  Otherwise a new one is created with the given data.
     *
     *  This is implemented in the user plugins so that plugins can create instances of
     *  classes derived from User, if necessary.
     */
    public function makeAwakeUser($uid, $login, $cryptPassword, $salt, $longname, $email, $fl, $groups, $aJSON);

    /**
     *  Gets called by Group::Find for every plugin to find a Group object for the
     *  given group ID.
     *
     *  This only gets called if no Group object has been instantiated for the given GID yet
     *  so the plugin need not check for whether one exists, but can wake up the Group object
     *  unconditionally.
     *
     *  This should throw only for exceptional errors; if the user plugin cannot find
     *  a group for this GID, it should simply return NULL.
     */
    public function tryLoadGroup($gid);

    /**
     *  Returns the Group instance matching the given ID.
     *
     *  If an object has already been instantiated for that ID, it is returned.
     *  Otherwise a new one is created with the given data.
     *
     *  This is implemented in the user plugins so that plugins can create instances of
     *  classes derived from Group, if necessary.
     */
    public function makeAwakeGroup($gid, $gname, $members);

    /**
     *  Fetches all users from the database and creates User objects for them
     *  by calling makeAwakeUser on every one of them. After this has returned,
     *  the cache of User objects in the User static class data is fully filled.
     */
    public function loadAllUsers();

    /**
     *  Fetches all groups from the database and creates Group objects for them
     *  by calling makeAwakeGroup on every one of them. After this has returned,
     *  the cache of Group objects in the Group static class data is fully filled.
     */
    public function loadAllGroups();
}


/********************************************************************
 *
 *  IUserPluginMutable interface definition
 *
 ********************************************************************/

/**
 *  Extension of the IUserPlugin interface. If a user plugin returns CAPSFL_USER_MUTABLE with
 *  IUserPlugin::getCapabilities(), it must also implement this interface.
 */
interface IUserPluginMutable extends IUserPlugin
{
    /**
     *  If a user plugin implements IUserPluginMutable, User::CreateUser() will call this
     *  function.
     *
     *  The plugin must then create a persistent representation of the given user data, e.g. in
     *  its database, and create an instance of User (or a subclass thereof, if the plugin defines
     *  one) with the data AND the newly created user UID.
     *
     *  This may throw exceptions upon errors, especially if the given login name is already in use.
     *
     *  Otherwise this MUST return a new User instance.
     */
    public function createUser($login,
                               $password,
                               $longname,
                               $email,
                               $fl);             //!< in: User::FLUSER_* flags

    /**
     *  If a user plugin implements IUserPluginMutable, User::CreateGroup() will call this
     *  function.
     *
     *  The plugin must then create a persistent representation of the given group data,
     *  e.g. in its database, and create an instance of Group (or a subclass thereof, if
     *  the plugin defines one) with the data AND the newly created user GID.
     *
     *  This may throw exceptions upon errors, especially if the given group name is already in use.
     *
     *  Otherwise this MUST return a new Group instance.
     */
    public function createGroup($gname);

    /**
     *  If a user plugin implements IUserPluginMutable, User::update() will call this
     *  function with the User object in question, which has already been updated.
     *
     *  The function will only get called if the user data has actually changed. The plugin
     *  must then update a persistent representation of the user's data, e.g. in its database.
     *
     *  This may throw exceptions upon errors. The return value is ignored.
     */
    public function updateUser(User $oUser);

    /**
     *  If a user plugin implements IUserPluginMutable, User::setKeyValue() will call this
     *  function with the User object in question, which has already been updated.
     *
     *  The function will only get called if the user data has actually changed. The plugin
     *  must then update a persistent representation of the user's data, e.g. in its database.
     *
     *  This may throw exceptions upon errors. The return value is ignored.
     */
    public function updateKeyValues(User $oUser);

    /**
     *  If a user plugin implements IUserPluginMutable, Group::update() will call this
     *  function with the Group object in question, which has already been updated.
     *
     *  The function will only get called if the group data has actually changed. The plugin
     *  must then update a persistent representation of the group's data, e.g. in its database.
     *
     *  This may throw exceptions upon errors. The return value is ignored.
     */
    public function updateGroup($oGroup);

    /**
     *  If a user plugin implements IUserPluginMutable, Group::delete() will call this
     *  function with the Group object in question.
     *
     *  The plugin must then remove the group from its storage, e.g. from the database.
     *
     *  This may throw exceptions upon errors. The return value is ignored.
     */
    public function deleteGroup($oGroup);

    /**
     *  If a user plugin implements IUserPluginMutable, User::setMemberships() will call this
     *  function.
     *
     *  The plugin must then update its membership representation (e.g. in its database) so that
     *  the given user is ONLY a member of the given groups and no others.
     *
     *  This should not fail.
     */
    public function setUserMemberships($oUser,
                                       $aGroups);

    /**
     *  If a user plugin implements IUserPluginMutable, User::addToGroup() will call this
     *  function.
     *
     *  The plugin must then update its membership representation (e.g. in its database) so that
     *  the given user is a member of the given group IN ADDITION to existing memberships.
     *
     *  This plugin function only gets called if the user is not yet a member of the given
     *  group; the plugin need not check for that.
     *
     *  This should not fail.
     */
    public function addUserToGroup($oUser, $oGroup);

    /**
     *  If a user plugin implements IUserPluginMutable, User::removeFromGroup() will call this
     *  function.
     *
     *  The plugin must then update its membership representation (e.g. in its database) so that
     *  the given user is NO LONGER a member of the given group while leaving other memberships
     *  alone.
     *
     *  This plugin function only gets called if the user actually IS a member of the given
     *  group; the plugin need not check for that.
     *
     *  This should not fail.
     */
    public function removeUserFromGroup($oUser, $oGroup);

    /**
     *  If a plugin implements IUserPluginMutable, User::changeLogin will call
     *  this function.
     *
     *  The plugin must then update the login it associates a user with.
     *
     *  This should not fail.
     */
    public function changeUserLogin(User $oUser, string $oldLogin);
}
