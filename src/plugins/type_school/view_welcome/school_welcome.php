<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  IMAPAccountsView class
 *
 ********************************************************************/

/**
 *  Implements the "Welcome" page.
 */
abstract class SchoolWelcome
{
    public static function Emit(string $email,
                                string $token)
    {
        $htmlTitle = L('{{L//Welcome to %DOREEN%!}}');

        $oHTML = new HTMLChunk();
        $oHTML->openPage($htmlTitle, FALSE);

        $htmlEmail = toHTML($email);
        $htmlToken = toHTML($token);

        $idDialog = 'school-welcome';
        $oHTML->openDiv($idDialog);

            $oHTML->addLine(L(<<<HTML
{{L/SCHOOLWELCOME2/
<p>Welcome to %DOREEN%. On this system you can view students and newsletters for
your child's school class and also manage your student's data online.</p>

<p>Your class's parent representative has created an account for you and your e-mail %EMAIL%.
All you have to do now is pick a password for yourself and enter it twice in the fields below. After that,
you will be able to log into the system and view your child's data and that of the other children in your
child's class.</p>}}
HTML
            , [ '%EMAIL%' => '<code>'.$htmlEmail.'</code>' ]
                            ));



            $oHTML->openForm();

            $oHTML->addHidden("$idDialog-email", $htmlEmail);
            $oHTML->addHidden("$idDialog-token", $htmlToken);
            $oHTML->close(); # div

            $oHTML->addPasswordRows($idDialog);

            $oHTML->addErrorAndSaveRow($idDialog,
                                       HTMLChunk::FromEscapedHTML(L("{{L//Set my password!}}")));

            $oHTML->close(); # form

        $oHTML->close();

        WholePage::AddTypescriptCallWithArgs(SCHOOL_PLUGIN_NAME, /** @lang JavaScript */'school_initSetPassword',
                                             [ $idDialog ] );

        WholePage::Emit($htmlTitle, $oHTML);
    }

}
