<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  UUID class
 *
 ********************************************************************/

abstract class UUID
{
    /**
     *  Creates a new UUID in "short caps" format.
     */
    public static function Create()
    {
        # PHP sprintf upper-cases hex characters.
        return sprintf('%04X%04X%04X%04X%04X%04X%04X%04X',
                       mt_rand(0, 65535),
                       mt_rand(0, 65535),
                       mt_rand(0, 65535),
                       mt_rand(16384, 20479),
                       mt_rand(32768, 49151),
                       mt_rand(0, 65535),
                       mt_rand(0, 65535),
                       mt_rand(0, 65535));
    }

    const REGEX_UUID_LONG  = '/^([0-9A-Z]{8})-([0-9A-Z]{4})-([0-9A-Z]{4})-([0-9A-Z]{4})-([0-9A-Z]{12})$/i';
    const REGEX_UUID_SHORT = '/^([0-9a-z]{8})([0-9a-z]{4})([0-9a-z]{4})([0-9a-z]{4})([0-9a-z]{12})$/i';

    /**
     *  Returns an array of five hexadecimal UUID components if the given string is in UUID
     *  "long" format. Otherwise it returns NULL.
     */
    public static function TestLong($uuid)
    {
        if (    (strlen($uuid) == 36)      # 87891aa2-023d-d810-218f-dcd665ae6850 format
             && (preg_match(self::REGEX_UUID_LONG, $uuid, $aMatches))
           )
            return $aMatches;

        return NULL;
    }

    /**
     *  Returns an array of five hexadecimal UUID components if the given string is in UUID
     *  "short" format. Otherwise it returns NULL.
     */
    public static function TestShort($uuid)
    {
        if (    (strlen($uuid) == 32)
             && (preg_match(self::REGEX_UUID_SHORT, $uuid, $aMatches))
           )
            return $aMatches;

        return NULL;
    }

    /**
     *  Normalizes the given UUID string to "short caps" format, which means upper-casing it and removing dashes.
     *
     *  Throws if we can't parse the input, unless $fThrow is FALSE, in which case we return NULL.
     *
     * @return string | NULL
     */
    public static function NormalizeShortCaps($uuid,
                                              $fThrow = TRUE)
    {
        if (    ($aMatches = self::TestLong($uuid))
             || ($aMatches = self::TestShort($uuid))
           )
            return strtoupper($aMatches[1].$aMatches[2].$aMatches[3].$aMatches[4].$aMatches[5]);

        if ($fThrow)
            throw new DrnException("Invalid format in UUID $uuid");

        return NULL;
    }

    /**
     *  Normalizes the given UUID string to "long lower" format, which means lower-casing it and adding dashes.
     *
     *  This is the format returned by PostgreSQL for data of the UUID type.
     *
     *  Throws if we can't parse the input, unless $fThrow is FALSE, in which case we return NULL.
     *
     * @return string | NULL
     */
    public static function NormalizeLong($uuid,
                                         $fThrow = TRUE)
    {
        if (    ($aMatches = self::TestLong($uuid))
             || ($aMatches = self::TestShort($uuid))
           )
            return strtolower($aMatches[1].'-'.$aMatches[2].'-'.$aMatches[3].'-'.$aMatches[4].'-'.$aMatches[5]);

        if ($fThrow)
            throw new DrnException("Invalid format in UUID $uuid");

        return NULL;
    }

}