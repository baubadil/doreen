<?php
/*
 *PLUGIN    Name: type_school
 *PLUGIN    URI: http://www.baubadil.org/doreen
 *PLUGIN    Description: Provides ticket types and templates for school newsletters.
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
const SCHOOL_PLUGIN_NAME = 'type_school';

/* The database tables version. If this is higher than the stored version for this plugin in
   the global config, the plugin install routine gets triggered to upgrade. */
const SCHOOL_PLUGIN_DB_VERSION = 1;

/* Field IDs defined by this plugin. These add to the field IDs defined by the core.  */
const FIELD_SCHOOL_FIRST                  = 1700;

const FIELD_SCHOOL_CATEGORY_CLASS   = FIELD_SCHOOL_FIRST;               # Category ID, project ID
const FIELD_STUDENT                 = FIELD_SCHOOL_FIRST +  1;
const FIELD_STUDENT_PARENTS         = FIELD_SCHOOL_FIRST +  2;

/* List of class names and include files for the autoloader. */
const SCHOOL_CLASS_INCLUDES = [
    'SchoolBlurb' => 'plugins/type_school/school_blurb.php',
    'SchoolCli' => 'plugins/type_school/school_cli.php',
    'SchoolClass' => 'plugins/type_school/school_class.php',
    'SchoolParentsHandler' => 'plugins/type_school/school_fieldh_parents.php',
    'ParentForJson' => 'plugins/type_school/school_fieldh_parents.php',
    'SchoolStudentTicket' => 'plugins/type_school/school_ticket_student.php',
    'SchoolStudentNewsletter' => 'plugins/type_school/school_ticket_newsletter.php',
];

/* Register the "init" function. This call gets executed when the plugin is loaded by the plugin engine. */
Plugins::RegisterInit(SCHOOL_PLUGIN_NAME, function()
{
    Plugins::RegisterInstance(new PluginSchool(),
                              SCHOOL_CLASS_INCLUDES);
});

/* Register the "install" function. */
Plugins::RegisterInstall2(  SCHOOL_PLUGIN_NAME,
                            SCHOOL_PLUGIN_DB_VERSION,
                            function()
                            {
                                require_once dirname(__FILE__).'/school_install.php';
                                return new SchoolInstall();
                            });


/********************************************************************
 *
 *  Plugin interface classes
 *
 ********************************************************************/

/**
 *  The "School" plugin interface. This implements the necessary methods to hook
 *  the plugin into the core system. See \ref plugins for how this works.
 *
 *  This plugin provides:
 *
 *   -- a "Student" ticket class (see SchoolStudentTicket ) including the following ticket fields:
 *
 *       -- FIELD_STUDENT, which uses VCardHandler (directly, not linking to
 *          a separate VCard ticket);
 *
 *       -- FIELD_STUDENT_PARENTS, an array of Doreen User IDs, which are the
 *          parents of that student; their mail addresses will be used for the newsletter;
 *
 *       -- FIELD_SCHOOL_CATEGORY_CLASS, which is stored in the tickets.project_id
 *          column and mapped; this is a school class category (see SchoolClass);
 *
 *   -- a "Newsletter" ticket type using the standard FIELD_TITLE and FIELD_DESCRIPTION.
 *
 *  Parents represented by regular Doreen User instances (because they should be able to
 *  log in) and can be associated with Student tickets. Every parent is given ACCESS_READ
 *  for student tickets and newsletter tickets of the student's class.
 *
 *  The plugin also provides back-end classes and GUI features for organizing students and
 *  parents. For every SchoolClass of students,
 *
 *   -- one Doreen group and
 *
 *   -- one Student template and
 *
 *   -- one Newsletter template
 *
 *  will be created (see \ref SchoolClass::Create()).
 *
 *  Additionally, parent representatives will be members of the "Representatives"
 *  group, who have ACCESS_WRITE and ACCESS_CREATE access for all student and
 *  newsletter tickets.
 */
class PluginSchool implements ITypePlugin,
                              ISpecializedTicketPlugin,
                              IMainPagePlugin,
                              ICommandLineHandler
{
    const SCHOOL_STUDENT_TYPENAME = "Student";
    const SCHOOL_STUDENT_TYPE_ID_CONFIGKEY = 'id_student-file-type';
    // There is no single template, but one per SchoolClass, and they're stored as extradata in categories.

    const SCHOOL_NEWSLETTER_TYPENAME = "Newsletter";
    const SCHOOL_NEWSLETTER_TYPE_ID_CONFIGKEY = 'id_newsletter-file-type';
    // There is no single newsletter, but one per SchoolClass, and they're stored as extradata in categories.

    /**
     *  This must return the plugin name.
     */
    public function getName()
    {
        return SCHOOL_PLUGIN_NAME;
    }

    /*
     *  Implementation of the IPlugin interface function. See remarks there.
     */
    public function getCapabilities()
    {
        return self::CAPSFL_URLHANDLERS
             | self::CAPSFL_MAINPAGE
             | self::CAPSFL_TYPE
             | self::CAPSFL_TYPE_SPECIALTICKET
             | self::CAPSFL_COMMANDLINE;
    }

    /**
     *  This gets called by index.php to allow plugins to register GUI request handlers.
     */
    public function registerGUIAppHandler()
    {
        WebApp::Get('/school-welcome/:email/:token', function()
        {
            require_once INCLUDE_PATH_PREFIX.'/plugins/type_school/view_welcome/school_welcome.php';
            SchoolWelcome::Emit(WebApp::FetchStringParam('email'),
                                WebApp::FetchStringParam('token'));
        });
    }

    /**
     *  This gets called by api.php to allow plugins to register API request handlers.
     */
    public function registerAPIAppHandler()
    {
        /**
         *  Creates
         *
         *   -- a new school class with groups and results with the given name
         *
         *   -- a new Doreen user account with the given longname, email and mobile phone
         *      and adds the account to the "parents" and "parent reps" groups of the new
         *      class so that this user can add other users to that class.
         *
         *  This sends out an invitation mail to that user's email so that they can log
         *  in using the password reset mechanism.
         *
         *  Required permissions: current user must be admin or guru.
         */
        WebApp::Post('/school-class-and-rep', function()
        {
            if (!LoginSession::IsCurrentUserAdminOrGuru())
                throw new NotAuthorizedException();

            SchoolClass::CreateWithRep(LoginSession::$ouserCurrent,
                                       WebApp::FetchStringParam('name', 3),
                                       WebApp::FetchStringParam('email'),
                                       WebApp::FetchStringParam('longname'),
                                       WebApp::FetchParam('mobile'),
                                       TRUE);
        });

        WebApp::Post('/school-set-password', function()
        {
            $a = [];
            foreach ( [ 'email', 'token', 'password', 'password-confirm' ] as $key)
                $a[$key] = WebApp::FetchStringParam($key);

            User::DoResetPassword(SchoolClass::SCHOOL_USER_MAXAGE_MINUTES,
                                  NULL,
                                  $a['email'],
                                  $a['token'],
                                  $a['password'],
                                  $a['password-confirm']);
        });
    }

    /**
     *  This gets called by the main page to have all main page plugins return their chunks
     *  to be displayed. This must return an array of Blurb instances.
     *
     * @return Blurb[]
     */
    public function makeBlurbs()
    {
        return [ new SchoolBlurb() ];
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
            FIELD_SCHOOL_CATEGORY_CLASS  => FIELDFL_STD_CORE | FIELDFL_TYPE_INT | FIELDFL_VIRTUAL_IGNORE_POST_PUT | FIELDFL_SHOW_CUSTOM_DATA
                                                | FIELDFL_MAPPED_FROM_PROJECT, # Category ID, in ticket project_id, not used as real ticket field.
            FIELD_STUDENT                => FIELDFL_STD_DATA_OLD_NEW | FIELDFL_REQUIRED_IN_POST_PUT,
            FIELD_STUDENT_PARENTS        => FIELDFL_STD_DATA_OLD_NEW | FIELDFL_REQUIRED_IN_POST_PUT | FIELDFL_ARRAY,
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
        return [ FIELD_SCHOOL_CATEGORY_CLASS => "{{L//class}}" ];
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
        return [ FIELD_STUDENT => 1 ];
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
            case FIELD_SCHOOL_CATEGORY_CLASS:
                require_once INCLUDE_PATH_PREFIX.'/plugins/type_school/school_fieldh_class.php';
                new SchoolClassHandler();
            break;

            case FIELD_STUDENT:
                require_once INCLUDE_PATH_PREFIX.'/plugins/type_school/school_fieldh_student.php';
                new SchoolStudentHandler();
            break;

            case FIELD_STUDENT_PARENTS:
                new SchoolParentsHandler();
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
        return [
            self::SCHOOL_STUDENT_TYPENAME => 'SchoolStudentTicket',
            self::SCHOOL_NEWSLETTER_TYPENAME => 'SchoolStudentNewsletter',
        ];
    }

    /**
     *  This is part of ICommandLineHandler and gets called from the CLI with an array of
     *  those command line arguments which have not yet been consumed by the default
     *  command line parser. The plugin must peek into the list of arguments and remove
     *  those array items that activate it as well as additional arguments that might be
     *  required in that case. After all plugins implementing this interface have been
     *  called, the CLI will fail with an "unknown command" if any arguments are left in
     *  the array. If the plugin returns TRUE, it is assumed that it wants to process
     *  the command line, and then processCommands() will get called as a second step.
     */
    public function parseArguments(&$aArgv)
    {
        return SchoolCli::ParseArguments($aArgv);
    }

    /**
     *  Called by the CLI if the plugin returned TRUE from parseArguments().
     *  $idSession contains the session ID from the command line or NULL if there was none.
     *
     *  No return value.
     */
    public function processCommands($idSession)
    {
        SchoolCli::ProcessCommands($this);
    }

    /**
     *  Through this function command line plugins can add items to the 'help' output.
     */
    public function addHelp(&$aHelpItems)
    {
        SchoolCli::AddHelp($aHelpItems);
    }

}
