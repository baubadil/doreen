<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


use MongoDB\BSON\Javascript;

/********************************************************************
 *
 *  FTContains class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_FT_CONTAINS. See FieldHandler for how these work.
 *
 *  FIELD_FT_CONTAINS is mostly used with kits and contains a list of parts (other tickets)
 *  that are contained in the kit. It has FIELDFL_ARRAY and FIELDFL_ARRAY_COUNT set and
 *  acts like FIELD_PARENTS.
 */
class FTContainsHandler extends ParentsHandler
{
    public $label = '{{L//Contents}}';
    public $help  = '{{L//If this is a kit containing other parts, you can specify a complete parts list here (or you can do so later).}}';

    public static $nlsNoOtherParts = "{{L//This article contains no other parts.}}";


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_FT_CONTAINS);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     *
     *  We override the parent to display a table for an AJAX parts list, that
     *  gets loaded in a second request.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        if ($oContext->mode == MODE_READONLY_DETAILS)
        {
            /* We get the list of child tickets in $value. We only need to bother with
               an extra AJAX request if there are any child tickets at all. */
            if ($value)
            {
                $oChunk = new HTMLChunk();
                $dlgid = 'ft_partslist';
                $oChunk->addAjaxTable($dlgid,
                                      [ 'Icon',
                                        'Name',
                                        'Article no.',
                                        'Count' ],
                                      [ 'data-align="center" data-valign="middle"',
                                        'data-align="left" data-valign="middle"',
                                        'data-align="left" data-valign="middle"',
                                        'data-align="center" data-valign="middle"' ],
                                      [ '%ICON%',
                                        '%NAME%',
                                        '%ARTNO%',
                                        '%COUNT%' ]
                                     );
                WholePage::AddTypescriptCallWithArgs(FTDB_PLUGIN_NAME, /** @lang JavaScript */'ftdb_initPartsListTable',
                                                     [ $dlgid,
                                                       $oContext->oTicket->id ] );
            }
            else
                $oChunk = HTMLChunk::FromString(L(self::$nlsNoOtherParts));
        }
        else
        {
            $oChunk = new HTMLChunk();

            if ($value)
                $this->formatTicketsList($oChunk,
                                         $oContext,
                                         $value,
                                         -5,
                                         "{{L//%COUNT% different parts}}");
        }

        return $oChunk;
    }

    /**
     *  Called for ticket details in MODE_READONLY_DETAILS to add a Bootstrap row div
     *  to the page's HTML to display the ticket data.
     *
     *  Display the whole row only if we have a value.
     *
     * @return void
     */
    public function appendReadOnlyRow(TicketPageBase $oPage)               //!< in: TicketPageBase instance with ticket dialog information
    {
        if ($value = $this->getValue($oPage))
            $this->addReadOnlyColumnsForRow($oPage,
                                            NULL,
                                            $this->formatValueHTML($oPage, $value));
    }

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  We override the FieldHandler implementation to provide the editor for the
     *  list of kit contents.
     */
    public function addDialogField(TicketPageBase $oPage,          //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        $a = self::GetPartsList($oPage->oTicket->id, -1);

        $value = '';
        if ($aResults = getArrayItem($a, 'results'))
        {
            $aValues = [];
            foreach ($aResults as $a2)
            {
                $idPart = $a2['ticket_id'];
                $c = $a2['ft_count'];
                $aValues[] = "$idPart:$c";
            }
            $value = implode(',', $aValues);
        }

        $oPage->oDlg->addHiddenInputDIV($idControl,
                                        toHTML($value),
                                        defined('FTDB_DEBUG'));

        WholePage::AddNLSStrings( [
            'remove-part' => L("{{L//Remove this part row}}" ),
            'duplicate-part' => L("{{L//Part is already in the list}}")
        ] );

        $ticketTypeId = TicketType::FindFromGlobalConfig(PluginFTDB::CONFIGKEY_PART_TYPE_ID, FALSE)->id;
        $oPage->oDlg->openGridRow();
        $oPage->oDlg->openGridColumn(8);
        //TODO filter parts that are already in the list (and make JS update that filter)
        $oPage->oDlg->addTicketPicker("$idControl-add",
                                      [],
                                      [
                                          FIELD_TYPE => [
                                              $ticketTypeId
                                          ]
                                      ],
                                      FALSE);
        $oPage->oDlg->close();
        $oPage->oDlg->openGridColumn(2);
        $oPage->oDlg->addInput(
                               'number',
                               "$idControl-add-count",
                               '',
                               1
        );
        $oPage->oDlg->close();
        $oPage->oDlg->openGridColumn(2);
        $helpPart = L("{{L//Add to parts list}}" );
        $icon = Icon::Get('add-another');
        $oPage->oDlg->addLine(<<<HTML
<span class="drnFTAddButton">&nbsp;<a href="#" data-toggle="tooltip" data-placement="auto" title="$helpPart" id="$idControl-add-submit">$icon</a></span>
HTML
        );
        $oPage->oDlg->close();
        $oPage->oDlg->close();

        WholePage::AddTypescriptCallWithArgs(FTDB_PLUGIN_NAME, /** @lang Javascript */ 'ftdb_initPartsListEditor',
                                             [ $idControl,
                                               "$idControl-div",
                                               $aResults ] );
    }



    /********************************************************************
     *
     *  Newly introduced static methods
     *
     ********************************************************************/

    /**
     *  Static handler for the asynchronous GET /api/ft-partslist REST API.
     *
     *  $page is 1-based and should be 1 on the first call. $cperPage should
     *  have the no. of items to be displayed per page.
     *
     *  As a special case, if $page is -1, then all items are returned.
     */
    public static function GetPartsList($idKit,
                                        $page)
    {
        $cPerPage = 10;
        if ($page == -1)
        {
            $page = 1;
            $cPerPage = 0;
        }

        $aParts = [];

        $aCounts = [];
        $cPartTypes = $cTotalParts = 0;         // cPartTypes has the # of different parts, cTotalParts has the total.

        $offset = ($page - 1) * $cPerPage;

        /* We can't use OFFSET and LIMIT since we need all results to compute the complete parts list.
           So respect offset and limit in the PHP loop instead. */
        if ($res = Database::DefaultExec(<<<SQL
SELECT
    tp.value,
    tp.count
FROM ticket_parents tp
LEFT JOIN ticket_texts tx ON (tx.ticket_id = tp.value AND tx.field_id = $1) WHERE tp.field_id = $2 AND tp.ticket_id = $3
ORDER BY tx.value
SQL
                                                                    , [ FIELD_TITLE,            FIELD_FT_CONTAINS,    $idKit ]))
        {
            $c = 0;
            $cInserted = 0;
            while ($row = Database::GetDefault()->fetchNextRow($res))
            {
                ++$cPartTypes;
                # Temp store this item count, we'll insert it into the array below.
                $cThis = $row['count'];
                $cTotalParts += $cThis;

                // Here's where we handle offset and limit.
                if (    ($c >= $offset)
                     && (($cPerPage == 0) || ($cInserted < $cPerPage))        // limit
                   )
                {
                    $idPart = $row['value'];
                    $aParts[$idPart] = 1;
                    $aCounts[$idPart] = $cThis;
                    ++$cInserted;
                    }

                ++$c;
            }
        }

        $aResults = Ticket::MakeApiResult($cPartTypes,
                                          $page,
                                          $cPerPage,
                                          $aParts,
                                          Ticket::JSON_LEVEL_ALL,
                                          L("{{L//%COUNT1% different parts, %COUNT2% parts in total}}",
                                                [ '%COUNT1%' => $cPartTypes,
                                                  '%COUNT2%' => $cTotalParts
                                                ]),
                                          L(self::$nlsNoOtherParts));

        if (isset($aResults['results']))
            # Add the counts to the array.
            foreach ($aResults['results'] as &$a2)      // note, reference, so we can modify it
            {
                $idPart = $a2['ticket_id'];
                $a2['ft_count'] = getArrayItem($aCounts, $idPart);
                $idIcon = $a2['ft_icon'];
                $a2['ft_icon_formatted'] = Format::Thumbnail($idIcon, Globals::$thumbsize);
                if ($artnos = $a2['ft_article_nos'])
                    $a2['ft_article_nos_formatted'] = FTArticleNosHandler::Format($artnos, TRUE);
                else
                    $a2['ft_article_nos'] = NULL;
            }

        $aResults['cTotalParts'] = $cTotalParts;

        return $aResults;
    }

}


/********************************************************************
 *
 *  FTContainedInHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_FT_CONTAINEDIN. See FieldHandler for how these work.
 */
class FTContainedInHandler extends ChildrenHandler
{
    public $label = '{{L//Contained in}}';
    public $help  = '{{L//This allows for specifying which fischertechnik kits this part is contained in.}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_FT_CONTAINEDIN);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Called for ticket details in MODE_READONLY_DETAILS to add a Bootstrap row div
     *  to the page's HTML to display the ticket data.
     *
     *  The parent class ChildrenHandler overrides this method to do nothing, which we
     *  don't want. Instead, display the whole row if we have a value.
     *
     * @return void
     */
    public function appendReadOnlyRow(TicketPageBase $oPage)               //!< in: TicketPageBase instance with ticket dialog information
    {
        if ($value = $this->getValue($oPage))
        {
            $oHtml = new HTMLChunk();
            $this->formatTicketsList($oHtml,
                                     $oPage,
                                     $value,
                                     10);
            $this->addReadOnlyColumnsForRow($oPage,
                                            NULL,
                                            $oHtml);
        }
    }

    /**
     *  Called for ticket details to add a row to the dialog form which
     *  displays the field data for the ticket and allows for editing it.
     *  Only gets called in MODE_CREATE or MODE_EDIT, because otherwise there is
     *  no editable dialog. (In MODE_READONLY_DETAILS, appendReadOnlyRow() gets called instead.)
     *
     *  We override the FieldHandler implementation to do nothing because we don't want
     *  to have this data in the form data at all.
     *
     * @return void
     */
    public function appendFormRow(TicketPageBase $oPage)   # in: TicketPageBase instance with ticket dialog information
    {
    }

    /**
     *  Second function that gets called after appendFormRow() in MODE_CREATE or MODE_EDIT
     *  modes to add the field name to list of fields to be submitted as JSON in the body
     *  of a ticket POST or PUT request (for create or update, respectively).
     *
     *  We override the FieldHandler implementation to do nothing because we don't want
     *  to have this data in the form data at all.
     *
     * @return void
     */
    public function addFieldToJSDialog(TicketPageBase $oPage)
    {
    }
}
