<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


abstract class ViewMyAccount
{
    public static function Emit()
    {
        if (LoginSession::IsUserLoggedIn() === NULL)
            myDie(L('{{L//You are not logged in}}'));

        $groups = '';
        $aGroups = LoginSession::$ouserCurrent->getMemberships();
        foreach ($aGroups as $gid => $oGroup)
        {
            $groups .= toHTML($oGroup->gname);
            $groups .= "<br>\n";
        }
        if (LoginSession::IsCurrentUserAdminOrGuru())
            $groups .= '('.L('{{L//This cannot be changed here; please use <a href="%LINK%">the administrator form</a> to change group memberships.}}',
                             [ '%LINK%' => Globals::$rootpage.'/users' ]).')';
        else
            $groups .= '('.L('{{L//This can only be changed by an administrator.}}').')';

        $htmlTitle = L('{{L//Manage your user account}}');

        $oHTML = new HTMLChunk();
        $oHTML->openPage($htmlTitle, FALSE);

        $htmlPasswordInfo = NULL;
        $htmlEmailInfo = NULL;
        $fCanChangeEmail = TRUE;

        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_USERMANAGEMENT) as $oImpl)
        {
            /** @var IUserManagement $oImpl */
            if ($oImpl->modifyMyAccountView($htmlPasswordInfo, $htmlEmailInfo, $fCanChangeEmail))
                break;
        }
        if (!$htmlPasswordInfo)
            $htmlPasswordInfo = L('<p class="help-block">{{L//You only need to fill in these fields if you want to change your current password. Otherwise leave them blank.}}</p>');
        if (!$htmlEmailInfo)
            $htmlEmailInfo = L("<p class=\"help-block\">{{L//This address will be used by %DOREEN% for sending ticket mail. It also allows you to reset your password in case you have lost it. The address is never displayed publicly.}}</p>");

        $oTokenAcl = DrnACL::Find(ACL_SYS_TOKEN);
        $flTokenAcl = $oTokenAcl->getUserAccess(LoginSession::$ouserCurrent);

        $ts = Timestamp::CreateFromUTCDateTimeString("2018-06-01 16:04:34");

        $oHTML->addXmlDlg(dirname(__FILE__).'/view_myaccount_dlg1.xml',
                          [ '%LOGINNAME%' => toHTML(LoginSession::$ouserCurrent->login),
                            '%GROUPS%' => $groups,
                            '%REALNAME%' => toHTML(LoginSession::$ouserCurrent->longname),
                            '%EMAIL%' => toHTML(LoginSession::$ouserCurrent->email),
                            '%UID%' => LoginSession::$ouserCurrent->uid,
                            '%PASSWORDINFO%' => $htmlPasswordInfo,
                            '%EMAILINFO%' => $htmlEmailInfo,
                            '%APITOKEN%' => toHTML(LoginSession::$ouserCurrent->getExtraValue(User::JWT_KEY)),
                            '%TOKENCLASS%' => ($flTokenAcl & ACCESS_READ) ? '' : 'tokenRow" hidden class="hidden',
                            '%TOKENGENERATORCLASS%' => ($flTokenAcl & ACCESS_CREATE) ? '' : ' disabled" disabled="disabled',
                            '%LONGDATE%' => $ts->toLocale(TRUE, TRUE, FALSE),
                            '%SHORTDATE%' => $ts->toLocale(TRUE, TRUE, TRUE),
                          ] );

        $a = [ 'uid' => (int)LoginSession::$ouserCurrent->uid,
               'fCanChangeEmail' => $fCanChangeEmail,
             ];
        foreach ( [ User::FLUSER_TICKETMAIL => 'fTicketMail',
                  ] as $fl => $idControl)
        {
            $a[$idControl] = (bool)(!!(LoginSession::$ouserCurrent->fl & $fl));
        }
        $a['dateFormat'] = LoginSession::GetUserDateFormat();

        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initMyAccountForm',
                                             [ $a ]);

        WholePage::Emit($htmlTitle, $oHTML);
    }
}
