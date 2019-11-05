<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  VCardTicket
 *
 ********************************************************************/

/**
 *  Ticket subclass for all tickets of the "VCard" ticket type.
 *
 *  See \ref ISpecializedTicketPlugin::getSpecializedClassNames() for how specialized
 *  ticket classes work.
 */
class VCardTicket extends Ticket
{
    # Override the static class icon returned by Ticket::getClassIcon().
    protected static $icon = 'address-card';


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    public function getInfo($id)
    {
        switch ($id)
        {
            case self::INFO_SHOW_IMPORT_ID:
                return FALSE;

            case self::INFO_TITLE_LABEL:
                return "{{L//Display name}}";       // caller calls L() on it

            case self::INFO_EDIT_TITLE_HELP:
                return L("{{L//This is automatically set to the first and last names combined, but you can also enter any value manually.}}");

            case self::INFO_DETAILS_TITLE_WITH_TICKET_NO:
                return FALSE;

        }

        return parent::getInfo($id);
    }


    /********************************************************************
     *
     *  Newly introduced instance methods
     *
     ********************************************************************/

    public function formatHtml(bool $fDetails)
        : HTMLChunk
    {
        $this->populate(TRUE);

        return VCardHandler::FormatHtml($this->getFieldValue(FIELD_VCARD), $fDetails);
    }

}

