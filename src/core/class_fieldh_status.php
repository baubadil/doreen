<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  StatusHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_STATUS.
 *
 *  This is a more complicated override because ticket statuses are restricted
 *  by a ticket workflow. In particular, not all status transitions may be allowed
 *  by a workflow.
 *
 *  Since the behavior for the "Status" ticket field thus depends on the
 *  TicketWorkflow instance that is defined for the ticket's type, this field
 *  handler chooses a unique method:
 *
 *   1. We derive a WorkflowHandler from SelectFromSetHandlerBase. The classes
 *      StatusHandler and WorkflowHandler are thus siblings.
 *
 *   2. While the StatusHandler field handler is the same for all "Status" fields
 *      regardless of workflow and ticket type, there will be multiple WorkflowHandler
 *      instances for the different workflows in use. The method overrides in
 *      StatusHandler then forward the method calls to the method in the correct
 *      WorkflowHandler instance.
 *
 *  Through this approach we can have a single StatusHandler instance for
 *  FIELD_STATUS (which is what all of the Doreen code expects), but the behavior
 *  of that instance depends on the TicketWorkflow that was configured for a
 *  ticket type.
 */
class StatusHandler extends SelectFromSetHandlerBase
{
    public $label          = '{{L//Status}}';
    public $help           = '{{L//This indicates what work still needs to be done for this task.}}';
    public $fDrillMultiple = FALSE;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_STATUS);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Returns the description for this ticket field. This is used by \ref appendFormRow()
     *  for the label of the dialog row.
     *  $oContext can be inspected for context but MAY BE NULL for a safe default value.
     *
     *  StatusHandler overrides this so that if $oPage is given, we can forward the call
     *  to a WorkflowHandler.
     */
    public function getLabel(TicketContext $oContext = NULL)  //!< in: TicketContext instance or NULL
        : HTMLChunk
    {
        if (isset($oContext->oType->idWorkflow))
        {
            $oWorkflowHandler = WorkflowHandler::FindWorkflowHandler($oContext->oType->idWorkflow);
            return $oWorkflowHandler->getLabel($oContext);
        }
        return parent::getLabel($oContext);
    }

    /**
     *  This FieldHandler method must return the initial value for the field in MODE_CREATE.
     *  The default FieldHandler implementation would return NULL.
     *
     *  We override this method to forward this method call to WorkflowHandler.
     */
    public function getInitialValue(TicketContext $oContext)
    {
        $oWorkflowHandler = WorkflowHandler::FindWorkflowHandler($oContext->oType->idWorkflow);
        return $oWorkflowHandler->getInitialValue($oContext);
    }

    /**
     *  Abstract method newly introduced by SelectFromSetHandlerBase to be overridden
     *  by subclasses to return an array of id => HTML string pairs representing the
     *  set of valid values that this field can have. The strings will be HTML-escaped.
     *  The returned array must include the current value!
     *
     *  Status values are more complicated: we forward this method call to the
     *  method of the same name of this ticket type's workflow handler.
     */
    public function getValidValues(TicketContext $oContext,    //!< in: TicketContext instance
                                   $currentValue)
    {
        $oWorkflowHandler = WorkflowHandler::FindWorkflowHandler($oContext->oType->idWorkflow);
        return $oWorkflowHandler->getValidValues($oContext, $currentValue);
    }

    /**
     *  Called for ticket details in MODE_READONLY_DETAILS to add a Bootstrap row div
     *  to the page's HTML to display the ticket data.
     *
     *  Override the parent to call Ticket::showStatusInDetails() to give it a chance to
     *  suppress the entire row.
     *
     * @return void
     */
    public function appendReadOnlyRow(TicketPageBase $oPage)               //!< in: TicketPageBase instance with ticket dialog information
    {
        if ($oPage->oTicket->showStatusInDetails())
            parent::appendReadOnlyRow($oPage);
    }


    /**
     *  Called for ticket details to add a row to the dialog form which
     *  displays the field data for the ticket and allows for editing it.
     *  Only gets called in MODE_CREATE or MODE_EDIT, because otherwise there is
     *  no editable dialog. (In MODE_READONLY_DETAILS, appendReadOnlyRow() gets called instead.)
     *
     *  Normally the parent method works fine (which calls addDialogFIeld in turn), but we suppress the
     *  field entirely if the ticket type has FL_AUTOMATIC_STATUS set.
     *
     * @return void
     */
    public function appendFormRow(TicketPageBase $oPage)   # in: TicketPageBase instance with ticket dialog information
    {
        if (!($oPage->oType->fl & TicketType::FL_AUTOMATIC_STATUS))
            parent::appendFormRow($oPage);
    }

    /**
     *  Second function that gets called after appendFormRow() in MODE_CREATE or MODE_EDIT
     *  modes to add the field name to list of fields to be submitted as JSON in the body
     *  of a ticket POST or PUT request (for create or update, respectively).
     *
     *  We override the FieldHandler implementation to suppress the field if the ticket type
     *  has FL_AUTOMATIC_STATUS set. Otherwise we call the parent.
     *
     * @return void
     */
    public function addFieldToJSDialog(TicketPageBase $oPage)
    {
        # Suppress the field name if we have an automatic title.
        if (!($oPage->oType->fl & TicketType::FL_AUTOMATIC_STATUS))
            parent::addFieldToJSDialog($oPage);
    }

    /**
     *  Called by appendFormRow() to return the explanatory help that should be
     *  displayed under the entry field in a ticket's "Edit" form.
     *
     *  We override this method to forward this method call to WorkflowHandler (which derives from SelectFromSetHandlerBase),
     *  which in its default version calls the FieldHandler version again. But
     *  subclasses can override that implementation to provide a meaningful
     *  description of a particular workflow.
     */
    public function getEntryFieldHelpHTML(TicketPageBase $oPage)
        : HTMLChunk
    {
        $oWorkflowHandler = WorkflowHandler::FindWorkflowHandler($oPage->oType->idWorkflow);
        return $oWorkflowHandler->getEntryFieldHelpHTML($oPage);
    }

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  The parent (SelectFromSetHandlerBase) implementation would add a <SELECT>/<OPTION>
     *  drop-down based on what getValidValues() returns.
     *
     *  We override this method to forward this method call to WorkflowHandler (which derives from SelectFromSetHandlerBase),
     *  which in its default version calls the SelectFromSetHandlerBase version again. So this has no effect for
     *  the "task" workflow, but it allows plugins to tinker with the dialog fields for different workflows.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,          //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
//        Debug::Log("addDialogField: ticket type = ".$oPage->oType->id);
//        Debug::Log("addDialogField: ticket workflow = ".$oPage->oType->idWorkflow);
        $oWorkflowHandler = WorkflowHandler::FindWorkflowHandler($oPage->oType->idWorkflow);
        $oWorkflowHandler->addDialogField($oPage, $idControl);
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *
     *  We override this method to forward the method call to WorkflowHandler, if necessary.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        if (isset($oContext->oType->idWorkflow))
        {
            $oWorkflowHandler = WorkflowHandler::FindWorkflowHandler($oContext->oType->idWorkflow);
            return $oWorkflowHandler->formatValuePlain($oContext, $value);
        }

        return NULL;
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     *
     *  We override this method to forward the method call to WorkflowHandler, if necessary.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        if (    ($oContext->mode != MODE_READONLY_FILTERLIST)
             && (isset($oContext->oType->idWorkflow))
           )
        {
            // Forward method call to workflow handler.
            $oWorkflowHandler = WorkflowHandler::FindWorkflowHandler($oContext->oType->idWorkflow);
            return $oWorkflowHandler->formatValueHTML($oContext, $value);
        }

        # Status values in drill-down filter list: display static values from Workflow class.
        TicketWorkflow::GetAllStatusValues();
        $statusDescr = TicketWorkflow::GetStatusDescription($value);
        return TicketWorkflow::StatusFormatHelper($value, $statusDescr);
    }

    /**
     *  This gets called in ticket details view to have the HTML contents of a
     *  column in a changelog row produced.
     *
     *  This should only ever be called by tryFormatChangelogItem(), which surrounds
     *  the call with a try() block so that throwing exceptions here will print the
     *  exception message in the changelog row instead of blowing up the request entirely.
     *
     *  We override the SelectFromSetHandlerBase implementation to forward this method call to WorkflowHandler.
     */
    public function formatChangelogItem(TicketPageBase $oPage,
                                        ChangelogRow $oRow)
    {
        $ticket_id = $oRow->what;
        $workflow_id = $oRow->workflow_id;
        Debug::Log(Debug::FL_STATUS, __CLASS__.'::'.__FUNCTION__.": ticket $ticket_id, process $workflow_id");
        $oWorkflowHandler = WorkflowHandler::FindWorkflowHandler($workflow_id);
        return $oWorkflowHandler->formatChangelogItem($oPage, $oRow);
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
     *  We override this method to forward this method call to WorkflowHandler.
     *
     * @return void
     */
    public function writeToDatabase(TicketContext $oContext,           //!< in: TicketContext instance
                                    Ticket $oTicket,            //!< in: new or existing ticket instance
                                    $oldValue,           //!< in: existing value (NULL if not present or in MODE_CREATE)
                                    $newValue,           //!< in: new value to be written out
                                    $fWriteChangelog)    //!< in: TRUE for MODE_EDIT (except in special cases), FALSE for MODE_CREATE
    {
        $oWorkflowHandler = WorkflowHandler::FindWorkflowHandler($oContext->oType->idWorkflow);
        $oWorkflowHandler->writeToDatabase($oContext, $oTicket, $oldValue, $newValue, $fWriteChangelog);
    }

    /**
     *  Gets called by \ref writeToDatabase(), only in MODE_EDIT, to have a changelog entry
     *  written for the update. This is in the middle of a database transaction so the change
     *  will be discarded on errors. Like \ref writeToDatabase(), this only gets calls for
     *  fields whose values are actually changing.
     *
     *  We override this method to forward this method call to WorkflowHandler.
     *
     * @return void
     */
    public function addToChangelog(TicketContext $oContext,
                                   $oldRowId,
                                   $newRowId,
                                   $newValue,
                                   $value_str = NULL)
    {
        $oWorkflowHandler = WorkflowHandler::FindWorkflowHandler($oContext->oType->idWorkflow);
        $oWorkflowHandler->addToChangelog($oContext, $oldRowId, $newRowId, $newValue, $value_str);
    }
}
