<?php
/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Includes
 *
 ********************************************************************/

require_once INCLUDE_PATH_PREFIX.'/3rdparty/php-encryption/Exception/CryptoException.php';
require_once INCLUDE_PATH_PREFIX.'/3rdparty/php-encryption/Exception/CannotPerformOperationException.php';
require_once INCLUDE_PATH_PREFIX.'/3rdparty/php-encryption/Exception/CryptoTestFailedException.php';
require_once INCLUDE_PATH_PREFIX.'/3rdparty/php-encryption/Exception/InvalidCiphertextException.php';
require_once INCLUDE_PATH_PREFIX.'/3rdparty/php-encryption/Crypto.php';

use \Defuse\Crypto\Crypto;

/**
 *  Specialized exception class.
 */
class CryptException extends DrnException
{
    public function __construct($msg)
    {
        parent::__construct("Error in Doreen crypt module: ".$msg);
    }
}

/**
 *  Wrapper around php-encryption. See https://github.com/defuse/php-encryption .
 *
 *  From the documentation: Messages are encrypted with AES-128 in CBC mode and are authenticated with HMAC-SHA256 (Encrypt-then-Mac).
 *  PKCS7 padding is used to pad the message to a multiple of the block size. HKDF is used to split the user-provided key into two keys:
 *  one for encryption, and the other for authentication. It is implemented using the openssl_ and hash_hmac functions.
 */
class DrnCrypto
{
    public static $symmetricKey = NULL;

    /********************************************************************
     *
     *  Public methods
     *
     ********************************************************************/

    /**
     *  Returns the symmetric key created at install time, or NULL if it doesn't exist.
     *  The key is loaded on the first call and then cached.
     */
    public static function LoadKey()
    {
        if (!self::$symmetricKey)
        {
            # We could use FileHelpers::GetContents() but that would expose the file name on errors.
            if (!($fp = @fopen(Globals::$fnameEncryptionKey, "rb")))
                throw new CryptException("Failed to load encryption key");
            $key = @stream_get_contents($fp);
            fclose($fp);
            self::$symmetricKey = $key;
        }

        return self::$symmetricKey;
    }

    /**
     *  Encrypts the given data chunk with the given symmetric key, which should have been
     *  loaded via LoadKey().
     *
     *  The key is binary, unless you set $fBase64Encode to TRUE, in which case this calls
     *  self::Base64Encode() beforehand.
     */
    public static function Encrypt($message,
                                   $symmetricKey,        //!< in: key from GenerateKey()
                                   $fBase64Encode)       //!< in: whether to bas64-encode the result
    {
        try
        {
            $encrypted = Crypto::encrypt($message, $symmetricKey);
        }
        catch (\Exception $e)
        {
            throw new CryptException("Failed to encrypt securely");
        }

        if ($fBase64Encode)
            return self::Base64Encode($encrypted);

        return $encrypted;
    }

    /**
     *  Decrypts with the same symmetric key that was used with Encrypt().
     */
    public static function Decrypt($encrypted,
                                   $symmetricKey,
                                   $fBase64Decode)
    {
        if ($fBase64Decode)
            $encrypted = self::Base64Decode($encrypted);

        try
        {
            $message = Crypto::decrypt($encrypted, $symmetricKey);
        }
        catch (\Exception $e)
        {
            throw new CryptException("Failed to decrypt securely");
        }

        return $message;
    }

    /**
     *  Small wrapper around base64_encode().
     *
     *  That is designed to make binary data survive transport through transport layers that are not 8-bit clean, such as mail bodies.
     *  Base64-encoded data takes about 33% more space than the original data.
     *
     *  As opposed to the plailn PHP function, this throws on errors.
     */
    public static function Base64Encode($text)
    {
        if (!($encoded = base64_encode($text)))
            throw new CryptException("Failed to base64-encode");
        return $encoded;
    }

    /**
     *  The reverse to Base64Encode().
     *
     *  As opposed to the plailn PHP function, this throws on errors.
     */
    public static function Base64Decode($text)
    {
        if (!($decoded = base64_decode($text, TRUE)))       // strict
            throw new CryptException("Failed to base64-decode");
        return $decoded;
    }

    /**
     *  Calls GenerateKey() and writes the resulting key into the installationd directory.
     *  Called only during install and by the CLI init-key command.
     */
    public static function CreateKeyFile()
    {
        $symmetricKey = DrnCrypto::GenerateKey();
        if (FALSE === file_put_contents(Globals::$fnameEncryptionKey, $symmetricKey, FILE_BINARY))
            die("Error: failed to write file ".Globals::$fnameEncryptionKey."!\n");
    }

    /**
     *  Generates a new symmetric key and returns it. The result is binary and not plain text.
     */
    public static function GenerateKey()
    {
        try
        {
            $key = Crypto::createNewRandomKey();
        }
        catch (\Exception $e)
        {
            throw new CryptException("Failed to generate secure key");
        }

        return $key;
    }
}