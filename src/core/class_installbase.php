<?php

namespace Doreen;

/**
 *  Helper class to make plugin installs easier. Most existing plugins use older, legacy code,
 *  but this is the future.
 */
abstract class InstallBase
{
    public $pluginName;
    public $keyPluginVersion;
    public $pluginDBVersionNow;
    public $constPluginDBVersionNeeded;
    public $htmlPlugin;
    public $fRefreshTypes = FALSE;

    public function init(string $pluginName,
                         string $keyPluginVersion,
                         int $pluginDBVersionNow,
                         int $constPluginDBVersionNeeded)
    {
        $this->pluginName = $pluginName;
        $this->keyPluginVersion = $keyPluginVersion;
        $this->pluginDBVersionNow = $pluginDBVersionNow;
        $this->constPluginDBVersionNeeded = $constPluginDBVersionNeeded;

        $this->htmlPlugin = ' Plugin '.Format::HtmlQuotes($pluginName);
    }

    abstract public function doInstall();

    /**
     *  Adds rows to the ticket_fields table to install ticket fields on the system.
     *
     *  $aFields must be an array of field ID -> sub-array pairs, where each sub-array must be a flat list
     *  of 'name', 'tblname', 'ordering' and 'parent' values.
     *
     */
    protected function addInstallTicketFields(array $aValuePairs)
    {
        # Re-insert or update the ticket fields on every database version change because they change often and doing it this way allows us to use PHP constants in the code.
        GlobalConfig::AddInstall("$this->htmlPlugin: Refresh ticket field values",
            function()
            use ($aValuePairs)
            {
                Database::GetDefault()->upsert('ticket_fields',
                                               'i',
                                               [ 'name',
                                                 'tblname',
                                                 'ordering',
                                                 'parent' ],
                                               $aValuePairs);
                TicketField::ForceRefresh();
            });
    }

    protected function addInstallTicketType($globalConfigKey,      //!< in: globalconfig key
                                            $ticketTypeName,
                                            $parent_type_id,       //!< in: parent type ID, if already known; NULL for none; or TYPE_PARENT_SELF
                                            $llDetls,              //!< in: flat list of field IDs for ticket details
                                            $llList,               //!< in: flat list of field IDs for ticket list
                                            $idWorkflow,
                                            $fl)
    {
        GlobalConfig::AddInstall("$this->htmlPlugin: Create/update \"$ticketTypeName\" ticket type",
            function()
            use ($globalConfigKey, $ticketTypeName, $parent_type_id, $llDetls, $llList, $idWorkflow, $fl)
            {
                TicketType::Install($globalConfigKey,
                                    $ticketTypeName,
                                    $parent_type_id,
                                    $llDetls,
                                    $llList,
                                    $idWorkflow,
                                    $fl);
            });

    }

    protected function addInstallTemplate(string $keyTypeID,
                                          string $templateName,
                                          array $aPermissions,
                                          string $keyTemplateID,
                                          string $keyProjectID = NULL)
    {
        GlobalConfig::AddInstall("$this->htmlPlugin: Create/update \"$templateName\" ticket template",
            function()
            use ($keyTypeID, $templateName, $aPermissions, $keyTemplateID, $keyProjectID)
            {
                if (!( $oType = TicketType::FindFromGlobalConfig($keyTypeID, FALSE) ))
                {
                    throw new DrnException("Cannot find ID for ticket type \"$keyTypeID\" for template \"$templateName\" in configuration");
                }
                Ticket::InstallTemplate($keyTemplateID,
                                        $templateName,
                                        $oType,
                                        $keyProjectID,
                                        $aPermissions);
            });
    }

    protected function addInstallWorkflow(string $keyWorkflowId,
                                          string $workflowName,
                                          int $initial,              //!< in: initial status value for new tickets that implement this process
                                          string $workflow_statuses,    //!< in: string with comma-separated list of integer status values (e.g. STATUS_OPEN))
                                          array $aTransitions)         //!< in: complete defintion of the valid state transitions as a two-dimensional array with $from => [ $to, $to, ... ];
    {
        GlobalConfig::AddInstall("$this->htmlPlugin: Create/update \"$workflowName\" workflow and status values",
            function()
            use ($keyWorkflowId, $workflowName, $initial, $workflow_statuses, $aTransitions)
            {
                $oWorkflow = NULL;
                if ($idWorkflow = GlobalConfig::Get($keyWorkflowId))
                {
                    $oWorkflow = TicketWorkflow::Find($idWorkflow, TRUE);

                    $oWorkflow->update($workflow_statuses,
                                       $aTransitions);
                }
                else
                {
                    $oWorkflow = TicketWorkflow::Create($workflowName,
                                                        $initial,
                                                        $workflow_statuses,
                                                        $aTransitions);
                    GlobalConfig::Set($keyWorkflowId, $oWorkflow->id);
                }

                GlobalConfig::Save();

            });
    }
}
