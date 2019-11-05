<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FTBlurb
 *
 ********************************************************************/

/**
 *  Title page functionality for the FTDB plugin. See the Blurb class for how this works.
 */
class FTBlurb extends Blurb
{
    /**
     *  Must return a pair of strings with ID and descriptive name for the blurb.
     *  The ID is stored in GlobalConfig, the descriptive name is displayed
     *  in the global settings.
     */
    public function getID()
    {
        return [ 'ftdb_intro', 'FTDB introduction' ];
    }

    /**
     *  Called by the Doreen main page code to give plugins a chance to add HTML to the main page.
     *  To do so, the plugin can add to the given HTMLChunk instance.
     */
    public function emit(&$htmlTitle,
                         HTMLChunk $oHTML)
    {
        $htmlTitle = L("{{L//Welcome to the FTDB!}}");

//        $ticketslink = Globals::$rootpage."/tickets";

        $aRootCategoryHTML = [];
        if ($aRootCategories = FTCategory::GetRootCategories())
            foreach ($aRootCategories as $id => $oCategory)
                $aRootCategoryHTML[$oCategory->name] = FieldHandler::FormatDrillDownButton(NULL,
                                                                                           FALSE,
                                                                                           $oCategory->makeDrillDownURL(),
                                                                                           HTMLChunk::FromString($oCategory->name))->html;

        ksort($aRootCategoryHTML);
        $htmlCategories = implode(' ', $aRootCategoryHTML);

        $oHTML->addLine(L(<<<HTML
<p>Willkommen bei der neuen fischertechnik-Datenbank! Hier gibt es fast alle Informationen und Abbildungen 
von Baukästen (mit Stücklisten), Einzelteilen, Anleitungen und Werbung von fischertechnik, fischergeometric, fischerform 
und fischerTiP.</p>

<p>Wie findet man etwas?</p>

<ul>
<li><p>Einerseits kannst Du etwas in die <b>Suchbox</b> oben eingeben (zum Beispiel "Baustein 30"). Anschließend werden alle 
Teile und Baukästen angezeigt, die dem Suchbegriff entsprechen.</p> 
<p>Anschließend kannst Du das Suchergebnis über Filter weiter verfeinern (etwa wenn Du nur nach Einzelteilen suchst).</p>
<p>Die Suche ist einfach zu benutzen, aber auch sehr leistungsfähig: klicke oben auf das Fragezeichen neben der Suchbox,
um weitere Informationen zu erhalten.</p>
</li>

<li>Du kannst auch direkt in den <b>Kategorien</b> stöbern: %CATEGORIES%</li>
</ul>

<div class="alert alert-warning"><p> <b>Update 7. Oktober 2018:</b> Nachdem die FTDB 3.0 jahrelang auf einer Testseite lief,  
ist sie jetzt endlich auf diese Domain ft-datenbank.de umgezogen. Für Anregungen und Verbesserungsvorschläge sind wir dankbar!
<a href="https://forum.ftcommunity.de/viewforum.php?f=32">Aktuelle Diskussion im Forum der ft:c ist hier</a>.</p>
</div>
HTML
            , [ '%CATEGORIES%' => $htmlCategories ] ));
    }
}
