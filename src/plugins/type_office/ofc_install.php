<?php

namespace Doreen;


class OfficeInstall extends InstallBase
{
    public function doInstall()
    {
        if ($this->pluginDBVersionNow < 1)
        {
            GlobalConfig::AddInstall("$this->htmlPlugin: Create ofc_contacts table", <<<SQL
CREATE TABLE ofc_contacts (
    i           SERIAL PRIMARY KEY,
    ticket_id   INTEGER NOT NULL REFERENCES tickets(i) ON DELETE CASCADE,
    field_id    SMALLINT NOT NULL REFERENCES ticket_fields(i), 
    vcard_id    INTEGER NOT NULL REFERENCES tickets(i) ON DELETE CASCADE,  -- VCard ticket
    contact_type SMALLINT,
    fileno      TEXT,
    parent_id   INTEGER DEFAULT NULL REFERENCES ofc_contacts(i) ON DELETE CASCADE
)
SQL
            );
        }

        if ($this->pluginDBVersionNow < 1)
            $this->fRefreshTypes = TRUE;

        $this->addInstallTicketFields(
            [
                FIELD_OFFICE_CONTACTS           => [ 'ofc_contacts', 'ofc_contacts', 3.1, NULL ],
            ] );

        if ($this->fRefreshTypes)
        {
            GlobalConfig::AddInstall("$this->htmlPlugin: Create/update \"file\" ticket type", function()
            {
                $idWorkflow = GlobalConfig::GetIntegerOrThrow(PluginAccounting::CONFIGKEY_WORKFLOW);
                # Note that payment date is hidden and displayed by the status and invoice workflow.
                $llDetls = [ FIELD_TITLE, FIELD_PROJECT, FIELD_DESCRIPTION, FIELD_OFFICE_CONTACTS, FIELD_STATUS, FIELD_IMPORTEDFROM ];
                $llList =  [ FIELD_TITLE, FIELD_PROJECT,                    FIELD_OFFICE_CONTACTS, FIELD_STATUS ];
                TicketType::Install(PluginOffice::CONFIGKEY_FILE_TYPE_ID,
                                    PluginOffice::OFC_FILE_TYPENAME,
                                    TYPE_PARENT_SELF,     # parent
                                    $llDetls,
                                    $llList,
                                    $idWorkflow,        # workflow
                                    0);
            });
        }

        if ($this->pluginDBVersionNow < 1)
        {
            self::addInstallTemplate(PluginOffice::CONFIGKEY_FILE_TYPE_ID,
                                     "File",
                                     [ Group::ALLUSERS => ACCESS_READ | ACCESS_MAIL,
                                       Group::EDITORS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE,
                                       Group::ADMINS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE,
                                     ],
                                     PluginOffice::CONFIGKEY_FILE_TEMPLATE_ID);
        }
    }
}
