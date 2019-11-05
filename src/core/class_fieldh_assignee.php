<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  AssigneeHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_UIDASSIGN.
 *
 *  Like PriorityHandler, this allows for picking a value from a finite set
 *  of values, and thus derives from SelectFromSetHandlerBase.
 *
 *  This set of values however is not hard-coded into the field handler,
 *  but determined at run time: it is all users who have write access to
 *  the ticket.
 *
 *  A value of NULL (which happens to be the default returned by getInitialValue())
 *  indicates that "Nobody" has been assigned the ticket.
 */
class AssigneeHandler extends SelectFromSetHandlerBase
{
    public $label = '{{L//Assignee}}';
    public $help  = '{{L//The user who is reponsible for this ticket.}}';

    const USERKEY_LASTASSIGNEE = 'core_last_assignee';      # Used to remember the last value with the currently logged in User.


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_UIDASSIGN);
        $this->flSelf |= self::FL_GRID_PREFIX_FIELD;
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  This FieldHandler method must return the initial value for the field in MODE_CREATE.
     *  The default FieldHandler implementation would return NULL.
     *
     *  That would indicate "Nobody" as an assignee.
     *  Instead, if a user is currently logged in and in the list of potential assignees
     *  returned by getValueValues(), let this user be the initial value.
     *  Otherwise try to reuse the calue from last time.
     *
     * @param TicketContext $oContext
     */
    public function getInitialValue(TicketContext $oContext)
    {
        if ($oContext->lastmod_uid)
            foreach ($oContext->oTicket->getUsersWithAccess(ACCESS_UPDATE) as $uid => $flAccess)
                if ($uid == $oContext->lastmod_uid)
                    return $uid;

        if ($oUser = LoginSession::IsUserLoggedIn())
            if ($uidLast = $oUser->getExtraValue(self::USERKEY_LASTASSIGNEE))
                return $uidLast;

        return parent::getInitialValue($oContext);
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
        $aReturn = array( 0 => L('{{L//Nobody}}'));

        if (    (!$currentValue)
             && (Globals::$fImportingTickets)        # HACK: Allow all values when importing tickets from elsewhere.
           )
            $aUserIDsWithAccess = array_keys(User::GetAll());
        else
            # All users with read access to the ticket (or template) are potential assignees.
            $aUserIDsWithAccess = array_keys($oContext->oTicket->getUsersWithAccess(ACCESS_READ));
                    # $oContext->oTicket is either the ticket or, in MODE_CREATE mode, the template.

        Debug::FuncEnter(Debug::FL_USERS, __FUNCTION__);
        if ($aUsers = User::FindMany($aUserIDsWithAccess))
            foreach ($aUsers as $uid => $oUser)
                $aReturn[$uid] = $oUser->longname;
        Debug::FuncLeave();

        asort($aReturn);

        return $aReturn;
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *
     *  This default implementation simply returns $value, except for numbers, which are
     *  formatted first according to the current locale. Many subclasses override this to
     *  turn internal values into human-readable representations.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        if (!$value)
            return L('{{L//Nobody}}');

        if ($value !== NULL)
            if ($oUser = User::Find($value))
                return $oUser->longname;
            else
                return L('{{L//[Unknown user ID %ID%]}}',
                         [ '%ID%' => $value ]);

        return "";
    }

    /**
     *  This gets called by \ref writeToDatabase() for every field before the
     *  new value gets written into the database. This must return a value that
     *  fits into the value column of the field's database table, or, in the
     *  case of FIELDFL_ARRAY, an arra of those values. It can also throw a
     *  APIException with the field name if validation fails.
     *
     *  We don't modify the value for assignee here, but we try to remember the
     *  last used value with the currently logged in user's data for convenience.
     *
     * @return mixed
     */
    public function validateBeforeWrite(TicketContext $oContext,    //!< in: TicketContext instance
                                        $oldValue,                  //!< in: current value (or NULL during create ticket)
                                        $newValue)                  //!< in: suggest new value from form field
    {
        if ($newValue)
            if ($oUser = LoginSession::IsUserLoggedIn())
                $oUser->setKeyValue(self::USERKEY_LASTASSIGNEE,
                                    $newValue,
                                    FALSE);

        return parent::validateBeforeWrite($oContext, $oldValue, $newValue);
    }

}

