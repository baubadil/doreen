<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/*
 *  Doreen has three entry points: htdocs/index.php, htdocs/api/api.php,
 *  cli/cli.php.
 *
 *  This is htdocs/index.php, the GUI server.
 *
 *  Everything that's seen in the user's browser goes through this index.php.
 *  The htdocs/.htaccess file makes sure that requests like /ticket/123 end up
 *  here.
 */


/********************************************************************
 *
 *  Global constants and variables
 *
 ********************************************************************/

$oldReporting = error_reporting(E_ALL);
$oldDisplayErrors = ini_get('display_errors');
ini_set('display_errors', 1);

$p = strpos($_SERVER['SCRIPT_NAME'], 'index.php');  # /doreen/index.php             /doreen/api/api.php
$g_requestURI = $_SERVER['REQUEST_URI'];            #                               /doreen/api/getusers

define('TO_HTDOCS_ROOT', '.');
define('INCLUDE_PATH_PREFIX', TO_HTDOCS_ROOT.'/../src');
define('IS_API', FALSE);
require INCLUDE_PATH_PREFIX.'/core/class_globals.php';
require INCLUDE_PATH_PREFIX.'/core/prologue.php';

DrnLocale::Init();

Globals::$rootpage = substr($g_requestURI, 0, $p - 1);          # everything up to /doreen (WITHOUT the trailing slash)
Globals::InitRequest(substr($g_requestURI, $p));              # everything after /doreen/ (WITHOUT the leading slash)
Globals::SetErrorHandler();
Debug::PrintHeader('index');

header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");


/********************************************************************
 *
 *  Check installation
 *
 ********************************************************************/

# Before an install is working, we go through three phases:
#
#   1)  Globals::$fnameInstallVars (with database type, name, user, password) does not exist:
#       then this is a completely fresh install. Go to install1.php.
#
#   2)  Globals::$fnameInstallVars (and the database) exists, but the "config" table does not
#       yet exist: go to install2+3.
#
#   4)  "config" table exists, but is empty: then go to install4, which creates the
#       admin user and the config table.
#

try
{
    if (!@include(Globals::$fnameInstallVars))
    {
        Globals::CheckRequiredPHPExtensions();
        require INCLUDE_PATH_PREFIX.'/core/install/install1.php';
    }
    else
    {
        Globals::InitDatabase();

        require INCLUDE_PATH_PREFIX.'/core/class_config.php';
        GlobalConfig::Init();

        if (GlobalConfig::$installStatus == GlobalConfig::STATUS0_NO_CONFIG_TABLE)
        {
            # config table does not exist: then create all the standard tables
            # (which INCLUDES install5_install-data to have them up-to-date)
            require INCLUDE_PATH_PREFIX.'/core/install/install2+3.php';
            # This does not return.
            # After this the config table exists, but is still EMPTY.
        }

        if (GlobalConfig::$installStatus == GlobalConfig::STATUS1_EMPTY_CONFIG_TABLE)
        {
            # config table still empty: then create admin user account
            require INCLUDE_PATH_PREFIX.'/core/install/install4.php';
            # This does not return.
        }
        # else continue for now
    }
}
catch(\Exception $e)
{
    $msg = "Error preparing the Doreen installation: ".$e->getMessage();
    $msg .= "<pre>".$e->getTraceAsString()."</pre>";
    myDie($msg);
}


error_reporting($oldReporting);
ini_set('display_errors', $oldDisplayErrors);


/********************************************************************
 *
 *  Process logins
 *
 ********************************************************************/

# Ensure that host is in GlobalConfig. In HTTP mode we can always get it from $_SERVER
# but we might need it on the CLI as well.
$hostnameCache = GlobalConfig::Get( 'hostname'); // Might be empty or outdated.
$hostnameReal = $_SERVER['HTTP_HOST'];
if ($hostnameCache != $hostnameReal)
{
    GlobalConfig::Set('hostname', $hostnameReal);
    GlobalConfig::Save();
}

if (GlobalConfig::$installStatus == GlobalConfig::STATUS99_ALL_GOOD)
    JWT::Init();

if ($login = getRequestArg('login'))
{
    if (!(LoginSession::Create($login,
                               getRequestArgOrDie('password'),
                               (getRequestArg('cookie') == 'yes'))))
        myDie("Invalid login or password.");

    $gobackto = getRequestArg('gobackto');
    WebApp::Reload($gobackto);
}
else if (Globals::GetRequestOnly() == 'logout')
{
    LoginSession::LogOut();
    WebApp::Reload();
}
else
{
    # No explicit login/logout command: then attempt to unserialize the user object from the session data.
    LoginSession::TryRestore();
}

if (!LoginSession::$ouserCurrent)
    LoginSession::$ouserCurrent = User::Find(User::GUEST);

Debug::LogUserInfo();

if (GlobalConfig::$installStatus != GlobalConfig::STATUS99_ALL_GOOD)        # GlobalConfig::STATUS2_DATABASE_OUTDATED or GlobalConfig::STATUS3_PLUGINS_OUTDATED
{
    // Need to be able to consent to cookies so you can log in.
    if (substr(Globals::GetRequestOnly(), 0, 10) == 'cookies-ok')
    {
        LoginSession::ConsentToCookies();
        WebApp::Reload(getRequestArg('from'));
    }
    # Global update (DATABASE_VERSION is out of date) or plugin update needed:
    require INCLUDE_PATH_PREFIX.'/core/install/install5_update.php';
    # This does not return.
}


/********************************************************************
 *
 *  Register core request handlers
 *
 ********************************************************************/

WebApp::Get('/.well-known/:type', function()
{
    $type = WebApp::FetchParam('type');
    switch ($type) {
        case 'change-password':
            WebApp::Reload('myaccount');
        break;
    }
});

WebApp::Get('/cookies-ok', function()
{
    LoginSession::ConsentToCookies();
    WebApp::Reload(WebApp::FetchParam('from', FALSE));
});

WebApp::Get('/keep-alive', function() {
    LoginSession::KeepSessionAlive();
    http_response_code(204);
});

WebApp::Get('/register', function()
{
    require_once INCLUDE_PATH_PREFIX.'/core/form_register.php';
    formRegister();
});

WebApp::Get('/lostpass', function()
{
    require_once INCLUDE_PATH_PREFIX.'/core/form_register.php';
    formLostPass();
});

WebApp::Get('/lostpass2/:email/:token', function()
{
    require_once INCLUDE_PATH_PREFIX.'/core/form_register.php';

    formLostPass(WebApp::FetchParam('email'),
                 WebApp::FetchParam('token'));
});

WebApp::Get('/myaccount', function()
{
    ViewMyAccount::Emit();
});

WebApp::Get('/settings', function()
{
    ViewGlobalSettings::Emit();
});

WebApp::Get('/mail-queue', function()
{
    ViewGlobalSettings::EmitMailQueue();
});

WebApp::Post('/nuke', function()
{
    ViewGlobalSettings::PostNuke();
});

WebApp::Get('/users', function()
{
    // require_once INCLUDE_PATH_PREFIX.'/core/form_users+groups.php';
    ViewUsersGroups::Emit();
});

WebApp::Get('/newticket/:idTemplate:int', function()
{
    ViewTicket::CreateAndEmit(MODE_CREATE, WebApp::FetchParam('idTemplate'));
});

WebApp::Get('/editticket/:ticket_id:int', function()
{
    ViewTicket::CreateAndEmit(MODE_EDIT, WebApp::FetchParam('ticket_id'));
});

// Implementation for /ticket/ and aliases for that requested by plugins.
// ticket_id can be an integer or a UUID.
foreach (TicketType::GetTicketLinkAliases() as $ticketOrAlias => $dummy)
{
    WebApp::Get("/$ticketOrAlias/:ticket_id", function()
    {
        ViewTicket::CreateAndEmit(MODE_READONLY_DETAILS, WebApp::FetchParam('ticket_id'));
    });
}

/**
 *  Implemenation for /wiki/ to display a Wiki ticket by its title.
 *  This is just an extra step for finding out the ticket ID by title
 *  and then emitting the ticket.
 */
WebApp::Get('/wiki/:wiki_title', function()
{
    ViewTicket::CreateAndEmitFromWikiTitle(WebApp::FetchParam('wiki_title'));
});

WebApp::Get('/binary/:idBinary:int', function()
{
    $idBinary = WebApp::FetchParam('idBinary');

    $oBinary = Binary::Load($idBinary);

    # Assert that current user has READ access to the ticket this binary was attached to.
    # This throws if not.
    Ticket::FindForUser($oBinary->idTicket,
                        LoginSession::$ouserCurrent,
                        ACCESS_READ);

    $oBinary->emitAndExit(); # Ticket::EmitBinaryAndExit($idBinary, $aInfo);
});

/*
 *   Emits the raw binary data for an image thumbnail.
 */
WebApp::Get('/thumbnail/:idBinary:int', function()
{
    if (!($size = WebApp::FetchParam('size', FALSE)))
        $size = Globals::$thumbsize;
    DrnThumbnailer::Output(WebApp::FetchParam('idBinary'),
                           $size);
    exit;
});

/*
 *   The tickets list, most importantly, for search results.
 *
 *   Arguments are not currently fully documented, but are parsed and processed by the
 *   ViewTickets class; see remarks there. Most importantly:
 *
 *       -- page=(int): page to display
 *
 *       -- sortby=(-?)(string): ticket field name to sort by. Special columns are 'id', 'created', 'score'.
 *          By default, sort is ascending; for descending, prefix "!", e.g. "!score".
 *
 *       -- fulltext (full-text search; this results from the search box)
 *
 *       -- format=(list|grid) (optional, default is list)
 */
WebApp::Get('/tickets', function()
{
    ViewTicketsList::Emit();
});

/**
 * Behind the /lang/xx_XX/ comes the returnto argument, which can be of arbitrary length
 * and include additional slashes.
 *
 * Can't use /:returnto as explicit param, since changing the language in the index would
 * lead to an empty parameter.
 */
WebApp::Get('/lang/:lang', function()
{
    $lang = WebApp::FetchParam('lang');
    DrnLocale::Set($lang);

    # Now reload the page to the 'returnto' argument. We can't use fetchParam because that may
    # contain additional slashes, so we'll just extract the whole rest of the URL manually
    # and reload with that.
    # Globals::$requestOnly is now something like lang/de_DE/ticket/31414 WITHOUT a leading slash.
    $returnTo = substr(Globals::GetRequestOnly(), strlen("lang/$lang/"));
    WebApp::Reload($returnTo);
});

WebApp::Get('/', function()
{
    ViewMainPage::Emit();
});


/********************************************************************
 *
 *  Register plugin request handlers
 *
 ********************************************************************/

# Now go through all plugins to allow them to register their own app handlers.
foreach (Plugins::GetWithCaps(IUserPlugin::CAPSFL_URLHANDLERS) as $oImpl)
{
    /** @var IURLHandlerPlugin $oImpl */
    $oImpl->registerGUIAppHandler();
}


/********************************************************************
 *
 *  Process requests
 *
 ********************************************************************/

try
{
    WebApp::Run();
}
catch(\Exception $e)
{
    myDie(toHTML($e->getMessage()));
}
