<?php

/*
 *  install4.php gets called after the Doreen database has been created, but
 *  is still empty and needs to be filled with tables.
 *
 *  We get here only if doreen-install-vars.inc.php EXISTS and the config table
 *  EXISTS but is EMPTY (has no rows).
 *
 *  We DO have a connection to the database at this point.
 *
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Active included code
 *
 ********************************************************************/

$oHTML = new HTMLChunk();

$htmlTitle = L("Installation step 3: create administrator account");
WholePage::EmitHeader($htmlTitle);
$oHTML->openPage($htmlTitle, FALSE);

if ($login = getRequestArg('account-login'))
{
    $password = getRequestArgOrDie('account-password');
    $passwordConfirm = getRequestArgOrDie('account-password-confirm');
    if ($password != $passwordConfirm)
        myDie("Passwords don't match");
    $longname = getRequestArgOrDie('account-longname');
    $email = getRequestArgOrDie('account-email');

    try
    {
        Install::CreateAdminAndFinish($login,
                                      $password,
                                      $longname,
                                      $email);

        # This seems like a good time to also initialize the search engine.
        # TODO No it doesn't. There's no way to start the search server before the admin account is created.
//        if ($oSearch = SearchBase::GetSearchInstance())
//            $oSearch->onInstall();

        $oHTML->append(L(<<<EOD
<div class="alert alert-success">
{{L//Great! Your administrator account has been created. Please log in with your login name and password, and you're set to go!}}
</div>
EOD
                      ));
    }
    catch(\Exception $e)
    {
        myDie(toHTML($e));
    }
}
else
{
    $oHTML->addXmlDlg(dirname(__FILE__).'/install4_dlg1.xml',
                      [ '%LOGIN%'        => (defined('PREFILL_ADMIN_LOGIN')) ? PREFILL_ADMIN_LOGIN : '',
                        '%PASSWORD%'     => (defined('PREFILL_ADMIN_PWD')) ? PREFILL_ADMIN_PWD : '',
                        '%EMAIL%'        => (defined('PREFILL_ADMIN_EMAIL')) ? PREFILL_ADMIN_EMAIL : '',
                      ]
                     );
}

$oHTML->close();    # page
$oHTML->flush();
WholePage::EmitFooter();

exit;
