<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Category class
 *
 ********************************************************************/

/**
 *  A Doreen "category" allows for assigning a string value from an enumeration
 *  to a ticket. In other words, a category is a 1:N relation between a ticket and a
 *  string value that is part of an enumeration; the value is stored as an integer
 *  with the ticket data in the \ref ticket_categories table. The mappings of string
 *  category definitions to ints are stored in the \ref categories table.
 *
 *  Categories are tied to a Doreen ticket field. The default FIELD_CATEGORY is
 *  reserved for bug trackers, but you can define an arbitrary other ticket field.
 *
 *  Categories can also be hierarchical in that every category definition can optionally
 *  have a parent. By definition, if a ticket has category X, it is also automatically
 *  part of all parent categories of X. (If a ticket has the category "Bee", then
 *  it is also an "Insect" and an "Animal".)
 *
 *  This base class interface supports simple category definitions without parents.
 *  If you want to support parent categories, you best derive a subclass from this
 *  that overrides the Create() method with something better.
 */
class Category extends ManagedTable
{
    static protected $tablename = 'categories';
    static protected $llFields  = [ 'field_id',
                                    'name',
                                    'parent',
                                    'extra' ];

    const EXTRAKEY_FOR_PROJECT_ID = 'for_project_id';

    public $field_id;
    public $name;
    public $parent;                         // Numeric ID or NULL.
    public $extra;          // JSON
    public $aExtraDecoded = NULL;        // NULL means $extra has not yet been decoded; [] means it has, but $extra was empty

    protected function decodeExtra()
    {
        if ($this->aExtraDecoded === NULL)
        {
            if ($this->extra)
                $this->aExtraDecoded = json_decode($this->extra, TRUE);
            else
                $this->aExtraDecoded = [];
        }
    }

    /**
     *  Returns the project ID that this category can only be used with, or NULL if the
     *  category is not limited to a project.
     *
     * @return int | null
     */
    public function getForProjectID()
    {
        $this->decodeExtra();
        return $this->aExtraDecoded[self::EXTRAKEY_FOR_PROJECT_ID] ?? NULL;
    }

    /**
     *  Returns the parent FTCategory for $this or NULL if $this is a root category.
     *
     * @return Category | null
     */
    public function getParent()
    {
        if ($this->parent)
        {
            self::LoadAll();
            return self::FindByID($this->parent);
        }

        return NULL;
    }

    /**
     *  Returns a flat list of the category's parent IDs hierarchy, with $this->id
     *  being the last item in the array. If the category has no parent, then
     *  the array only contains $this->id.
     *
     * @return int[]
     */
    public function getParents()
    {
        $aIDs = [ $this->id ];
        $o = $this;
        while ($o = $o->getParent())
            array_unshift($aIDs, $o->id);

        return $aIDs;
    }

    /**
     *  Returns the root Category for this category. Returns $this if $this has no parent.
     *
     * @return Category
     */
    public function getRoot()
    {
        $o = $this;
        while ($idParent = $o->parent)
            $o = self::FindByID($idParent);
        return $o;
    }

    public function isChildOf(Category $oPossibleParent)
    {
        $oThis = $this;
        while ($oParent = $oThis->getParent())
        {
            if ($oParent->id == $oPossibleParent->id)
                return TRUE;
            $oThis = $oParent;
        }

        return FALSE;
    }

    /**
     *  Returns a URL to a ticket search with the category preselected as a drill-down filter.
     */
    public function makeDrillDownURL()
    {
        $fieldname = TicketField::Find($this->field_id)->name;
        return Globals::$rootpage."/tickets?drill_$fieldname=$this->id";
    }

    /**
     *  Updates the categories extra data with the fields from the given array and writes
     *  the entire extradata out to the database.
     *
     *  This replaces only the values for which keys are set in $a. Other fields are left alone.
     */
    public function setExtra(array $a)
    {
        foreach ($a as $key => $value)
            $this->aExtraDecoded[$key] = $value;

        $aUpdateFields['extra'] = $this->extra = json_encode($this->aExtraDecoded);

        $this->updateImpl($aUpdateFields);
    }

    /**
     *  Creates a Category instance. This is called "CreateBase" because a Category
     *  subclass should probably implement its own meaningful Create method with
     *  specialized parameters and we don't want to prescribe the function prototype
     *  for that.
     *
     *  $aExtra must either be NULL or a valid PHP array with key/value pairs that
     *  get JSON-encoded and written into the "extra" column of the category. The
     *  base Category class uses no such keys, but a subclass might find those
     *  valuable.
     */
    public static function CreateBase(TicketField $oField,
                                      $name,
                                      $aExtra = NULL)
    {
        $extra = ($aExtra) ? json_encode($aExtra) : NULL;
        return self::CreateImpl( [ 'field_id' => $oField->id,
                                   'name' => $name,
                                   'parent' => NULL,
                                   'extra' => $extra
                                 ]);
    }

    public static function FindByNameBase(TicketField $oField,
                                          $name)
    {
        self::LoadAll();
        /** @var Category[] $aAll */
        $aAll = self::GetAll();
        foreach ($aAll as $id => $oCat)
            if ($oCat->name == $name)
                return $oCat;

        return NULL;
    }

    /**
     *  Loads all categories from the DB and returns those that have the given
     *  field ID in them, or NULL if none were found.
     *
     * @return Category[]
     */
    public static function GetAllForField($field_id)
    {
        self::LoadAll();
        $a = [];
        /** @var Category[] $aAll */
        $aAll = self::GetAll();
        foreach ($aAll as $id => $o)
            if ($o->field_id == $field_id)
                $a[$id] = $o;

        return count($a) ? $a : NULL;
    }

    /**
     *  Returns the category with the given ID, or NULL if it's not found. This does not need
     *  a field ID and can return a Category object for any ticket field, since the IDs are
     *  unique among all categories.
     *
     * @return Category | NULL
     */
    public static function FindByID($id)
    {
        $o = NULL;
        if ($id)
        {
            /* It is very likely that we'll get dozens of category queries since they are hierarchical.
               So on the first invocation, load all categories -- it doesen't take that much memory
               and it's faster than 12 SQL round trips. */
            self::LoadAll();

            /** @var Category $o */
            $o = self::FindImpl($id);
        }
        return $o;
    }
}

