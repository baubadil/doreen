<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  Static class which produces an icon, in most cases from Font Awesome.
 *  This way we can maybe later replace Font Awesome with something else.
 *
 *  This mostly provides some aliases to Font Awesome icons, but also provides
 *  some extra special code for spinners. If $i is the name of a Font Awesome
 *  icon (wihout the "fa-" prefix), it will just be passed through and work.
 *  See https://fontawesome.com/v4.7.0/icons/ for the list.
 *
 *  Commonly used icons in Doreen are:
 *
 *  Formatting:
 *      bold italic underline strikethrough code list-ol list-ul table edit
 *
 *  Other:
 *      lock magic cog user check quote-left quote-right arrow-{left|right|down}
 *      university refresh server star eye calendar share link
 */
class Icon
{
    public static function Get($i,
                               $fMenu = false)
        : string
    {
        $base = ($fMenu) ? 'fa fa-fw' : 'fa fa-lg';

        switch ($i)
        {
            case 'import':
                return '<i class="'.$base.' fa-download"></i>';

            case 'poweroff':
                return '<i class="'.$base.' fa-power-off"></i>';

            case 'password':
                return '<i class="'.$base.' fa-key"></i>';

            case 'remove':
                return '<i class="'.$base.' fa-trash"></i>';

            case 'spinner':
//                return '<i class="'.$base.' fa-spinner fa-spin"></i>';
                return '<span class="'.Globals::CSS_SPINNER_CLASS.'"><div class="drn-bounce1"></div><div class="drn-bounce2"></div><div class="drn-bounce3"></div></span>';

            case 'add-another':
                return '<i class="'.$base.' fa-plus"></i>';

            case 'add-another-user':
                return '<i class="'.$base.' fa-user-plus"></i>';

            case 'checkbox_checked':
                return '<i class="'.$base.' fa-check-square-o"></i>';
            case 'checkbox_unchecked':
                return '<i class="'.$base.' fa-square-o"></i>';

            case 'radio_checked':
                return '<i class="'.$base.' fa-dot-circle-o"></i>';
            case 'radio_unchecked':
                return '<i class="'.$base.' fa-circle-o"></i>';

            case 'realname':
                return '<i class="'.$base.' fa-quote-left"></i>';

            case 'mail':
                return '<i class="'.$base.' fa-envelope-o"></i>';

            case 'city':        // Only FA > 5 has this, remove this once we upgrade.
                return '<i class="'.$base.' fa-taxi"></i>';

            case 'sort_amount_asc':
                return '<i class="'.$base." fa-sort-amount-asc\"></i>";

            case 'sort_amount_desc':
                return '<i class="'.$base." fa-sort-amount-desc\"></i>";

            case 'sort_alpha_asc':
                return '<i class="'.$base." fa-sort-alpha-asc\"></i>";

            case 'sort_alpha_desc':
                return '<i class="'.$base." fa-sort-alpha-desc\"></i>";

            case 'cancel':
                return '<i class="'.$base." fa-ban\"></i>";

            case 'thumbsup':
                return '<i class="'.$base." fa-thumbs-o-up\"></i>";
            case 'thumbsdown':
                return '<i class="'.$base." fa-thumbs-o-down\"></i>";

            case 'ticket-id':
                return '<b>#</b>';

            case 'menu':
                return '<i class="'.$base." fa-bars\"></i>";

            case 'crash':
                return '<i class="'.$base." fa-frown-o\"></i>";

            case 'document':
                return '<i class="'.$base." fa-file-text-o\"></i>";

            case 'help':
                return '<i class="'.$base." fa-question-circle-o\"></i>";

            default:
                return '<i class="'.$base." fa-$i\"></i>";
        }
    }

    /**
     *  Second factory that calls \ref Get() in turn, but produces a
     *  valid HTMLChunk.
     *
     *  @return HTMLChunk
     */
    public static function GetH($i, $fMenu = false)
        : HTMLChunk
    {
        $o = new HTMLChunk();
        $o->html = self::Get($i, $fMenu);
        return $o;
    }

    public static function Country($c)
    {
        return "<img width='24' src=\"".Globals::$rootpage."/img/$c.png\">";
    }
}
