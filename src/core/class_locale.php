<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  Encapsulates everything related to National Language Support in Doreen,
 *  including gettext translations, pretty quotes and number and currency formatting.
 *
 *  The locale is determined at script runtime. index.php calls \ref Init(), which
 *  uses the browser language settings by default, which can be overridden by the
 *  user in the languages drop-down. Currently de_DE and en_US are fully supported:
 *  that is, with date/time/monetary/quotes/number formatting and complete Doreen
 *  translations. All these things change immediately when the language is changed.
 *
 *  See \ref drn_nls for the rationale behind all this.
 */
abstract class DrnLocale
{
    /* Quotation marks: http://www.matthias-kammerer.de/SonsTypo2.htm
     * NOTE 2018-02-07: PHP 7.0 supports the \u{XXXX} syntax in double quotes to specify
     * Unicode codepoints (in hex) directly. I have removed all HTML entities here and
     * the run-time conversions that convert those to UTF-8, instead this now uses
     * the Unicode code points directly.
     */
    const A_LOCALES  = [ 'de_DE' => [ 'Deutsch',
                                      '%e. %B %Y',                # [1] strftime() for 2. Oktober 2015
                                      "\u{201E}",                 # [2] opening pretty quote
                                      "\u{201C}",                 # [3] closing pretty quote
                                      ',',                        # [4] decimal point
                                      "\u{202F}",                 # [5] thousands separator: NARROW NO-BREAK SPACE
                                      '%e. %B %Y %H:%M:%S',       # [6] strftime() for 2. Oktober 2015 13:01:25
                                      'de_DE',                    # [7] locale to use for moments.js (client-side)
                                      '%V%N%C',                   # [8] monetary string
                                      '%x',                       # [9] strftime() short timestamp
                                    ],
                         'en_US' => [ 'English (U.S.)',
                                      '%B %e, %Y',                # strftime() for October 2, 2015
                                      "\u{201C}",                 # [2] opening pretty quote
                                      "\u{201D}",                 # [3] closing pretty quote
                                      '.',                        # [4] decimal point
                                      ',',                        # [5] thousands separator
                                      '%B %e, %Y %l:%M:%S %p',    # [6] strftime() for October 2, 2015 01:01:25 AM
                                      'en_US',                    # [7] locale to use for moments.js (client-side)
                                      '%V%N%C',                   # [8] monetary string
                                      // '%C%V',                     # [8] monetary string
                                      '%x',                       # [9] strftime() short timestamp
                                    ],
//                         'fr_FR' => [ 'Français',
//                                      '%e. %B %Y',                # [1] strftime() for 2. Oktober 2015?
//                                      '\u{00AB}',                 # [2] opening pretty quote
//                                      '\u{00BB}',                 # [3] closing pretty quote
//                                      ',',                        # [4] decimal point
//                                      '.',                        # [5] thousands separator
//                                      '%e. %B %Y %H:%M:%S',       # [6] strftime() for 2. Oktober 2015 13:01:25
//                                      'fr_FR',                    # [7] locale to use for moments.js (client-side)
//                                      '%V%N%C',                   # [8] monetary string
//                                    ],
    ];

    const GETTEXT_DEFAULT_DOMAIN = 'doreen';

    const NAME              = 0;
    const DATEFMT           = 1;
    const OPENQUOTE         = 2;
    const CLOSEQUOTE        = 3;
    const DECIMAL           = 4;
    const THOUSANDS         = 5;
    const DATETIMEFMT       = 6;
    const MOMENTSJS_LOCALE  = 7;
    const MONETARYSTRING    = 8;        # Allowed characters: %N = amount, %N = non-breaking space, %C = currency symbol
    const DATESHORTFMT      = 9;

    const USER_LOCALE = 'lang';     // Key for the user value that contains the user selected langauge.

    private static $locale;
    private static $aCurrent;

    /**
     *  Initializes the locale system. Called once on startup only; do not call this manually. This is in a separate
     *  function from \ref Globals::Init() because we need session support, which may not have been initialized before.
     */
    public static function Init()
    {
        $translationsDir = TO_HTDOCS_ROOT.'/../locale';

        $locale = 'en_US';

        if (isset($_SESSION['locale']))
        {
            $locale = $_SESSION['locale'];
            Debug::Log(Debug::FL_LANG, "found locale \"$locale\" in session data");
        }
        else if (    ($oUser = LoginSession::IsUserLoggedIn())
                  && ($storedLocale = $oUser->getExtraValue(self::USER_LOCALE)))
        {
            $locale = $storedLocale;
            Debug::Log(Debug::FL_LANG, "found locale \"$locale\" in user settings");
        }
        else if (php_sapi_name() != 'cli')
        {
//            Debug::Log(Debug::FL_LANG, "no locale in session, trying browser settings");

            if ($lang = getArrayItem($_SERVER, 'HTTP_ACCEPT_LANGUAGE'))
                switch (substr($lang, 0, 2))
                {
                    case 'de':
                        $locale = 'de_DE';
                    break;
                }

            Debug::Log(Debug::FL_LANG, "found locale \"$locale\" in browser settings");
        }

        self::Set($locale, FALSE);

        $rcdir = bindtextdomain(self::GETTEXT_DEFAULT_DOMAIN, $translationsDir);
        Debug::Log(Debug::FL_LANG, "bindtextdomain returned $rcdir");
        bind_textdomain_codeset(self::GETTEXT_DEFAULT_DOMAIN, 'UTF-8');
        if (!(textdomain(self::GETTEXT_DEFAULT_DOMAIN)))
            Debug::Log(Debug::FL_LANG, "*** WARNING: textdomain() returned NULL");
    }

    /**
     *  Returns the current locale setting. If $fMajor == FALSE (the default), this
     *  returns both major and minor locale strings (e.g. `en_US`); otherwise it
     *  returns the upper-case major string only (e.g. `EN`).
     */

    public static function Get($fMajor = FALSE)
    {
        if ($fMajor)
            return strtoupper(substr(self::$locale, 0, 2));
        return self::$locale;
    }

    /**
     *  Returns the entire locale data array for the current locale. See \ref GetItem().
     */
    public static function GetCurrent()
    {
        return self::$aCurrent;
    }

    /**
     *  Returns an array with locale => description string pairs, e.g. "de_DE" => "Deutsch",
     *  for all languages supported. Currently two items only.
     *
     * @return string[]
     */
    public static function GetAll()
    {
        $aReturn = [];
        foreach (self::A_LOCALES as $id => $a2)
            $aReturn[$id] = $a2[self::NAME];

        return $aReturn;
    }

    /**
     *  Returns the given locale item for the current locale. The following indices
     *  are valid:
     *
     *   -- DrnLocale::NAME
     *
     *   -- DrnLocale::DATEFMT
     *
     *   -- DrnLocale::OPENQUOTE
     *
     *   -- DrnLocale::CLOSEQUOTE
     *
     *   -- DrnLocale::DECIMAL
     *
     *   -- DrnLocale::THOUSANDS
     *
     *   -- DrnLocale::DATETIMEFMT
     *
     *   -- DrnLocale::MOMENTSJS_LOCALE
     *
     *  It shouldn't be necessary to use these low-level items directly. Use the methods
     *  in the Format class instead, which call this in turn as necessary.
     */
    public static function GetItem($index)
    {
        return self::$aCurrent[$index];
    }

    /**
     *  Throws if the given string is not a valid supported locale. Only de_DE and en_US
     *  are currently supported.
     */
    public static function Validate($str)
    {
        foreach (self::A_LOCALES as $id => $a2)
            if ($str == $id)
                return;

        throw new DrnException(L("{{L//%LANG% is not a supported locale}}", [ '%LANG%' => "\"$str\"" ]));
    }

    /**
     *  Sets the current locale for gettext and such. Called from \ref Init() and again if the
     *  user switches languages.
     *
     *  This calls setlocale() for LC_MESSAGES *only*, not for LC_ALL. Otherwise all of PHP blows
     *  up and starts using the non-English decimal point everywhere. My intuition was that this
     *  would only affect number_format() and the like, but it immediately affects echo and even
     *  passing numbers to to PostgreSQL via pg_send_query_params().
     *
     *  Proof:
     *  ```php
            php -r 'setlocale(LC_ALL, "de_DE.UTF-8"); $a = [ 0.1 ]; print_r($a); echo $a[0]."\n";'
            Array
            (
                [0] => 0,1
            )
            0,1
     *  ```
     */
    public static function Set($locale,               //!< in: short locale string like 'en_US' without suffixes
                               $fStore = TRUE)
    {
        # Make sure we support that language. Otherwise ignore it.
        $aLocales = self::A_LOCALES;
        if (!isset($aLocales[$locale]))
            $locale = 'en_US';

        Debug::Log(Debug::FL_LANG, __METHOD__."($locale): self::locale=".self::$locale);

        if ($locale != self::$locale)
        {
            self::$locale = $locale;
            self::$aCurrent = $aLocales[$locale];

            # Append UTF-8 to the locale name. As to whether it should be "utf8" or "UTF-8", glibc seems to
            # translate UTF-8 into utf8 internally, and that seems to be the safer option to use in setlocale()
            # and the "emerging standard": http://unix.stackexchange.com/questions/186963/what-is-the-proper-encoding-name-to-use-in-locale-for-utf-8
            $localeUTF8 = "$locale.UTF-8";

            putenv("LANGUAGE=$localeUTF8"); /* 2017-08-02: On Debian stretch, using LC_MESSAGES in putenv() no longer works.
                        It worked fine with Jessie, and it works fine on Gentoo still. It must now be LANGUAGE or gettext
                        does not find any messages any more!! */
            if (!($res = setlocale(LC_MESSAGES, $localeUTF8)))
                myDie("Cannot set locale $localeUTF8. Please contact the system administrator.");

            Debug::Log(Debug::FL_LANG, __METHOD__."($locale): setlocale(LC_MESSAGES) returned ".Format::UTF8Quote($res));

            # We can't get the decimal point from localeconv() because we can't use
            # setlocale(LC_NUMBERS), see above.

            if ($fStore)
            {
                # We already closed the sesssion for writing so re-open it.
                session_start();
                Debug::Log(Debug::FL_LANG, __METHOD__."(): storing new locale ".Format::UTF8Quote($locale));
                $_SESSION['locale'] = $locale;
                session_write_close();
                if ($oUser = LoginSession::IsUserLoggedIn())
                    $oUser = LoginSession::$ouserCurrent->setKeyValue(self::USER_LOCALE, $locale, FALSE);
            }
        }
    }

    /**
     *  Splits the given number according to the current locale's decimal point.
     *  Returns a list with exactly one or two items, or throws.
     */
    private static function SplitFloat($amount,
                                       $dlgfield,
                                       &$dec)           //!< out: current decimal point
        : array
    {
        $dec = self::GetItem(self::DECIMAL);
        if (is_array($amount))
            throw new DrnException("Expected string, received array");
        $llParts = explode($dec, $amount);

        foreach ($llParts as $part)
            if (!isInteger($part))
                throw new InvalidFloatException($dlgfield, $amount, $dec);

        if (count($llParts) > 2)
            throw new InvalidFloatException($dlgfield, $amount, $dec);

        return $llParts;
    }

    /**
     *  Validates the given float according to the decimal point from the current locale
     *  and returns the number as an actual float.
     *  Throws APIException with the given dlgfield if the format is not recognized.
     */
    public static function ValidateFloat($amount, $dlgfield)
        : float
    {
        if (is_array($amount))
            throw new ApiException($dlgfield, "Internal error: expected float, received array");
        $dec = NULL;
        try
        {
            $llParts = self::SplitFloat($amount, $dlgfield, $dec);
        }
        catch(DrnException $e)
        {
            // Fall back to dots in case the client already normalized the float.
            if (strpos($amount, '.') !== FALSE && is_numeric($amount))
                return floatval($amount);
            throw $e;
        }
        if (count($llParts) == 1)
            return (float)$llParts[0];
        return (float)$llParts[0].'.'.$llParts[1];
    }

    /**
     *  Validates the given monetary amount according to the decimal point from the current locale.
     *  Throws APIException with the given dlgfield if the format is not recognized.
     *
     *  This checks for whether the amount has at most two digits after the decimal point.
     *  Both postive and negative amounts are accepted. Returns an array with 'locale',
     *  'number' (float), 'numberFormatted' (string) fields.
     *
     *  Examples:
     *
     *   -- Input "123.12" with locale en_US returns [ locale: "en_US", number: 123.12, numberFormatted: "123.12" ]
     *
     *   -- Input "123.12" with locale de_DE throws an error
     *
     *   -- Input "123,12" with locale de_DE returns [ locale: "de_DE", number: 123.12, numberFormatted: "123,12" ]
     */
    public static function ValidateMonetaryAmount($amount,
                                                  $dlgfield)        //!< in: for APIException
    {
        $dec = NULL;
        $llParts = self::SplitFloat($amount, $dlgfield, $dec);
        if (count($llParts) == 1)
            $llParts[1] = "00";
        else
            // count must be 2 now
            if (strlen($llParts[1]) > 2)
                throw new APIException($dlgfield, L("{{L//%AMOUNT% is not a valid monetary amount: there must be at most two digits after the decimal point %DEC%}}",
                                                    [ '%AMOUNT%' => Format::UTF8Quote($amount),
                                                      '%DEC%' => Format::UTF8Quote($amount),
                                                    ]));

        // Must have 2 valid particles now.
        return [
            'locale' => self::$locale,
            'number' => floatval($llParts[0].'.'.$llParts[1]),
            'numberFormatted' => $llParts[0].$dec.$llParts[1]
        ];

    }
}
