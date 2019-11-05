<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  JsonHandlerBase
 *
 ********************************************************************/

/**
 *  Base class for field handlers that wrap multiple fields into a JSON object with
 *  a fixed set of keys, the combination of which gets stored in ticket_texts.
 *
 *  This provides the following features:
 *
 *   -- implement \ref makeSearchable() so that only the JSON values are indexed;
 *
 *   -- implement \ref
 */
abstract class JsonHandlerBase extends FieldHandler
{

    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($field_id)
    {
        parent::__construct($field_id);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  Instead of the default plain text entry field, which would display the
     *  field value as a JSON string, we add that field as a hidden field (so
     *  that it does get submitted as JSON), but add additional entry fields
     *  with javascript magic that combine the JSON on input.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,          //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        $aKeys = static::GetKeys();

        $aJSON = $this->getValue($oPage);
        $strJSON = json_encode($aJSON);
        $oPage->oDlg->addInput('hidden',
                               $idControl,
                               '',
                               toHTML($strJSON),
                               0,
                               NULL);

        $oPage->oDlg->openGridRow();    // inner row

        $cInnerLabelColumns = 3;
        foreach ($aKeys as $key => $strLabel)
        {
            $v = $aJSON[$key] ?? NULL;
            $idInput = "$idControl-$key";
            $oPage->oDlg->addLabel(HTMLChunk::FromString($strLabel), $idInput, 'col-xs-'.$cInnerLabelColumns);
            $oPage->oDlg->openDiv(NULL, 'col-xs-'.(12 - $cInnerLabelColumns));
            $oPage->oDlg->addInput('text',
                                   $idInput,
                                   '',
                                   toHTML($v),
                                   0,
                                   NULL);
            $oPage->oDlg->close(); // div
        }

        $oPage->oDlg->close();      // inner row

        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initJsonHandler',
                                             [ $idControl, $aKeys ]);
    }

    /**
     *  This can get called from a search engine's onTicketCreated() implementation
     *  when a ticket has been created or updated and needs to be indexed for a
     *  search engine. It gets called for every ticket field reported by
     *  \ref ITypePlugin::reportSearchableFieldIDs() and must return the data
     *  that the search engine should index.
     *
     *  The JsonHandlerBase implementation returns the values from the JSON object.
     */
    public function makeSearchable(Ticket $oTicket)
    {
        // This returns the raw JSON string.
        if ($val = parent::makeSearchable($oTicket))
            if ($a = json_decode($val, TRUE))
                return implode(' ', array_values($a));

        return NULL;
    }


    /********************************************************************
     *
     *  Newly introduced static methods
     *
     ********************************************************************/

    /**
     *  Newly introduced by JsonHandlerBase, this must return the string keys of the
     *  JSON object, with flags.
     */
    abstract protected static function GetKeys();
}
