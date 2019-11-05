<?php

namespace Doreen;


/**
 *  Called from the type_bug "check preconditions" function if an upgrade is needed.
 */
function vcardInstall(string $keyPluginVersion,         //!< in: GlobalConfig key
                      int $pluginDBVersionNow,          //!< in: GlobalConfig value
                      int $constPluginDBVersionNeeded)  //!< in: new version const from plugin main file
{
    $htmlPlugin = ' '."Plugin ".Format::HtmlQuotes('type_vcard');

    $fRefreshTypes = FALSE;

    if ($pluginDBVersionNow < 1)
    {
        GlobalConfig::AddInstall("$htmlPlugin: Store plugin version $constPluginDBVersionNeeded in config $keyPluginVersion", <<<EOD
INSERT INTO config (key, value) VALUES ('$keyPluginVersion', $constPluginDBVersionNeeded)
EOD
        );

        $fRefreshTypes = TRUE;
    }

    if ($pluginDBVersionNow < 3)
        $fRefreshTypes = TRUE;

    # Re-insert or update the ticket fields on every database version change because they change often and doing it this way allows us to use PHP constants in the code.
    GlobalConfig::AddInstall("$htmlPlugin: Refresh ticket field values", function()
    {
        Database::GetDefault()->upsert('ticket_fields',
                 'i',                               [ 'name',                'tblname',           'ordering',  'parent' ],
               [ FIELD_VCARD                     => [ 'vcard',               'ticket_texts',      2,           NULL ],
               ] );
        TicketField::ForceRefresh();
    });

    if ($fRefreshTypes)
    {
        GlobalConfig::AddInstall("$htmlPlugin: Create/update \"contact\" ticket type", function()
        {
            $llDetls = [ FIELD_TITLE, FIELD_VCARD, FIELD_IMPORTEDFROM_PERSONID ];
            $llList  = [ FIELD_TITLE, FIELD_VCARD ];
            TicketType::Install(PluginVCard::CONFIGKEY_VCARD_TYPE_ID,
                                PluginVCard::TYPENAME,
                                NULL,     # parent
                                $llDetls,
                                $llList,
                                NULL,        # workflow
                                0);
        });
    }

    if ($pluginDBVersionNow < 2)
    {
        GlobalConfig::AddInstall("$htmlPlugin: Create \"VCard\" ticket template", function()
        {
            if (!($oType = TicketType::FindFromGlobalConfig(PluginVCard::CONFIGKEY_VCARD_TYPE_ID, FALSE)))
                throw new DrnException("Cannot find contact ticket type in configuration");

            $oTemplate = Ticket::CreateTemplate(NULL,
                                                L("{{L//Contact}}"),
                                                $oType,
                                                NULL,      # project ID, we have none yet
                                                [ Group::ALLUSERS => ACCESS_READ | ACCESS_MAIL,
                                                  Group::EDITORS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE,
                                                  Group::ADMINS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE
                                                ]);
            GlobalConfig::Set(PluginVCard::CONFIGKEY_VCARD_TEMPLATE_ID, $oTemplate->id);
        });
    }

    # Finally, for all versions, store the current plugin version.
    if ($pluginDBVersionNow < $constPluginDBVersionNeeded)
    {
        GlobalConfig::AddInstall("$htmlPlugin: Update plugin version to $constPluginDBVersionNeeded in config $keyPluginVersion", <<<EOD
UPDATE config SET value = '$constPluginDBVersionNeeded' WHERE key = '$keyPluginVersion'
EOD
        );
    }

    # In case we set the public type IDs above.
    GlobalConfig::Save();
}
