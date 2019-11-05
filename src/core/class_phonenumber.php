<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;


/**
 *  Helper class to deal with phone numbers.
 */
class PhoneNumber
{

    /**
     *  Attempts to normalize the given phone number using the given country's conventions.
     *
     *  This will return something like +4917912345 for (0179) 12345 and 'DE'.
     */
    public static function Normalize($phone,
                                     $countryCode,      //!< in: 2-character country code, e.g. 'DE'
                                     $compact = TRUE,
                                     &$fIsMobile)         //!< out: set to TRUE if number is mobile, FALSE otherwise
    {
        $oPhoneUtil = PhoneNumberUtil::getInstance();
        try
        {
            $oParsed = $oPhoneUtil->parse($phone, $countryCode);
            $format = ($compact)
                ? PhoneNumberFormat::E164
                : PhoneNumberFormat::INTERNATIONAL;

            if (($type = $oPhoneUtil->getNumberType($oParsed)) == PhoneNumberType::MOBILE)
                $fIsMobile = TRUE;
            else
                $fIsMobile = FALSE;
//            Debug::Log(0, "phone number type: $type");

            return $oPhoneUtil->format($oParsed, $format);
        }
        catch (NumberParseException $e)
        {
            Debug::Log(Debug::FL_IMAP, "libphonenumber exception: ".$e->getMessage());
            return NULL;
        }
    }
}

