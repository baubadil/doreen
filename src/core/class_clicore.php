<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  Abstract class to implement newer command-line facilities for the core.
 *  Note that older (legacy) commands are still in cli.php directly, but
 *  more and more are being moved here.
 */
abstract class CliCore extends CliBase
{
    const A_ARGS = [                # [ type, no. of extra args, help ]
        'print-ticket' =>           [ self::TYPE_MODE,
                                      1,
                                      '<ticketid>: Prints raw ticket data for the given ticket.' ],
        'reindex-all' =>            [ 0, // self::TYPE_MODE,
                                      0,
                                      ': Reindex all tickets with the search engine' ],
        'reindex-one' =>            [ 0, // self::TYPE_MODE,
                                      0,
                                      '<ticketid>: Reindex one ticket with the search engine' ],
        'reindex-all-flag' =>       [ self::TYPE_MODE,
                                      1,
                                      '<1|0>: Sets or clears the flag whether to show a message on the main page that the search engine needs reindexing' ],
        'config' =>                 [ self::TYPE_MODE,
                                      0,
                                      '[--set <key>=<value>]: Print Doreen configuration values' ],
        'reset' =>                  [ self::TYPE_MODE,
                                      0,
                                      '[-x]: Clear the current Doreen database so that Doreen prompts for a new install (very harmful!)' ],
        'reinstall' =>              [ self::TYPE_MODE,
                                      0,
                                      '[-x]: After reset -x, reinstall Doreen with the same settings previously written into doreen-optional-vars' ],
        'delete-all-tickets' =>     [ 0, // self::TYPE_MODE,
                                      0,
                                      ': Delete all tickets from the database (requires -x for safety)' ],
        'mail-daemon' =>            [ 0, // self::TYPE_MODE,
                                      0,
                                      ': Run the Doreen mail queue daemon (one instance only)' ],
        'health' =>                 [ self::TYPE_MODE,
                                      0,
                                      ': Run a quick health check on this installation and output results' ],
        'health-mail' =>            [ self::TYPE_MODE,
                                      1,
                                      '<email>: Run a quick health check on this installation and output results' ],
        'help' =>                   [ 0, // self::TYPE_MODE,
                                      0,
                                      ': Display this help' ],
        'init-crypt' =>             [ 0, // self::TYPE_MODE,
                                      0,
                                      ': Initialize the Doreen symmetric encryption key' ],
        'reset-password' =>         [ 0, // self::TYPE_MODE,
                                      0,
                                      '<user>: Reset the password for the given user account' ],
        'autostart-services'   =>   [ 0, // self::TYPE_MODE,
                                      0,
                                      '[-x]: Start all autostart services that are not yet running', ],
        'autostart-systemd' =>      [ 0, // self::TYPE_MODE,
                                      0,
                                      '[-x]: Configure systemd to run autostart-services as system startup', ],
        'flush-caches' =>           [ 0, // self::TYPE_MODE,
                                      0,
                                      ': Flush all system caches, restart the database (simulate a cold system)', ],
        'install-list' =>           [ self::TYPE_MODE,
                                      0,
                                      ': List installations under the given --install-dir', ],
        'install-new' =>            [ self::TYPE_MODE,
                                      0,
                                      ': Back up and disable the current installation so that Doreen prompts for a new install', ],
        'install-switch' =>         [ self::TYPE_MODE,
                                      1,
                                      '<name>: Back up and disable the current installation and switches to the given one', ],
        'install-vars' =>           [ self::TYPE_MODE,
                                      0,
                                      '[--query]: Display or (with --query) set optional installation variables (useful when testing install repeatedly)' ],
        'plugin-clone' =>           [ self::TYPE_MODE,
                                      1,
                                      '<gitrepo>: Add the plugin from <gitrepo>; this will create a sibling directory ../<stem>, symlink to it from under src/plugins and pull the repo' ],
        'plugin-list' =>           [ self::TYPE_MODE,
                                      0,
                                      'List all plugins installed and whether they are enabled' ],
        'backup' =>                 [ self::TYPE_MODE,
                                      1,
                                      '<dir>: Create a backup tarball (for use with the "restore" command) of the current Doreen installation in the given directory (including DB dump, attachments and configuration)' ],
        'restore' =>                [ self::TYPE_MODE,
                                      1,
                                      '[--force] <backup-tarball>: Restore a backup from the given tarball created with the "backup" command; use --force to override an existing installation' ],
        'titlepage' =>              [ self::TYPE_MODE,
                                      0,
                                      '[--set]: Display or (with --set) set the items to be displayed on the main page', ],
        'titlepageticket' =>        [ self::TYPE_MODE,
                                      0,
                                      '[--set]: Display or (with --set) set the ticket whose contents to display on the main page', ],
        'generate-password' =>      [ self::TYPE_MODE,
                                      0,
                                      ': Generate an 8-character random password string' ],
        'regenerate-jwt' =>         [ self::TYPE_MODE,
                                      1,
                                      '<host>[/<path>] [-x]: Regenerate the JWT signing key and frontend token for the given host and optionally path (e.g. "/doreen"); invalidates all currently existing tokens' ],
        'get-all-children' =>       [ self::TYPE_MODE,
                                      1,
                                      '<ticketid>: displays the IDs of all children of the given ticket ' ],
        '--set' =>                  [ self::TYPE_STRING,
                                      1,
                                      [ 'config', 'titlepage' ] ],
        '--query' =>                [ self::TYPE_FLAG,
                                      0,
                                      [ 'install-vars' ] ],
    ];

    /**
     *  Required method to be implemented by subclasses; this must return the args array.
     *
     * @return array
     */
    protected static function GetArgsData()
    {
        return self::A_ARGS;
    }

    const A_CONFIG_KEYS = [
        'hostname' => self::TYPE_STRING,
        GlobalConfig::KEY_SEARCH_REQUIRES_LOGIN => self::TYPE_BOOL,
    ];

    public static function ProcessCommands(string $installDir)
    {
        global  $g_aPlugins, $g_optForce, $g_fExecute;

        switch (self::$mode)
        {
            case 'config':
                if ($strSet = getArrayItem(self::$aStrings, '--set'))
                {
                    if (!preg_match('/([^=]+)=(.+)/', $strSet, $aMatches))
                        cliDie("Invalid format for --set argument: must be <key>=<value>");
                    $aKeysCopy = self::A_CONFIG_KEYS;
                    $key = $aMatches[1];
                    if (!($type = getArrayItem($aKeysCopy, $key)))
                        cliDie("Invalid config key ".Format::UTF8Quote($key));
                    if ($type == self::TYPE_BOOL)
                        $val = (int)parseBoolOrThrow($aMatches[2]);
                    else
                        $val = $aMatches[2];
                    GlobalConfig::Set($key, $val);
                    GlobalConfig::Save();
                }
                else
                {
                    echo "Loaded plugins: ".implode(', ', $g_aPlugins)."\n";
                    echo "Doreen version: ".Globals::GetVersion()."\n";
                    echo "Database tables version: ".DATABASE_VERSION."\n";

                    foreach (self::A_CONFIG_KEYS as $key => $flag)
                    {
                        if (!($val = GlobalConfig::Get($key)))
                            $val = ($flag == self::TYPE_BOOL) ? "FALSE" : "NULL";
                        echo "Config key ".Format::UTF8Quote($key).": $val\n";
                    }

                    echo "Use ".Format::UTF8Quote("config --set <key>=<value>")." to modify one of the above config keys.\n";
                }
            break;

            case 'titlepage':
                if ($strNewBlurbs = self::$aStrings['--set'] ?? NULL)
                    Blurb::SetAll(explode(',', $strNewBlurbs));

                echo "Available main page items:\n";
                foreach (Blurb::GetAll() as $oBlurb)
                {
                    list($id, $name) = $oBlurb->getID();
                    echo "  $id: ".L($name);
                    if ($oBlurb->fEnabled)
                        echo " (** ENABLED)";
                    echo "\n";
                }
                if (!$strNewBlurbs)
                    echo "Use --set <list> to set a new list, separating multiple items with commas.\n";
            break;

            case 'titlepageticket':
                if ($new = self::$aStrings['--set'] ?? NULL)
                {
                    if (!(isInteger($new)))
                        cliDie("Argument to ".self::$mode." must be an integer");
                    if (!($oTicket = Ticket::FindOne($new)))
                        cliDie("#$new is not a valid ticket ID");
                    Blurb::SetTitlePageWikiTicket($new);
                }

                $id = Blurb::GetTitlePageWikiTicket();
                echo "Current title page ticket: ".($id ?? "not configured yet")."\n";
            break;

            case 'install-list':
                Install::ListCli($installDir);
            break;

            case 'install-new':
                Process::AssertRunningAsRoot();
                Install::CreateBlank($installDir);
            break;

            case 'install-switch':
                Process::AssertRunningAsRoot();
                Install::SwitchTo($installDir, self::$llMainArgs[0]);
            break;

            case 'install-vars':
                if ($fQuery = isset(self::$aFlags['--query']))
                    Process::AssertRunningAsRoot();

                Install::ShowVariables($fQuery);
            break;

            case 'print-ticket':
                if (!($oTicket = Ticket::FindOne($idTicket = self::$llMainArgs[0])))
                    throw new DrnException("Cannot find ticket #$idTicket");
                $oTicket->populate(TRUE);
                print_r($oTicket);
            break;

            case 'plugin-clone':
                Install::CliPluginClone(self::$llMainArgs[0],
                                        $g_optForce);
            break;

            case 'plugin-list':
                Install::CliPluginsList();
            break;

            case 'backup':
                BackupRestore::Backup(self::$llMainArgs[0], $installDir);
            break;

            case 'restore':
                BackupRestore::Restore(self::$llMainArgs[0],
                                       $installDir,
                                       $g_optForce);
            break;

            case 'health':
                Health::PrintCli();
            break;

            case 'health-mail':
                Health::SendAsMail(self::$llMainArgs[0], $g_optForce);
            break;

            case 'generate-password':
                echo "New password: ".User::GeneratePassword()."\n";
            break;

            case 'reindex-all-flag':
                switch (self::$llMainArgs[0])
                {
                    case 0:
                        GlobalConfig::FlagNeedReindexAll(FALSE);
                    break;

                    case 1:
                        GlobalConfig::FlagNeedReindexAll(TRUE);
                    break;

                    default:
                        cliDie("Argument must be 0 or 1");
                }
            break;

            case 'regenerate-jwt':
                if ($token = GlobalConfig::Get(GlobalConfig::KEY_JWT_FRONTEND_TOKEN))
                {
                    $parser = new \Lcobucci\JWT\Parser();
                    $currentToken = $parser->parse($token);

                    // Only generating this to check the issuer, can't use it later since the signature secret changes.
                    $frontendToken = JWT::GetToken(JWT::FRONTEND_UID,
                                                   JWT::TYPE_API_CLIENT,
                                                   '',
                                                   0);
                    if ($currentToken->getClaim(JWT::CLAIM_ISSUER) != $frontendToken->getClaim(JWT::CLAIM_ISSUER))
                        echo "[Warning] Issuer of token will change from ".Format::UTF8Quote($currentToken->getClaim(JWT::CLAIM_ISSUER))." to ".Format::UTF8Quote($frontendToken->getClaim(JWT::CLAIM_ISSUER))."\n";
                }

                if (!$g_fExecute)
                {
                    echo "Run with -x to actually regenerate the key and token.\n";
                    return;
                }

                # Fake the /doreen part temporarily since JWT uses Globals::$rootpage.
                if (($p = strpos(self::$llMainArgs[0], '/')) !== FALSE)
                    Globals::$rootpage = substr(self::$llMainArgs[0], $p);

                $secret = bin2hex(random_bytes(128));
                JWT::UpdateKey($secret);
                echo "New key saved\n";

                $frontendToken = JWT::GetToken(JWT::FRONTEND_UID,
                                               JWT::TYPE_API_CLIENT,
                                               '',
                                               0);
                GlobalConfig::Set(GlobalConfig::KEY_JWT_FRONTEND_TOKEN, $frontendToken);
                echo "New front-end token generated\n";

                //TODO clear all generated user tokens and IDs.
                GlobalConfig::Save();
            break;

            case 'get-all-children':
                if (!($oTicket = Ticket::FindOne($idTicket = self::$llMainArgs[0])))
                    throw new DrnException("Cannot find ticket #$idTicket");
                self::PrintChildren(0, $oTicket);
            break;
        }
    }

    public static function PrintChildren(int $indent,
                                         Ticket $oTicket)
    {
        $oTicket->populate(TRUE);
        echo str_repeat(" ", $indent * 2)."Ticket #".$oTicket->id.": ".Format::UTF8Quote($oTicket->getTitle())."\n";
        if ($strChildren = $oTicket->aFieldData[FIELD_CHILDREN] ?? NULL)
            foreach (explode(",", $strChildren) as $idChild)
            {
                if (!($oChild = Ticket::FindOne($idChild)))
                    throw new DrnException("Cannot find child ticket #$idChild of parent $oTicket->id");
                self::PrintChildren($indent + 1,
                                    $oChild);
            }
        // print_r($oTicket->aFieldData[FIELD_CHILDREN]);
    }
}
