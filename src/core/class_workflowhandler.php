<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  WorkflowHandler class
 *
 ********************************************************************/

/**
 *  Sibling field handler of StatusHandler. See remarks there for an explanation
 *  why this is necessary.
 */
class WorkflowHandler extends SelectFromSetHandlerBase
{
    public $label = '{{L//Status}}';

    /** @var ITypePlugin[] */
    private static $aPluginRegisterWorkflowHandlers = [];    # Array of fieldid (FIELD_*) => ITypePlugin instances to be able to call createWorkflowHandler() quickly.
    private static $fHandlersInited = FALSE;

    /** @var WorkflowHandler[] */
    private static $aWorkflowHandlers = [];                 # Array of instantiated workflow handlers (field id => object pairs).

    # Override SelectFromSetHandlerBase default.
    public $eDisplayStyle = self::MODE_ALWAYS_USE_RADIO;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($workflow_id)
    {
        parent::__construct(FIELD_IGNORE);

        self::$aWorkflowHandlers[$workflow_id] = $this;

        $this->field_id = FIELD_STATUS;         # HACK alert, this makes getValue() look up the correct field
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  This FieldHandler method must return the initial value for the field in MODE_CREATE.
     *  The default FieldHandler implementation would return NULL.
     *
     *  StatusHandler::getInitialValue() overrides this to call this WorkflowHandler implementation,
     *  where we can return the initial value as defined in the workflow.
     */
    public function getInitialValue(TicketContext $oContext)
    {
        if (isset($oContext->oType->idWorkflow))
        {
            $oWorkflow = TicketWorkflow::Find($oContext->oType->idWorkflow);
            return $oWorkflow->initial;
        }

        return STATUS_OPEN;
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     *
     *  We provide status formatting for the built-in status values here; this gets
     *  called for the "Task" ticket type, for example.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        TicketWorkflow::GetAllStatusValues();
        $statusDescr = TicketWorkflow::GetStatusDescription($value);
        return TicketWorkflow::StatusFormatHelper($value, $statusDescr);
    }

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  The parent (SelectFromSetHandlerBase) implementation would add a <SELECT>/<OPTION>
     *  drop-down based on what getValidValues() returns.
     *
     *  This gets forwarded from StatusHandler::addDialogField(). We override this method
     *  to show the status options as colorful radio buttons instead of a drop-down.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,          //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        parent::addDialogField($oPage, $idControl);

        if ($oPage)
            if ($oPage->mode == MODE_EDIT)
                if ($idTaskType = GlobalConfig::GetIntegerOrThrow('id_task-type'))
                    if ($oPage->oType->id == $idTaskType)
                    {
                        $idSelectPriority = $oPage->idDialog."-priority";
                        WholePage::AddJSAction('core', 'onEditTaskPageReady', [
                            $idControl,
                            STATUS_CLOSED,
                            $idSelectPriority
                        ]);
                    }
    }

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     *
     *  This gets forwarded to us from StatusHandler::formatChangelogItem().
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        $old = $oRow->int_old;
        $new = $oRow->int_new;
        TicketWorkflow::GetAllStatusValues();
        return L('{{L//%FIELD% changed from %OLD% to %NEW%}}',
                 [ '%FIELD%' => $this->getLabel($oPage)->html,
                   '%OLD%' => TicketWorkflow::StatusFormatHelper($old, TicketWorkflow::GetStatusDescription($old))->html,
                   '%NEW%' => TicketWorkflow::StatusFormatHelper($new, TicketWorkflow::GetStatusDescription($new))->html ]);
    }

    /**
     *  This gets called from \ref onCreateOrUpdate() for each ticket
     *  field whose data needs to be written to (or updated in) the database.
     *  Note: In MODE_CREATE, $oContext->oTicket contains the template from
     *  which the new ticket was created, whereas $oTicket has the newly created
     *  ticket. In MODE_EDIT, both are set to the ticket being changed.
     *  In MODE_EDIT, this only gets called if the new value is different from
     *  the old.
     *  This also generates changelog entries, unless $fWriteChangelog is FALSE. See
     *  \ref Changelog::AddTicketChange() for details about the format.
     *  See \ref data_serialization for an overview of the different
     *  data representations.
     *
     *  The standard implementation for WorkFlowHandler just calls the parent, but subclasses
     *  can override this for specific workflows.
     *
     * @return void
     */
    public function writeToDatabase(TicketContext $oContext,           //!< in: TicketContext instance
                                    Ticket $oTicket,            //!< in: new or existing ticket instance
                                    $oldValue,           //!< in: existing value (NULL if not present or in MODE_CREATE)
                                    $newValue,           //!< in: new value to be written out
                                    $fWriteChangelog)    //!< in: TRUE for MODE_EDIT (except in special cases), FALSE for MODE_CREATE
    {
//         Debug::FuncEnter(__CLASS__.'::'.__FUNCTION__);
        parent::writeToDatabase($oContext, $oTicket, $oldValue, $newValue, $fWriteChangelog);
//         Debug::FuncLeave();
    }

    /**
     *  Gets called by \ref writeToDatabase(), only in MODE_EDIT, to have a changelog entry
     *  written for the update. This is in the middle of a database transaction so the change
     *  will be discarded on errors. Like \ref writeToDatabase(), this only gets calls for
     *  fields whose values are actually changing.
     *
     *  This gets forwarded from StatusHandler::addToChangelog().
     *
     * @return void
     */
    public function addToChangelog(TicketContext $oContext,
                                   $oldRowId,
                                   $newRowId,
                                   $newValue,
                                   $value_str = NULL)
    {
        parent::addToChangelog($oContext, $oldRowId, $newRowId, $newValue, $value_str);
    }

    /**
     *  Abstract method newly introduced by SelectFromSetHandlerBase to be overridden
     *  by subclasses to return an array of id => HTML string pairs representing the
     *  set of valid values that this field can have. The strings will be HTML-escaped.
     *  The returned array must include the current value!
     *
     *  The WorkflowHandler implementation gets called from \ref StatusHandler::getValidValues()
     *  and must produce an array of status values that we can change to from the given value.
     */
    public function getValidValues(TicketContext $oContext,    //!< in: TicketContext instance
                                   $currentValue)
    {
        $initial = $this->getInitialValue($oContext);
        $aValidValues = [ $initial ];          # default for many things

//         Debug::FuncEnter(__CLASS__.'::'.__FUNCTION__.", currentValue: \"$currentValue\", initial=$initial, mode: ".$oContext->mode);
        TicketWorkflow::GetAllStatusValues();
        if ($oContext->mode == MODE_CREATE)
        {
            # New ticket:
            if (Globals::$fImportingTickets)        # HACK: Allow all values when importing tickets from elsewhere.
                $aValidValues = array_keys(TicketWorkflow::$aStatuses);

            # Make sure the initial value is in the array as well, in case a subclass does some tricks.
            Debug::Log(Debug::FL_STATUS, "MODE_CREATE, getInitialValue() returned $initial");
            $aValidValues[] = $initial;         # might be a duplicate, but that will be erased below
        }
        else if ($currentValue) # can be NULL on buggy imports
        {
            # Updating existing ticket: then get values from TicketWorkflow.
            if ($oWorkflow = TicketWorkflow::Find($oContext->oType->idWorkflow))
                $aValidValues = $oWorkflow->getValidStateTransitions($currentValue);
//             Debug::Log(__CLASS__.'::'.__FUNCTION__.": valid state transitions for process {$oWorkflow->id} from current $currentValue: ".print_r($aValidValues, TRUE));
        }

        $aReturn = [];
        foreach ($aValidValues as $v)
            $aReturn[$v] = TicketWorkflow::GetStatusDescription($v);

//         Debug::Log("Returning valid values: ".print_r($aReturn, TRUE));

//         Debug::FuncLeave();

        return $aReturn;
    }                                                                  #

    /**
     *  Method newly introduced by SelectFromSetHandlerBase, called by addDialogField() only if
     *  $this->eDisplayStyle calls for formatting radio buttons. In that case, this gets called
     *  for each value to be displayed to retrieve a formatted HTML string to be submitted to HTMLChunk::addRadio().
     *
     *  Override the parent to format the status values with color.
     */
    protected function formatRadioValue($val, $str)
        : HTMLChunk
    {
        return TicketWorkflow::StatusFormatHelper($val, $str);
    }


    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Initializes all workflow handlers on the system. This goes through all plugins
     *  that implement IWorkflowPlugin and calls
     *  \ref IWorkflowPlugin::registerWorkflowHandlers() on them.
     */
    public static function InitPluginWorkflowHandlers()
    {
        if (!self::$fHandlersInited)
        {
            foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_WORKFLOW) as $oImpl)
            {
                /** @var IWorkflowPlugin $oImpl  */
                if ($llWorkflowIDs = $oImpl->registerWorkflowHandlers())
                    foreach ($llWorkflowIDs as $workflow_id)
                        self::$aPluginRegisterWorkflowHandlers[$workflow_id] = $oImpl;
            }
            self::$fHandlersInited = TRUE;
        }
    }

    /**
     *  We call this FindWorkflowHandler as opposed to Find() since there is already a Find() method
     *  in the parent classes with a different signature.
     *
     *  @return self
     */
    public static function FindWorkflowHandler($workflow_id)
    {
        if (!($oHandler = getArrayItem(self::$aWorkflowHandlers, $workflow_id)))
        {
            self::InitPluginWorkflowHandlers();

            # Try plugins first, which allows for overriding the default field handler.
            /** @var IWorkflowPlugin $oPlugin */
            if ($oPlugin = getArrayItem(self::$aPluginRegisterWorkflowHandlers, $workflow_id))
            {
                $oPlugin->createWorkflowHandler($workflow_id);
                $oHandler = getArrayItem(self::$aWorkflowHandlers, $workflow_id);
            }
            else
                $oHandler = new WorkflowHandler($workflow_id);
        }

        if (!$oHandler)
            throw new DrnException("Missing workflow handler for workflow ID $workflow_id");

        return $oHandler;
    }
}
