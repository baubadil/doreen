<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FTCategory class
 *
 ********************************************************************/

/**
 *  The "model" class for fischertechnik categories. This derives from Category
 *  in the Doreen core, which in turn derives from ManagedTable.
 */
class FTCategory extends Category
{
    /**
     *  Creates a new FTCategory. Presently this happens only during FTDB import since
     *  we have no GUI to manage categories yet. This overrides the parent method.
     */
    public static function Create(string $name,
                                  FTCategory $oParentCategory = NULL)
        : FTCategory
    {
        $o = parent::CreateImpl( [ 'field_id' => FIELD_FT_CATEGORY_ALL,
                                   'name' => $name,
                                   'parent' => $oParentCategory ? $oParentCategory->id : NULL,
                                   'extra' => NULL
                                 ]);

        /** @var FTCategory $o */
        return $o;
    }

    /**
     *  Returns the parent FTCategory for $this or NULL if $this is a root category.
     *
     * @return FTCategory | null
     */
    public function getParent()
    {
        /** @var FTCategory $o */
        $o = parent::getParent();
        return $o;
    }

    /**
     *  Returns the root FTCategory for this category. Returns $this if $this has no parent.
     *
     * @return FTCategory | null
     */
    public function getRoot()
    {
        /** @var FTCategory $o */
        $o = parent::getRoot();
        return $o;
    }

    /**
     * @return FTCategory | NULL
     */
    public static function FindByID($id)
    {
        /** @var FTCategory $o */
        $o = parent::FindByID($id);
        return $o;
    }

    /**
     *  Returns all categories that have no parent, as $id => FTCategory pairs, or NULL if none
     *  are found.
     *
     * @return FTCategory[]
     */
    public static function GetRootCategories()
    {
        self::LoadAll();
        /**
         * @var  $o FTCategory
         */
        $aReturn = [];
        foreach (self::GetAll() as $id => $o)
            if (!$o->parent)
                $aReturn[$id] = $o;

        return count($aReturn) ? $aReturn : NULL;
    }
}
