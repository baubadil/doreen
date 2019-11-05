<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Database base class
 *
 ********************************************************************/

/**
 *  Database is an abstract base class that is used by all Doreen code to be
 *  agnostic to whether it is running on MySQL or PostgreSQL. All SQL is only made
 *  through functions declared here. Two implementation plugins exist,
 *  DatabaseMySQL and DatabasePostgres, and others could be added if needed.
 *
 *  So, depending on Doreen's installation variables, on every script run
 *  Doreen creates an instance of one of the two implementation classes, and
 *  then makes all calls through the abstract interface.
 *
 *  As an example, after creating an instance of a Database subclass, you can
 *  just run $oDatabase->exec("SELECT * FROM table"), and it will work on both
 *  MySQL and PostgreSQL. Most of these functions, like exec(), fetchNextRow(),
 *  getVersion() etc. use the PHP bindings for each database directly.
 *
 *  Additionally, however, the caller must be aware of which SQL features are
 *  actually understood by which database. Some functions exist here to provide
 *  for when implementations differ:
 *
 *   -- \ref createUserAndDB() is used at install time to create the Doreen database
 *      and the database user with which we connect to it. Setting up the
 *      privileges correctly is database-dependent.
 *
 *   -- \ref getLastInsertID() must be used to retrieve the column value of a primary
 *      index for a row that was just inserted.
 *
 *   -- \ref beginTransaction(), commit() and rollback() must be used for transactions.
 *
 *   -- \ref makeGroupConcat() allows for concatenating many column values together.
 *
 *   -- \ref encodeBlob() and decodeBlob() are required to deal with PostgreSQL's BYTEA type.
 *
 *  Finally, the abstract Database class implements some useful helpers that are
 *  database-agnostic, like insertMany(), upsert() and makeInIntList().
 */
abstract class Database
{
    # Data type abstractions (must be set by subclass constructor)
    public $timestampUTC;           # datatype to use for time stamps without time zone (all are UTC)
    public $blob;                   # datatype to use for binary blobs (MySQL: 'BLOB', PostgreSQL: 'BYTEA')

    public static $defaultDBType;
    public static $defaultDBName;
    public static $defaultDBHost;
    public static $defaultDBUser;
    public static $defaultDBPassword;

    /** @var  Database $o */
    private static $o;

    protected $iTransactionLevel = 0;

    public static function InitDefault()
    {
        foreach ( [ 'DBTYPE' => &self::$defaultDBType,
                    'DBNAME' => &self::$defaultDBName,
                    'DBHOST' => &self::$defaultDBHost,
                    'DBUSER' => &self::$defaultDBUser,
                    'DBPASSWORD' => &self::$defaultDBPassword,
                  ] as $const => &$self)
            if (defined($const))
                $self = constant($const);
            else
                self::dieInstallError($const);

        $dbplugin = 'db_'.self::$defaultDBType;
        self::$o = Plugins::InitDatabase($dbplugin);
    }

    private static function dieInstallError(string $const)
    {
        myDie("Constant $const is not defined. There seems to be a problem with your ".toHTML(Globals::$fnameInstallVars, 'code')." file.");
    }

    public static function SetDefault(Database $o)
    {
        self::$o = $o;
    }

    /**
     *  Returns the default database object. This is what you want to use 99% of the time.
     *  @return self
     */
    public static function GetDefault()
        : self
    {
        return self::$o;
    }

    /**
     *  Shortcut to GetDefault()->exec().
     */
    public static function DefaultExec($query, $aParams = [])
    {
        return self::$o->exec($query, $aParams);
    }

    /**
     *  Connects to the database with the user name and password that was configured at
     *  install time and written into the "install-vars" file.
     */
    abstract public function connect();

    /**
     *  Connects to the database via the administrator account ("root" on MySQL, "postgres" on PostgreSQL).
     *  Since the password for that user is not stored at install time, it need to be given here (after
     *  having prompted the user, probably).
     */
    abstract public function connectAdmin($host,
                                          $password);

    /**
     *  Disconnects from the database.
     */
    abstract public function disconnect();

    /**
     *  Executes the system command to restart the database server. Probably must be run as root.
     */
    abstract public function restartServer();

    /**
     *  Returns version information about the database server. Used by the "global settings" page.
     */
    abstract public function getVersion();

    /**
     *  Gets called by the installer (after connectAdmin) to create the database and the Doreen database user account.
     */
    abstract public function createUserAndDB($dbname, $dbuser, $dbpassword);

    abstract public function isError($res);

    abstract public function tryExec($query, $aParams = NULL);

    abstract public function exec($query, $aParams = NULL);

    /**
     *  Returns the no. of rows in the given result returned by tryExec(), or 0
     *  if there was an error. Also returns 0 if the result set is empty.
     */
    abstract public function numRows($res);

    /**
     *  Fetches the next row from a result returned by tryExec() as an associative array.
     *  Returns NULL if no (more) rows are available.
     */
    abstract public function fetchNextRow($res);

    /**
     *  Helper function that calls exec($query), $row = fetchNextRow($res) and then returns $row[$colname].
     *  Useful for single-value returns like COUNT(...). Warning: returns NULL if the value
     *  is not set.
     */
    public function execSingleValue($query,         //!< in: query passed to exec()
                                    $aParams,       //!< in: parameters passed to exec()
                                    $colname)       //!< in: array index (column name from query) to peek into
    {
        if ($res = $this->exec($query, $aParams))
            if ($row = $this->fetchNextRow($res))
                return getArrayItem($row, $colname);

        return NULL;
    }

    /**
     *  Resets the internal row offset in the result resource back to 0 so that the next fetchNextRow() will fetch the first result row again.
     */
    abstract public function rewind($res);

    /**
     *  Returns the new SERIAL (primary key) assigned after an INSERT statement.
     *  $table and $column are required for PostgreSQL, which doesn't have a
     *  LAST_INSERT_ID() function like MySQL.
     */
    abstract public function getLastInsertID($table, $column);

    public function isInTransaction()
    {
        return $this->iTransactionLevel > 0;
    }

    /**
     *  Begins a transaction. These calls can be nested; the BEGIN transaction
     *  and COMMIT statements will only be sent to the database on the first
     *  call of this and the last call of commit().
     */
    abstract public function beginTransaction();

    abstract public function commit();

    abstract public function rollback();

    /**
     *  Obtains a table lock on the given table, in the most restrictive mode (ACCESS EXCLUSIVE).
     *  This blocks until the lock is available. Must be used from within a transaction, PostgreSQL docs say:
     *  http://www.postgresql.org/docs/current/static/sql-lock.html
     */
    abstract public function lockTable($tblname);

    /**
     *  Helper to abstract away the GROUP_CONCAT feature, which is quite a bit
     *  more complicated on PostgreSQL.
     */
    abstract public function makeGroupConcat($column, $groupby = NULL);

    /**
     *  Produces a time stamp difference in seconds between the two TIMESTAMP values.
     *  The diff is positive if $col2 > $col1.
     *
     *  Examples: makeTimeStampDiff("dt1", "dt2") will yield:
     *
     *   -- on MySQL:       TIMESTAMPDIFF(SECOND, dt1, dt2)
     *
     *   -- on PostgreSQL:  EXTRACT(EPOCH FROM dt2) - EXTRACT(EPOCH FROM dt1)
     */
    abstract public function makeTimestampDiff($col1, $col2);

    /**
     *  Produces a an expression for fulltext search, for example "$field ILIKE '%needle%'".
     */
    abstract public function makeFulltextQuery($field, $needle);

    /**
     *  Encode binary data before inserting it into a BLOB column. This is necessary for PostgreSQL BYTEA columns.
     */
    abstract public function encodeBlob(&$data);

    /**
     *  Decode binary data coming from a BLOB column. This is necessary for PostgreSQL BYTEA columns.
     */
    abstract public function decodeBlob(&$blob);

    /**
     *  Return total size of Doreen database on disk.
     */
    abstract public function getTotalSize($dbname);

    /**
     *  Return the path to the database's files on disk.
     */
    abstract public function getDataDirectory();

    /**
     *  Creates a materialized view, which is a cached result of a query that needs manual refreshing.
     *  See, for example, https://www.postgresql.org/docs/current/static/rules-materializedviews.html .
     *
     *  Note that at least with PostgreSQL, the cached query cannot have bind parameters.
     */
    abstract public function createMaterializedView($viewname,      //!< in: view name (for database)
                                                    $select);       //!< in: SELECT to cache (cannot have bind parameters)

    /**
     *  Refreshes the materialized view.
     */
    abstract public function refreshMaterializedView($viewname);

    /**
     *  Deletes the materialized view.
     */
    abstract public function dropMaterializedView($viewname);

    /**
     *  Can be used to escape a single value in an SQL string. The returned string is already enclosed
     *  in quotes.
     *
     *  Usage of this method is NOT RECOMMENDED unless an argument really cannot be inserted as a $1
     *  substitue with tryExec (e.g. because a variable number of string arguments needs to be passed
     *  to an SQL 'IN' operator).
     */
    abstract public function escapeString(string $str)
        : string;

    /**
     *  LISTEN / NOTIFY implementation wrapper around pg_get_notify(). Works only with PostgreSQL.
     */
    abstract public function getNotify();

    /**
     *  Implementation for insertMany().
     */
    private function insertMany2($tblname,
                                 $aFieldNames,            //!< in: flat list of table column names
                                 $aValues)
    {
        $sql = "INSERT INTO $tblname ( ".implode(', ', $aFieldNames).') VALUES ';

        $cFields = count($aFieldNames);
        $i = 0;
        foreach ($aValues as $v)
        {
            if ($i % $cFields == 0)
            {
                if ($i > 0)
                    $sql .= "), ";
                $sql .= '(';
            }
            else
                $sql .= ', ';
            $sql .= '$'.++$i;
        }
        $sql .= ");";

         $this->exec($sql, $aValues);
    }

    /**
     *  Helper to make big INSERT statements more efficient.
     *
     *  Example: insertMany( $tblname,
     *                       [ 'field1', 'field2', 'field3' ],
     *                       [ 1, 2, 3, 4, 5, 6 ] )
     *
     *  is a shortcut for
     *
     *  exec("INSERT INTO $tblname ( 'field1', 'field2', 'field3' ) VALUES ( $1, $2, $3 ), ( $4, $5, $6 )",
     *       $aValues);
     *
     *  This checks whether $aValues is a non-empty array and does absolutely nothing if it isn't.
     */
    public function insertMany($tblname,
                               $aFieldNames,            //!< in: flat list of table column names
                               $aValues)
    {
        if (    is_array($aValues)
             && count($aValues)
           )
        {
            $aValues2 = [];
            $i = 0;
            $cFields = count($aFieldNames);

            # Copy the values and flush the buffer every 500 values, so that
            # the insert does not become too big.
            foreach ($aValues as $v)
            {
                // PostgreSQL can't handle PHP's FALSE in booleans so try this.
                if ($v === FALSE)
                    $v = 0;

                $aValues2[] = $v;
                if (    (++$i % $cFields == 0)
                     && (count($aValues2) > 500)
                   )
                {
                    $this->insertMany2($tblname, $aFieldNames, $aValues2);
                    $aValues2 = [];
                }
            }

            # Insert the remaining ones, if any.
            if (count($aValues2))
                $this->insertMany2($tblname, $aFieldNames, $aValues2);
        }
    }

    /**
     *  Helper to do an INSERT OR UPDATE in a primitive way.
     *
     *  Postgres doesn't have it before 9.5, and their developers certainly make it sound very complex.
     *  Reference: https://wiki.postgresql.org/wiki/UPSERT
     *
     *  This implementation simply peeks into the database whether the primary key exists and then
     *  does INSERT or UPDATE accordingly, and we do it in a transaction, but I suppose it's not race-free.
     *  It's still better than nothing and we can't require PostgreSQL 9.5 yet.
     */
    public function upsert($tblname,
                           $columnPrimaryKey,           //!< in: name of the column that has the primary key that we check for existing values
                           $aOtherColumnNames,          //!< in: array of the other column names that need to be inserted or updated
                           $aValuePairs)                //!< in: array of values to insert or update, in $primaryKey => [ $value, $value, ... ] format, in the order of $aOtherColumnNames
    {
        $this->beginTransaction();

        # Get all primary keys from table so we know which rows exist already. Those will get UPDATE instead of INSERT.
        $aExistingPrimaryKeys = [];
        $res = $this->exec("SELECT $columnPrimaryKey FROM $tblname");
        while ($row = $this->fetchNextRow($res))
        {
            $key = $row[$columnPrimaryKey];
            $aExistingPrimaryKeys[$key] = 1;
        }

        $aAllColumnNames = array_merge( [ $columnPrimaryKey ], $aOtherColumnNames);
        $aInsertValues = [];

        foreach ($aValuePairs as $key => $aValues)
        {
            if (isset($aExistingPrimaryKeys[$key]))
            {
                # Build an SQL string like "UPDATE $tblname SET $key1 = $1, $key2 = $2 WHERE $columnPrimaryKey = $3" with an according SQL values array.
                $i = 1;
                $aSQLValues = [];
                $sql = "UPDATE $tblname SET ";
                foreach ($aOtherColumnNames as $column)
                {
                    if ($i > 1)
                        $sql .= ', ';
                    $sql .= "$column = $".$i++;
                    $aSQLValues[] = array_shift($aValues);
                }
                $sql .= " WHERE $columnPrimaryKey = $".$i;
                $aSQLValues[] = $key;
                $this->exec($sql, $aSQLValues);
            }
            else
            {
                # INSERT: add it to the bunch for insertMany()
                $aInsertValues[] = $key;
                foreach ($aValues as $value)
                    $aInsertValues[] = $value;
            }
        }

        if (count($aInsertValues))
            $this->insertMany($tblname, $aAllColumnNames, $aInsertValues);

        $this->commit();
    }

    /**
     *  Makes a string that can be used with the IN operator of a WHERE clause (e.g. "WHERE ticket_id IN (1, 2, 3)").
     *
     *  $aInts is assumed to be an array of integer values (values are taken, keys are ignored), and each value
     *  is validated here to avoid SQL insertions.
     *
     *  This function is needed because the IN operator does not support parameter substitutions in PostgreSQL and
     *  we'd still like to use it without security holes.
     *
     *  @return string|null
     */
    public static function MakeInIntList($llInts)
    {
        if ($llInts && count($llInts))
        {
            foreach ($llInts as $int)
                if (!isInteger($int))
                    throw new DrnException("Invalid integer value \"$int\" in WHERE... IN() clause");

            return implode(',', $llInts);
        }

        return NULL;
    }

    /**
     *  Restores a database dump generated by the GenerateDump method.
     */
    public abstract function restoreDump(string $dbname, string $dumpPath);

    /**
     *  Creates a database dump.
     */
    public abstract function generateDump(string $outfile);

    /**
     *  Deletes the database.
     */
    public abstract function delete(string $dbname, string $dbuser);
}

/**
 *  A list of JoinBase instances.
 */
class JoinsList
{
    /** @var JoinBase[] $aJoins */
    private $aJoins = [];

    /**
     *  Adds the given JoinBase if its field_id is not in the list yet.
     */
    public function add(JoinBase $oLJ)
    {
        $field_id = $oLJ->field_id;
        if (!isset( $this->aJoins[$field_id]))
            $this->aJoins[$field_id] = $oLJ;
    }

    public function toString()
    {
        $str = '';
        foreach ($this->aJoins as $oLJ)
            $str .= $oLJ->toString();

        return $str;
    }
}

/**
 *  Helper class to make building left joins easier. This allows you to more easily construct the
 *  right hand side of a complicated WHERE clause.
 */
class JoinBase
{
    /*
     *  From within BuildWhereForFilters():
     *
     *  ```php
     *  $strLeftJoinThis .= "\nLEFT JOIN ticket_ints $tblAlias ON ($tblAlias.ticket_id = $ticketIDReference AND $tblAlias.field_id = $filterFieldID)";
     *  ```
     *  From within orderby:
     *
     *  ```php
     *  $strLeftJoinThis .= "\nLEFT JOIN $tblname              ON ($tblname.ticket_id = tickets.i           AND $tblname.field_id = $field_id)";
     *  ```
    */
    public $field_id;
    public $tblName;
    public $tblAlias;
    public $ticketIDRef;
    public $joinType;
    public $strExtra;

    public function __construct(TicketField $oField,      //!< in: alias or null
                                $ticketIDReference = NULL,
                                $joinType)
    {
        $this->field_id = $oField->id;
        $this->tblName = $oField->tblname;
        $this->tblAlias = 'tbl_'.$oField->name;
        if (!($this->ticketIDRef = $ticketIDReference))
            $this->ticketIDRef = 'tickets.i';
        $this->joinType = $joinType;
    }

    /**
     *  $str will be prefixed with the table name alias.
     */
    public function addExtra($str)      //!< in: "column = value", e.g. "value LIKE '%BLAH%'
    {
        $this->strExtra = " AND $this->tblAlias.$str";
    }

    public function toString()
    {
        if ($this->tblAlias)
            return "\n$this->joinType $this->tblName $this->tblAlias ON ($this->tblAlias.ticket_id = $this->ticketIDRef AND $this->tblAlias.field_id = $this->field_id$this->strExtra)";

        return "\n$this->joinType $this->tblName ON ($this->tblName.ticket_id = $this->ticketIDRef AND $this->tblName.field_id = $this->field_id$this->strExtra)";
    }
}

/**
 *  Creates a LEFT JOIN clause.
 */
class LeftJoin extends JoinBase
{
    public function __construct(TicketField $oField,      //!< in: alias or null
                                $ticketIDReference = NULL)
    {
        parent::__construct($oField,
                            $ticketIDReference,
                            "LEFT JOIN");
    }
}

/**
 *  Creates an INNER JOIN clause. Since INNER is optional, it can be left out.
 */
class InnerJoin extends JoinBase
{
    public function __construct(TicketField $oField,      //!< in: alias or null
                                $ticketIDReference = NULL,
                                $strExtra = NULL)
    {
        parent::__construct($oField,
                            $ticketIDReference,
                            "JOIN");     // JOIN = INNER JOIN
    }
}
