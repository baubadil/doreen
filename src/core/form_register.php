<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *
 */
function formRegister()
{
    if (    ( LoginSession::$ouserCurrent !== NULL)
         && ( LoginSession::$ouserCurrent->uid != User::GUEST)
       )
        throw new DrnException(L("{{L//Why would you want to register when you're already logged in?}}"));

    $htmlTitle = L('{{L//Register a new user account}}');

    $oHTML = new HTMLChunk();
    $oHTML->openPage($htmlTitle, FALSE);

    $oHTML->addLine("This function has been deactivated.");

    $oHTML->close();    # page

    WholePage::Emit($htmlTitle, $oHTML);
}

/**
 *
 */
function formLostPass($email = NULL,
                      $token = NULL)
{
    $htmlTitle = L('{{L//Did you lose your password?}}');

//    Debug::Log(print_r($_SERVER, TRUE));

    $oHTML = new HTMLChunk();
    $oHTML->openPage($htmlTitle, FALSE);

    /**
     *  \page reset_password How Doreen allows for resetting user passwords
     *
     *  This has the following steps (following http://stackoverflow.com/questions/6585649/php-forgot-password-function):
     *
     *   1)  When user asks to reset their password, make them enter their email address. (This form.)
     *
     *   2)  After the user presses "Submit", don't indicate if that email address was valid or not,
     *       just say "mail has been sent". Otherwise users could probe which email addresses have been
     *       registered on the site.
     *
     *   3)  If the email address was valid, generate a token and insert it into the database in
     *       the user's record, together with a timestamp so that the token can only be used for 2 hours.
     *       Then send an email to the user along with a link to your http*s* reset page (token and email address in the url).
     *
     *   4)  When user clicks on the link leading to the reset page, offer to set a new password. For additional
     *       security, we'll have the user enter the login name here too.
     *
     *   5)  The second submit will have the token, email, login name (as entered by the user) and the new password.
     *       Validate all these and only if they match the database, reset the password.
     *
     *  We'll also set a cap on the total no. of reset requests that can be attempted within 10 minutes to
     *  make things more difficult for the script kiddies.
     *
     *  Recommended further reading:
     *
     *   --  https://blog.skullsecurity.org/2011/hacking-crappy-password-resets-part-1
     *
     *   --  http://stackoverflow.com/questions/549/the-definitive-guide-to-form-based-website-authentication
     */

    $idDialog = '';
    $apiFields = [];
    $apiCmd = '';

    if (    !$email
         && !$token
       )
    {
        $idDialog = 'resetPasswordForm1';
        $apiCmd = 'resetpassword1';
        $apiFields = [ 'email' ];
        $oHTML->addLine(L("<p>{{L//If you have forgotten your password, you can enter the email address that you have previously used with your %DOREEN% account below.}}</p>"));

        $oHTML->openDivLegacy("id=\"$idDialog\"");
        $oHTML->openForm();

        $oHTML->addEntryFieldRow('email',
                                 "$idDialog-email",
                                 L("{{L//Email address}}"),     # label
                                 L("{{L//Email}}"),             # placeholder
                                 TRUE,                          # $fRequired
                                 L("{{L//Instructions for how to reset your password will be sent to this address.}}"),
                                 'mail');                       # icon

        $oHTML->addErrorAndSaveRow($idDialog,
                                   HTMLChunk::FromEscapedHTML(L("{{L//Send email}}")));

        $oHTML->close(); # form
        $oHTML->close(); # div

        $nlsDone = L("{{L//An email has been sent to the above address. Please follow the instructions in that email to have your password reset.}}");
    }
    else
    {
        $idDialog = 'resetPasswordForm2';
        $apiCmd = 'resetpassword2';
        $apiFields = [ 'email', 'token', 'login', 'password', 'password-confirm' ];

        $htmlEmail = toHTML($email);
        $htmlToken = toHTML($token);

        $oHTML->addLine(L("<p>{{L//Welcome back! You have been confirmed as the owner of the email address %{%EMAIL%}%. Below you can reset your password.}}</p>", [ '%EMAIL%' => $htmlEmail ] ));

        $oHTML->openDivLegacy('id="resetPasswordForm2"');
        $oHTML->openForm();

        $oHTML->openDivLegacy("class=\"hidden\"");
        $oHTML->addLine("<input type='hidden' id='resetPasswordForm2-email' value='$htmlEmail' />");
        $oHTML->addLine("<input type='hidden' id='resetPasswordForm2-token' value='$htmlToken' />");
        $oHTML->close(); # div

        $oHTML->addEntryFieldRow('text',
                                 "$idDialog-login",
                                 L("{{L//Your login name}}"),  # label
                                 L("{{L//Login}}"),
                                 FALSE,                     # $fRequired; we validate this in the API
                                 L("{{L//To make sure you are not accidentally resetting someone else's password, please enter your login name as well.}}"),                      # help line
                                 NULL);                     # icon

        $oHTML->addPasswordRows($idDialog);

        $oHTML->addErrorAndSaveRow($idDialog,
                                   HTMLChunk::FromEscapedHTML(L("{{L//Reset the password!}}")));

        $oHTML->close(); # form
        $oHTML->close(); # div

        $nlsDone = L("{{L//Your password has been reset. You can now use the above login and your new password to log into %DOREEN%.}}");
    }

    WholePage::AddNLSStrings([
        'registerDone' => $nlsDone
    ]);
    WholePage::AddJSAction('core', 'initRegisterForm', [
        $idDialog,
        $apiFields,
        $apiCmd,
    ], TRUE);
    $oHTML->close();    # page

    WholePage::Emit($htmlTitle, $oHTML);
}
