<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FTIconHandler class
 *
 ********************************************************************/

/**
 *  Field handler for FIELD_FT_ICON.
 *
 *  The "icon"value is an integer binary ID, and this field handler emits HTML
 *  for the Doreen thumbnailer to show an icon (in a second HTTP request).
 *
 *  Note that after import, this value is NOT set. On the first call, the
 *  field handler picks an icon from the list of attachments and then creates
 *  a row for it.
 *
 *  A NULL value means that no row exists, and this has not yet been done.
 *  A zero (0) integer means that no icon was found.
 */
class FTIconHandler extends SelectFromSetHandlerBase
{
    public $label = '{{L//Icon}}';
    public $help  = "{{L//Here you can select one of the attachments as this article variant's icon.}}";


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        FieldHandler::__construct(FIELD_FT_ICON);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Returns the value for the field from the ticket.
     *
     *  For FIELD_FT_ICON, the value is an integer and must be one of the
     *  binary IDs attached to this ticket. We validate this here. If
     *  the value is NULL (which is always the case unless an image was
     *  explicitly picked for the ft part) or invalid, we pick one of
     *  the binary IDs from the attachments list based on heuristics
     *  which images are best and then write it back to the database.
     */
    public function getValue(TicketContext $oContext)
    {
        $value = parent::getValue($oContext);

        /* This is NULL when called for the first time. */
        if ($value === NULL)
            if (    ($oContext->mode != MODE_CREATE)
                 && ($oContext->oTicket)
               )
                $value = $this->pickIconFromAttachments($oContext);

        return $value;      // can be NULL
    }

    /**
     *  Called for ticket details to add a row to the dialog form which
     *  displays the field data for the ticket and allows for editing it.
     *  Only gets called in MODE_CREATE or MODE_EDIT, because otherwise there is
     *  no editable dialog. (In MODE_READONLY_DETAILS, appendReadOnlyRow() gets called instead.)
     *
     *  We override this only to suppress the icon completely in CREATE mode
     *  since there are no attachments to pick the icon from yet, and
     *  we'd get errors otherwise.
     *
     * @return void
     */
    public function appendFormRow(TicketPageBase $oPage)   # in: TicketPageBase instance with ticket dialog information
    {
        $fHasAttachments = FALSE;
        if ($oPage->mode != MODE_CREATE)
            $fHasAttachments = (count($this->getValidValues($oPage, NULL)) > 0);

        if ($fHasAttachments)
            parent::appendFormRow($oPage);
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value,                     //!< in: value to format by this field handler
                                    $fHTML = TRUE)              //!< in: format HTML or plain text?
        : HTMLChunk
    {
        $o = new HTMLChunk();
        if ($value)     // NULL or 0
            if (isInteger($value))
            {
                $fDetails = ($oContext->mode == MODE_READONLY_DETAILS);
                $thumbsize = ($fDetails)
                    ? 300
                    : Globals::$thumbsize;
                $htmlThumb = Format::Thumbnail($value, $thumbsize);

                // If we're in details view, then link to the image file. Otherwise to the details view.
                if ($fDetails)
                    $o->html = "<a href=\"".Binary::MakeHREF($value)."/\">$htmlThumb</a>";
                else
                    $o->html = HTMLChunk::MakeTooltip($htmlThumb,
                                                      $oContext->oTicket->makeGoToFlyover(),
                                                      WebApp::MakeUrl($oContext->oTicket->makeUrlTail()));

            }
        return $o;
    }

    /**
     *  Abstract method newly introduced by SelectFromSetHandlerBase to be overridden
     *  by subclasses to return an array of id => HTML string pairs representing the
     *  set of valid values that this field can have. The strings will be HTML-escaped.
     *  The returned array must include the current value!
     */
    public function getValidValues(TicketContext $oContext,    //!< in: TicketContext instance
                                   $currentValue)
    {
        $aReturn = [];

        $oChangelog = $oContext->oTicket->loadChangelog();
        foreach ($oChangelog->aChangelogRows as $oRow)
            if (    ($oRow->field_id == FIELD_ATTACHMENT)
                 && ($oRow->cx)
               )
            {
                $oBinary = Binary::CreateFromChangelogRow($oRow);
                $aReturn[$oBinary->idBinary] = toHTML($oBinary->filename);
            }

        return $aReturn;
    }


    /********************************************************************
     *
     *  Newly introduced static methods
     *
     ********************************************************************/

    /**
     *  Helper function that picks an icon from the list of attachments of this FT ticket.
     *  This gets called from \ref getValue() on the first call when the value is still
     *  NULL and writes the value back to the database for caching.
     */
    private function pickIconFromAttachments(TicketContext $oContext)
    {
        $idBinaryMax = 0;       // NULL means not queried yet, but 0 means "no icon".
        $oChangelog = $oContext->oTicket->loadChangelog();
        if ($oChangelog->cAttachments)
        {
            $imageType = NULL;
            if ($oContext->oTicket instanceof FTTicket)
            {
                /** @var FTTicket $oTicketFT */
                $oTicketFT = $oContext->oTicket;

                if (    ($oRootCategory = $oTicketFT->getRootCategory())
                     && ($name = $oRootCategory->name)
                   )
                {
                    switch ($name)
                    {
                        case "Baukästen":
                            $imageType = ATTACHTYPE_IMAGE_KIT_EXTERIOR;
                        break;
                    }
                    $idBinaryMax = $this->pickHighestResolution($oChangelog,
                                                                $imageType);
                }
            }

            // If we found no matching icon for that image type, try again with NULL.
            if ($imageType && !$idBinaryMax)
                $idBinaryMax = $this->pickHighestResolution($oChangelog, NULL);
        }

        /* Write a line to the database so we won't have to load the whole changelog again. */
        $id = $oContext->oTicket->id;
        Debug::FuncEnter(Debug::FL_PLUGIN1, "Writing icon value $idBinaryMax to ticket_ints for caching #$id");
        FieldHandler::InsertRow($oContext->oTicket,
                                $this->oField,
                                NULL,       // old value
                                $idBinaryMax);
        Debug::FuncLeave();

        return $idBinaryMax;
    }

    private function pickHighestResolution(Changelog $oChangelog,
                                           $imageType)       //!< in: one of the ATTACHTYPE_IMAGE_* values or NULL or any
    {
        $idBinaryMax = NULL;

        /* Make a list of attachments and pick the image with the highest resolution. */
        $cxMax = 0;
        foreach ($oChangelog->aChangelogRows as $oRow)
            if (    ($oRow->field_id == FIELD_ATTACHMENT)
                 && ($oRow->cx)
               )
            {
                if (    ($imageType === NULL)
                     || (    ($aSpecial = json_decode($oRow->special, TRUE))
                          && ($aSpecial['ft_attach_type'] == $imageType)
                        )
                   )
                    if ($oRow->cx > $cxMax)
                    {
                        $idBinaryMax = $oRow->value_1;
                        $cxMax = $oRow->cx;
                    }
            }

        return $idBinaryMax;
    }

    /**
     *  Clears all caches related to FTDB icons by resetting all the values picked by
     *  \ref pickIconFromAttachments().
     *
     *  This also clears the thumbnailer cache.
     */
    public static function ClearCache()
    {
        $field_id = FIELD_FT_ICON;
        $oField = TicketField::Find($field_id);
        Database::DefaultExec(<<<SQL
DELETE FROM $oField->tblname WHERE field_id = $field_id;   
SQL
        );

        DrnThumbnailer::ClearCache();
    }
}
