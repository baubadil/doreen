<?php
/*
 *PLUGIN    Name: Local users plugin.
 *PLUGIN    URI: http://www.baubadil.org/doreen
 *PLUGIN    Description: Implements user management in the local database.
 *PLUGIN    Version: 0.1.0
 *PLUGIN    Author: Baubadil GmbH.
 *PLUGIN    License: Proprietary
 *PLUGIN    Defaults: required
 */

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Global constants
 *
 ********************************************************************/

const USER_LOCALDB_PLUGIN_NAME      = 'user_localdb';

Plugins::RegisterInit(USER_LOCALDB_PLUGIN_NAME, function()
{
    Plugins::RegisterInstance(new UserPluginLocalDB());
});

Plugins::RegisterInstall(USER_LOCALDB_PLUGIN_NAME, function()
{
    if (!($pluginDBVersion = GlobalConfig::Get(USER_LOCALDB_CONFIGKEY_VERSION)))
        $pluginDBVersion = 0;

    if ($pluginDBVersion < USER_LOCALDB_VERSION)
    {
        installUserLocalDB($pluginDBVersion);
    }
});


/********************************************************************
 *
 *  Our User and Group subclasses
 *
 ********************************************************************/

class UserLocalDB extends User
{
}

class GroupLocalDB extends Group
{
}


/********************************************************************
 *
 *  Plugin interface classes
 *
 ********************************************************************/

class UserPluginLocalDB implements IUserPluginMutable
{

    private static $fGroupsRead = false;
    private static $fUsersRead = false;


    /********************************************************************
     *
     *  IUserPlugin interface implementations
     *
     ********************************************************************/

    /**
     *  This must return the plugin name.
     */
    public function getName()
    {
        return USER_LOCALDB_PLUGIN_NAME;
    }

    /**
     *  Implementation of the IPlugin interface function. See remarks there.
     */
    public function getCapabilities()
    {
        return (IUserPlugin::CAPSFL_USER | IUserPlugin::CAPSFL_USER_MUTABLE);
    }

    /**
     *  Implementation of the IUserPlugin interface function. See remarks there.
     */
    public function authenticateUser($login,              //!< in: login name (must be matched case-insensitively)
                                     $password,           //!< in: password (plaintext or encrypted)
                                     $fPlaintext)         //!< in: if true, $password is in plaintext
    {
        if ($row = $this->findUserRowByLogin($login))
        {
            if (($row['fl'] & (User::FLUSER_DISABLED | User::FLUSER_NOLOGIN)) == 0)
            {
                $cryptPassword = ($fPlaintext)
                                ? User::EncryptPassword($password, $row['salt'])
                                : $password;
                if ($row['password'] == $cryptPassword)
                {
                    # The user might have been woken up already, e.g. in the case of the "myaccount"
                    # page, where we force the user to re-enter their password.
                    return $this->makeAwakeUser($row['uid'],
                                                $login,
                                                $cryptPassword,
                                                $row['salt'],
                                                $row['longname'],
                                                $row['email'],
                                                $row['fl'],
                                                $row['groups'],
                                                getArrayItem($row, 'data'));
                }
            }
        }

        return NULL;
    }

    /**
     *  Returns the User object for the given login string, or NULL if no
     *  such user exists.
     */
    function findUserByLogin($login,
                             $fAllowDisabled = FALSE)
    {
        if ($row = $this->findUserRowByLogin($login))
            return $this->makeAwakeUser($row['uid'],
                                        $login,
                                        $row['password'],
                                        $row['salt'],
                                        $row['longname'],
                                        $row['email'],
                                        $row['fl'],
                                        $row['groups'],
                                        getArrayItem($row, 'data'));

        return NULL;
    }

    /**
     *  Returns the User object for the given email string, or NULL if no
     *  such user exists.
     */
    function findUserByEmail($email,
                             $fAllowDisabled = FALSE)
    {
        if ($row = $this->findUserRowByEmail($email))
            return $this->makeAwakeUser($row['uid'],
                                        $row['login'],
                                        $row['password'],
                                        $row['salt'],
                                        $row['longname'],
                                        $email,
                                        $row['fl'],
                                        $row['groups'],
                                        getArrayItem($row, 'data'));

        return NULL;
    }

    /*
     *  Implementation of the IUserPlugin interface function. See remarks there.
     */
    public function tryLoadUsers($aUIDs)        # in: flat array (list) of user IDs
    {
        $aUsers = [];

        # Validate arguments and see if maybe all the users are awake already.
        $aLookup = [];
        foreach ($aUIDs as $uid)
        {
            if (!isInteger($uid))
                throw new DrnException("Invalid user ID $uid given to tryLoadUsers()");

            if (isset(User::$aAwakenedUsers[$uid]))
                $aUsers[$uid] = User::$aAwakenedUsers[$uid];
            else
                $aLookup[$uid] = 1;
        }

        # Only hit the database if we have not found all users.
        if (count($aLookup))
        {
            $inQuery = Database::MakeInIntList(array_keys($aLookup));
            $groupConcatMembershipsGid = Database::GetDefault()->makeGroupConcat('memberships.gid');
            $colData = (GlobalConfig::$installStatus == GlobalConfig::STATUS99_ALL_GOOD) ? 'users.data,' : '';
            $res = Database::DefaultExec(<<<SQL
SELECT DISTINCT
   users.uid,
   users.login,
   users.password,
   users.salt,
   users.longname,
   users.email,
   users.fl,
   $colData
   (SELECT $groupConcatMembershipsGid FROM memberships WHERE uid = users.uid) AS groups
FROM users
LEFT JOIN memberships ON memberships.uid = users.uid
WHERE users.uid IN ($inQuery);
SQL
                                     );

            while ($row = Database::GetDefault()->fetchNextRow($res))
            {
//                 Debug::Log("!!! fetched uid $uid");
                $uid = $row['uid'];
                $aUsers[$uid] = $this->makeAwakeUser($uid,
                                                     $row['login'],
                                                     $row['password'],
                                                     $row['salt'],
                                                     $row['longname'],
                                                     $row['email'],
                                                     $row['fl'],
                                                     $row['groups'],
                                                     getArrayItem($row, 'data'));
            }
        }

        return $aUsers;
    }

    /*
     *  Implementation of the IUserPlugin interface function. See remarks there.
     */
    public function makeAwakeUser($uid,
                                  $login,
                                  $cryptPassword,
                                  $salt,
                                  $longname,
                                  $email,
                                  $fl,
                                  $groups,
                                  $aJSON)       //!< in: array or JSON string from 'data' column or NULL
    {
//         Debug::Log(__CLASS__.'::'.__FUNCTION__.": waking up uid $uid; groups=$groups");
        if (isset(User::$aAwakenedUsers[$uid]))
            return User::$aAwakenedUsers[$uid];

        return new UserLocalDB($this,               # owning plugin
                               $uid,
                               $login,
                               $cryptPassword,
                               $salt,
                               $longname,
                               $email,
                               $fl,
                               $groups,
                               $aJSON);
    }

    /*
     *  Implementation of the IUserPlugin interface function. See remarks there.
     */
    public function tryLoadGroup($gid)
    {
        $groupConcatMembershipsUid = Database::GetDefault()->makeGroupConcat('memberships.uid');

        $res = Database::DefaultExec(<<<SQL
SELECT
    groups.gid,
    groups.gname,
    (SELECT $groupConcatMembershipsUid FROM memberships WHERE gid = groups.gid) AS members
FROM groups
LEFT JOIN memberships ON memberships.gid = groups.gid
WHERE groups.gid = $1
SQL
               , [ $gid ] );

        if ($row = Database::GetDefault()->fetchNextRow($res))
            return $this->makeAwakeGroup($gid,
                                         $row['gname'],
                                         $row['members']);

        return NULL;
    }

    /*
     *  Implementation of the IUserPlugin interface function. See remarks there.
     */
    public function makeAwakeGroup($gid, $gname, $members)
    {
        if (isset(Group::$aAwakenedGroups[$gid]))
            return Group::$aAwakenedGroups[$gid];

        return new GroupLocalDB($this,          # owning plugin
                                $gid,
                                $gname,
                                $members);
    }

    /*
     *  Implementation of the IUserPlugin interface function. See remarks there.
     */
    public function loadAllUsers()
    {
        # On the first call, wake up all the users. By calling makeAwakeUser
        # for every user in the database, we fill the cache in the User static
        # class data with the User instances.
        if (!self::$fUsersRead)
        {
            $groupConcatMembershipsGid = Database::GetDefault()->makeGroupConcat('memberships.gid');

            $colData = (GlobalConfig::$installStatus == GlobalConfig::STATUS99_ALL_GOOD) ? 'users.data,' : '';
            $res = Database::DefaultExec(<<<SQL
SELECT
   users.uid,
   users.login,
   users.password,
   users.salt,
   users.longname,
   users.email,
   users.fl,
   $colData
   (SELECT $groupConcatMembershipsGid FROM memberships WHERE uid = users.uid) AS groups
FROM users
LEFT JOIN memberships ON memberships.uid = users.uid
SQL
                                     );
            while ($row = Database::GetDefault()->fetchNextRow($res))
                $this->makeAwakeUser($row['uid'],
                                     $row['login'],
                                     $row['password'],
                                     $row['salt'],
                                     $row['longname'],
                                     $row['email'],
                                     $row['fl'],
                                     $row['groups'],
                                     getArrayItem($row, 'data'));
            self::$fUsersRead = true;
        }
    }

    /*
     *  Implementation of the IUserPlugin interface function. See remarks there.
     */
    public function loadAllGroups()
    {
        if (!self::$fGroupsRead)
        {
            $groupConcatMembershipsUid = Database::GetDefault()->makeGroupConcat('memberships.uid');

            $DISABLE_FLAGS = User::FLUSER_DISABLED;

            $res = Database::DefaultExec(<<<SQL
SELECT
    groups.gid,
    groups.gname,
    (SELECT $groupConcatMembershipsUid
      FROM memberships
      JOIN users ON (memberships.uid = users.uid) AND (users.fl & $DISABLE_FLAGS = 0)
      WHERE gid = groups.gid
    ) AS members
FROM groups
LEFT JOIN memberships ON memberships.gid = groups.gid
SQL
                                     );

            while ($row = Database::GetDefault()->fetchNextRow($res))
                $this->makeAwakeGroup($row['gid'],
                                      $row['gname'],
                                      $row['members']);
            self::$fGroupsRead = true;
        }
    }


    /********************************************************************
     *
     *  IUserPluginMutable interface implementations
     *
     ********************************************************************/

    /*
     *  Implementation of the IUserPluginMutable interface function. See remarks there.
     *
     *  This must either throw or create an instance of User. We derive UserLocalDB
     *  from User, so we return an instance of that.
     */
    public function createUser($login,
                               $password,       //!< in: cleartext password, will be encrypted, must not be NULL
                               $longname,
                               $email,
                               $fl)             //!< in: User::FLUSER_* flags
    {
        if ($row = $this->findUserRowByLogin($login,
                                             TRUE))        # allow disabled
            throw new DrnException("Login name $login is already in use");

        $cryptPassword = NULL;  # login disabled
        require_once INCLUDE_PATH_PREFIX.'/3rdparty/class_pwdgen.inc.php';
        $salt = \PasswordGenerator::getASCIIPassword(20);
        $cryptPassword = User::EncryptPassword($password, $salt);
        $dtNow = gmdate('Y-m-d H:i:s').' UTC';

        Database::DefaultExec(<<<SQL
INSERT INTO users
    ( login,   password,        salt,   longname,   email,   fl,   created_dt,  updated_dt ) VALUES
    ( $1,      $2,              $3,     $4,         $5,      $6,   $7,          $8 )
SQL
  , [ $login,  $cryptPassword,  $salt,  $longname,  $email,  $fl,  $dtNow,      $dtNow ] );

        $uid = Database::GetDefault()->getLastInsertID('users', 'uid');

        return $this->makeAwakeUser($uid,
                                    $login,
                                    $cryptPassword,
                                    $salt,
                                    $longname,
                                    $email,
                                    $fl,
                                    '',         # no groups yet
                                    []);        # no JSON data yet
    }

    /*
     *  Implementation of the IUserPluginMutable interface function. See remarks there.
     *
     *  This must either throw or create an instance of Group. We derive GroupLocalDB
     *  from Group, so we return an instance of that.
     */
    public function createGroup($gname)
    {
        Database::DefaultExec(<<<SQL
INSERT INTO groups
    ( gname) VALUES
    ( $1 )
SQL
  , [ $gname ] );

        $gid = Database::GetDefault()->getLastInsertID('groups', 'gid');
        return $this->makeAwakeGroup($gid,
                                     $gname,
                                     '');           # members: none yet
    }

    /*
     *  Implementation of the IUserPluginMutable interface function. See remarks there.
     *
     *  This may throw.
     */
    public function updateUser(User $oUser)
    {
        $dtNow = gmdate('Y-m-d H:i:s').' UTC';

        $strData = NULL;
        if ($oUser->aKeyValuePairs && count($oUser->aKeyValuePairs))
            $strData = json_encode($oUser->aKeyValuePairs);

        Database::DefaultExec('UPDATE users SET password = $1, longname = $2, email = $3, fl = $4,updated_dt = $5, data = $6 WHERE uid = $7',
                              [ $oUser->cryptPassword,      # 1
                                $oUser->longname,           # 2
                                $oUser->email,              # 3
                                $oUser->fl,                 # 4
                                $dtNow,                     # 5
                                $strData,
                                $oUser->uid ] );            # 7
    }

    /*
     *  Implementation of the IUserPluginMutable interface function. See remarks there.
     *
     *  This may throw.
     */
    public function updateKeyValues(User $oUser)
    {
        $dtNow = gmdate('Y-m-d H:i:s').' UTC';

        $strData = NULL;
        if ($oUser->aKeyValuePairs && count($oUser->aKeyValuePairs))
            $strData = json_encode($oUser->aKeyValuePairs);

        Database::DefaultExec('UPDATE users SET updated_dt = $1, data = $2 WHERE uid = $3',
                              [ $dtNow,                     # 1
                                $strData,                   # 2
                                $oUser->uid ] );            # 3
    }

    /*
     *  Implementation of the IUserPluginMutable interface function. See remarks there.
     *
     *  This may throw.
     */
    public function updateGroup($oGroup)
    {
        Database::DefaultExec('UPDATE groups SET gname = $1   WHERE gid = $2',
                                     [ $oGroup->gname,  $oGroup->gid] );
    }

    /*
     *  Implementation of the IUserPluginMutable interface function. See remarks there.
     *
     *  This may throw.
     */
    public function deleteGroup($oGroup)
    {
        Database::DefaultExec('DELETE FROM groups WHERE gid = $1',
                                     [ $oGroup->gid ] );
    }

    /*
     *  Implementation of the IUserPluginMutable interface function. See remarks there.
     *
     *  This should not fail.
     */
    public function setUserMemberships($oUser,
                                       $aGroups)
    {
        Database::GetDefault()->beginTransaction();
        Database::DefaultExec("DELETE FROM memberships WHERE uid = $1",
                                     [ $oUser->uid ] );
        foreach ($aGroups as $oGroup)
            $this->addUserToGroup($oUser, $oGroup);

        Database::GetDefault()->commit();
    }

    /*
     *  Implementation of the IUserPluginMutable interface function. See remarks there.
     *
     *  This should not fail.
     */
    public function addUserToGroup($oUser,
                                   $oGroup)
    {
        Database::DefaultExec('INSERT INTO memberships (uid, gid) VALUES ( $1,          $2)',
                                     [ $oUser->uid, $oGroup->gid ] );
    }

    /*
     *  Implementation of the IUserPluginMutable interface function. See remarks there.
     *
     *  This should not fail.
     */
    public function removeUserFromGroup($oUser,
                                        $oGroup)
    {
        Database::DefaultExec('DELETE FROM memberships WHERE uid = $1 AND gid = $2;',
                                     [ $oUser->uid, $oGroup->gid ] );
    }

    /**
     *  Implementation of the IUserPluginMutable interface function. See remarks thee.
     *
     *  This should not fail.
     */
    public function changeUserLogin(User $oUser,
                                    string $oldLogin)
    {
        Database::DefaultExec('UPDATE users SET login = $1 WHERE uid = $2',
                              [ $oUser->login,
                                $oUser->uid ] );
    }


    /********************************************************************
     *
     *  Protected internal functions
     *
     ********************************************************************/

    /*
     *  Helper which returns a database row with the data for the user with the given login
     *  name, or NULL of no such user exists.
     *
     *  Does not check the user flags, meaning that a user row is also returned if the user
     *  account has been disabled.
     */
    protected function findUserRowByLogin($login,                       //!< in: login name (must be matched case-insensitively)
                                          $fAllowDisabled = FALSE)
    {
        $groupConcatMembershipsGid = Database::GetDefault()->makeGroupConcat('memberships.gid');

        $colData = (GlobalConfig::$installStatus == GlobalConfig::STATUS99_ALL_GOOD) ? 'users.data,' : '';
        if ($res = Database::DefaultExec(<<<SQL
SELECT
   users.uid,
   users.login,
   users.password,
   users.salt,
   users.longname,
   users.email,
   users.fl,
   $colData
   (SELECT $groupConcatMembershipsGid FROM memberships WHERE uid = users.uid) AS groups
FROM users
LEFT JOIN memberships ON memberships.uid = users.uid
WHERE LOWER(login) = $1
SQL
          , [ strtolower($login) ] ))
        {
            if (    ($row = Database::GetDefault()->fetchNextRow($res))
                 && ( $fAllowDisabled || (!($row['fl'] & User::FLUSER_DISABLED)) )
               )
                return $row;
        }

        return NULL;
    }

    /*
     *  Helper which returns a database row with the data for the user with the given login
     *  name, or NULL of no such user exists. This will return the first match in case
     *  there are several such rows.
     *
     *  If $fAllowDisabled = TRUE, this will ignore user flags, meaning that a user row is
     *  also returned if the user account has been disabled.
     */
    protected function findUserRowByEmail($email,
                                          $fAllowDisabled = FALSE)
    {
        $groupConcatMembershipsGid = Database::GetDefault()->makeGroupConcat('memberships.gid');

        $colData = (GlobalConfig::$installStatus == GlobalConfig::STATUS99_ALL_GOOD) ? 'users.data,' : '';
        if ($res = Database::DefaultExec(<<<SQL
SELECT
   users.uid,
   users.login,
   users.password,
   users.salt,
   users.longname,
   users.email,
   users.fl,
   $colData
   (SELECT $groupConcatMembershipsGid FROM memberships WHERE uid = users.uid) AS groups
FROM users
LEFT JOIN memberships ON memberships.uid = users.uid
WHERE UPPER(email) = $1
SQL
          , [ strtoupper($email) ] ))
        {
            if (    ($row = Database::GetDefault()->fetchNextRow($res))
                 && ( $fAllowDisabled || (!($row['fl'] & User::FLUSER_DISABLED)) )
               )
                return $row;
        }

        return NULL;
    }

    /*
     *  Helper which returns a database row with the data for the group with the given name.
     */
    protected function findGroupByName($gname)
    {
        if (    ($res = Database::GetDefault()->tryExec(<<<SQL
SELECT
   gid,
   gname
FROM groups
WHERE gname=$1
SQL
               , array($gname)))
            && (!(Database::GetDefault()->isError($res)))
            && ($row = Database::GetDefault()->fetchNextRow($res))
          )
            return $row;

        return NULL;
    }
}


/********************************************************************
 *
 *  Plugin interface functions
 *
 ********************************************************************/

/*
 *  Public plugin install function.
 */
function installUserLocalDB($pluginDBVersion)
{
    $timestamp = Database::GetDefault()->timestampUTC;
    $lenLoginName = LEN_LOGINNAME;
    $lenPassword = LEN_PASSWORD;
    $lenUserSalt = LEN_USERSALT;
    $lenLongName = LEN_LONGNAME;
    $lenEmail = LEN_EMAIL;

    if ($pluginDBVersion < 1)
    {
        GlobalConfig::AddPrio1Install('Create users table', <<<SQL
CREATE TABLE users (
    uid         SERIAL PRIMARY KEY,
    login       VARCHAR($lenLoginName) NOT NULL,
    password    CHAR($lenPassword) NOT NULL,
    salt        CHAR($lenUserSalt) NOT NULL,
    longname    VARCHAR($lenLongName) NOT NULL,
    email       VARCHAR($lenEmail) NOT NULL,
    fl          INTEGER NOT NULL,
    created_dt  $timestamp NOT NULL,
    updated_dt  $timestamp NOT NULL
)
SQL
        );

        GlobalConfig::AddPrio1Install('Create groups table', <<<SQL
CREATE TABLE groups
(
    gid         SERIAL PRIMARY KEY,
    gname       VARCHAR($lenLongName) NOT NULL  -- length for compat with xTracker
)
SQL
        );

        GlobalConfig::AddPrio1Install('Create group memberships table', <<<SQL
CREATE TABLE memberships
(
    i           SERIAL PRIMARY KEY,
    uid         INTEGER NOT NULL REFERENCES users(uid),
    gid         INTEGER NOT NULL REFERENCES groups(gid)
)
SQL
        );

        GlobalConfig::AddPrio1Install('Create group memberships index (uid)', <<<SQL
CREATE INDEX idxuid ON memberships (uid)
SQL
        );

        GlobalConfig::AddPrio1Install('Create group memberships index (gid)', <<<SQL
CREATE INDEX idxgid ON memberships (gid)
SQL
        );
    }

    $USER_LOCALDB_VERSION = USER_LOCALDB_VERSION;
    GlobalConfig::AddInstall("Update user_localdb version to $USER_LOCALDB_VERSION in config ".USER_LOCALDB_CONFIGKEY_VERSION, function()
    {
        GlobalConfig::Set(USER_LOCALDB_CONFIGKEY_VERSION, USER_LOCALDB_VERSION);
        GlobalConfig::Save();
    });
}
