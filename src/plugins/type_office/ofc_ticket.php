<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FTTicket
 *
 ********************************************************************/

/**
 *  Ticket subclass for all tickets of the "File" ticket type.
 *
 *  See \ref ISpecializedTicketPlugin::getSpecializedClassNames() for how specialized
 *  ticket classes work.
 */
class OfcFileTicket extends Ticket
{
    # Override the static class icon returned by Ticket::getClassIcon().
    protected static $icon = 'briefcase'; # 'archive'; // 'folder-open';


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    public function getInfo($id)
    {
        return parent::getInfo($id);
    }
}

