<?php
/*
 *PLUGIN    Name: Admin users plugin.
 *PLUGIN    URI: http://www.baubadil.org/doreen
 *PLUGIN    Description: Implements certain hard-coded user and group definitions.
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

# Convenience variable containing this plugin's name. For use within this plugin to facilitate copy & paste.
# We use a variable instead of a constant because this may be redefined when multiple plugins are loaded.
const USER_HARDCODED_PLUGIN_NAME = 'user_hardcoded';

Plugins::RegisterInit(USER_HARDCODED_PLUGIN_NAME, function()
{
    Plugins::RegisterInstance(new UserPluginHardcoded());
});


/********************************************************************
 *
 *  Plugin interface classes
 *
 ********************************************************************/

class UserPluginHardcoded implements IUserPlugin
{
    /**
     *  This must return the plugin name.
     */
    public function getName()
    {
        return USER_HARDCODED_PLUGIN_NAME;
    }

    /*
     *  Implementation of IUserPlugin interface function. See remarks there.
     */
    function getCapabilities()
    {
        return IUserPlugin::CAPSFL_USER;
    }

    /*
     *  Implementation of IUserPlugin interface function. See remarks there.
     *
     *  This plugin does not provide any users who can sign in, so always
     *  return NULL.
     */
    function authenticateUser($login,              # in: login name
                              $password,           # in: password (plaintext or encrypted)
                              $fPlaintext)         # in: if true, $password is in plaintext
    {
        return NULL;

//         $cryptPassword = ($fPlaintext)
//                         ? User::EncryptPassword($password, '')  # no salt here
//                         : $password;
//         if (    ($login == 'admin')
//              && ($cryptPassword == User::EncryptPassword('admin', ''))
//            )
//             return new UserHardcoded($this,             # plugin
//                                      User::ADMIN,
//                                      "admin",
//                                      $cryptPassword,
//                                      '',        # salt
//                                      "Administrator",
//                                      '',        # email
//                                      0,         # flags
//                                      '1,2');
    }

    /**
     *  Returns the User object for the given login string, or NULL if no
     *  such user exists.
     */
    function findUserByLogin($login,
                             $fAllowDisabled = FALSE)
    {
        return NULL;
    }

    /**
     *  Returns the User object for the given email string, or NULL if no
     *  such user exists.
     */
    function findUserByEmail($email,
                             $fAllowDisabled = FALSE)
    {
        return NULL;
    }

    /*
     *  Implementation of IUserPlugin interface function. See remarks there.
     */
    public function tryLoadUsers($aUIDs)        # in: flat array (list) of user IDs
    {
        $aReturn = [];
        foreach ($aUIDs as $uid)
            if ($uid == User::GUEST)
                $aReturn[$uid] = $this->makeAwakeUser($uid,
                                                      NULL, // $login,
                                                      NULL, // $cryptPassword,
                                                      NULL, // $salt,
                                                      L("{{L//Guest}}"),
                                                      NULL, // $email,
                                                      0,    // $fl,
                                                      strval(Group::GUESTS),        // groups
                                                      []);
        return $aReturn;
    }

    /*
     *  Implementation of IUserPlugin interface function. See remarks there.
     */
    public function makeAwakeUser($uid,
                                  $login,
                                  $cryptPassword,
                                  $salt,
                                  $longname,
                                  $email,
                                  $fl,
                                  $groups,
                                  $aJSON)
    {
        if (isset(User::$aAwakenedUsers[$uid]))
            return User::$aAwakenedUsers[$uid];

        if ($login || $cryptPassword || $salt || $email)
            throw new DrnException("Invalid parameters");

        return new UserHardcoded($this,               # owning plugin
                                 $uid,
                                 $longname,
                                 $groups);
    }

    /*
     *  Implementation of IUserPlugin interface function. See remarks there.
     */
    public function tryLoadGroup($gid)
    {
        if ($gid == Group::GUESTS)
            return $this->makeAwakeGroup($gid,
                                         L("{{L//Guests}}"),
                                         strval(User::GUEST));

        return NULL;
    }

    /*
     *  Implementation of IUserPlugin interface function. See remarks there.
     */
    public function makeAwakeGroup($gid, $gname, $members)
    {
        if (isset(Group::$aAwakenedGroups[$gid]))
            return Group::$aAwakenedGroups[$gid];

        return new GroupHardcoded($this,          # owning plugin
                                  $gid,
                                  $gname,
                                  $members);
    }

    /*
     *  Implementation of IUserPlugin interface function. See remarks there.
     */
    public function loadAllUsers()
    {
        $this->tryLoadUsers(array(User::GUEST));
    }

    /*
     *  Implementation of IUserPlugin interface function. See remarks there.
     */
    public function loadAllGroups()
    {
        $this->tryLoadGroup(Group::GUESTS);
    }
}


/********************************************************************
 *
 *  Our User subclass.
 *
 ********************************************************************/

class UserHardcoded extends User
{
    public function __construct($oPlugin,           # in: the user plugin who should own the User object
                                $uid,               # in: numeric user ID
                                $longname,          # in: long user name string
                                $groups)            # in: groups string; must be comma-separated list of valid numeric group IDs (e.g. "1,2,3")
    {
        parent::__construct($oPlugin,
                            $uid,
                            NULL, // $login,
                            NULL, // $cryptPassword,
                            NULL, // $salt,
                            $longname,
                            NULL, // $email,
                            User::FLUSER_PSEUDO,    // $fl,
                            $groups,
                            []);
    }
}


/********************************************************************
 *
 *  Our Group subclass.
 *
 ********************************************************************/

class GroupHardcoded extends Group
{
}

