<?php
/*
 *PLUGIN    Name: type_office
 *PLUGIN    URI: http://www.baubadil.org/doreen
 *PLUGIN    Description: Provides ticket types and templates for managing office files
 *PLUGIN    Version: 0.1.0
 *PLUGIN    Author: Baubadil GmbH
 *PLUGIN    License: Proprietary
 */

/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Global constants
 *
 ********************************************************************/

/* The name of this plugin. This is passed to the plugin engine and must be globally unique. */
const OFFICE_PLUGIN_NAME = 'type_office';

/* The database tables version. If this is higher than the stored version for this plugin in
   the global config, the plugin install routine gets triggered to upgrade. */
const OFFICE_PLUGIN_DB_VERSION = 1;

/* Field IDs defined by this plugin. These add to the field IDs defined by the core.  */
const FIELD_OFFICE_FIRST                  = 1500;

const FIELD_OFFICE_CONTACTS             = FIELD_OFFICE_FIRST;

/* List of class names and include files for the autoloader. */
const OFFICE_CLASS_INCLUDES = [
    'OfcContactsList'       => 'plugins/type_office/ofc_contact.php',
    'OfcContact'            => 'plugins/type_office/ofc_contact.php',
    'OfcFileTicket'         => 'plugins/type_office/ofc_ticket.php',
];

/* Register the "init" function. This call gets executed when the plugin is loaded by the plugin engine. */
Plugins::RegisterInit(OFFICE_PLUGIN_NAME, function()
{
    Plugins::RegisterInstance(new PluginOffice(),
                              OFFICE_CLASS_INCLUDES);
});

/* Register the "install" function. */
Plugins::RegisterInstall2(  OFFICE_PLUGIN_NAME,
                            OFFICE_PLUGIN_DB_VERSION,
                            function()
                            {
                                require_once dirname(__FILE__).'/ofc_install.php';
                                return new OfficeInstall();
                            });


/********************************************************************
 *
 *  Plugin interface classes
 *
 ********************************************************************/

/**
 *  The Office plugin interface. This implements the necessary methods to hook
 *  the plugin into the core system. See \ref plugins for how this works.
 *
 *  This plugin implements:
 *
 *   -- FIELD_OFFICE_CONTACTS and its field handler, OfcContactsHandler;
 *
 *   -- a "File" ticket type using that field;
 *
 *   -- no specialized ticket type yet.
 */
class PluginOffice implements ITypePlugin, ISpecializedTicketPlugin
{
    const CONFIGKEY_FILE_TYPE_ID     = 'id_office-file-type';
    const CONFIGKEY_FILE_TEMPLATE_ID = 'id_office-file-template';
    const OFC_FILE_TYPENAME          = "File";

    # Constant for getSpecializedClassNames().
    const A_SPECIALIZED_CLASSES = [
        self::OFC_FILE_TYPENAME             => 'OfcFileTicket',
    ];

    /**
     *  This must return the plugin name.
     */
    public function getName()
    {
        return OFFICE_PLUGIN_NAME;
    }

    /*
     *  Implementation of the IPlugin interface function. See remarks there.
     */
    public function getCapabilities()
    {
        return self::CAPSFL_TYPE
             | self::CAPSFL_TYPE_SPECIALTICKET;
    }

    /**
     *  This ITypePlugin method gets called lazily on every type plugin when the ticket
     *  fields engine is initialized to give all such plugins the chance to report
     *  the ticket fields it introduces. See \ref intro_ticket_types for an introduction
     *  of how ticket fields and ticket types work. This must return an array of
     *  field ID => FIELDFL_* flags pairs. The plugin must be able to create a field
     *  handler in \ref createFieldHandler() for each field ID reported here.
     *
     * @return int[]
     */
    public function reportFieldsWithHandlers()
    {
        return [
            FIELD_OFFICE_CONTACTS   => FIELDFL_CUSTOM_SERIALIZATION | FIELDFL_SHOW_CUSTOM_DATA
        ];
    }

    /**
     *  This ITypePlugin method gets gets called by \ref TicketField::GetDrillDownIDs() to
     *  give all plugins a chance to add to the global list of ticket field IDs for which
     *  drill-down should be performed. See \page drill_down_filters for an introduction.
     *  This must either return NULL or a an array of field IDs with L() strings that
     *  describe the field as a filter class (starting in lower case).
     *
     * @return string[] | null
     */
    public function reportDrillDownFieldIDs()
    {
        return NULL;
    }

    /**
     *  This ITypePlugin method gets called by \ref TicketField::GetSearchBoostFields() to
     *  give all plugins a chance to add to the global list of ticket field IDs for which
     *  full-text search is supported. This must either return NULL or a an array of
     *  field ID / search boost value pairs.
     *
     *  Note that during ticket POST/PUT (create/update), boost values are checked only
     *  for whether they are non-NULL. Only during full-text searches are the actual
     *  boost values (e.g. 1 or 5) sent to the search engine. It is therefore possible
     *  to change a non-null boost value to another non-null bost value without reindexing.
     *
     * return int[]
     */
    public function reportSearchableFieldIDs()
    {
        return NULL;
    }

    /**
     *  This ITypePlugin method must be implemented by all plugins that want to provide
     *  custom field handlers to Doreen. This gets called from \ref FieldHandler::Find()
     *  to give every type plugin a chance to instantiate a field handler for a given
     *  field ID if the plugin supplies one.
     *
     *  All field handlers must be derived from the FieldHandler base class. Note that the
     *  plugin must first report the field ID with \ref reportFieldsWithHandlers() for
     *  this to be called. See \ref intro_ticket_types for an introduction of how ticket
     *  fields and ticket types work.
     *
     *  This only gets called if no field handler has been created yet for the given
     *  $field_id. The plugin must simply call new() on the new field handler, which
     *  must call the the FieldHandler parent constructor, and that will register the
     *  handler correctly so that this plugin method will not get called again for that
     *  field ID. The return value of this function is ignored.
     *
     * @return void
     */
    public function createFieldHandler($field_id)
    {
        switch ($field_id)
        {
            case FIELD_OFFICE_CONTACTS:
                require_once INCLUDE_PATH_PREFIX.'/plugins/type_office/ofc_fieldh_contacts.php';
                new OfcContactsHandler;
            break;
        }
    }

    /**
     *  This is part of ISpecializedTicket (extending ITypePlugin) and gets called on
     *  all such plugins once the first time a ticket is instantiated in memory.
     *  This gives all such plugins a chance to report for which ticket types they
     *  implement specialized ticket classes that derive from Ticket. This must
     *  return either NULL or an array of ticket type => class name pairs.
     *
     * @return string[] | null
     */
    public function getSpecializedClassNames()
    {
        return self::A_SPECIALIZED_CLASSES;
    }
}
