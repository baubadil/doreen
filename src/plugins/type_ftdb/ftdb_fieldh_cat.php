<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FTCategoryLeafHandler class
 *
 ********************************************************************/

/**
 *  Implementation of a handler for FIELD_FT_CATEGORY_ALL ('ft_cat_all').
 *
 *  This has the main implementation for FT categories.
 *
 *  As with all field handlers, these handle view functionality. The "model" is in the FTCategory
 *  class, which derives from the generic Category class in the Doreen core.
 *
 *  Since FTDB categories are hierarchical, a FT part is automatically part of a category's
 *  parent category, unless the category is a "root" category, of which there are currently four
 *  ("Anleitungen", "Baukästen", "Einzelteile", "Werbung").
 *
 *  Examples:
 *
 *   1) The kit "Pneumatik" (30863) displays the categories "Baukästen" > "Fortgeschrittene" > "Pneumatik".
 *
 *   2) "Baustein 30 schwarz" (32879) displays the categories "Einzelteile" > "Bausteine + Gelenke" > "Baustein".
 *
 *  CategoryHandlerBase handles the complications of parent categories.
 *
 *  Spec:
 *
 *   -- On input, must have a single integer category, which must be of the
 *      same field ID. Cannot be NULL. (Handled by CategoryHandlerBase).
 *
 *   -- In Ticket instance: comma-separated list of integers
 *
 *   -- GET/POST/PUT JSON data: comma-separated list of integers
 *
 *   -- Database: array of ints (several rows in ticket_ints, FIELDFL_ARRAY)
 *
 *   -- Search engine: is drillable, pushed as array of ints.
 */
class FTCategoryLeafHandler extends CategoryHandlerBase
{
    public $label = '{{L//Category}}';
    public $help  = '{{L//In the FTDB, every kit or part should have a category, which you can specify here.}}';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_FT_CATEGORY_ALL);
    }


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    const ICON_PLUS = 'plus-square-o';
    const ICON_MINUS = 'minus-square-o';

    /**
     *  Called by \ref ViewTicket::Emit() to format the list of button filters for a ticket field. This
     *  gets called once for every ticket type that is drillable, and the results end up on top the search
     *  results view.
     *
     *  The FieldHandler() default returns a simple list of buttons. Categories are hierarchical, so that
     *  is not good enough, and there are hundreds of them in the FTDB anyway, so we want to display a tree
     *  that is collapsed by default.
     *
     *  This is complicated.
     */
    public function formatDrillDownResults(TicketContext $oContext,     //!< in: ticket page context
                                           $aValueCounts,               //!< in: integer value => count pairs (e.g. type id for "wiki" => 3)
                                           string $baseCommand,         //!< in: base for generated URLs (e.g. 'tickets' or 'board')
                                           $aParticles2,                //!< in: a copy of the WebApp::ParseURL result particles
                                           $aActiveFilters)             //!< in: two-dimensional array of active filter names and values; empty if none
    {
        /** @var FTCategoryNode[] $aNodesWithCounts */
        $aNodesWithCounts = [];
        foreach ($aValueCounts as $idCategory => $c)
        {
            $idCategory = (int)$idCategory;

            if (!($oCategory = FTCategory::FindByID($idCategory)))
                throw new DrnException("Invalid category $idCategory");

            $o = new FTCategoryNode($oCategory,
                                    $c);

            $aNodesWithCounts[$idCategory] = $o;
        }

        /* Now make sure all the parent categories are in the tree even if they are not in the aggregations. */
        /** @var FTCategoryNode[] $aAllNodesWithParents */
        $aAllNodesWithParents = $aNodesWithCounts;
        foreach ($aNodesWithCounts as $oNode)
            $oNode->addAllParentNodes($aAllNodesWithParents);

        /* Now, built a tree of all categories in memory, sorted by name, starting with the root categories (that have no parent). */
        $llRootNodes = [];
        foreach ($aAllNodesWithParents as $idCategory => $oNode)
            if (!$oNode->oCategory->parent)
                $llRootNodes[] = $oNode;

        // Expand a category if it is selected as an active filter already. (We'll expand the parents below.)
        if (isset($aActiveFilters[$this->fieldname]))
            foreach (array_keys($aActiveFilters[$this->fieldname]) as $idCategory)
            {
                $idCategory = (int)$idCategory;
                $o = $aAllNodesWithParents[$idCategory];
                $o->fExpand = TRUE;
                while ($idParent = $o->oCategory->parent)
                {
                    $o = $aAllNodesWithParents[$idParent];
                    $o->fExpand = TRUE;
                }
            }

        $oHTML = new HTMLChunk();
        $idDiv = 'ft-categories-tree';
        $oHTML->openDiv($idDiv);
        $this->makeHTMLForNodesAndChildren($oHTML,
                                           $llRootNodes,
                                           TRUE,        // expand
                                           $aValueCounts,
                                           $baseCommand,
                                           $aParticles2,
                                           $aActiveFilters);
        $oHTML->close();

        // Now add the script magic for expanding / collapsing the tree when the + or - buttons are clicked.
        $aIcons = [];
        foreach ( [ self::ICON_PLUS, self::ICON_MINUS ]  as $icon)
            $aIcons[$icon] = Icon::Get($icon);

        WholePage::AddTypescriptCallWithArgs(FTDB_PLUGIN_NAME, /** @lang JavaScript */ 'ftdb_initCategoriesTree',
                                             [ $idDiv,
                                               $aIcons ] );

        return $oHTML->html;
    }

    /**
     *  Private helper to allow for recursively building the categories tree.
     *
     *  This gets called initially by \ref formatDrillDownResults() with $llNodes set to the list of
     *  root categories (Anleitungen, Baukästen, Einzelteile, Werbung), and it then recurses into the
     *  subcategories for each of them.
     *
     *  $llNodes is TRUE for the initial call; on recursion, it is set to TRUE of categories have already
     *  been selected as active filters, so that the active filters are always visible in the tree.
     *
     *  $fExpand is TRUE initially for the root nodes; when recursing with the children, we use the fExpand
     *  member of the FTCategoryNode, which has been set by the caller according to the currently active
     *  category filters.
     */
    private function makeHTMLForNodesAndChildren(HTMLChunk $oHTML,  //!< in/out: HTML chunk to append to
                                                 $llNodes,          //!< in: list of FTCategoryNode objects to display and recurse into.
                                                 $fExpand,          //!< in: TRUE if $llNodes should be initially visible
                                                 $aValueCounts,     //!< in: integer value => count pairs (e.g. type id for "wiki" => 3) (from parent call)
                                                 string $baseCommand,      //!< in: command for building the URL, e.g. 'tickets' (e.g. from WebApp::$command)
                                                 $aParticles2,      //!< in: a copy of the WebApp::ParseURL result particles (from parent call)
                                                 $aActiveFilters)   //!< in: two-dimensional array of active filter names and values; empty if none
    {
        if (count($llNodes))
        {
            /* The expanded / collapsed tree works by having a drn-catlist DIV class and by adding
               and removing the 'hidden' class initially here and then by script when the +/- icons
               get clicked. We also set and change the icon accordingly for every node. */
            $classes = 'drn-catlist';
            if (!$fExpand)
                $classes .= ' hidden';

            $oHTML->openDiv(NULL, $classes);
            $oHTML->openList(HTMLChunk::LIST_UL, NULL);

            // First make a list of children as HTML buttons so we can sort them alphabetically before recursing.
            $aFilterValuesThisByName = [];
            foreach ($llNodes as $oNode)
            {
                $plain = $oNode->oCategory->name;
                $aFilterValuesThisByName[$plain] = [ $oNode, $this->makeOneDrillDownButton($baseCommand,
                                                                                           $aParticles2,
                                                                                           $aActiveFilters,
                                                                                           $oNode->oCategory->id,
                                                                                           HTMLChunk::FromString($plain),
                                                                                           $oNode->cItems) ];
            }

            if (count($aFilterValuesThisByName))
            {
                // Now sort them alphabetically before recursing.
                ksort($aFilterValuesThisByName);

                foreach ($aFilterValuesThisByName as $a2)
                {
                    /** @var FTCategoryNode $oNode */
                    list($oNode, $oButton) = $a2;

                    $oHTML->openListItem();
                    $oHTML->appendChunk($oButton);

                    if (count($oNode->llChildNodes))
                    {
                        $icon = Icon::Get( ($oNode->fExpand) ? self::ICON_MINUS : self::ICON_PLUS );
                        $oHTML->addLine("<a class=\"drn-catlist-plusminus\" href=\"#\">$icon</a>");

                        # Recurse! This produces HTML for the list of children.
                        $htmlSub = $this->makeHTMLForNodesAndChildren($oHTML,
                                                                      $oNode->llChildNodes,
                                                                      $oNode->fExpand,
                                                                      $aValueCounts,
                                                                      $baseCommand,
                                                                      $aParticles2,
                                                                      $aActiveFilters);
                        $oHTML->addLine($htmlSub);
                    }
                    $oHTML->close();        // LI
                }
            }

            $oHTML->close();        // UL
            $oHTML->close();        // DIV
        }
    }

    /**
     *  Gets called by makeDrillDownFilterURL() for $this ticket field ONLY for filter values
     *  that have previously been activated.
     *
     *  The default implementation will only deselect a previously active filter value if the button
     *  gets clicked again. This is not enough for the categories tree: if a value gets selected,
     *  then all parent values need to be deselected as well. It doesn't make sense to have both
     *  "parent" and a "child" in the tree, then the "child" has no effect.
     */
    protected function canKeepPreviousFilterValue($vPreviouslyActive,       //!< in: filter value that is active in current filter set
                                                  $valueThisButton)         //!< in: filter value for whose button HTML is being generated
        : bool
    {
        $oCategoryThisButton = FTCategory::FindByID($valueThisButton);
        $oCategoryPreviouslyActive = FTCategory::FindByID($vPreviouslyActive);

        if ($oCategoryThisButton->isChildOf($oCategoryPreviouslyActive))
            return FALSE;

        if ($oCategoryPreviouslyActive->isChildOf($oCategoryThisButton))
            return FALSE;

        return parent::canKeepPreviousFilterValue($vPreviouslyActive, $valueThisButton);
    }

}

/**
 *  Helper class to manage FTCategory items with their corresponding aggregated counts in search results.
 */
class FTCategoryNode
{
    public $oCategory;
    public $cItems;
    public $llChildNodes  = [];
    public $fExpand = FALSE;

    public function __construct(FTCategory $oCategory,
                                $c)
    {
        $this->oCategory = $oCategory;
        $this->cItems = (int)$c;
    }

    public function addAllParentNodes(&$aAllNodesByID)
    {
        if ($idParent = $this->oCategory->parent)
        {
            if (!($oParent = getArrayItem($aAllNodesByID, $idParent)))     // this parent not yet added?
            {
                if (!($oParent = FTCategory::FindByID($idParent)))
                    throw new DrnException("Invalid parent category $idParent");
                $oParentNode = new FTCategoryNode($oParent, 0);
                $aAllNodesByID[$idParent] = $oParentNode;

                # Recurse!
                $oParentNode->addAllParentNodes($aAllNodesByID);
            }

            $oParent->llChildNodes[] = $this;
        }
    }
}
