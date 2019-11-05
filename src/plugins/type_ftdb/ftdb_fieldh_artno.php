<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FTArticleNosHandler class
 *
 ********************************************************************/

/**
 *  FieldHandler derivate for fischertechnik article numbers (FIELD_FT_ARTICLENOS ticket field).
 *  See FieldHandler for how field handlers work in general.
 *
 *  FIELD_FT_ARTICLENOS uses the ticket_texts table to store article numbers as JSON.
 *  There is no 1:1 relation between ft kits / parts and article numbers: for the same
 *  part, ft has sometimes reassigned article numbers. On the other hand, article numbers
 *  have also sometimes been reused for other articles after they had not been used for
 *  a few years.
 *
 *  As a result, to make article numbers unambiguous, they have to be used together with
 *  a year. This has the additional benefit that the FTDB can use articles to specify
 *  the lifetime of a ft article (kit or part). The article no. JSON therefore has the
 *  following format:
 *
 *  ```json
 *      [[year,number],...]
 *  ```
 *  where both year and number are strings, not integers. The "year" specifies the first
 *  year that the "number" was used for the product. As a special case, a year with a
 *  null "number" means that the article was discontinued.
 *
 *  Example for the kit "Vorstufe 100v":
 *
 *  ```json
 *    [["1972","30702"],["1975","30111"],["1979",null]]
 *  ```
 *
 *  This means that the kit was introduced as 30702 in 1972; the article no. was then
 *  changed to 30111 in 1975; the kit was discontinued in 1979.
 *
 */
class FTArticleNosHandler extends FieldHandler
{
    public $label = '{{L//Article numbers}}';
    public $help  = <<<EOD
{{L/FTARTNOINTRO/Here you can specify the official article numbers for this kit or part. Each line should specify
the year when the article number was introduced. You can indicate that an article was discontinued in a particular
year by adding a row with that year and leaving the article number blank.}}
EOD
    ;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        FieldHandler::__construct(FIELD_FT_ARTICLENOS);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *
     *  We override the FieldHandler implementation to turn the JSON from the table row into a meaningful
     *  display with year and article number.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        return self::Format($value, FALSE);
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     *
     *  We override the FieldHandler implementation to turn the JSON from the table row into a meaningful
     *  display with year and article number.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        $o = new HTMLChunk();

        if ($o->html = self::Format($value, TRUE))
            $this->highlightSearchTerms($oContext, $o);

        return $o;
    }

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  The FieldHandler base simply adds an entry field, which isn't quite enough
     *  for the JSON of the article numbers. Instead, we hide the entry field and
     *  add a bit more JavaScript GUI code.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,           //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)                      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        $jsonValue = $this->getValue($oPage);

        $oPage->oDlg->addHiddenInputDIV($idControl,
                                        toHTML($jsonValue),
                                        defined('FTDB_DEBUG'));

        WholePage::AddNLSStrings( [
            'add-article-no' => L("{{L//Add this row to the list of article numbers}}"),
            'remove-article-no' => L("{{L//Remove this article number row}}" )
                                  ] );
        WholePage::AddIcon('add-another');

        $args = WholePage::EncodeArgs( [ $idControl, "$idControl-div" ] );
        $args .= ",\n    ".$jsonValue;       // already json-encoded
        WholePage::AddTypescriptCall(FTDB_PLUGIN_NAME, <<<JS
ftdb_initArticleNosEditor($args);
JS
        );
    }

    /**
     *  This gets called by \ref writeToDatabase() for every field before the
     *  new value gets written into the database. This must return a value that
     *  fits into the value column of the field's database table, or, in the
     *  case of FIELDFL_ARRAY, an arra of those values. It can also throw a
     *  APIException with the field name if validation fails.
     *
     *  We override the FieldHandler default, which does no checking, to validate
     *  the JSON that may have been entered by the user.
     *
     * @return mixed
     */
    public function validateBeforeWrite(TicketContext $oContext,    //!< in: TicketContext instance
                                        $oldValue,                  //!< in: current value (or NULL during create ticket)
                                        $newValue)                  //!< in: suggest new value from form field
    {
        // Don't fail on data that's being imported but be strict with what users may be entering.
        if (!Globals::$fImportingTickets)
        {
            if ($newValue)
                if ($aArticleNoPairs = json_decode($newValue))
                {
                    foreach ($aArticleNoPairs as $a2)
                        if (!is_array($a2))
                            throw new APIException($this->fieldname,
                                                   "Invalid data format in ".Format::UTF8Quote($this->fieldname).", expected a two-dimensional array");
                        else
                        {
                            if (count($a2) != 2)
                                throw new APIException($this->fieldname,
                                                       "Invalid data format in ".Format::UTF8Quote($this->fieldname).", expected two array items in sub-array");
                            else
                            {
                                list($y, $an) = $a2;
                                if (    !$y
                                     || ($y < 1960)
                                     || ($y > Timestamp::Now()->getYear())
                                    )
                                    throw new APIException($this->fieldname,
                                                           L('{{L//Invalid year %YEAR% in article numbers}}',
                                                             [ '%YEAR%' => Format::UTF8Quote($y)
                                                             ]));
                                if (    ($an !== NULL)
                                     && (strlen($an) < 5)
                                   )
                                    throw new APIException($this->fieldname,
                                                           L('{{L//Invalid number %AN% in article numbers}}',
                                                             [ '%AN%' => Format::UTF8Quote($an)
                                                             ]));
                            }
                        }
                }
        }

        return $newValue;
    }

    /**
     *  This can get called from a search engine's onTicketCreated() implementation
     *  when a ticket has been created or updated and needs to be indexed for a
     *  search engine. It gets called for every ticket field reported by
     *  \ref ITypePlugin::reportSearchableFieldIDs() and must return the data
     *  that the search engine should index.
     *
     *  We override the FieldHandler default implementation to only store the article
     *  numbers by stripping the years and null values from the JSON text. We return
     *  the article numbers as an array of strings.
     */
    public function makeSearchable(Ticket $oTicket)
    {
        # Store only the article numbers, separated by spaces.
        if ($value = getArrayItem($oTicket->aFieldData, $this->field_id))
        {
            $aStore = [];

            $a = json_decode($value, TRUE);
            foreach ($a as $pair)
            {
                list($year, $noOrNULL) = $pair;
                if ($s = trim($noOrNULL))
                    $aStore[] = $s;
            }

            if (count($aStore))
                return $aStore;
        }

        return NULL;
    }


    /********************************************************************
     *
     *  Newly introduced static methods
     *
     ********************************************************************/

    /**
     *  Static helper for formatValue(), but can be called from elsewhere. This formats
     *  the given article number JSON string (in [[year, number], ...] format) into
     *  either HTML or plain text, with multiple lines.
     */
    public static function Format($json,
                                  $fHTML)
    {
        $out = '';
        if ($json)
        {
            $a = json_decode($json, TRUE);

            // First, convert the list of pairs into a PHP array.
            $a2 = [];
            foreach ($a as $pair)
            {
                list($year, $noOrNULL) = $pair;
                $a2[$year] = $noOrNULL;
            }

            # Now sort it by year, maintaining associations.
            ksort($a2);
            foreach ($a2 as $year => $noOrNULL)
            {
                if ($out)
                {
                    if ($fHTML)
                        $out .= "<br>";
                    $out .= "\n";
                }

                if ($fHTML)
                    $out .= toHTML("$year: ").($noOrNULL ? toHTML($noOrNULL) : Format::MDASH);
                else
                    $out .= "$year: $noOrNULL";
            }
        }

        return $out;
    }
}
