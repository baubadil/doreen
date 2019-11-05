<?php

namespace Doreen;


/**
 *  Called from the type_bug "check preconditions" function if an upgrade is needed.
 */
function typeFTDBInstall(string $keyPluginVersion,      //!< in: GlobalConfig key
                         int $pluginDBVersionNow,       //!< in: GlobalConfig value
                         int $pluginDBVersionNeeded)
{
    $htmlPlugin = ' '."Plugin ".Format::HtmlQuotes('type_ftdb');

    $fRefreshTypes = FALSE;

    if ($pluginDBVersionNow < 1)
    {
        GlobalConfig::AddInstall("$htmlPlugin: Store plugin version $pluginDBVersionNeeded in config $keyPluginVersion", <<<EOD
INSERT INTO config (key, value) VALUES ('$keyPluginVersion', $pluginDBVersionNeeded)
EOD
        );

        $fRefreshTypes = TRUE;
    }

    if ($pluginDBVersionNow < 15)
        $fRefreshTypes = TRUE;

    # Re-insert or update the ticket fields on every database version change because they change often and doing it this way allows us to use PHP constants in the code.
    GlobalConfig::AddInstall("$htmlPlugin: Refresh ticket field values", function()
    {
        Database::GetDefault()->upsert('ticket_fields',
                 'i',                               [ 'name',                'tblname',           'ordering',  'parent' ],
               [ FIELD_FT_ARTICLENOS             => [ 'ft_article_nos',      'ticket_texts',      2.09,        NULL ],
                 FIELD_FT_ARTICLEVARIANT_UUID    => [ 'ft_variant_uuid',     'ticket_uuids',      2.1,         NULL ],
                 FIELD_FT_CONTAINS               => [ 'ft_contains',         'ticket_parents',    2.91,        NULL ],
                 FIELD_FT_CONTAINEDIN            => [ 'ft_contained_in',     'ticket_parents',    2.92,        NULL ],
                 FIELD_FT_ICON                   => [ 'ft_icon',             'ticket_ints',       2.07,        NULL ],
                 FIELD_FT_CATEGORY_ALL           => [ 'ft_cat_all',          'ticket_categories', 2.082,       NULL ],
                 FIELD_FT_WEIGHT                 => [ 'ft_weight',           'ticket_floats',     2.083,       NULL ],
               ] );
        TicketField::ForceRefresh();
    });

    if ($fRefreshTypes)
    {
        GlobalConfig::AddInstall("$htmlPlugin: Create/update \"fischertechnik part\" ticket type", function()
        {
            $llDetls = [ FIELD_TITLE, FIELD_DESCRIPTION, FIELD_FT_ICON, FIELD_FT_ARTICLENOS, FIELD_FT_ARTICLEVARIANT_UUID, FIELD_CHANGELOG, FIELD_COMMENT, FIELD_ATTACHMENT, FIELD_FT_CONTAINS, FIELD_FT_CONTAINEDIN, FIELD_FT_CATEGORY_ALL, FIELD_FT_WEIGHT ];
            $llList  = [ FIELD_TITLE, FIELD_FT_ICON, FIELD_FT_ARTICLENOS, FIELD_FT_CONTAINS, FIELD_FT_CONTAINEDIN, FIELD_FT_CATEGORY_ALL, FIELD_FT_WEIGHT ];
            TicketType::Install(PluginFTDB::CONFIGKEY_PART_TYPE_ID,
                                PluginFTDB::FT_TYPENAME_PART,
                                NULL,     # parent
                                $llDetls,
                                $llList,
                                NULL,        # workflow
                                0);
        });
    }

    if ($pluginDBVersionNow < 1)
    {
        GlobalConfig::AddInstall("$htmlPlugin: Create \"fischertechnik part\" ticket template", function()
        {
            if (!($oType = TicketType::FindFromGlobalConfig(PluginFTDB::CONFIGKEY_PART_TYPE_ID, FALSE)))
                throw new DrnException("Cannot find fischertechnik article type in configuration");

            $oTemplate = Ticket::CreateTemplate(NULL,
                                                L("{{L//fischertechnik part}}"),
                                                $oType,
                                                NULL,      # project ID, we have none yet
                                                array( Group::ALLUSERS => ACCESS_READ,
                                                       Group::EDITORS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_MAIL,
                                                       Group::ADMINS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE | ACCESS_MAIL,
                                                       Group::GUESTS => ACCESS_READ
                                                ));
            GlobalConfig::Set(PluginFTDB::CONFIGKEY_PART_TEMPLATE_ID, $oTemplate->id);
        });
    }

    if ($pluginDBVersionNow < 15)
    {
        GlobalConfig::AddInstall("$htmlPlugin: Remove obsolete ticket field", function()
        {
            Database::DefaultExec("DELETE FROM ticket_categories WHERE field_id = $1",
                                                                                      [ FIELD_FT_CATEGORY_ROOT_OBSOLETE ]);
            TicketField::Delete(FIELD_FT_CATEGORY_ROOT_OBSOLETE);
        });
    }

    # Finally, for all versions, store the current plugin version.
    if ($pluginDBVersionNow < $pluginDBVersionNeeded)
    {
        GlobalConfig::AddInstall("$htmlPlugin: Update plugin version to $pluginDBVersionNeeded in config $keyPluginVersion", <<<EOD
UPDATE config SET value = '$pluginDBVersionNeeded' WHERE key = '$keyPluginVersion'
EOD
        );
    }

    if ($pluginDBVersionNow < 17)
    {
        // Create a ticket for Datenschutz and other required info.
        GlobalConfig::AddInstall("$htmlPlugin: Create wiki ticket for editing required info", function()
        {
            $oWikiTemplate = Ticket::FindTemplateFromConfigKeyOrThrow(GlobalConfig::KEY_ID_TEMPLATE_WIKI);
            $oTicket = $oWikiTemplate->createAnother(LoginSession::$ouserCurrent,
                                                     LoginSession::$ouserCurrent,
                                                     [ 'title' => "FTDB ADDITIONAL NOTICES",
                                                       'description' => "Replace this text please" ],
                                                     FALSE);
            GlobalConfig::Set(PluginFTDB::CONFIGKEY_INFO_TICKET_ID, $oTicket->id);
        });
    }

    # In case we set the public type IDs above.
    GlobalConfig::Save();
}
