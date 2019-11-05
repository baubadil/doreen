<?php

/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FinanceAssetTicket
 *
 ********************************************************************/

/**
 *  Ticket subclass for all tickets of the "Student" ticket type.
 *
 *  See \ref ISpecializedTicketPlugin::getSpecializedClassNames() for how specialized
 *  ticket classes work.
 */
class SchoolStudentTicket extends VCardTicket
{
    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Returns miscellanous small bits of information about this ticket. This method is designed
     *  to be overridden by Ticket subclasses so that certain bits of ticket behavior can be modified
     *  if desired.
     *
     */
    public function getInfo($id)
    {
        switch ($id)
        {
            case self::INFO_CREATE_TICKET_TITLE:
            case self::INFO_CREATE_TICKET_BUTTON:
                $cls = $this->getSchoolClassOrThrow()->name;
                return L('{{L//Create new student in %CLS%}}',
                         [ '%CLS%' => $cls]);

            case self::INFO_DETAILS_TITLE_WITH_TICKET_NO:
            case self::INFO_DETAILS_SHOW_CHANGELOG:
                return FALSE;
        }

        return parent::getInfo($id);
    }

    /**
     *  Produces a string that is used as the title of the "Edit ticket" form.
     */
    public function makeEditTicketTitle($fHTMLHeading = TRUE)
    {
        return L('{{L//Edit student %S%}}',
                 [ '%S%' => Format::UTF8Quote($this->getTitle()) ]);
    }

    /********************************************************************
     *
     *  Newly introduced instance methods
     *
     ********************************************************************/

    public function getSchoolClassOrThrow()
        : SchoolClass
    {
        if (!($o = SchoolClass::FindByID($this->getFieldValue(FIELD_PROJECT))))
            throw new DrnException("cannot determine school class ID for ticket #$this->id");
        /** @var SchoolClass $o */
        return $o;
    }

    /********************************************************************
     *
     *  Newly introduced static methods
     *
     ********************************************************************/

    /**
     *  Returns the given ticket properly type-cast or throws.
     */
    public static function AssertStudent(Ticket $oTicket)
        : self
    {
        if (!($oTicket instanceof self))
            throw new DrnException("expected ticket ID #$oTicket->id to be a student");
        /** @var self $oTicket */
        return $oTicket;
    }
}


