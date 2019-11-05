<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  Helper class used by ManagedTable to cache data once per derived class. This
 *  is magic that occurs behind the scene so that the derived class need not worry
 *  about which objects have already been instantiated.
 *
 *  Note that these variables are INSTANCE, not STATIC variables, but semantically,
 *  they are static variables because an instance of this class exists for every
 *  class that derives from ManagedTable.
 */
class ManagedTableClassInstance
{
    /** @var $aAllLoadedByID ManagedTable[]  */
    public $aAllLoadedByID = [];

    public $fLoadedAll = FALSE;

    public $aFieldsCache = NULL;
    public $aTypesCache = NULL;
}

/**
 *  Magic class that helps with implementing classes that mirror database tables
 *  that should support the typical Create, Read, Update, Delete cycle.
 *
 *  Essentially, you derive a class from ManagedTable and set the 'tablename' and
 *  'llFields' members in your derived class. In particular, 'llFields' must be a list
 *  of column names that must exist exactly like this in the database table. It is
 *  then possible to call LoadAll() on your derived class, and it will be magically
 *  filled with objects that have exactly those fields with data from the table.
 *
 *  It is assumed that the table has an 'i' column that serves as the primary index.
 *  The values therein are used as the object IDs in this class, and objects are
 *  sorted by that ID in the $aAllLoadedByID array. If your table's primary index
 *  is not called 'i', override static::$idColumn in your derived class.
 *
 *  It is also assumed that all columns contain strings. However, you can override
 *  static::$llFieldMaps to have $fieldname => $target pairs. If set, $target must
 *  be a string with 'varname:type' syntax, where varname is the name of the class
 *  instance variable (can be the same as the field name), and 'type' can be one
 *  of the following (literal capitals):
 *
 *   -- STRING: varname is treated as a string.
 *
 *   -- INT: varname is typecast to (int).
 *
 *   -- BOOLEAN: varname is typecast to a boolean; see initObject() for the logic
 *      how values are converted. Using this flag is highly recommended for bools,
 *      especially when fetching data from PostgreSQL, because otherwise these
 *      won't be handled correctly.
 *
 *   -- HIDDEN: varname is still a string, but it is not exported by \ref toArray().
 *
 *  After LoadAll(), the entire table specified by the derived class is instantiated
 *  as objects.
 *
 *  Additionally, a static Find() method allows for instantiating a single object
 *  whose ID is known.
 *
 *  This already implements a working delete() instance method.
 *
 *  A derived class should additionally implement the following for a proper interface:
 *
 *   -- a static Create() method with named arguments;
 *
 *   -- an update() instance method with named arguments.
 */
class ManagedTable
{
    public $id;                     # Row ID, same as the 'i' primary index.

    static protected $tablename = NULL;
    static protected $llFields  = [];           # List of TABLE field names and, unless overriden in llFieldMaps, also instance variable names.
    static protected $idColumn = 'i';           # name of the column to use as the 'id' field (not to be listed in $llFields)
    static protected $llFieldMaps = [];

    static protected $llLeftJoins = [];         # Optional: additional (LEFT) JOIN statements if extra tables are to be pulled in.
    static protected $llLeftJoinFields = [];    # Optional: fields from those tables in SELECT.
        /* Note that the left join fields are NOT automatically managed. You must override MakeAwakeOnce() to handle those. */
    static protected $llExtraColumns = [];      # Optional: extra columns for SELECT, for custom MakeAwake overrides.

    /** @var ManagedTableClassInstance[] */
    private static $aClassInstances = [];          # One global array for all classes, sorted by derived class names.

    /**
     *  Returns the ManagedTableClassInstance for the current class. Instantiates it
     *  on the first call for every derived class.
     */
    public static function GetClassInstance()
        : ManagedTableClassInstance
    {
        $derivedClassName = get_called_class();
        if (!isset(self::$aClassInstances[$derivedClassName]))
            self::$aClassInstances[$derivedClassName] = new ManagedTableClassInstance();

        return self::$aClassInstances[$derivedClassName];
    }

    public static function GetFieldName($fieldname)
    {
        if (preg_match('/(.*)\.(.*)/', $fieldname, $aMatches))
            return $aMatches[2];

        return $fieldname;
    }

    /**
     *  Returns a list of instance variable names. This is static::$llFields unless
     *  field names have been overridden in static::$llFieldMaps. The 'id' instance
     *  variable name is never included.
     */
    public static function MakeInstanceVariableNames(&$aTypes)
    {
        $llFieldNames = static::$llFields;

        $a = [];
        foreach ($llFieldNames as $fieldname)
        {
            $useFieldname = $fieldname;
            if ($override = getArrayItem(static::$llFieldMaps, $fieldname))
            {
                if (preg_match('/([^:]*):([^:]*)/', $override, $aMatches))
                {
                    $useFieldname = $aMatches[1];
                    $aTypes[$useFieldname] = $aMatches[2];
                }
            }
            $a[] = $useFieldname;
        }
        return $a;
    }

    /**
     *  Returns the object with the given ID.
     *
     *  If an object has already been instantiated for that ID, it is returned.
     *  Otherwise a new one is created with the given data.
     *
     *  This uses the magic of late static bindings to fill the new object with
     *  the field names from the derived class. See
     *  http://php.net/manual/en/language.oop5.late-static-bindings.php for details.
     */
    public static function MakeAwakeOnce($row)      //!< in: array with field names; the 'id' and other field names must exist!
    {
        if (!($id = $row['id'] ?? NULL))
            throw new DrnException("Internal error: missing ID in row");
        $oClass = self::GetClassInstance();
        if (isset($oClass->aAllLoadedByID[$id]))
            return $oClass->aAllLoadedByID[$id];

        if (!$oClass->aFieldsCache)     # late static binding! use the derived class
            # first call for this class:
            $oClass->aFieldsCache = array_merge([ 'id' ],
                                                self::MakeInstanceVariableNames($oClass->aTypesCache));

        $o = new static();          # late static binding! use the derived class
        initObject($o,
                   $oClass->aFieldsCache,
                   array_values($row),
                   $oClass->aTypesCache);
        $oClass->aAllLoadedByID[$id] = $o;
        return $o;
    }

    /**
     *  Loads all objects from the table. The objects are loaded on the first call and then cached.
     *  Returns the last object found, or NULL if there are none.
     *
     *  Normally you would call this without arguments. Find() however calls this with a WHERE clause.
     */
    public static function LoadAll($where = NULL,
                                   $whereArg = NULL,    //!< in: single argument for if $where has a $1 string
                                   $orderby = NULL)     //!< in: column name to order by or NULL
    {

        $oLast = NULL;
        $oClass = self::GetClassInstance();

        # If fLoadedAll is TRUE, we never have to load.
        # If fLoadedAll is NOT specified,

        Debug::FuncEnter(Debug::FL_MANAGEDTABLE, __METHOD__."(), where: $where");

        if (!$oClass->fLoadedAll)
        {
            if (!static::$llLeftJoins)
                $cols = implode(', ',
                                static::$llFields);      # late static binding! http://php.net/manual/en/language.oop5.late-static-bindings.php
            else
            {
                # If we have left joins, prefix the columns with table names to prevent ambiguity.
                $a2 = static::$llFields;
                $cols = static::$tablename.'.'.array_shift($a2);
                foreach ($a2 as $field)
                    $cols .= ", ".static::$tablename.".$field";
                foreach (static::$llLeftJoinFields as $field)
                    $cols .= ", $field";
            }

            foreach (static::$llExtraColumns as $str)
                $cols .= ", $str";

            $table = static::$tablename;            # late static binding! http://php.net/manual/en/language.oop5.late-static-bindings.php
            $leftjoins = (static::$llLeftJoins) ? "\n".implode("\n", static::$llLeftJoins) : '';
            $aArgs = [];
            if ($where)
            {
                $where = "\n$where";
                if ($whereArg !== NULL)
                {
                    if (is_array($whereArg))
                        $aArgs = $whereArg;
                    else
                        $aArgs = [ $whereArg ];
                }
            }
            $idColumn = static::$idColumn;          # late static binding! defaults to 'i', the standard primary key
            if ($orderby)
                $orderby = "\nORDER BY $orderby";
            $res = Database::DefaultExec(<<<SQL
SELECT $idColumn AS id, $cols
FROM $table$leftjoins$where$orderby
SQL
                                             , $aArgs
            );
            while ($row = Database::GetDefault()->fetchNextRow($res))
                $oLast = static::MakeAwakeOnce($row);

            if (!$where)
                $oClass->fLoadedAll = TRUE;
        }

        Debug::FuncLeave();

        return $oLast;
    }

    /**
     *  Returns all currently awake objects, without loading any first.
     */
    public static function GetAll()
    {
        $oClass = self::GetClassInstance();
        return $oClass->aAllLoadedByID;
    }

    /**
     *  Returns all loaded objects as a PHP array of arrays to be encoded as JSON.
     *  This calls
     *
     *  The front-end may or may not provide a TypeScript interface for this format
     */
    public static function GetAllAsArray($llKeys = NULL, $orderby = NULL)
    {
        $oClass = self::GetClassInstance();

        self::LoadAll(NULL, NULL, $orderby);

        $aForJSON = [];
        foreach ($oClass->aAllLoadedByID as $id => $o)
            $aForJSON[] = $o->toArray($llKeys);

        return $aForJSON;
    }

    /**
     *  Returns the single object with the given ID, or NULL if it does not exist.
     *  Attempts to load it from the database.
     *
     *  Subclasses should implement a Find() method and call this and specify a
     *  proper 'return' PHP doc variable so that the method is documented as
     *  returning a derived class.
     */
    public static function FindImpl($id)
    {
        Debug::Log(Debug::FL_MANAGEDTABLE, __METHOD__."($id) for managed table ".static::$tablename);

        if (!isInteger($id))
            throw new DrnException("The given ".get_called_class()." ID ".Format::UTF8Quote($id)." is not an integer");
        $id = (int)$id;

        $oClass = self::GetClassInstance();
        if (array_key_exists($id, $oClass->aAllLoadedByID))     // Use array_key_exists to be able to access NULL values.
            return $oClass->aAllLoadedByID[$id];

        $idColumn = static::$idColumn;          # late static binding! defaults to 'i', the standard primary key
        self::LoadAll("WHERE $idColumn = $1", $id);

        // If even after loading the ID does not exist, then set the
        // value for this key to NULL so we don't query again.
        if (!array_key_exists($id, $oClass->aAllLoadedByID))
            $oClass->aAllLoadedByID[$id] = NULL;

        return $oClass->aAllLoadedByID[$id];
    }

    /**
     *  Returns an array of objects as $id => $object pairs or NULL if none are found.
     *
     *  If an object cannot be found and $fThrow == TRUE, an exception is thrown.
     *  Otherwise we just ignore the bad ID silently.
     */
    public static function FindManyByID($llIDs,
                                        $fThrow = TRUE)
    {
        $aReturn = [];

        $oClass = self::GetClassInstance();
        $llNeedQuery = [];
        foreach ($llIDs as $id)
        {
            if (!isPositiveInteger($id))
                throw new DrnException("The given ".get_called_class()." ID ".Format::UTF8Quote($id)." is not a positive integer");
            $id = (int)$id;
            if (!array_key_exists($id, $oClass->aAllLoadedByID))     // value can be NULL
                $llNeedQuery[] = $id;
        }

        if (count($llNeedQuery))
        {
            $str = Database::MakeInIntList($llNeedQuery);
            $tblname = static::$tablename;
            $idcolumn = static::$idColumn;
            self::LoadAll("WHERE $tblname.$idcolumn IN ($str)");
        }

        foreach ($llIDs as $id)
        {
            // If even after loading the ID does not exist, then set the
            // value for this key to NULL so we don't query again.
            if (!array_key_exists($id, $oClass->aAllLoadedByID))
            {
                if ($fThrow)
                    throw new DrnException("Cannot find managed object for id $id");
                else
                    $oClass->aAllLoadedByID[$id] = NULL;
            }

            $aReturn[$id] = $oClass->aAllLoadedByID[$id];
        }

        return count($aReturn) ? $aReturn : NULL;
    }

    /**
     *  Helper to be called by the Create() implementation of a subclass.
     *
     *  Note that this does not set the primary key, but assumes that the primary key is a SERIAL, whose ID
     *  is auto-incremented and then fetched from the database and inserted into the new object.
     *  If your table's primary key is NOT a SERIAL, you probably need to write your own insert.
     *
     *  @return self
     */
    static protected function CreateImpl($aFields)
    {
        $table = static::$tablename;            # late static binding! http://php.net/manual/en/language.oop5.late-static-bindings.php

        Database::GetDefault()->insertMany($table, array_keys($aFields), $aFields);
        $newID = Database::GetDefault()->getLastInsertID($table, static::$idColumn); # late static binding! defaults to 'i', the standard primary key

        # ID field must come first. If there are join fields, add them and set them to zsero.
        $aFields2 = array_merge( [ 'id' =>  (int)$newID ],
                                 $aFields);
        foreach (static::$llLeftJoinFields as $field)
            $aFields2 += [ self::GetFieldName($field) => NULL ];

        return static::MakeAwakeOnce($aFields2);
    }

    /**
     *  Removes $this from the cache of awake objects for this class, which is a precondition
     *  for PHP releasing the memory associated with this instance. However, if the implementation
     *  derived from ManagedTable maintains other caches with awake objects, those will need
     *  to be released as well before PHP's garbage collector can free the memory eventually.
     *  Implementations best override this method and call this parent then.
     *
     *  Note that the PHP garbage collector doesn't seem to kick in until available memory has
     *  been exhausted or gc_collect_cycles() is called explicitly. Use memory_get_usage()
     *  for testing.
     */
    public function release()
    {
        $oClass = self::GetClassInstance();
        unset($oClass->aAllLoadedByID[$this->id]);
    }

    /**
     *  Returns the member data field as an array which can be converted to JSON.
     *
     *  $llKeys should be the list of keys to return. If it is NULL, we'll use static::$llKeys.
     *
     *  This skips values whose mapped type has been set to HIDDEN.
     */
    public function toArray($llKeys = NULL)
        : array
    {
        $a2 = [ 'i' => (int)$this->id ];

        if (!$llKeys)
            $llKeys = static::$llFields;

        $oClass = self::GetClassInstance();

        foreach ($llKeys as $field)
        {
            if (    ($type = $oClass->aTypesCache[$field] ?? NULL)
                 && ($type == 'HIDDEN')
               )
                continue;

            $a2[$field] = $this->$field;
        }

        return $a2;
    }

    /**
     *  Updates the database instance with the given key => value
     *  pairs. Does NOT update the member variables of $this!
     *
     *  @return void
     */
    protected function updateImpl($aFields)
    {
        $table = static::$tablename;            # late static binding! http://php.net/manual/en/language.oop5.late-static-bindings.php

        $sql = "UPDATE $table SET ";
        $aValues = [];
        $i = 1;
        foreach ($aFields as $key => $value)
        {
            if ($i > 1)
                $sql .= ', ';
            $sql .= "$key = $".$i++;
            // PostgreSQL can't handle PHP's FALSE in booleans so try this.
            if ($value === FALSE)
                $value = 0;
            $aValues[] = $value;
        }
        $idColumn = static::$idColumn;          # late static binding! defaults to 'i', the standard primary key
        $sql .= " WHERE $idColumn = $".$i;
        $aValues[] = $this->id;

        Database::DefaultExec($sql, $aValues);
    }

    /**
     *  Deletes this instance, both from the table and from the static list of instantiated objects.
     *
     *  PHP does not have a forced way to release memory, but if the caller holds no other references to
     *  the object, its memory might actually be released.
     */
    public function delete()
    {
        $oClass = self::GetClassInstance();
        $table = static::$tablename;
        $idColumn = static::$idColumn;          # late static binding! defaults to 'i', the standard primary key
        Database::DefaultExec(<<<EOD
DELETE FROM $table WHERE $idColumn = $1
EOD
                         , [ $this->id ] );

        unset($oClass->aAllLoadedByID[$this->id]);
    }
}
