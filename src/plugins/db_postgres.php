<?php
/*
 * Plugin Name: Postgres database plugin.
 * Plugin URI: http://www.baubadil.org/doreen
 * Description: Implements database support for PostgreSQL.
 * Version: 0.1.0
 * Author: Baubadil GmbH.
 * Author URI: http://URI_Of_The_Plugin_Author
 * License: GPL2.
 */

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Global constants
 *
 ********************************************************************/

# Convenience variable containing this plugin's name. For use within this plugin to facilitate copy & paste.
# We use a variable instead of a constant because this may be redefined when multiple plugins are loaded.
const POSTGRES_PLUGIN_NAME = 'db_postgres';

Plugins::RegisterCanActivate(POSTGRES_PLUGIN_NAME, function()
{
    if (!extension_loaded('pgsql'))         # Gentoo's USE flag is called 'postgres', but this is what PHP uses.
        return "Local PHP installation has no PostgreSQL support. Please install or recompile your PHP.";
    return NULL;
});

Plugins::RegisterInit(POSTGRES_PLUGIN_NAME, function()
{
    return new DatabasePostgres();
});


/**
 * @page postgres_tuning PostgreSQL performance tuning
 *
 *       According to http://www.revsys.com/writings/postgresql-performance.html, the following
 *       options could be useful:
 *
 *       -- `shared_buffers = <num>` — My default was 128M, and they advise using 25% of available RAM.
 *          "Most people find that setting it larger than a third starts to degrade performance."
 *          Setting it to 1024 MB made no discernible difference here though.
 *
 *       -- `effective_cache_size = <num>` — Tells the PostgreSQL optimizer how much memory PostgreSQL
 *          has available for caching data and helps in determing whether or not it use an index or not.
 *          The larger the value increases the likely hood of using an index. This should be set to the
 *          amount of memory allocated to shared_buffers plus the amount of OS cache available. Often
 *          this is more than 50% of the total system memory. Setting it to 8GB instead of 4 GB
 *          also made no difference.
 *
 *       -- `work_mem = <num>` — This option is used to control the amount of memory using in sort
 *          operations and hash tables (per operation, not system-wide!). Also made no difference.
 */

/********************************************************************
 *
 *  Implementation of the Database abstract base class
 *
 ********************************************************************/

/**
 *  Implementation of the Database abstract base class for PostgreSQL support.
 */
class DatabasePostgres extends Database
{
    private $con = NULL;

    public function __construct()
    {
        $this->timestampUTC = 'TIMESTAMP';      # defaults to 'WITHOUT TIME ZONE'
        $this->blob = 'BYTEA';                  #
    }

    public function connect()
    {
        $this->connectAs(self::$defaultDBHost, self::$defaultDBUser, self::$defaultDBPassword);
    }

    public function connectAdmin($host,
                                 $password)
    {
        $this->connectAs($host, 'postgres', $password);
    }

    /**
     *  Newly introduced, MySQL-specific method to connect as a specific user to a specific database.
     */
    public function connectAs($host, $user, $password, $dbname = "")
    {
        $this->disconnect();

        $dbname = ($dbname) ? " dbname=$dbname" : '';
        if (!($this->con = @pg_connect("host=$host user=$user password=$password$dbname")))
            throw new DrnException("Could not connect to PostgreSQL server on host \"$host\" with user account \"$user\"");
    }

    /**
     *  Disconnects from the database.
     */
    public function disconnect()
    {
        if ($this->con)
        {
            pg_close($this->con);
            $this->con = NULL;
        }
    }

    /**
     *  Tries to find the systemd service name of the database server. On Gentoo, this
     *  is called postgresql-9.4/5. On Debian it's just 'postgresql'.
     */
    public static function GetRunningServerName()
    {
        exec('systemctl | grep postgres | grep running',
             $aOutput,
             $rc);
        if (!$rc)
            foreach ($aOutput as $line)
                if (preg_match('/(postgresql(?:-\d\.\d))\.service/', $line, $aMatches))
                    return $aMatches[1];

        return NULL;
    }

    /**
     *  Executes the system command to restart the database server. Probably must be run as root.
     */
    public function restartServer()
    {
        if ($service = self::GetRunningServerName())
            // e.g. postgresql-9.5
            Process::SpawnOrThrow("systemctl restart $service");
    }

    public function getVersion()
    {
        if (!$this->con)
            $this->connect();
        $a = pg_version($this->con);
        return $a['server'];
    }

    public function createUserAndDB($dbname, $dbuser, $dbpassword)
    {
        $this->exec("CREATE DATABASE \"$dbname\" WITH ENCODING 'UTF8'"  # owner = user executing command (admin)
                   );
        $this->exec("CREATE ROLE \"$dbuser\" WITH LOGIN ENCRYPTED PASSWORD '$dbpassword'");
        $this->exec("GRANT CREATE ON DATABASE \"$dbname\" TO \"$dbuser\"");
                # This should be sufficient. When a new table is CREATEd, it is owned by the user who created
                # it, so we'll just create the table as $dbuser and should have all necessary privileges.
    }

    public function isError($res)
    {
        return pg_result_error($res);
    }

    public function tryExec($query,
                            $aParams = [])
    {
        if (!$this->con)
            $this->connect();

        $time1 = microtime(true);

        if (!pg_send_query_params($this->con,
                                  $query,
                                  $aParams))
        {
            $err = pg_last_error($this->con);
            throw new DrnException("pg_send_query_params returned error \"$err\" for query $query");
        }

        if (Debug::$flDebugPrint & Debug::FL_SQL)
        {
            $i = 1;
            $query2 = $query;
            foreach ($aParams as $param)
            {
                if (strlen($param) > 40)
                    $param = substr($param, 0, 40)."...";
                $query2 = str_replace('$'.$i, ' (( '.$param.' )) ', $query2);
                ++$i;
            }
            Debug::Log(Debug::FL_SQL, "Executing $query2 ...");
        }

        $res = pg_get_result($this->con);
        $times = timeTaken($time1);

        Debug::Log(Debug::FL_SQL, "====> $times secs, ".pg_num_rows($res).' row(s)');

        return $res;
    }

    public function exec($query,
                         $aParams = [])
    {
        $res = $this->tryExec($query, $aParams);
        if ($err = $this->isError($res))
        {
            $msg = "PostgreSQL query failed: $err";
            /* . Query was: $query";
            if ($aParams && count($aParams))
                $msg .= "Params: ".print_r($aParams, TRUE); */

            throw new DrnException($msg);
        }

        return $res;
    }

    /**
     *  Returns the no. of rows in the given result returned by tryExec(), or 0
     *  if there was an error. Also returns 0 if the result set is empty.
     */
    public function numRows($res)
    {
        $c = pg_num_rows($res);
        if ($c < 0)
            return 0;
        return $c;
    }

    /**
     *  Fetches the next row from a result returned by tryExec() as an associative array.
     *  Returns NULL if no (more) rows are available.
     */
    public function fetchNextRow($res)
    {
        return pg_fetch_assoc($res);
    }

    /**
     *  Resets the internal row offset in the result resource back to 0 so that the next fetchNextRow() will fetch the first result row again.
     */
    public function rewind($res)
    {
        pg_result_seek($res, 0);
    }

    /*
     *  Returns the new SERIAL (primary key) assigned after an INSERT statement.
     *  $table and $column are required for PostgreSQL, which doesn't have
     *  a LAST_INSERT_ID() function like MySQL.
     */
    public function getLastInsertID($table, $column)
    {
        $res = $this->exec("SELECT currval(pg_get_serial_sequence('$table', '$column')) AS id");
        $row = pg_fetch_assoc($res);
        return (int)$row['id'];
    }

    public function beginTransaction()
    {
        if ($this->iTransactionLevel == 0)
            $this->exec("BEGIN");      # not ISOLATION LEVEL SERIALIZABLE

        ++$this->iTransactionLevel;
    }

    public function commit()
    {
        if ($this->iTransactionLevel > 0)
        {
            if ($this->iTransactionLevel == 1)
                $this->exec("COMMIT");

            --$this->iTransactionLevel;
        }
    }

    public function rollback()
    {
        if ($this->iTransactionLevel > 0)
        {
            $this->exec("ROLLBACK");

            $this->iTransactionLevel = 0;
        }
    }

    /**
     *  Obtains a table lock on the given table, in the most restrictive mode (ACCESS EXCLUSIVE).
     *  This blocks until the lock is available.
     */
    public function lockTable($tblname)
    {
        $this->exec("LOCK TABLE $tblname IN ACCESS EXCLUSIVE MODE");
    }

    public function makeGroupConcat($column, $groupby = NULL)
    {
        if ($groupby)
            $groupby = " OVER (PARTITION BY $groupby)";
        return "ARRAY_TO_STRING(ARRAY_AGG($column)$groupby, ',')";
    }

    public function makeTimestampDiff($col1, $col2)
    {
        return "(EXTRACT(EPOCH FROM $col2) - EXTRACT(EPOCH FROM $col1))";
    }

    public function makeFulltextQuery($field, $needle)
    {
        $esc = pg_escape_literal($this->con, $needle);
        return "to_tsvector($field) @@ plainto_tsquery($esc)";
    }

    public function encodeBlob(&$data)
    {
        return pg_escape_bytea($data);
    }

    public function decodeBlob(&$blob)
    {
        return pg_unescape_bytea($blob);
    }

    public function getTotalSize($dbname)
    {
        $res = $this->exec("SELECT pg_database_size('$dbname') AS s;");
        $row = $this->fetchNextRow($res);
        return $row['s'];
    }

    public function getDataDirectory()
    {
        // $res = $this->exec("SHOW data_directory"); Requires admin rights, so no go.
        $line = `ps aux | grep postgresql | grep -- '-D '`;
        if (preg_match('/-D\s+(\S+)/', $line, $aMatches))
            return $aMatches[1];

        return NULL;
    }

    /**
     *  Creates a materialized view, which is a cached result of a query that needs manual refreshing.
     *  See, for example, https://www.postgresql.org/docs/current/static/rules-materializedviews.html .
     *
     *  Note that at least with PostgreSQL, the cached query cannot have bind parameters.
     */
    public function createMaterializedView($viewname,     //!< in: view name (for database)
                                           $select)       //!< in: SELECT to cache (cannot have bind parameters)
    {
        $this->exec(<<<SQL
CREATE MATERIALIZED VIEW $viewname AS
$select
SQL
        );
    }

    /**
     *  Refreshes the materialized view.
     */
    public function refreshMaterializedView($viewname)
    {
        $this->exec("REFRESH MATERIALIZED VIEW $viewname");
    }

    /**
     *  Deletes the materialized view.
     */
    public function dropMaterializedView($viewname)
    {
        $this->exec("DROP MATERIALIZED VIEW IF EXISTS $viewname");
    }

    /**
     *  Can be used to escape a single value in an SQL string. The returned string is already enclosed
     *  in quotes.
     *
     *  Usage of this method is NOT RECOMMENDED unless an argument really cannot be inserted as a $1
     *  substitue with tryExec (e.g. because a variable number of string arguments needs to be passed
     *  to an SQL 'IN' operator).
     */
    public function escapeString(string $str)
        : string
    {
        return pg_escape_literal($this->con, $str);
    }

    /**
     *  LISTEN / NOTIFY implementation wrapper around pg_get_notify(). Works only with PostgreSQL.
     */
    public function getNotify()
    {
        return pg_get_notify($this->con);
    }

    /**
     *  Gets a configuration option set in connect() when opening the connection to the database.
     */
    private function getOption(string $optionName)
    {
        $options = pg_options($this->con);
        $configStrings = explode(" ", $options);
        foreach ($configStrings as $option)
        {
            $details = explode("=", $option);
            if ($details[0] === $optionName)
                return implode("=", array_slice($details, 1));
        }
        return NULL;
    }

    public function restoreDump(string $dbname, string $dumpFile)
    {
        $cmd = "psql -U postgres --host '".pg_host($this->con)."' '".$dbname."' < '$dumpFile'";
        Globals::EchoIfCli("Restoring database from dump: ".Format::UTF8Quote($cmd));
        Globals::EchoIfCli("If you get prompted for a password, please enter the password for the 'postgres' database user.");
        // Adding password here so it is not printed to the command line.
        $dbadminpwd = $this->getOption('password');
        if (!empty($dbadminpwd))
            $cmd = "PGPASSWORD=$dbadminpwd ".$cmd;

        system($cmd);
    }

    public function generateDump(string $outfile)
    {
        $host = pg_host($this->con);
        $dbname = self::$defaultDBName;
        $cmd = "pg_dump -U postgres --host '$host' '$dbname' > '$outfile'";

        Globals::EchoIfCli("Creating database dump: ".Format::UTF8Quote($cmd));
        Globals::EchoIfCli("If you get prompted for a password, please enter the password for the 'postgres' database user.");

        system($cmd);
    }

    public function delete(string $dbname, string $dbuser)
    {
        $res = $this->exec("DROP DATABASE IF EXISTS \"".$dbname."\"\n");
        $res = $this->exec("DROP USER IF EXISTS \"".$dbuser."\"\n");
    }
}
