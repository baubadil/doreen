<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  SMTPHost class
 *
 ********************************************************************/

class SMTPHost
{
    public $Host;
    public $SMTPAuth;
    public $Username;
    public $Password;
    public $SMTPSecure;
    public $Port;               # 587: TCP port to connect to

    public $fAllowInsecure = false;

    private static function GetDefineOrThrow($key)
        : string
    {
        if (defined($key))
            return constant($key);

        throw new DrnException("Server mail configuration error: missing value for ".Format::UTF8Quote($key).". Please contact an administrator");
    }

    public static function CreateFromDefines()
    {
        if (!defined('MAIL_SMTP_HOSTS'))
            return NULL;        // sendmail mode

        $o = new self();
        $o->Host = self::GetDefineOrThrow('MAIL_SMTP_HOSTS');
        $o->SMTPAuth = true;
        $o->Username = self::GetDefineOrThrow('MAIL_SMTP_USER');
        $o->Password = self::GetDefineOrThrow('MAIL_SMTP_PASS');
        $o->SMTPSecure = self::GetDefineOrThrow('MAIL_SMTP_SSL_OR_TLS');
        $o->Port = self::GetDefineOrThrow('MAIL_SMTP_PORT');

        if (defined('MAIL_SMTP_INSECURE'))
            $o->fAllowInsecure = true;

        return $o;
    }
}

