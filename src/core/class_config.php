<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  GlobalConfig class
 *
 ********************************************************************/

/**
 *  The GlobalConfig class has only static functions and is a wrapper around the
 *  \ref config database table, which gets loaded entirely on every HTTP request.
 *  This holds things like the database version and other short, critical data
 *  which is likely to be needed on every request.
 *
 *  Global config entries are simple key/value string pairs always.
 *
 *  Again, as this data gets loaded from the database on every single page request,
 *  only very short strings should be stored here that are likely to be used all
 *  the time.
 */
class GlobalConfig
{
    const STATUS0_NO_CONFIG_TABLE       =  0;
    const STATUS1_EMPTY_CONFIG_TABLE    =  1;
    const STATUS2_DATABASE_OUTDATED     =  2;
    const STATUS3_PLUGINS_OUTDATED      =  3;
    const STATUS99_ALL_GOOD             = 99;

    public static $installStatus        =  0;

    public static $databaseVersion      = NULL;

    public static $aFields = [];

    public static $aFieldsOrig = [];
    public static $aUpdate = [];
    public static $aInsert = [];
    public static $aDelete = [];

    const KEY_EMAILQ_ID = 'emailq-msgq-id';
    const KEY_AUTOSTART_SERVICES = 'autostart-services';
    const KEY_MAINPAGEITEMS = 'mainpage-items';
    const KEY_MAINWIKITICKET = 'mainpage-wikiticket';
    const KEY_SEARCH_REQUIRES_LOGIN = 'search-requires-login';
    const KEY_REINDEX_SEARCH = 'needs-reindex';
    const KEY_REINDEX_RUNNING = 'reindex-running';

    const KEY_ID_TYPE_WIKI = 'id_wiki-type';                    // ID of the 'wiki' ticket type.
    const KEY_ID_TEMPLATE_WIKI = 'id_wiki-template';            // ID of the 'wiki' ticket template.

    const KEY_JWT_SECRET = 'jwt_secret';
    const KEY_JWT_FRONTEND_TOKEN = 'jwt_token';

    /**
     *  Initializes the global config.
     *
     *  Requires that the global database object has been instantiated (i.e. install step 1
     *  as been completed and the file pointed to by Globals::$fnameInstallVars exists).
     *  This then can return:
     *
     *    * STATUS0_NO_CONFIG_TABLE if the config table does not exist in the database. Caller should then go to
     *      step 2/3 of the install;
     *
     *    * STATUS1_EMPTY_CONFIG_TABLE if the config table exists but is EMPTY. Caller should then go to step 4
     *      of the install (create admin account);
     *
     *    * STATUS2_DATABASE_OUTDATED if the config table contains rows but 'database-version' < DATABASE_VERSION:
     *      Callers should then initiate an update;
     *
     *    * STATUS3_PLUGINS_OUTDATED if the main database version is up-to-date but some plugin has requested an
     *      update;
     *
     *    * STATUS99_ALL_GOOD if everything is fine.
     */
    public static function Init()
    {
        $res = Database::GetDefault()->tryExec("SELECT key, value FROM config");
        if (Database::GetDefault()->isError($res))
            return self::$installStatus = self::STATUS0_NO_CONFIG_TABLE;

        # Read the key/value pairs.
        while ($row = Database::GetDefault()->fetchNextRow($res))
        {
            $key = $row['key'];
            self::$aFields[$key] = self::$aFieldsOrig[$key] = $row['value'];
        }

        if (!isset(self::$aFields['database-version']))
            return self::$installStatus = self::STATUS1_EMPTY_CONFIG_TABLE;

        if (self::$aFields['database-version'] < DATABASE_VERSION)
            return self::$installStatus = self::STATUS2_DATABASE_OUTDATED;

        Plugins::TestInstalls();

        if (count(Install::$aNeededInstallsPrio1) || count(Install::$aNeededInstallsPrio2) || count(Install::$aNeededInstalls))
            return self::$installStatus = self::STATUS3_PLUGINS_OUTDATED;

        return self::$installStatus = self::STATUS99_ALL_GOOD;
    }

    /**
     *  Returns the value for the given config key, or the given default value
     *  (or NULL) if none exists.
     */
    public static function Get(string $key,
                               $default = NULL)
    {
        if (isset(self::$aFields[$key]))
            return self::$aFields[$key];
        return $default;
    }

    public static function GetBoolean($key,
                                      bool $fDefault)
        : bool
    {
        $v = self::Get($key, ($fDefault) ? 1 : 0);
        if (    ($v === 0)
             || ($v === '0')
           )
            return FALSE;
        if (    ($v === 1)
             || ($v === '1')
           )
            return TRUE;
        throw new DrnException("Value \"$v\" for \"$key\" in global configuration is not a boolean");
    }

    /**
     *  Wrapper around Get() that throws if the key is not set or is not an integer.
     */
    public static function GetIntegerOrThrow($key)
        : int
    {
        if (!($val = self::Get($key)))
            throw new DrnException("Cannot find value for \"$key\" in global configuration");
        if (!isInteger($val))
            throw new DrnException("Value for \"$key\" in global configuration is not an integer");

        return $val;
    }

    /**
     *  Sets the given config key to the given value and marks the configuration
     *  as dirty.
     *
     *  Providing a NULL $value will delete the key.
     *
     *  You MUST call GlobalConfig::Save() afterwards or the changes will be lost.
     *
     * @return void
     */
    public static function Set($key, $value)
    {
        if (self::Get($key) !== $value)
        {
            $fExists = isset(self::$aFieldsOrig[$key]);

            if ($value === NULL)
            {
                if ($fExists)
                    self::$aDelete[$key] = 1;
            }
            else
            {
                self::$aFields[$key] = $value;

                if ($fExists)
                    # key existed originally: needs UPDATE
                    self::$aUpdate[$key] = 1;
                else
                    # key needs INSERT:
                    self::$aInsert[$key] = 1;
            }
        }
    }

    /**
     * Removes the given key, if it exists; does not fail if it doesn't exist.
     */
    public static function Clear($key)
    {
        if (isset(self::$aFields[$key]))
        {
            unset(self::$aFields[$key]);
            Database::DefaultExec('DELETE FROM config WHERE key = $1', [ $key ] );
        }
    }

    /**
     *  Saves changes of the global configuration back to the config table. This only
     *  does anything if at least one Set() call has been made.
     */
    public static function Save()
    {
        foreach (self::$aUpdate as $key => $dummy)
            Database::DefaultExec('UPDATE config SET value = $1         WHERE key = $2',
                                                                 [ self::$aFields[$key],  $key ] );
        self::$aUpdate = [];

        foreach (self::$aDelete as $key => $dummy)
            Database::DefaultExec('DELETE FROM config WHERE key = $1',
                                                                      [ $key ]);
        self::$aDelete = [];

        # TODO The following could be combined into a single query.
        foreach (self::$aInsert as $key => $dummy)
        {
            Database::DefaultExec('INSERT INTO config ( key,   value  ) '.
                                                    'VALUES ( $1,    $2   )',
                                                            [ $key,  self::$aFields[$key] ] );
            # Add this value to aFieldsOrig in case Save() gets called again for this page load.
            self::$aFieldsOrig[$key] = self::$aFields[$key];
        }
        self::$aInsert = [];
    }


    /********************************************************************
     *
     *  Specific settings
     *
     ********************************************************************/

    const KEY_TICKET_MAIL_ENABLED = 'ticket-mail-enabled';
    const KEY_TOP_NAVBAR_FIXED = 'top-navbar-fixed';

    // So far this can only handle booleans.
    const TYPE_BOOL = 1;

    const A_CONFIG_KEYS = [
        self::KEY_TICKET_MAIL_ENABLED   => [ self::TYPE_BOOL, TRUE ],
        self::KEY_TOP_NAVBAR_FIXED      => [ self::TYPE_BOOL, TRUE ],
    ];

    /**
     *  Returns a $type, $default key for the given settings key, or throws
     *  if the key is invalid.
     */
    private static function FindSettingOrThrow(string $key)
    {
        $aConfig = self::A_CONFIG_KEYS;
        if (!isset($aConfig[$key]))
            throw new DrnException("Invalid settings key $key");

        return $aConfig[$key];
    }

    private static function GetBooleanOThrow(string $key)
        : bool
    {
        list($type, $default) = self::FindSettingOrThrow($key);
        return self::GetBoolean('conf_'.$key, $default);
    }

    /**
     *  Returns the current value of the "ticket mail enabled" global setting, which defaults to TRUE.
     */
    public static function IsTicketMailEnabled()
        : bool
    {
        return self::GetBooleanOThrow(self::KEY_TICKET_MAIL_ENABLED);
    }

    /**
     *  Returns the current value of the "top navbar fixed" global setting, which defaults to TRUE.
     */
    public static function IsTopNavbarFixedEnabled()
        : bool
    {
        return self::GetBooleanOThrow(self::KEY_TOP_NAVBAR_FIXED);
    }

    /**
     *  Returns the prefix for ticket mail subject lines with a trailing space. Currently this is
     *  hard-coded as [Doreen] or whatever is configured as the branding name through optional vars.
     */
    public static function GetTicketMailSubjectPrefix()
        : string
    {
        return '['.Globals::$doreenName.'] ';
    }

    /**
     *  Implementation for the POST /config REST API.
     */
    public static function ApiSet(string $key,
                                  $value)
    {
        list($type, $default) = self::FindSettingOrThrow($key);

        switch ($type)
        {
            case self::TYPE_BOOL:
                self::Set('conf_'.$key, ($value) ? 1 : 0);
            break;

            default:
                throw new DrnException("Unknown type with settings key $key");
        }

        self::Save();
    }

    public static function FlagNeedReindexAll($set = TRUE)
    {
        self::Set(self::KEY_REINDEX_SEARCH, $set ? 1 : 0);
        self::Save();
    }

    /**
     *  Adds an install routine to the global initialization.
     *
     *  $i can either be an SQL string or a callable which can do arbitrary installation tasks.
     *
     *  See \ref Install::ExecuteRoutines().
     *
     *  This method should be in the Install class but then all plugins would need updating
     *  so it remains here.
     */
    public static function AddInstall($title, $i)
    {
        Install::$aNeededInstalls += [ $title => $i ];
    }

    /**
     *  Adds a priority-1 install routine to the global initialization.
     *
     *  This operates like AddInstall() except that this will have higher priority.
     *  See \ref Install::ExecuteRoutines().
     *
     *  This method should be in the Install class but then all plugins would need updating
     *  so it remains here.
     */
    public static function AddPrio1Install($title, $i)
    {
        Install::$aNeededInstallsPrio1 += [ $title => $i ];
    }

    /**
     *  Adds a priority-2 install routine to the global initialization.
     *
     *  This operates like AddInstall() except that this will have higher priority.
     *  See \ref Install::ExecuteRoutines().
     *
     *  This method should be in the Install class but then all plugins would need updating
     *  so it remains here.
     */
    public static function AddPrio2Install($title, $i)
    {
        Install::$aNeededInstallsPrio2 += [ $title => $i ];
    }

}
