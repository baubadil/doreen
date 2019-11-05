<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  ViewUsersGroups class
 *
 ********************************************************************/

/**
 *  This implements the "Users and groups" administration page.
 */
abstract class ViewUsersGroups
{
    public static function Emit()
    {
        if (!LoginSession::IsCurrentUserAdminOrGuru())
            throw new NotAuthorizedException();

        $htmlTitle = L('{{L//Manage users and groups}}');

        $oHTML = new HTMLChunk();
        $oHTML->openPage($htmlTitle, FALSE, 'user');

        $oHTML->addPara(L(<<<HTML
{{L/ADMINUSERGROUPINTRO/Here you can change users, groups and their memberships.
    A <b>user account</b> with a password is required to log into %DOREEN%.
    A <b>group</b> is a group of users, and all access control in %DOREEN% is based on group memberships.
    In particular, the members of the %{Administrators}% group are %{superusers}% that are allowed all administrative tasks.
    You can add a user to a group (or revoke memberships) either from the user's settings or from a group's settings.}}
HTML
        ));

        /*
         *  Tabs header
         */

        $aTabs = [];
        $oHTML->openTabsHeader();
        $oHTML->addTabHeader($aTabs, 'user', '{{L//Users}}');
        $oHTML->addTabHeader($aTabs, 'group', '{{L//Groups}}');
        $oHTML->addTabHeaderButton('create-user', 'add-another-user', '{{L//Add user}}');
        $oHTML->addTabHeaderButton('create-group', 'add-another', '{{L//Add group}}');
        $oHTML->close(); // tabs header

        /*
         *  Users tab
         */

        $oHTML->openTabbedPage($aTabs, 'user', '');

        $aUserPlaceholders = $oHTML->addAjaxTable2('user-table',
                              [
                                        [ '{{L//ID}}',          '%ID%',         HTMLChunk::AJAX_TYPE_INT | HTMLChunk::AJAX_ALIGN_CENTER, ],
                                        [ '{{L//Login name}}',  '%LOGIN%',      0, ],
                                        [ '{{L//Real name}}',   '%LONGNAME%',   0 ],
                                        [ '{{L//Email}}',       '%EMAIL%',      0, ],
                                        [ '{{L//Login}}',       '%CANLOGIN%',   HTMLChunk::AJAX_ALIGN_CENTER, ],
                                        [ '{{L//Ticket mail}}', '%CANMAIL%',    HTMLChunk::AJAX_ALIGN_CENTER, ],
                                        [ '{{L//Groups}}',      '%CGROUPS%',    HTMLChunk::AJAX_TYPE_INT | HTMLChunk::AJAX_ALIGN_CENTER, ],
                                        [ '{{L//Actions}}',     '%ACTIONS%',    HTMLChunk::AJAX_ALIGN_CENTER, ],
                              ]);

        $oHTML->close();    // tabbed page

        /*
         *  Groups tab
         */

        $oHTML->openTabbedPage($aTabs, 'group', '');

        $aGroupPlaceholders = $oHTML->addAjaxTable2('group-table',
                              [
                                        [ '{{L//ID}}',          '%ID%',         HTMLChunk::AJAX_TYPE_INT | HTMLChunk::AJAX_ALIGN_CENTER, ],
                                        [ '{{L//Group name}}',  '%GNAME%',      0, ],
                                        [ '{{L//Members}}',     '%CMEMBERS%',   HTMLChunk::AJAX_TYPE_INT | HTMLChunk::AJAX_ALIGN_CENTER, ],
                                        [ '{{L//Usage}}',       '%CUSAGE%',     HTMLChunk::AJAX_TYPE_INT | HTMLChunk::AJAX_ALIGN_CENTER, ],
                                        [ '{{L//Actions}}',     '%ACTIONS%',    HTMLChunk::AJAX_ALIGN_CENTER, ],
                              ]);

        $oHTML->close();    // tabbed page


        $oHTML->close(); # page

        /*
         *  Emit!
         */

        WholePage::AddNLSStrings( [
                'edit-user-help' => L("{{L//Edit user data for %{%LOGIN%}% (%{%LONGNAME%}%)%HELLIP%}}"),
                'change-password-help' => L("{{L//Change password for %{%LOGIN%}% (%{%LONGNAME%}%)%HELLIP%}}"),
                'disable-account-help' => L("{{L//Disable user account %{%LOGIN%}% (%{%LONGNAME%}%)%HELLIP%}}"),
                'edit-group-help' => L("{{L//Edit data for group %{%GNAME%}%%HELLIP%}}"),
                'delete-group-help' => L("{{L//Delete the group %{%GNAME%}%%HELLIP%}}"),
                'create-user-heading' => L("{{L//Create a new user account}}"),
                'create-group-heading' => L("{{L//Create a new group}}"),
            ] );

        $oHTML->addXmlDlg(dirname(__FILE__).'/view_usersgroups.xml');

        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */'core_initUsersAndGroupsPage',
                                             [ $aTabs,
                                               $aUserPlaceholders,
                                               $aGroupPlaceholders,
                                               LoginSession::$ouserCurrent->uid,
                                               Group::ALLUSERS ] );

        WholePage::SilenceFooter();
        WholePage::Emit($htmlTitle, $oHTML);
    }
}
