<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */


namespace Doreen;


/********************************************************************
 *
 *  Keyword class
 *
 ********************************************************************/

/**
 *  The Keyword class represents a keyword definition from the keyword_defs table.
 *
 *  Use Keyword::LoadAll() to load all keyword definitions from the database.
 */
class Keyword
{
    public $id;                     # Keyword ID, same as 'i' column in keyword_defs table.
    public $keyword;                # Keyword name, as shown to the user.

    public static $aAllLoadedByID = [];
    public static $aAllLoadedByKeyword = [];

    private static $fLoadedAll = FALSE;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    protected function __construct()
    {
    }

    /*
     *  Returns the Keyword instance matching the given ID.
     *
     *  If an object has already been instantiated for that ID, it is returned.
     *  Otherwise a new one is created with the given data.
     */
    public static function MakeAwakeOnce($id,
                                         $keyword)
    {
        if (isset(self::$aAllLoadedByID[$id]))
            return self::$aAllLoadedByID[$id];

        $o = new self();
        initObject($o,
                   [ 'id', 'keyword' ],
                   func_get_args());
        self::$aAllLoadedByID[$id] = $o;
        self::$aAllLoadedByKeyword[$keyword] = $o;
        return $o;
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  Returns the keyword as an HTMLChunk.
     */
    public function formatHTML()
        : HTMLChunk
    {
        $o = new HTMLChunk();
        $o->html = Format::HtmlQuotes(toHTML($this->keyword));
        return $o;
    }


    /* ******************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Loads all keywords from the database.
     */
    private static function LoadAll()
    {
        if (!self::$fLoadedAll)
        {
            $res = Database::DefaultExec(<<<EOD
SELECT
    i,
    keyword
FROM keyword_defs
EOD
                                     );
            while ($row = Database::GetDefault()->fetchNextRow($res))
                self::MakeAwakeOnce($row['i'], $row['keyword']);

            self::$fLoadedAll = true;
        }
    }

    /**
     *  Throws if the given keyword is not adhering to our naming rules, which are:
     *
     *  We use a regex for valid C identifiers.
     */
    private static function Validate($keyword)
    {
        # http://stackoverflow.com/questions/5474008/regular-expression-to-confirm-whether-a-string-is-a-valid-identifier-in-python
        if (!preg_match('/^[^\d\W]\w*$/', $keyword))
            throw new DrnException(L('{{L//%KEY% is not a valid keyword; it must start with a letter followed by digits or more letters}}',
                                     array('%KEY%' => $keyword)));
        # \w is a word character (alphanumeric or _) and represents [0-9a-zA-Z_]
        # \W is a negated \w; it represents any non-word character
    }

    /**
     * @return Keyword | null
     */
    public static function Get($id)
    {
        self::LoadAll();
        if (isset(self::$aAllLoadedByID[$id]))
            return self::$aAllLoadedByID[$id];
        return NULL;
    }

    /**
     *  Returns the Keyword object for the given keyword. If an entry already exists in
     *  the database, it is returned. Otherwise a new entry is created in the database.
     */
    public static function CreateOrGet($keyword)
    {
        self::Validate($keyword);

        self::LoadAll();

        if (isset(self::$aAllLoadedByKeyword[$keyword]))
            return self::$aAllLoadedByKeyword[$keyword];

        Database::DefaultExec(<<<EOD
INSERT INTO keyword_defs
    ( keyword) VALUES
    ( $1)
EOD
  , [ $keyword ] );

        $idKeyword = Database::GetDefault()->getLastInsertID('keyword_defs', 'i');

        return self::MakeAwakeOnce($idKeyword, $keyword);
    }
}

