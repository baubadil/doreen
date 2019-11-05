<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  PriorityHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_PRIORITY.
 *
 *  This is a simple example for a ticket field which can have one of a finite
 *  set of values which are not stored in this handler. Since this derives
 *  from SelectFromSetHandlerBase, all we need to do is provide an array of
 *  those values in our getValidValues() override, and the values can be
 *  picked from in the SELECT/OPTION dropdown emitted by the parent class.
 *
 *  This is different from CategoryHandlerBase, whose subclasses also allows
 *  for selecting from a finite set of values, but those values are in the
 *  database.
 */
class PriorityHandler extends FieldHandler
{
    public $label = '{{L//Priority}}';
    public $help  = '{{L//Ticket priorities make it easier to keep track of which tasks should be handled first.}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_PRIORITY);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This FieldHandler method must return the initial value for the field in MODE_CREATE.
     *  The default FieldHandler implementation would return NULL.
     */
    public function getInitialValue(TicketContext $oContext)
    {
        return 3;       # medium priority
    }

    const BUTTON_CLASS = 'setprio';

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  We override this to provide a bootstrap-slider control.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,          //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        $oPage->oDlg->addSlider($idControl,
                                (int)$this->getValue($oPage),
                                1,
                                self::GetMaxPriorityValue());
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     *
     *  We override the parent to display a popover dialog to be able to change the priority.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        if (    (    ($oContext->mode == MODE_READONLY_LIST)
                  || ($oContext->mode == MODE_READONLY_GRID)
                  || ($oContext->mode == MODE_READONLY_DETAILS)
                )
             && ($oContext->oTicket)
             && (LoginSession::IsUserLoggedIn())
             && ($oContext->oTicket->canUpdate(LoginSession::$ouserCurrent))
           )
        {
            if (WholePage::RunOnce(self::BUTTON_CLASS))
            {
                WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_SLIDER);
                WholePage::Enable(WholePage::FEAT_CSS_ANIMATIONS);

                $rootpage = Globals::$rootpage;
                $idSharedPriorityForm = 'sharedPriorityForm';
                $oHTMLPage = new HTMLChunk();
                $oHTMLPage->html = <<<HTML
<div id="$idSharedPriorityForm" class="hide">
    <form action="$rootpage/api/priority" id="popForm" method="put">
        <div class="form-group">
            <br>
        </div>
        <div class="form-group" style="padding-left: 2em; padding-right: 2em;">
            <input type="text" size="50" name="priority" class="form-control input-md drn-set-priority">
        </div>
        <hr>
        <div class="form-group text-right">
            <div class="alert alert-danger hidden drn-error-box" role="alert"><p>Error message</p></div>
            <button type="button" class="btn btn-default drn-cancel">Cancel</button>
            <button type="button" class="btn btn-primary drn-save">Save</button>
        </div>
    </form>
</div>
HTML;

                WholePage::AddHTMLChunk($oHTMLPage);

                WholePage::AddNLSStrings( [ 'setpriority' => L("{{L//Set priority}}") ] );
                $cls = self::BUTTON_CLASS;
                $maxPrio = self::GetMaxPriorityValue();
                WholePage::AddJSAction('core', 'initSetPriorityButtons', [
                    $maxPrio,
                    $cls,
                    $idSharedPriorityForm
                ]);
            } // if (WholePage::RunOnce(self::BUTTON_CLASS))

            // The initSetPriorityButtons() JS function attaches handlers to all buttons with self::BUTTON_CLASS.
            $o = new HTMLChunk();
            // The div is necessary for animation.
            $o->openDiv(NULL, NULL, NULL, 'span');
            $o->appendChunk(HTMLChunk::MakeElement('b',
                                                   [],
                                                   HTMLChunk::MakeElement('span',
                                                                          [ 'id' => 'prio-'.$oContext->oTicket->id ],
                                                                          HTMLChunk::FromString($value))));
            $o->append(Format::NBSP);
            $o->appendChunk(HTMLChunk::MakeElement('a',
                                                   [ 'href' => '#',
                                                     'id' => 'setprio-'.$oContext->oTicket->id,
                                                     'class' => self::BUTTON_CLASS,
                                                     'role' => 'button',
                                                     'data-ticket' => $oContext->oTicket->id,
                                                     'data-priority' => $value,
                                                   ],
                                                   Icon::GetH('hand-o-up')));
            $o->close(); # div
        }
        else
            $o = HTMLChunk::FromString($value);

        return $o;
    }


    /********************************************************************
     *
     *  Newly introduced static methods
     *
     ********************************************************************/

    private static $max = NULL;

    /**
     *  Returns the maximum priority value that's currently in use in the database.
     */
    public static function GetMaxPriorityValue()
        : int
    {
        if (self::$max === NULL)
        {
            if ($max = Database::GetDefault()->execSingleValue(<<<SQL
SELECT MAX(value) AS max
  FROM ticket_ints
  -- JOIN tickets ON tickets.i = ticket_ints.ticket_id
  WHERE field_id = $1;
SQL
                   , [ FIELD_PRIORITY ],
                  'max'
                ))
                self::$max = (int)$max;
            else
                self::$max = 1;
        }

        return self::$max;
    }
}
