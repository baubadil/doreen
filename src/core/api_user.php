<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */


/*
 *  This file gets included from within anonymous (closure) functions in api.php
 *  to handle user-related requests.
 */

namespace Doreen;


/********************************************************************
 *
 *  Helpers
 *
 ********************************************************************/

class MustBeInAllUsersException extends APIException
{
    public function __construct($dlgfield)
    {
        parent::__construct($dlgfield,
                            L('{{L//All users must always be members of the group %ALLUSERS%}}',
                              [ '%ALLUSERS%' => Format::HtmlQuotes(Group::GetName(Group::ALLUSERS)) ]));
    }
}

class MustRemainAdminException extends APIException
{
    public function __construct($dlgfield, $gid)
    {
        parent::__construct($dlgfield, L('{{L//You cannot remove yourself from the %GROUP% group.}}', array( '%GROUP%' => Format::HtmlQuotes(Group::GetName($gid)))));
    }
}

/********************************************************************
 *
 *  ApiUser class
 *
 ********************************************************************/

/**
 *  Static class that combines ticket API handler implementations.
 *  Using this class enables us to use the ApiUser class and have
 *  our autoloader take care of finding the include file.
 */
class ApiUser
{
    /**
     *  Throws an APIException if the user is not admin AND also cannot edit the given group.
     *
     *  It is assumed that the current user is at least a guru.
     */
    public static function ValidateAuthorizedToChangeGroup($gid)
    {
        # Admins can do everything.
        if (!LoginSession::IsCurrentUserAdmin())
        {
            // Gurus can remove people from groups they themselves are a member of, but never from the gurus group itself.
            if ($gid == Group::GURUS)
                throw new APIException('groups',
                                       L('{{L//You are not authorized to modify the special group %GROUP%}}',
                                         [ '%GROUP%' => Format::HtmlQuotes(Group::GetName($gid)) ]));
            else if (!LoginSession::$ouserCurrent->isMember($gid))
                throw new APIException('groups',
                                       L('{{L//You are not authorized to modify the group %GROUP% since you are not a member yourself}}',
                                         [ '%GROUP%' => Format::HtmlQuotes(Group::GetName($gid)) ]));
        }
    }

    /**
     *
     */
    public static function FindUserForAdmin()
    {
        if (!LoginSession::IsCurrentUserAdmin())
            throw new NotAuthorizedException;

        $uid = WebApp::FetchParam('uid',
                                  TRUE);     # throw if missing
        if (!($oUser = User::Find($uid)))
            throw new DrnException(L('{{L//Invalid user ID %UID%}}',
                                     [ '%UID%' => $uid ]));  # message will be HTML-escaped

        if (!LoginSession::$ouserCurrent->mayEditUser($oUser))
            throw new NotAuthorizedException();

        return $oUser;
    }

    /**
     *
     */
    public static function FindGroupForAdmin()
    {
        if (!LoginSession::IsCurrentUserAdmin())
            throw new NotAuthorizedException;

        $gid = WebApp::FetchParam('gid',
                                  TRUE);     # throw if missing
        if (!($oGroup = Group::Find($gid)))
            throw new DrnException(L('{{L//Invalid group ID %GID%}}',
                                     [ '%GID%' => $gid ]));

        self::ValidateAuthorizedToChangeGroup($gid);

        return $oGroup;
    }

    /**
     *  Implementation for the GET /users REST API. Requires admin permission.
     */
    public static function GetUsers()
    {
        if (!LoginSession::IsCurrentUserAdmin())
            throw new NotAuthorizedException;

        WebApp::$aResponse += [ 'results' => User::GetAllAsArray(),
                                'uidGuest' => User::GUEST ];
    }

    /**
     *  Implementation for the GET /groups REST API. Requires admin permission.
     */
    public static function GetGroups()
    {
        if (!LoginSession::IsCurrentUserAdmin())
            throw new NotAuthorizedException;

        WebApp::$aResponse += [ 'results' => Group::GetAllAsArray(),
                                'gidAllUsers' => Group::ALLUSERS,
                                'gidAdmins' => Group::ADMINS,
                                'gidGurus' => Group::GURUS,
                                'gidEditors' => Group::EDITORS ];
    }

    /**
     *  This handles the PUT /account/:uid REST API (user updates their own account).
     */
    public static function PutAccount()
    {
        if (LoginSession::IsUserLoggedIn() === NULL)
            throw new DrnException(L('{{L//You cannot manage an account without being logged in}}'));

        $uid = WebApp::FetchParam('uid',
                                  TRUE);     # throw on error
        if ($uid != LoginSession::$ouserCurrent->uid)
            throw new DrnException(L('{{L//Invalid user ID %UID%}}', [ '%UID%' => $uid ]));          # message will be HTML-escaped

        if (!($password = WebApp::FetchParam('current-password')))
            throw new APIException('current-password', L('{{L//Missing password}}'));

        # UID is the same as logged in user, so use the login name for authentication.
        if (!User::Authenticate(LoginSession::$ouserCurrent->login,
                                $password,
                                TRUE))      # fPlaintext
            throw new APIException('current-password', L('{{L//Invalid password}}'));

        if ($newpassword = WebApp::FetchParam('password', FALSE))            # not required
        {
            $passwordConfirm = WebApp::FetchParam('password-confirm');
            if ($newpassword != $passwordConfirm)
                throw new APIException('password-confirm', L('{{L//Passwords do not match}}'));

            // If a new password was given, run it through user management plugins to see if it's acceptable.
            foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_USERMANAGEMENT) as $oImpl)
            {
                /** @var IUserManagement $oImpl */
                if ($error = $oImpl->validateChangeMyPassword($newpassword))
                    throw new APIException('password', $error);
            }
        }

        $fl = LoginSession::$ouserCurrent->update($newpassword,
                                                  WebApp::FetchParam('longname'),
                                                  $email = WebApp::FetchParam('email'),
                                                  WebApp::FetchParam('fTicketMail'),
                                                  WebApp::FetchParam('dateFormat'));

        if ($fl & User::FL_CHANGED_PASSWORD)
            // If password actually changed, notify all interested parties.
            foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_USERMANAGEMENT) as $oImpl)
            {
                /** @var IUserManagement $oImpl */
                $oImpl->onPasswordChanged($email, $newpassword);
            }
    }

    private static function FetchGroups()
        : array
    {
        $groupslist = WebApp::FetchParam('groups');
        return is_array($groupslist) ? $groupslist : explode(',', $groupslist);
    }

    public static function PostGenerateToken()
    {
        if (!LoginSession::IsUserLoggedIn())
            throw new DrnException(L('{{L//You cannot manage an account without being logged in}}'));

        if (!WebApp::isHTTPS())
            throw new DrnException(L('{{L//Refusing to generate an API token over a non-HTTPS connection}}'));

        $uid = WebApp::FetchParam('uid',
                                  TRUE);     # throw on error
        if ($uid != LoginSession::$ouserCurrent->uid)
            throw new DrnException(L('{{L//Invalid user ID %UID%}}', [ '%UID%' => $uid ]));

        $acl = DrnACL::Find(ACL_SYS_TOKEN);
        if (!($acl->getUserAccess(LoginSession::$ouserCurrent) & ACCESS_CREATE))
            throw new DrnException(L("{{L//Not authorized to generate API tokens}}"));

        LoginSession::$ouserCurrent->generateAPIToken();
        WebApp::$aResponse += [ 'token' => LoginSession::$ouserCurrent->getExtraValue(User::JWT_KEY) ];
    }

    /**
     *  This handles the POST /user command.
     */
    public static function Post()
    {
        if (!LoginSession::IsCurrentUserAdmin())
            throw new NotAuthorizedException;

        $aGroups = Group::GetAll();

        $fAllUsersSet = 0;

        // Get memberships wanted for user and check GID validity and permissions.
        $aAddUserToGroups = [];
        foreach (self::FetchGroups() as $gid)
        {
            if (!isset($aGroups[$gid]))
                throw new APIException('groups',
                                       L('{{L//Invalid group ID %GID%}}',
                                         [ '%GID%' => Format::UTF8Quote($gid) ] ));

            self::ValidateAuthorizedToChangeGroup($gid);

            $oGroup = $aGroups[$gid];
            $aAddUserToGroups[] = $oGroup;

            if ($gid == Group::ALLUSERS)
                $fAllUsersSet = 1;
        }

        if (!$fAllUsersSet)
            throw new MustBeInAllUsersException('groups');

        $fl = 0;
        if (WebApp::FetchParam('fTicketMail'))
            $fl = User::FLUSER_TICKETMAIL;

        // GO!
        $ouserNew = User::Create(WebApp::FetchParam('login'),
                                 WebApp::FetchParam('password'),
                                 WebApp::FetchParam('longname'),
                                 WebApp::FetchParam('email'),
                                 $fl);

        foreach ($aAddUserToGroups as $oGroup)
            $ouserNew->addToGroup($oGroup);

        WebApp::$aResponse['result'] = $ouserNew->toArray();
    }

    /**
     *  This handles the PUT /user/:uid command.
     */
    public static function Put()
    {
        $oUser = self::FindUserForAdmin();
        $uid = $oUser->uid;

        if (isset(WebApp::$aArgValues['longname']))
        {
            $aGroups = Group::GetAll();

            # Then, make a list of group memberships to be updated, before actually making changes
            # to the database. We don't want to change the DB and then die with a permissions error
            # and the DB half changed.
            $aRemoveUserFromGroups = [];
            $aAddUserToGroups = [];

            # Get REQUESTED memberships.
            $aGroupsRequested = [];
            foreach (self::FetchGroups() as $gid)
                $aGroupsRequested[$gid] = 1;

            # Check against EXISTING memberships of this user.
            $aGroupsExisting = $oUser->aGroupIDs;

            # Go through ALL groups.
            foreach ($aGroups as $gid => $oGroup)
            {
                $fIsInNew = isset($aGroupsRequested[$gid]);
                $fIsInOld = isset($aGroupsExisting[$gid]);

                if ($fIsInOld != $fIsInNew)
                {
                    Debug::Log(Debug::FL_USERS, "group $gid changed: fIsInOld=$fIsInOld, fIsInNew=$fIsInNew");

                    self::ValidateAuthorizedToChangeGroup($gid);

                    if ($fIsInOld && !$fIsInNew)
                    {
                        # Removing user from group:
                        if ($gid == Group::ALLUSERS)
                            throw new MustBeInAllUsersException('groups');
                        else if (    ($uid == LoginSession::$ouserCurrent->uid)
                                  && (    ($gid == Group::ADMINS)
                                       || ($gid == Group::GURUS)
                                     )
                                )
                            throw new MustRemainAdminException('groups', $gid);

                        $aRemoveUserFromGroups[] = $oGroup;
                    }
                    else if (!$fIsInOld && $fIsInNew)
                        # Adding user to group:
                        $aAddUserToGroups[] = $oGroup;
                }
            }

            // Now go hit the database!
            $oUser->update(NULL,        # password, do not update here
                           WebApp::FetchParam('longname'),
                           WebApp::FetchParam('email'),
                           WebApp::FetchParam('fTicketMail'));

            foreach ($aRemoveUserFromGroups as $oGroup)
                $oUser->removeFromGroup($oGroup);
            foreach ($aAddUserToGroups as $oGroup)
                $oUser->addToGroup($oGroup);
        }
        else
        {
            $oUser->update(WebApp::FetchParam('password'));
        }

        WebApp::$aResponse['result'] = $oUser->toArray();
    }

    /**
     *  Implementation for the POST /resetpassword1 REST API.
     */
    public static function ResetPassword1()
    {
        $email = WebApp::FetchParam('email',
                                    FALSE);          # do not throw there, we'd rather check here for empty mail as well
        if (!$email)
            throw new APIException('email', "Missing email address");

        $token = User::RequestResetPassword($email,
                                            120,
                                            5);
        $link = WebApp::MakeUrl("/lostpass2/$email/$token");

        # But only send the email to the given address if we have a user with that email.
        if ($oUser = User::FindByEmail($email))
        {
            Debug::Log(Debug::FL_USERS, "Found user ID for mail $email");

            Email::Enqueue([ $email ],  # To
                           NULL,        # BCC
                           L("{{L//Reset your %DOREEN% password}}"),
                           NULL,        # HTML mail
                           L(<<<EOD
{{L/RESETPASSWORD1/Hello!

A request has been made at %SERVER% to reset the password for the user account associated with the email address %EMAIL%.

If you have not issued that request yourself, please ignore this email.

Otherwise, please click on the following link, which brings you to a page allowing you to reset the password for your user account:

%LINK%

This link is valid for two hours. After that, you will have to request another email to reset your password.}}
EOD
                        , [ '%SERVER%' => Globals::GetHostname(),
                            '%EMAIL%' => $email,
                            '%LINK%' => $link
                          ] )
                       );
        }
        else
            Debug::Log(Debug::FL_USERS, "No user found for mail $email");
    }

    /**
     *  Implementation for the POST /resetpassword2 REST API.
     */
    public static function ResetPassword2()
    {
        $a = [];
        foreach ( [ 'email', 'token', 'login', 'password', 'password-confirm' ] as $key)
            $a[$key] = WebApp::FetchStringParam($key);

        User::DoResetPassword(120,
                              $a['login'],
                              $a['email'],
                              $a['token'],
                              $a['password'],
                              $a['password-confirm']);
    }

    /**
     *  Implementation for the POST /impersonate REST API.
     */
    public static function Impersonate($uid)
    {
        $oUser = NULL;
        if ($uid == 0)
            ;
        else if (!($oUser = User::Find($uid)))
            throw new DrnException("Invalid user ID $uid");

        // This validates again that the current user is admin.
        WebApp::$aResponse += [ 'token' => LoginSession::Impersonate($oUser) ];
    }

    /**
     *  Implementation for the POST /userkey REST API.
     */
    public static function SetUserKeyValue($key, $value)
    {
        if (!LoginSession::IsUserLoggedIn())
            throw new DrnException("No user is currently logged in");

        LoginSession::$ouserCurrent->setKeyValue($key,
                                                 $value,
                                                 TRUE);      // validate
    }


    /********************************************************************
     *
     *  GROUP COMMANDS
     *
     ********************************************************************/

    private static function FetchMembers()
        : array
    {
        $members = WebApp::FetchParam('members');
        return is_array($members) ? $members : explode(',', $members);
    }

    /**
     *  This handles the POST /group command.
     */
    public static function PostGroup()
    {
        if (!LoginSession::IsCurrentUserAdmin())
            throw new NotAuthorizedException;

        $aUsers = User::GetAll();

        // Get memberships wanted for user and check GID validity and permissions.
        /** @var  $aAddUsersToGroup User[] */
        $aAddUsersToGroup = [];
        foreach (self::FetchMembers() as $uid)
        {
            if (!isset($aUsers[$uid]))
                throw new APIException('members', L('{{L//Invalid user ID %UID%}}',
                                                    array($uid)));

            $oUser = $aUsers[$uid];
            $aAddUsersToGroup[] = $oUser;
        }

        // GO!
        $oGroupNew = Group::Create(WebApp::FetchParam('gname'));

        foreach ($aAddUsersToGroup as $oUser)
            $oUser->addToGroup($oGroupNew);

        WebApp::$aResponse['result'] = $oGroupNew->toArray();
    }

    /**
     *  This handles the PUT /group/:gid command.
     */
    public static function PutGroup()
    {
        $oGroup = self::FindGroupForAdmin();
        $gid = $oGroup->gid;
        $aUsers = User::GetAll();

        // Make a list of group memberships to be updated, before actually making changes
        // to the database. We don't want to change the DB and then die with a permissions error
        // and the DB half changed.
        /** @var  $aRemoveUsersFromGroup User[] */
        $aRemoveUsersFromGroup = [];
        /** @var  $aAddUsersToGroup  User[] */
        $aAddUsersToGroup = [];

        # Get REQUESTED memberships.
        $aNewMembers = [];
        foreach (self::FetchMembers() as $uid)
            $aNewMembers[$uid] = 1;

        # Go through ALL user accounts.
        foreach ($aUsers as $uid => $oUser)
        {
            if ($oUser->isDisabled())
                continue;

            $fIsMemberOld = $oUser->isMember($gid);
            $fIsMemberNew = isset($aNewMembers[$uid]);

            if ($fIsMemberOld != $fIsMemberNew)
            {
                if ($fIsMemberOld && !$fIsMemberNew)
                {
                    if ($gid == Group::ALLUSERS)
                        throw new MustBeInAllUsersException('members');
                    else if (    ($uid == LoginSession::$ouserCurrent->uid)
                              && (    ($gid == Group::ADMINS)
                                   || ($gid == Group::GURUS)
                                 )
                            )
                        throw new MustRemainAdminException('members', $gid);

                    $aRemoveUsersFromGroup[] = $oUser;
                }
                else if (!$fIsMemberOld && $fIsMemberNew)
                    $aAddUsersToGroup[] = $oUser;
            }
        }

        $oGroup->update(WebApp::FetchParam('gname'));

        foreach ($aRemoveUsersFromGroup as $oUser)
            $oUser->removeFromGroup($oGroup);
        foreach ($aAddUsersToGroup as $oUser)
            $oUser->addToGroup($oGroup);

        WebApp::$aResponse['result'] = $oGroup->toArray();
    }
}
