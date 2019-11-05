<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  CategoryHandlerBase class
 *
 ********************************************************************/

/**
 *  Base implementation for category handlers. This implements fields whose
 *  values are instances of Category or a subclass thereof.
 *
 *  This derives from SelectFromSetHandlerBase since the user should pick
 *  a category from a predefined set of values (an enumeration).
 *
 *  But categories have two specialties over other SelectFromSetHandlerBase
 *  variants: parents and project IDs.
 *
 *   1. Categories can be hierarchical: a category can have a parent. A
 *      category that has no parent is a "root" category. But if it does,
 *      then a ticket that is of a certain category semantically must be
 *      of the parent category as well. (A bee is an insect and thus also
 *      an animal.)
 *
 *      We implement this so that when the user picks a single category
 *      which has at least one parent, we write the picked category plus
 *      all the parents into the database for the ticket field. For this
 *      to work, a category field that uses parents must have FIELDFL_ARRAY
 *      set. The GUI will then filter out the parent values again when
 *      displaying the editor.
 *
 *   2. A category can be defined to be only valid with a specific project ID.
 *      This is a leftover from old xTracker days and not currently supported
 *      in the GUI, and it probably also doesn't work well with parents.
 *      But \ref getValidValues() handles those cases.
 */
class CategoryHandlerBase extends SelectFromSetHandlerBase
{

    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($field_id)          //!< in: e.g. FIELD_CATEGORY
    {
        parent::__construct($field_id);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Abstract method newly introduced by SelectFromSetHandlerBase to be overridden
     *  by subclasses to return an array of id => HTML string pairs representing the
     *  set of valid values that this field can have. The strings will be HTML-escaped.
     *  The returned array must include the current value!
     */
    public function getValidValues(TicketContext $oContext,    //!< in: TicketContext instance
                                   $currentValue)
    {
        $aReturn = [];

        /*
         *  This is also used by the drop-down in MODE_CREATE and MODE_EDIT
         *  (SelectFromSetHandlerBase::addDialogField()). In those cases, we want
         *  to sort the result set alphabetically and also indent children correctly.
         */

        if ($aCategoriesRaw = Category::GetAllForField($this->field_id))
        {
            $fEditor = (    ($oContext->mode == MODE_CREATE)
                         || ($oContext->mode == MODE_EDIT)
                       );

            if ($fEditor)
            {
                /* First sort the whole thing by name. This will also make sure that
                   children under the same parent appear sorted, even after we rearrange them below. */
                uasort($aCategoriesRaw, function(Category $a, Category $b)
                {
                    if ($a->name == $b->name)
                        return 0;
                    return ($a->name < $b->name) ? -1 : 1;
                });
            }

            $aCategoryNamesSorted = [];
            foreach ($aCategoriesRaw as $id => $oCategory)
            {
                // First filter by project ID: insert the category if it is for all projects
                // (for_project_id == NULL) or if the project ID matches.
                $for_project_id = $oCategory->getForProjectID();
                if (    (!$for_project_id)       # for all projects
                     || (    $oContext->oTicket
                          && isset($oContext->oTicket->aFieldData[FIELD_PROJECT])
                          && ($oContext->oTicket->aFieldData[FIELD_PROJECT] == $for_project_id)
                        )
                   )
                    if ($fEditor)
                    {
                        // Secondly, insert only root categories here by calling this private helper,
                        // which will recursively insert the children.
                        if (!$oCategory->parent)
                            $this->insertCategoryAndChildren($aCategoryNamesSorted, $aCategoriesRaw, $oCategory);
                    }
                    else
                        $aCategoryNamesSorted[$id] = toHTML($oCategory->name);
            }

            foreach ($aCategoryNamesSorted as $id => $name)
                $aReturn[$id] = $name;
        }

        return $aReturn;
    }

    /**
     *  Private helper for getValidValues() which inserts the given category into
     *  $aCategoriesSorted and then recursively inserts all children as well.
     *  Strings get indented with 4 spaces for each level.
     */
    private function insertCategoryAndChildren(&$aCategoriesSorted,     //!< in/out: target array
                                               $aCategoriesRaw,         //!< in: entire categories source array
                                               Category $oParent,       //!< in: category to insert (for which we'll recurse)
                                               $level = 0)              //!< in: recursion level (incremented with each recursive call)
    {
        $aCategoriesSorted[$oParent->id] = str_repeat(Format::NBSP, $level * 4).toHTML($oParent->name);

        foreach ($aCategoriesRaw as $idCategory => $oCategoryThis)
            if ($oCategoryThis->parent == $oParent->id)
                $this->insertCategoryAndChildren($aCategoriesSorted,
                                                 $aCategoriesRaw,
                                                 $oCategoryThis,
                                                 $level + 1);
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *
     *  This default implementation simply returns $value, except for numbers, which are
     *  formatted first according to the current locale. Many subclasses override this to
     *  turn internal values into human-readable representations.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        if ($value)
        {
            $aCategoryNames = [];

            $value = self::CollapseToLeaf($value);

            $oCategory = Category::FindByID($value);
            while ($oCategory)
            {
                $name = $oCategory->name;
                array_unshift($aCategoryNames,
                              $name);
                $oCategory = $oCategory->getParent();
            }

            return implode(' > ', $aCategoryNames);
        }

        return NULL;
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        $o = NULL;

        if ($value)
        {
            $aCategories = [];

            $value = self::CollapseToLeaf($value);

            $oCategory = Category::FindByID($value);
            while ($oCategory)
            {
                $oHtml2 = HTMLChunk::FromString($oCategory->name);

                if (    ($oContext->mode == MODE_READONLY_LIST)
                     || ($oContext->mode == MODE_READONLY_DETAILS)
                   )
                    $oHtml2 = HTMLChunk::MakeLink($oCategory->makeDrillDownURL(),
                                                  $oHtml2,
                                                  L("{{L//Click here to search for all tickets where %FIELD% is %VALUE%}}",
                                                    [ '%FIELD%' => $this->fieldname,
                                                      '%VALUE%' => Format::UTF8Quote($oCategory->name) ] ));

                array_unshift($aCategories,
                              $oHtml2);
                $oCategory = $oCategory->getParent();
            }

            $o = HTMLChunk::Implode(Format::NBSP.'&gt; ', $aCategories);

            if ($oContext->mode == MODE_READONLY_GRID)
                if ($this->flSelf & self::FL_GRID_PREFIX_FIELD)
                    $this->prefixDescription($o);
        }

        return $o ? $o : new HTMLChunk();
    }

    /**
     *  This gets called by \ref writeToDatabase() for every field before the
     *  new value gets written into the database. This must return a value that
     *  fits into the value column of the field's database table, or, in the
     *  case of FIELDFL_ARRAY, an arra of those values. It can also throw a
     *  APIException with the field name if validation fails.
     *
     *  We call the SelectFromSetHandlerBase parent and then expand parent category
     *  values if necessary.
     *
     * @return mixed
     */
    public function validateBeforeWrite(TicketContext $oContext,    //!< in: TicketContext instance
                                        $oldValue,                  //!< in: current value (or NULL during create ticket)
                                        $newValue)                  //!< in: suggest new value from form field
    {
        if (!isInteger($newValue))
            throw new APIException($this->fieldname,
                                   "Invalid category value ".Format::UTF8Quote($newValue).": a category must be a single integer value");

        if ($newValue = parent::validateBeforeWrite($oContext, $oldValue, $newValue))
        {
            if ($this->oField->fl & FIELDFL_ARRAY)
            {
                if (!($oCategory = Category::FindByID($newValue)))
                    throw new DrnException("Cannot find category $newValue");
                $newValue = $oCategory->getParents();
            }
        }

        return $newValue;
    }


    /********************************************************************
     *
     *  Newly introduced static methods
     *
     ********************************************************************/

    /**
     *  Looks at the given value if it's an array or a comma-separated string
     *  of values and, if so, removes all values that are parent categories so
     *  that only the leaf category is returned.
     */
    public static function CollapseToLeaf($value)
    {
        if (is_array($value))
            $aTest = $value;
        else
            $aTest = explode(',', $value);

        if ($aTest)
        {
            /** @var Category[] $aCategories */
            $aCategories = [];

            foreach ($aTest as $id)
                if (!($aCategories[(int)$id] = Category::FindByID($id)))
                    throw new DrnException("Invalid category ID ".Format::UTF8Quote($id)." in list ".Format::UTF8Quote($value));

//            Debug::Log(0, print_r($aCategories, TRUE));

            foreach ($aTest as $id)
            {
                $o = Category::FindByID($id);
                if ($oParent = $o->getParent())
                    unset($aCategories[$oParent->id]);
            }

            if (count($aCategories) != 1)
                throw new DrnException("Cannot collapse categories list ".Format::UTF8Quote($value).": not a list of parents with a leaf");
            $o = array_shift($aCategories);
            return $o->id;
        }

        return NULL;
    }

}


/********************************************************************
 *
 *  ProjectHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_PROJECT.
 *
 *  Projects are a special kind of category (with the ID of FIELD_PROJECT), with
 *  the following special properties:
 *
 *   -- Semantically, project IDs can be used to group tickets of the same type together.
 *      For example, in a bug tracker, there can be multiple projects even though all
 *      bug reports have the same ticket type.
 *
 *   -- FIELD_PROJECT is part of the core tickets class, not in ticket_ints. Projects
 *      can therefore be stored with a ticket template, and the tickets created from
 *      the template will inherit that project. (Projects are the only kind of data
 *      that is copied from templates to tickets at this point.)
 *
 *   -- Projects are not assigned by the user on ticket creation, but are copied
 *      from a ticket template only. To create a new project, a new ticket template
 *      must be created (for which no user interface currently exists).
 *
 *  Since projects are simple integers without meanings, a ticket field can use the
 *  FIELDFL_MAPPED_FROM_PROJECT field flag, which causes the project to be copied
 *  to that ticket field's data, and can then be used like any other field (e.g.
 *  for drilling down).
 */
class ProjectHandler extends CategoryHandlerBase
{
    public $label = '{{L//Project}}';
//    public $help = '{{L//Please select the project that this bug reports relates to.}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_PROJECT);
//        $this->flSelf |= self::FL_GRID_PREFIX_FIELD;
    }

}

