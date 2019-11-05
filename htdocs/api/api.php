<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/*
 *  Doreen has three entry points: htdocs/index.php, htdocs/api/api.php,
 *  cli/cli.php.
 *
 *  This is htdocs/api/api.php, the REST API server.
 *
 *  All API calls start with /api/, and htdocs/api/.htaccess file makes sure
 *  that requests like /api/ticket/123 end up here.
 */


/********************************************************************
 *
 *  Helpers
 *
 ********************************************************************/

function echoJSONErrorAndExit($dlgfield,
                              $msg,
                              $code = 400)
{
    $a = [  'status' => 'error',
            'message'=> $msg
         ];
    if ($dlgfield)
        $a['field'] = $dlgfield;

    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($a, DEBUG_JSON_OUTPUT);
    exit;
}

/*
 *  Make all warnings fatal.
 */
function DrnAPIErrorHandler($errno,
                            $errstr,
                            $errfile,
                            $errline,
                            $errcontext)
{
    echoJSONErrorAndExit(NULL, "PHP error in $errfile".'['.$errline."]: $errstr");
}


/********************************************************************
 *
 *  Initialization
 *
 ********************************************************************/

$p = strpos($_SERVER['SCRIPT_NAME'], 'api/api.php');  # /doreen/index.php             /doreen/api/api.php
$g_requestURI = $_SERVER['REQUEST_URI'];            #                               /doreen/api/getusers

define('TO_HTDOCS_ROOT', '..');
define('INCLUDE_PATH_PREFIX', TO_HTDOCS_ROOT.'/../src');
define('IS_API', TRUE);
require INCLUDE_PATH_PREFIX.'/core/class_globals.php';
require INCLUDE_PATH_PREFIX.'/core/prologue.php';

Globals::$rootpage = substr($g_requestURI, 0, $p - 1);         # everything up to /doreen  (WITHOUT the trailing slash)
Globals::InitRequest(substr($g_requestURI, $p + 4));     # everything after /doreen/api/ (WITHOUT the leading slash))
Globals::SetErrorHandler();

require Globals::$fnameInstallVars;

Globals::InitDatabase();

JWT::Init();

Debug::PrintHeader('api');

//set_error_handler('Doreen\\DrnAPIErrorHandler', E_ALL & ~E_DEPRECATED);
if (GlobalConfig::Init() != GlobalConfig::STATUS99_ALL_GOOD)
    echoJSONErrorAndExit(NULL, "Database needs upgrade, please contact administrator");

if (!LoginSession::AuthenticateJWT())
    LoginSession::$ouserCurrent = User::Find(User::GUEST);
Debug::LogUserInfo();

if (isset($_GET['lang']))
    DrnLocale::Set($_GET['lang']);


/********************************************************************
 *
 *  USER AND GROUP COMMANDS
 *
 ********************************************************************/

/**
 *  Returns all the users on the system (excluding disabled user accounts) as a JSON array.
 */
WebApp::Get('/users', function()
{
    ApiUser::GetUsers();
});

/**
 *  Returns all the groups on the system as a JSON array.
 */
WebApp::Get('/groups', function()
{
    ApiUser::GetGroups();
});

/**
 *  Updates account of user who is currently logged in. Parameters:
 *
 *   -- uid
 *
 *   -- current-password
 *
 *   -- password
 *
 *   -- password-confirm
 *
 *   -- longname
 *
 *   -- email (string)
 *
 *   -- fTicketMail (bool)
 *
 *   -- fAbsoluteDates (bool)
 *
 *  Required permissions: uid must be the same as the user that's currently signed in.
 */
WebApp::Put('/account/:uid:int', function()
{
    ApiUser::PutAccount();
});

/**
 *  Generates a new API token for the currently logged in user.
 *
 *  Returns the new token in the "token" property
 *
 *  Required permissions: uid must be the same as the user that's currently signed in.
 */
WebApp::Post('/token/:uid:int', function() {
    ApiUser::PostGenerateToken();
});

/**
 *  POST /user -- creates a new user account. Parameters:
 *
 *   -- login
 *
 *   -- password
 *
 *   -- longname
 *
 *   -- email (string)
 *
 *   -- fTicketMail (bool)
 *
 *   -- groups (either a string with comma-separated list of numeric group IDs, or an array of group IDs)
 *
 *  Returns the new User object under the 'results' key.
 *
 *  Required permissions: current user must be admin or guru; gurus are restricted in what groups they can modify.
 */
WebApp::Post('/user', function()
{
    ApiUser::Post();
});

/**
 *  Update existing user account.
 *
 *  Required JSON parameters: this comes in two variants.
 *
 *  Update account data:
 *
 *   -- uid (num)
 *
 *   -- longname (string)
 *
 *   -- email (string)
 *
 *   -- fTicketMail (bool)
 *
 *   -- groups (either a string with comma-separated list of numeric group IDs, or an array of group IDs)
 *
 *  Change password:
 *
 *   -- uid (num)
 *
 *   -- password (string)
 *
 *  Returns the updated User object under the 'results' key.
 *
 *  Required permissions: current user must be admin or guru; gurus are restricted in what groups they can modify.
 */
WebApp::Put('/user/:uid:int', function()
{
    ApiUser::Put();
});

/**
 *  Deletes a user account. Note that accounts are not really deleted, since there may be lots of foreign
 *  references in the database, but instead marked as "disabled" so they no longer show up in user lists
 *  and can no longer log in.
 *
 *  Required permissions: current user must be admin or guru; gurus are restricted in what groups they can modify.
 */
WebApp::Delete('/user/:uid:int', function()
{
    $oUser = ApiUser::FindUserForAdmin();
    $oUser->disable();
});

/**
 *  Creates a new user group.
 *
 *   -- gname (string)
 *
 *   -- members (comma-separated list of numeric user ID)
 *
 *  Required permissions: current user must be admin or guru; gurus are restricted in what groups they can modify.
 */
WebApp::Post('/group', function()
{
    ApiUser::PostGroup();
});

/**
 *  Updates a user group.
 *
 *   -- gname (string)
 *
 *   -- members (comma-separated list of numeric user ID)
 *
 *  Required permissions: current user must be admin or guru; gurus are restricted in what groups they can modify.
 */
WebApp::Put('/group/:gid:int', function()
{
    ApiUser::PutGroup();
});

/**
 *  Deletes a user group.
 *
 *  Required permissions: current user must be admin or guru; gurus are restricted in what groups they can modify.
 */
WebApp::Delete('/group/:gid:int', function()
{
    $oGroup = ApiUser::FindGroupForAdmin();
    $oGroup->delete();
});

/**
 *  Step 1 of password reset request (see \ref reset_password). Parameters:
 *
 *   -- email (string)
 *
 *  Required permissions: none. Note that this does not even fail if the
 *  mail address is invalid; see \ref reset_password for reasons why.
 */
WebApp::Post('/resetpassword1', function()
{
    ApiUser::ResetPassword1();
});

/**
 *  Step 2 of password reset request (see \ref reset_password). Parameters:
 *
 *   -- email (string)
 *
 *   -- token (string): long token generated by /resetpassword1 and set to user
 *
 *   -- login (string): user's login name, required on top of all the above
 *
 *   -- password (string):   new password
 *
 *   -- password-confirm (string): must match password
 *
 *  Required permissions: none. This will fail however if the parameters do not
 *  match our records or the token generated by /resetpassword1.
 */
WebApp::Post('/resetpassword2', function()
{
    ApiUser::ResetPassword2();
});

/**
 *  Allows an administrator to temporarily assume the role of another user. Parameters:
 *
 *   -- uid (integer)
 *
 * Returns token to impersonate user with.
 *
 *  Required permissions: current user must be admin.
 */
WebApp::Post('/impersonate', function()
{
    ApiUser::Impersonate(WebApp::FetchParam('uid'));
});

/**
 *  Sets the given string user value for the given key. This is a generic interface
 *  for storing arbitrary key/value pairs for the currently logged in user. This will
 *  fail if no user is currently logged in; it will also fail unless the given key/value
 *  pair is permitted by one of the installed Doreen plugins. The Doreen core defines
 *  no such key/value pairs currently, so the default is for this to fail always.
 */
WebApp::Post('/userkey/:key/:value', function()
{
    ApiUser::SetUserKeyValue(WebApp::FetchParam('key'),
                             WebApp::FetchParam('value'));
});


/********************************************************************
 *
 *  TICKET COMMANDS
 *
 ********************************************************************/

/**
 *  Returns an array of tickets.
 *
 *  This searches among existing tickets and is a REST API wrapper around Doreen's
 *  most powerful seach function, \ref Ticket::FindMany().
 *
 *  At least one of the following search filter parameters must be set in the WebApp params:
 *
 *   -- 'type': numeric (integer) type ID
 *
 *   -- 'fulltext': full-text search query, like in the GUI search box
 *
 *  Unless the current user is an administrator, this function will refuse to return
 *  all tickets; at least one of the above filters must be specified.
 *
 *  The following additional filters will always be be added automatically:
 *
 *   -- The result set will be limited to the tickets that the current user is allowed
 *      to see.
 *
 *   -- Only non-templates will be returned.
 *
 *  Optionally, additional drill-down filters are supported like this:
 *
 *   -- 'drill_' + any drillable ticket field name: comma-separated list of integer values
 *
 *  Multiple hard and drill-down filter parameters can be combined and will narrow the search
 *  (as if an AND operator was specified).
 *
 *  Optionally, the following are recognized:
 *
 *   -- 'sortby': sort order (plus ticket field name)
 *
 *   -- 'format': causes formatting information to be added under each ticket. The only
 *      supported 'format' value is 'grid' currently.
 *
 *  There is also a built-in limit of 999 tickets for the entire search set, and
 *  only one page will be returned. If no 'page' argument is specified, page 1 is
 *  assumed, and the first page of tickets will be returned.
 *
 *  The response will have the following fields:
 *
 *   -- 'status' ('OK' unless an error occured)
 *
 *   -- 'cTotal': integer, total number of tickets in result set, of which
 *      one page is returned
 *
 *   -- 'results': array of ticket objects. See \ref Ticket::toArray() and TicketCoreData
 *      in the TypeScript code, but field handlers may add many additional fields to
 *      the ones listed there. Commonly used fiels are 'ticket_id', 'type_id', 'href', 'title'.
 *
 */
WebApp::Get('/tickets', function()
{
    ApiTicket::GetMany(WebApp::FetchParam('page', FALSE),
                       WebApp::FetchParam('fulltext', FALSE),
                       WebApp::FetchParam('type', FALSE),
                       ApiTicket::FetchDrillDownParams(),
                       WebApp::FetchParam('sortby', FALSE),
                       WebApp::FetchParam('format', FALSE));
});

/**
 *  Returns a single ticket identified by its ID. The current user must have READ
 *  permission on the ticket, or the call will fail.
 *
 *  The response will have the following fields:
 *
 *   -- 'status' ('OK' unless an error occurred)
 *
 *   -- 'results: one ticket object. See \ref Ticket::toArray() and TicketCoreData
 *      in the TypeScript code, but field handlers may add many additional fields to
 *      the ones listed there. Commonly used fiels are 'ticket_id', 'type_id', 'href', 'title'.
 */
WebApp::Get('/ticket/:idTicket:int', function()
{
    ApiTicket::GetOne(WebApp::FetchParam('idTicket'));
});

/**
 *  Creates a new ticket.
 *
 *  TODO document parameters
 *
 *  TODO document required permissions
 *
 *  TODO document changelog items
 */
WebApp::Post('/ticket', function()
{
    ApiTicket::Post();
});

/**
 *  Updates an existing ticket.
 *
 *  TODO document parameters
 *
 *  TODO document required permissions
 *
 *  TODO document changelog items
 */
WebApp::Put('/ticket/:idTicket:int', function()
{
    ApiTicket::Put(WebApp::FetchParam('idTicket'));
});

/**
 *  Updates only the priority of an existing ticket.
 *
 *  Parameters:
 *
 *   -- idTicket (integer)
 *
 *   -- prio (integer): new priority value, which must be at least 1.
 *      Arbitarily high integers are supported, but the system will
 *      adjust huge values to be only the currently highest value
 *      plus 1.
 *
 *   Required permissions: ACCESS_UPDATE
 *
 *   This does NOT send mail.
 */
WebApp::Put('/priority/:idTicket:int/:priority:int', function()
{
    ApiTicket::PutPriority(WebApp::FetchParam('idTicket'),
                           WebApp::FetchParam('priority'));

});

/**
 *  Updates only the priority of an existing ticket.
 *
 *  Parameters:
 *
 *   -- idTicket (integer)
 *
 *   -- template (integer): ID of the new template.
 *
 *   Required permissions: Administrator.
 *
 *   This does NOT send mail.
 */
WebApp::Put('/template-under-ticket/:idTicket:int', function()
{
    ApiTicket::PutTemplateUnderTicket(WebApp::FetchParam('idTicket'),
                                      WebApp::FetchParam('template'));
});

/**
 *  Deletes an existing ticket.
 *
 *  This requires ACCESS_DELETE permission for the current user and is fairly slow
 *  since this also deletes all foreign references in the data base that point
 *  to the ticket's row in the \ref tickets table. Depending on how a Doreen
 *  installation is used, deleting tickets is only for exceptional circumstances,
 *  since it rewrites history and users should typically not be allowed to
 *  remove their activity from the system. As a result, ACCESS_DELETE should
 *  normally be reserved for administrators.
 *
 *  TODO document changelog items
 */
WebApp::Delete('/ticket/:idTicket:int', function()
{
    ApiTicket::Delete(WebApp::FetchParam('idTicket'));
});

/**
 *  Adds a new comment to an existing ticket. Sends Mails.
 *
 *  Returns the ID and contents of the created comment.
 *
 *  Parameters:
 *
 *  -- ticket_id (integer): ID of ticket to add comment to
 *  -- comment (string): Raw comment contents
 *
 *  Required permissions: ACCESS_CREATE
 *
 *  TODO document changelog items
 */
WebApp::Post('/comment', function()
{
    ApiTicket::PostTicketComment();
});

/**
 *  Update an existing comment.
 *
 *  Returns the ID and contents of the created comment.
 *
 *  Parameters:
 *
 *  -- comment_id (integer): ID of the comment
 *  -- comment (string): New raw contents of the comment
 *
 *  Required permissions: ACCESS_UPDATE
 *
 *  TODO document changelog items
 */
WebApp::Put('/comment/:comment_id:int', function()
{
    ApiTicket::PutTicketComment();
});

WebApp::Delete('/comment/:comment_id:int', function()
{
    ApiTicket::DeleteTicketComment();
});

/**
 *  Adds an attachment (uploaded binary file) to a ticket.
 *
 *  We expect the file data in standard HTTP file upload fashion.
 *
 *  Returns a JSON array with the following:
 *
 *  TODO document parameters
 *
 *  TODO document required permissions
 *
 *  TODO document changelog items
 */
WebApp::Post('/attachment/:idTicket:int', function()
{
    ApiTicket::PostAttachment(WebApp::FetchParam('idTicket'));
});

/**
 *  Renames an attachment.
 *
 *  Expects an additional parameter "newName" with the new filename for the attachment.
 */
WebApp::Put('/attachment/:idTicket:int/:idBinary:int', function()
{
    ApiTicket::RenameAttachment(WebApp::FetchParam('idTicket'),
                                WebApp::FetchParam('idBinary'),
                                WebApp::FetchParam('newName'));
});

/**
 *  Hides an attachment from a ticket. The file is kept on the server.
 */
WebApp::Delete('/attachment/:idTicket:int/:idBinary:int', function()
{
    ApiTicket::HideAttachment(WebApp::FetchParam('idTicket'),
                              WebApp::FetchParam('idBinary'));
});

/**
 *  Returns the permissions and other debug info of the given ticket.
 */
WebApp::Get('/ticket-debug-info/:idTicket:int', function()
{
    ApiTicket::GetDebugInfo(WebApp::FetchParam('idTicket'));
});

/**
 *  Really, really bad API to really delete all tickets from this Doreen instance,
 *  but leave all other metadata (users, groups etc.) intact. This can take a
 *  really long time, possibly hours, since it calls Ticket::delete() on every
 *  ticket.
 *
 *  This spawns a LongTask and returns immediately with the task ID;
 *  the job continues to run asynchronously.
 *
 *  Requires admin permission, of course.
 */
WebApp::Post('/nuke', function()
{
    if (!LoginSession::IsCurrentUserAdmin())
        throw new NotAuthorizedException;

    WebApp::SpawnCli("Delete all tickets",
                     [ 'delete-all-tickets',
                            '--execute'
                          ]);
});

/**
 *  Returns the templates for which the current user has CREATE permission.
 *  Each object in the array has 'id', 'title' and 'htmlTitle' fields, for convenience.
 *  See the BasicTemplateApiResult interface in the front-end.
 *  To create a template, one would use the ticket ID and the POST /ticket REST API with that ID.
 *
 *  Parameters:
 *
 *   -- current-ticket (optional, int): additional parameter that allows plugins
 *      to pre-fill new ticket data based on the currently visible ticket when
 *      creating a new ticket. Not used by the core.
 */
WebApp::Get('/templates', function()
{
    WebApp::$aResponse['results'] = ApiTicket::GetTemplates(WebApp::FetchParam('current-ticket',
                                                                               FALSE));
});

/**
 *  Returns an array of all defined ticket templates on the system.
 *
 *  The front-end provides the GetAllTemplatesApiResult interface for the response.
 *
 *  Required permissions: current user must be admin or guru.
 */
WebApp::Get('/all-templates', function()
{
    ApiTicket::GetAllTemplates();
});


/********************************************************************
 *
 *  SEARCH AUTOCOMPLETE
 *
 ********************************************************************/

/**
 *  Suggests searches for the given string. This gets called by the JavaScript behind
 *  the Doreen search bar for type-ahead find and returns a JSON array of suggestions.
 *
 *  The 'suggestions' key has an array of object, where each 'v' key has a suggestion value.
 */
WebApp::Get('/suggest-searches', function()
{
    $oAccess = Access::GetForUser(LoginSession::$ouserCurrent);
    $q = WebApp::FetchParam('q');
    WebApp::$aResponse['suggestions'] = $oAccess->suggestSearches($q);
});

WebApp::Get('/suggest-mails', function()
{
    $q = WebApp::FetchParam('fulltext');
    WebApp::$aResponse['suggestions'] = MailSuggester::SuggestMailAddresses($q);
});


/********************************************************************
 *
 *  PROGRESS BAR SUPPORT
 *
 ********************************************************************/

/**
 *  Legacy progress API. This reports progress for an asynchronous background job.
 *
 *  DO NOT USE IN NEW CODE. THIS IS BEING REPLACED BY THE /listen API.
 *
 *  The one required parameter is the session ID, which should have been returned
 *  by another API that started a long-running task (see, for example the
 *  POST /reindex-all REST API).
 *
 *  This will always return at least the following fields in the JSON:
 *
 *   -- status: 'OK' or 'error'
 *
 *   -- code: HTTP error code (200 if OK)
 *
 *   -- message: if status == 'error', the error message
 *
 *   -- cCurrent: if status == 'OK', items that have been processed
 *
 *   -- cTotal: if status == 'OK', total items that need to be processed
 *
 *   -- progress (integer, in percent), if status == 'OK'
 *
 *   -- fDone: TRUE or FALSE. If this is TRUE, the job has ended (either successfully
 *      or with an error) and the session ID is no longer valid. Only one GET /progress
 *      will have this flag set; subsequent calls for the same session ID will return an error.
 *
 *  Depending on the API, additional fields may be set.
 *
 *  See LongTask for implementation notes.
 */
WebApp::Get('/progress/:idSession:int', function()
{
    $idSession = WebApp::FetchParam('idSession');

    if ($a = LongTask::GetStatus($idSession))
    {
        $a['idSession'] = $idSession;

        if ($code = getArrayItem($a, 'code'))
            WebApp::$httpResponseCode = $code;
        foreach ($a as $key => $value)
            WebApp::$aResponse[$key] = $value;
    }

    Debug::Log(Debug::FL_JOBS, "/api/progress: returning json: ".print_r(WebApp::$aResponse, TRUE));
});

/**
 *  New style notification API for longtask evens, including progress. This is intended to
 *  replace the GET /progress REST API.
 *
 *  This gets called by the APIHandler::addListener() front-end method, which is the
 *  recommended way to use this.
 *
 *  Instead of per longtask, this operates on a "channel", which is more broadly defined.
 *  Typically, a channel is defined for a whole class of jobs and will receive notifications
 *  of start, progress, and error events. This has the following advantages over the old
 *  progress API:
 *
 *   1) The client no longer needs to monitor session IDs. It just listens to the channel,
 *      and it will receive progress messages, whether they come from new or ongoing tasks.
 *
 *   2) The client will receive progress for all sessions of a channel regardless of who
 *      started them: they might already be running when the client initializes, or they
 *      might be started by someone else on the command line. (The old progress API could
 *      only display progress for sessions that were started from the GUI, when the session
 *      ID was returned.)
 *
 *   3) The client does not have to keep polling over HTTP every 300 ms even though nothing
 *      has changed; the API will block in the back-end until something actually happens.
 *
 *  This will return JSON of the ListenResult front-end interface, which has at least the
 *  'event' field set. There will often be an extra 'data' field:
 *
 *   -- With event == 'started', the result will be a ListenResult with the idSession key set.
 *      This means that a longtask has started.
 *
 *   -- With event == 'progress', the result will be a ListenResultProgress (including idSession),
 *      with progress data under the "data" key. Progress will be delivered repeatedly until
 *      eventually the ProgressData.fDone is true; there will be only one such result,
 *      and it is the responsibility of the client code to adjust the GUI to display a
 *      "success" feedback if needed in that case.
 *
 *   -- With event == 'error', the result will be a ListenResultError; idSession will be set,
 *      and the aprent APIResult.status will also have 'error', and APIResult.message will
 *      have the error message.
 *
 *   -- If event == 'timeout' is returned, that means nothing happened in the back-end for
 *      30 seconds. This is to protect the back-end from starting countless blocking tasks;
 *      in that case, the client should simply call again.
 *
 *  The interface is extensible, so some channels may define additional events and data fields.
 */
WebApp::Get('/listen/:channel', function()
{
    LongTask::GetChannelStatus(WebApp::FetchParam('channel'),
                               WebApp::$aResponse,
                               WebApp::$httpResponseCode);
});

/**
 *  Stops the session identified by the given ID.
 *
 *  This works only for job that are marked as stoppable, and the current user must
 *  be the same user who started the job.
 */
WebApp::Post('/stop-session/:idSession:int', function()
{
    # Must be logged in to do that. TODO keep track of who starts a longtask and do better.
    if (LoginSession::IsUserLoggedIn() === NULL)
        throw new NotAuthorizedException();

    $idSession = WebApp::FetchParam('idSession');
    LongTask::Stop(LoginSession::$ouserCurrent, $idSession);
});


/********************************************************************
 *
 *  HELP
 *
 ********************************************************************/

/**
 *  Returns a help topic.
 *
 * @param string :topic A predefined topic string for which help should be returned.
 */
WebApp::Get('/help/:topic', function()
{
    WebApp::$aResponse += ApiHelp::Get(WebApp::FetchParam('topic'));
});


/********************************************************************
 *
 *  Global config
 *
 ********************************************************************/

WebApp::Put('/theme/:theme', function()
{
    if (!LoginSession::IsCurrentUserAdmin())
        throw new NotAuthorizedException;

    WholePage::SetTheme(WebApp::FetchParam('theme'));
});

/**
 *  Sets a global configuration variable. Used by the Global Settings GUI,
 *  requires administrator privileges.
 */
WebApp::Post('/config', function()
{
    if (!LoginSession::IsCurrentUserAdmin())
        throw new NotAuthorizedException;

    GlobalConfig::ApiSet(WebApp::FetchParam('key'),
                         WebApp::FetchParam('value'));
});

WebApp::Post('/set-autostart/:service/:enable:bool', function()
{
    if (!LoginSession::IsCurrentUserAdmin())
        throw new NotAuthorizedException;

    $service = WebApp::FetchParam('service');
    if (!($oService = ServiceLongtask::Find($service)))
        throw new DrnException("Invalid service name $service");
    $enable = WebApp::FetchParam('enable');
    $oService->setAutostart($enable);
});


/********************************************************************
 *
 *  PLUGIN APP HANDLERS
 *
 ********************************************************************/

# Now go through all plugins to allow them to register their own app handlers.
foreach (Plugins::GetWithCaps(IUserPlugin::CAPSFL_URLHANDLERS) as $oImpl)
{
    /** @var IURLHandlerPlugin $oImpl */
    $oImpl->registerAPIAppHandler();
}


/********************************************************************
 *
 *  APP EXECUTION
 *
 ********************************************************************/

try
{
    WebApp::Run();

    http_response_code(WebApp::$httpResponseCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(WebApp::$aResponse, DEBUG_JSON_OUTPUT);
    exit;
}
catch(APIException $e)
{
    echoJSONErrorAndExit($e->dlgfield, $e->getMessage(), $e->code);
}
catch(\Exception $e)
{
    echoJSONErrorAndExit(NULL, $e->getMessage());
}
