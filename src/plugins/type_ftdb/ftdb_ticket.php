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
 *  Ticket subclass for all tickets of the "fischertechnik article" ticket type.
 *
 *  See \ref ISpecializedTicketPlugin::getSpecializedClassNames() for how specialized
 *  ticket classes work.
 */
class FTTicket extends Ticket
{
    const MANUAL    = 1;
    const KIT       = 2;
    const PART      = 3;
    const MARKETING = 4;

    public function getIcon($fStatusColor = FALSE)
        : HTMLChunk
    {
        $icon = 'cube'; // default for PART

        switch ($this->identifyRootCategory())
        {
            case self::MANUAL:
                $icon = 'map-o';
            break;

            case self::KIT:
                $icon = 'archive';
            break;

            // case self::PART: default below
            case self::MARKETING:
                $icon = 'file-text-o';
            break;
        }

        return Icon::GetH($icon);
    }

    /**
     *  Returns miscellanous small bits of information about this ticket. This method is designed
     *  to be overridden by Ticket subclasses so that certain bits of ticket behavior can be modified
     *  if desired.
     */
    public function getInfo($id)
    {
        switch ($id)
        {
            case self::INFO_EDIT_TITLE_HELP:
                return L("{{L//This should describe the fischertechnik kit or part as closely as possible, for example like %{Baustein 30 schwarz}%.}}");

            case self::INFO_CREATE_TICKET_TITLE:
                return L("{{L//Create a new fischertechnik kit or part}}");

            case self::INFO_DETAILS_TITLE_WITH_TICKET_NO:
                return FALSE;
        }

        return parent::getInfo($id);
    }

    /**
     *  Produces the string that is used in <a title=...> in links to this
     *  ticket. This should describe the ticket sufficiently.
     */
    public function makeGoToFlyover(bool $fEdit = FALSE)    //!< in: if TRUE, describe an editor link instead
    {
        # NOTE: Keep these on three lines or else the dgettext extractor will get confused
        $lstr = ($fEdit)
            ? "{{L//Open editor for %{%TITLE%}%}}"
            : "{{L//View details for %{%TITLE%}%}}";

        return L($lstr,
                 [ '%TITLE%' => HTMLChunk::FromString($this->getTitle())->html
                 ] );
    }

    /**
     *  Returns the link particle that should be used for ticket details links.
     *
     *  We override this to return 'ft-article' for ft article links.
     */
    public function getTicketUrlParticle()
        : string
    {
        return FTDB_TICKET_URL_PARTICLE_FT;
    }


    /********************************************************************
     *
     *  Newly introduced instance methods
     *
     ********************************************************************/

    /**
     *  Returns the root category based on the categories of this ticket.
     *
     */
    public function getRootCategory()
    {
        $strCategories = $this->getFieldValue(FIELD_FT_CATEGORY_ALL);
        foreach (explode(',', $strCategories) as $idCategory)
            if ($oCategory = FTCategory::FindByID($idCategory))
                return $oCategory->getRoot();

        return NULL;
    }

    public function identifyRootCategory()
    {
        if ($oRootCategory = $this->getRootCategory())
        {
            switch ($oRootCategory->name)
            {
                case 'Anleitungen':
                    return self::MANUAL;

                case 'Baukästen':
                    return self::KIT;

                case 'Einzelteile':
                    return self::PART;

                case 'Werbung':
                    return self::MARKETING;
            }
        }
    }
}
