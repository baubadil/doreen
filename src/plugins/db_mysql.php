<?php
/*
 * Plugin Name: MySQL database plugin.
 * Plugin URI: http://www.baubadil.org/doreen
 * Description: Implements database support for MySQL.
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
const MYSQL_PLUGIN_NAME = 'db_mysql';

Plugins::RegisterCanActivate(MYSQL_PLUGIN_NAME, function()
{
    if (!extension_loaded('mysqli'))
        return "Local PHP installation has no MySQL (mysqli) support. Please install or recompile your PHP.";

    return NULL;
});

Plugins::RegisterInit(MYSQL_PLUGIN_NAME, function()
{
    return new DatabaseMySQL();
});


/********************************************************************
 *
 *  Implementation of the Database abstract base class
 *
 ********************************************************************/

/**
 *  Implementation of the Database abstract base class for MySQL support.
 */
class DatabaseMySQL extends Database
{
    /** @var  \mysqli $con */
    private $con;                           # Instance of mysqli.

    public function __construct()
    {
        $this->timestampUTC = 'TIMESTAMP';      # defaults to 'WITHOUT TIME ZONE'
        $this->blob = 'LONGBLOB';                  #
    }

    public function connect()
    {
        $this->connectAs(self::$defaultDBHost, self::$defaultDBUser, self::$defaultDBPassword, Database::$defaultDBName);
    }

    public function connectAdmin($host,
                                 $password)
    {
        $this->connectAs($host, 'root', $password);
    }

    /**
     *  Newly introduced, MySQL-specific method to connect as a specific user to a specific database.
     */
    public function connectAs($host, $user, $password, $dbname = "")
    {
        $this->con = new \mysqli($host, $user, $password, $dbname);
        if ($this->con->connect_errno)
            throw new DrnException("Could not connect to MySQL server: {$this->con->connect_errno} {$this->con->connect_error}");
        $this->con->query("SET SESSION sql_mode = 'ANSI';");
    }

    public function disconnect()
    {
        // TODO: Implement disconnect() method.
    }

    /**
     *  Executes the system command to restart the database server. Probably must be run as root.
     */
    public function restartServer()
    {
        throw new DrnException("Not implemented yet");
    }

    public function getVersion()
    {
        return $this->con->server_info;
    }

    public function createUserAndDB($dbname, $dbuser, $dbpassword)
    {
        $this->exec("CREATE DATABASE $dbname"); #  WITH ENCODING 'UTF8'"  # owner = user executing command (admin)
        $this->exec("CREATE USER '$dbuser'@'localhost' IDENTIFIED BY '$dbpassword'");
        $this->exec("GRANT ALL PRIVILEGES ON $dbname.* TO '$dbuser'@'localhost'");
        $this->exec("FLUSH PRIVILEGES");
    }

    public function isError($res)
    {
        if ($res === FALSE)
            return $this->con->error;
//         return pg_result_error($res);
    }

    public function tryExec($query,
                            $aParams = [])
    {
        $i = 1;
        foreach ($aParams as $param)
        {
            $query = str_replace('$'.$i, "'".$this->con->real_escape_string($param)."'", $query);
            ++$i;
        }

        Debug::Log(Debug::FL_SQL, $query);

        return $this->con->query($query);
                # This can return FALSE, or a mysqli_result object (for SELECT), or TRUE.

//         if (!pg_send_query_params($this->con,
//                                   $query,
//                                   $aParams))
//             throw new MyException("pg_send_query_params returned FALSE for query ".toHTML($query, 'code'));
//         return pg_get_result($this->con);
    }

    public function exec($query,
                         $aParams = [])
    {
        $res = $this->tryExec($query, $aParams);
        if ($err = $this->isError($res))
            throw new DrnException("MySQL query $query failed: $err");

        return $res;
    }

    public function numRows($res)
    {
        return $res->num_rows;
    }

    public function fetchNextRow($res)
    {
        /** @var \mysqli_result $res */
        return $res->fetch_assoc();
    }

    /**
     *  Resets the internal row offset in the result resource back to 0 so that the next fetchNextRow() will fetch the first result row again.
     */
    public function rewind($res)
    {
        throw new DrnException("Not implemented yet.");
    }

    /*
     *  Returns the new SERIAL (primary key) assigned after an INSERT statement.
     *  $table and $column are required for PostgreSQL, which doesn't have
     *  a LAST_INSERT_ID() function like MySQL.
     */
    public function getLastInsertID($table, $column)
    {
        if ($res = $this->exec("SELECT LAST_INSERT_ID() AS id;"))
            if ($row = $this->fetchNextRow($res))
                return (int)$row['id'];
    }

    public function beginTransaction()
    {
        if ($this->iTransactionLevel == 0)
            $this->con->begin_transaction();

        ++$this->iTransactionLevel;
    }

    public function commit()
    {
        if ($this->iTransactionLevel > 0)
        {
            if ($this->iTransactionLevel == 1)
                $this->con->commit();

            --$this->iTransactionLevel;
        }
    }

    public function rollback()
    {
        if ($this->iTransactionLevel > 0)
        {
            if ($this->iTransactionLevel == 1)
                $this->con->rollback();

            --$this->iTransactionLevel;
        }
    }

    /**
     *  Obtains a table lock on the given table, in the most restrictive mode (ACCESS EXCLUSIVE).
     *  This blocks until the lock is available.
     */
    public function lockTable($tblname)
    {
        # TODO not actually tested with MySQL.
        $this->exec("LOCK TABLE $tblname IN ACCESS EXCLUSIVE MODE");
    }

    public function makeGroupConcat($column, $groupby = NULL)
    {
        return "GROUP_CONCAT($column)";
    }

    public function makeTimestampDiff($col1, $col2)
    {
        # should be TIMESTAMPDIFF(SECOND, dt1, dt2) but untested
        throw new DrnException(__METHOD__.' not implemented yet');
    }

    public function makeFulltextQuery($field, $needle)
    {
        return "$field ILIKE '%".mysqli_real_escape_string($this->con, $needle)."%'";
    }

    public function encodeBlob(&$data)
    {
        return $data;
    }

    public function decodeBlob(&$blob)
    {
        return $blob;
    }

    public function getTotalSize($dbname)
    {
        $cb = 0;
        if ($res = $this->exec("SHOW TABLE STATUS;"))
            while ($row = $this->fetchNextRow($res))
                $cb += $row['Data_length'];

        return $cb;
    }

    public function getDataDirectory()
    {
        return ""; # TODO
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
        throw new DrnException(__METHOD__.' not yet supported with MySQL');
    }

    /**
     *  Refreshes the materialized view.
     */
    public function refreshMaterializedView($viewname)
    {
        throw new DrnException(__METHOD__.' not yet supported with MySQL');
    }

    /**
     *  Deletes the materialized view.
     */
    public function dropMaterializedView($viewname)
    {
        throw new DrnException(__METHOD__.' not yet supported with MySQL');
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
        throw new DrnException(__METHOD__.' not yet supported with MySQL');
    }

    /**
     *  LISTEN / NOTIFY implementation wrapper around pg_get_notify(). Works only with PostgreSQL.
     */
    public function getNotify()
    {
        throw new DrnException(__METHOD__.' not supported with MySQL');
    }

    public function generateDump(string $outfile)
    {
        throw new DrnException("Generating a database dump not yet supported with MySQL");
    }

    public function restoreDump(string $dbname, string $dumpFile)
    {
        throw new DrnException("Restoring a database dump not yet supported with MySQL");
    }

    public function delete(string $dbname, string $dbuser)
    {
        throw new DrnException("Deleting databases not yet supported for PostgreSQL databases");
    }
}
