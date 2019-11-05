<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  HTMLChunk class
 *
 ********************************************************************/

/**
 *  Abstraction for creating structured HTML code. An HTMLChunk is an
 *  object with a bit of HTML inside, to which more HTML can added.
 *
 *  To create an HTMLChunk, use either new() or the static FromString()
 *  or FromEscapedHTML() or MakeElement() methods.
 *
 *  This is used all over the Doreen code to generate HTML, for three
 *  main objectives:
 *
 *   -- minimize the security risk of sending unescaped HTML to the user
 *      by requiring HTMLChunk instances as input to many Doreen methods;
 *
 *   -- ensure well-formed nesting of open/close tags by making it easier
 *      to have matching open/close calls with an internal automatic stack;
 *
 *   -- make the resulting HTML pretty with proper indentation automatically.
 *
 *  More and more Doreen code that takes HTML strings as input or produces
 *  them is converted to use this class instead.
 *
 *  As a convention, functions beginning with open...() need a matching
 *  close() call. They maintain an internal stack so that close() will
 *  know what closing element to add to the HTML depending on which
 *  open...() call was made.
 */
class HTMLChunk
{
    public $html = '';

    private $indent2;
    private $cIndentSpaces = 0;
    private $aStack = [];


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct($cIndentSpaces = 2)
    {
        $this->cIndentSpaces = $cIndentSpaces;
        $this->indent2 = str_repeat(' ', $cIndentSpaces);
    }

    /**
     *  Creates a new HTMLChunk from the given string, which is escaped.
     *
     *  @return self
     */
    public static function FromString(string $str = NULL)
        : HTMLChunk
    {
        return self::FromEscapedHTML(htmlspecialchars($str));
    }

    public static function FromEscapedHTML(string $html)
        : HTMLChunk
    {
        $o = new self();
        $o->html = $html;
        return $o;
    }

    /**
     *  Factory method to create a new HTMLChunk containing the given element and possibly content.
     *
     *  If $oHTML is not NULL, it is wrapped between an opening and closing
     *  tag. Otherwise $tag is returned as a single element without / and without
     *  a matching closing tag.
     *
     *  @return self
     */
    public static function MakeElement(string $tag,                 //!< in: tag name without < or >
                                       array $aAttrs = NULL,        //!< in: attributes array (e.g. from MakeAttrsArray())
                                       HTMLChunk $oHTML = NULL)     //!< in: content between opening and closing tag
        : HTMLChunk
    {
        $o = new self();

        $o->html = '<'.$tag;
        if ($aAttrs)
            $o->html .= self::CollapseAttributes($aAttrs);

        if ($oHTML)
            $o->html .= ">$oHTML->html</$tag>";
        else
            $o->html .= '>';

        return $o;
    }

    /**
     *  Convenience helper around \ref MakeElement() that creates an 'a' element.
     */
    public static function MakeLink(string $href,
                                    HTMLChunk $oInnerLink,
                                    string $title = NULL,
                                    array $aAttrs = [])
        : HTMLChunk
    {
        $aAttrs['href'] = $href;
        if ($title)
            $aAttrs['title'] = $title;

        return self::MakeElement('a',
                                 $aAttrs,
                                 $oInnerLink);
    }

    /**
     *  Similar to PHP's implode() function, this takes the given array of HTMLChunk items
     *  (values only, keys are ignored) and produces a new HTMLChunk with the combined HTML.
     *
     *  @return self
     */
    public static function Implode(string $htmlGlue,        //!< in: this must be escaped HTML!
                                   array $aChunks)
        : HTMLChunk
    {
        $o = new self();

        $c = 0;
        /** @var HTMLChunk[] $aChunks */
        foreach ($aChunks as $oChunk)
        {
            if (!($oChunk instanceof HTMLChunk))
                throw new DrnException("Internal error: expected HTMLChunk instance");
            if ($c++)
                $o->html .= $htmlGlue.$oChunk->html;
            else
                $o->html .= $oChunk->html;
        }

        return $o;
    }


    /********************************************************************
     *
     *  Public instance methods
     *
     ********************************************************************/

    /**
     *  Append the given piece of HTML verbatim.
     */
    public function append(string $html = NULL)
        : HTMLChunk
    {
        $this->html .= $html;
        return $this;
    }

    public function prepend(string $html = NULL)
        : HTMLChunk
    {
        $this->html = $html.$this->html;
        return $this;
    }

    /**
     *  Append the given piece of HTML with the current indentation applied, and a newline at the end.
     *
     *  @return void
     */
    public function addLine($html = NULL)
    {
        if ($html !== NULL)
            $this->html .= $this->indent2."$html\n";
        else
            $this->html .= "\n";
    }

    public function appendChunk(HTMLChunk $o)
        : HTMLChunk
    {
        $this->addLine($o->html);
        return $this;
    }

    public function prependChunk(HTMLChunk $o)
        : HTMLChunk
    {
        if ($o->html)
            $this->html = $this->indent2.$o->html."\n".$this->html;
        return $this;
    }

    /**
     *  Convenience function which calls \ref MakeElement() and appends the result.
     */
    public function appendElement(string $tag,                 //!< in: tag name without < or >
                                  array $aAttrs = NULL,               //!< in: attributes array (e.g. from MakeAttrsArray())
                                  HTMLChunk $oHTML = NULL)     //!< in: content between opening and closing tag
        : HTMLChunk
    {
        return $this->appendChunk(self::MakeElement($tag, $aAttrs, $oHTML));
    }

    /*
     *  Echoes the current contents and clears the internal HTML that was collected up to this point.
     */
    public function flush()
    {
        if ($fCli = (php_sapi_name() == 'cli'))
            // Strip HTML before echoing on the CLI.
            echo Format::HtmlStrip($this->html, TRUE);
        else
            echo $this->html;

        $this->html = '';
        if (!$fCli)
        {
            ob_flush();
            flush();
        }
    }

    /**
     *  Returns a string with spaces for the current indentation.
     */
    public function getIndentString()
        : string
    {
        return $this->indent2;
    }

    /*
     *  Closes anything opened with an open* call on the HTML.
     */
    public function close()
    {
        if (!count($this->aStack))
            throw new DrnException("Internal error: cannot close() HTMLCHunk, nothing left on stack");
        $aLines = array_pop($this->aStack);
        if (!is_array($aLines))
            $aLines = array($aLines);  # convert into array
        foreach ($aLines as $line)
        {
            $this->changeIndent(-2);
            $this->addLine($line);
        }
    }


    /********************************************************************
     *
     *  Specific HTML pieces
     *
     ********************************************************************/

    /**
     *  Adds an H1/2/3... block.
     *
     *  @return void
     */
    public function addHeading($level,
                               $html,
                               string $extraClasses = NULL)         //!< in: e.g. 'text-center'
    {
        if ($extraClasses)
            $extraClasses = " class=\"$extraClasses\"";
        $this->addLine("<h$level$extraClasses>$html</h$level>");
    }

    /**
     *  Adds a Bootstrap page heading, calling addHeading(1) in turn.
     *
     *  @return void
     */
    public function addPageHeading($htmlHeading,        //!< Heading to be passed to addHeading().
                                   $icon = NULL)        //!< Icon to prefix the heading with, will be passed to Icon::Get().
    {
        $htmlHeading2 = $htmlHeading;
        if ($icon)
            $htmlHeading2 = Icon::Get($icon)." $htmlHeading";

        $this->addLine("<div class=\"page-header\">");
        $this->push("</div>");
        $this->addHeading(1, $htmlHeading2);
        $this->close();
    }

    public static $fLastPageContainerWasFluid = FALSE;

    /**
     *  Convenience function which opens a container and calls addPageHeading() in turn.
     *
     *  @return void
     */
    public function openPage($htmlHeading,
                             $fContainerFluid,
                             $icon = NULL,
                             $htmlFlushRight = NULL)        # flush right things
    {
        $this->addLine("<!-- open main page container -->");
        $this->addLine("<div class=\"".($fContainerFluid ? 'container-fluid' : 'container')."\">");
        $this->addLine("  <div class=\"drn-main-page\">");
        $this->push( [ "</div> <!-- .drn-main.page -->",
                       "</div> <!-- end main page container -->" ] );
        if ($htmlFlushRight)
        {
            $this->openDiv(NULL, 'pull-right');
            $this->addLine($htmlFlushRight);
            $this->close();
        }
        if ($htmlHeading)
        {
            $this->addPageHeading($htmlHeading, $icon);
            $this->addLine();
        }

        // Remember for WholePage::EmitFooter().
        self::$fLastPageContainerWasFluid = $fContainerFluid;
    }

    /**
     *  Convenience function that calls \ref addLine() with the given HTML enclosed in <p>...</p>.
     */
    public function addPara($html,
                            string $extraClasses = '')
    {
        if ($extraClasses)
            $extraClasses = " class=\"$extraClasses\"";
        $this->addLine("<p$extraClasses>$html</p>");
    }

    /**
     *  Convenience function that adds a paragraph with a "help-block" class, which looks good
     *  under an entry field with proper spacing.
     */
    public function addHelpPara(HTMLChunk $oHtml)
    {
        $this->appendElement('p',
                             [ 'class' => 'help-block' ],
                             $oHtml);
    }

    public function addAnchor(string $anchor)
    {
        $this->addLine("<a name='$anchor'></a>");
    }

    public function addToc(array $aAnchors)
    {
        $this->openList(HTMLChunk::LIST_GROUP);
        foreach ($aAnchors as $anchor => $title)
            $this->appendElement('li',
                                  [ 'class' => 'list-group-item' ],
                                  HTMLChunk::MakeLink("#$anchor",
                                                      HTMLChunk::FromString($title)));
        $this->close(); // UL
    }

    public function addJumbotron($htmlHeading, $htmlSub)
    {
//        $this->openDiv(NULL, 'container');
        $this->openDiv(NULL, 'jumbotron drn-big-gap');
        $this->addHeading(1, $htmlHeading);
        if ($htmlSub)
            $this->addPara($htmlSub);
        $this->close();
//        $this->close();
    }

    public function addLoremIpsum()
    {
        $this->append(<<<HTML
<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.
Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.
Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.
Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>
HTML
        );
}


    /********************************************************************
     *
     *  Bootstrap grid
     *
     ********************************************************************/

    /**
     *  Opens a Boostrap DIV class="container" or "container-fluid".
     *
     *  Note that Boostrap containers add indentation unless you also use
     *  the rest of the Bootstrap grid system (rows and columns).
     *
     *  Requires a matching close() call.
     */
    public function openContainer($fContainerFluid,             //!< in: If TRUE, we use container-fluid instead of container.
                                  $description = NULL,          //!< in: Description of container to be added in an HTML comment (also added to matching closing tag on close(); optional).
                                  $id = NULL,                   //!< in: HTML ID for the container (optional).
                                  $extraClasses = NULL)         //!< in: Additional classes for the container (optional).
    {
        if ($id)
            $id = " id=\"$id\"";
        if ($extraClasses)
            $extraClasses = " $extraClasses";

        $description1 = $description2 = '';
        if ($description)
        {
            $description1 = "<!-- open $description container -->";
            $description2 = "<!-- end $description container -->";
        }

        $this->addLine("<div class=\"".($fContainerFluid ? 'container-fluid' : 'container')."$extraClasses\"$id>$description1");
        $this->push("</div>$description2");
    }

    /**
     *  Opens a row for within the Bootstrap grid system.
     *
     *  Must be inside openContainer(), except when nesting rows within a column;
     *  never use a container in another container, just open the row within the
     *  column to get spacings right.
     *  See http://www.helloerik.com/the-subtle-magic-behind-why-the-bootstrap-3-grid-works .
     *
     *  Requires a matching close() call.
     */
    public function openGridRow($id = NULL,
                                $extraClasses = NULL)
    {
        if ($id)
            $id = " id=\"$id\"";
        if ($extraClasses)
            $extraClasses = " $extraClasses";
        $this->addLine("<div class=\"row$extraClasses\"".$id.">");
        $this->push("</div><!-- end .row -->");
    }

    /**
     *  Opens a column in a Bootstrap grid row with the given width.
     *
     *  $width can be in one of two formats:
     *
     *   -- If it is an integer, then every grid column receives the
     *      "col-xs-$width" class. This is the easiest method.
     *
     *   -- Alternatively, you can specify an array of string class
     *      names such as "col-xs-4 col-md-1" yourself.
     *
     *  If $width is an integer, it must be a number from 1 to 12
     *  and will be converted into a "col-xs-$width" class.
     *
     *  If $width is an array, it can be an array of col-*-* class
     *  strings, which will be used verbatim.
     *
     *  Must be inside openGridRow().
     *
     *  Requires a matching close() call.
     */
    public function openGridColumn($width,      //!< in: integer column width (1-12) or array of class names
                                   $id = NULL,
                                   $extraClasses = NULL)
    {
        if ($id)
            $id = " id=\"$id\"";

        if (is_array($width))
            $classes = implode(' ', $width);
        else
            $classes = "col-xs-$width";
        if ($extraClasses)
            $classes .= " $extraClasses";
        $this->addLine("<div class=\"$classes\"".$id.">");
        $this->push("</div><!-- end .$classes -->");
    }

    /**
     *  Convenience function that calls openGridColumn(), addLine() and close()
     *  for a single column.
     */
    public function addGridColumn($width,               //!< in: integer column width (1-12) or array of class names
                                  $htmlColumn,
                                  $id = NULL,
                                  $extraClasses = NULL)
    {
        $this->openGridColumn($width, $id, $extraClasses);
        $this->addLine($htmlColumn);
        $this->close();
    }

    /**
     *  Convenience function that calls addGridColumn() for each of the items in the array.
     */
    public function addGridColumns($llColumns,
                                   $llWidths,
                                   $prefix = NULL,
                                   $suffix = NULL)
    {
        foreach ($llColumns as $htmlColumn)
        {
            if (!($width = array_shift($llWidths)))
                throw new DrnException("Internal error: not enough widths in widths array");

            if ($prefix)
                $htmlColumn = $prefix.$htmlColumn;
            if ($suffix)
                $htmlColumn = $htmlColumn.$suffix;

            $this->addGridColumn($width, $htmlColumn);
        }
    }

    /**
     *  Convenience function that calls openGridRow() and addGridColumns() and close().
     */
    public function addGridRow($llColumns,
                               $llWidths,
                               $prefix = NULL,
                               $suffix = NULL)
    {
        $this->openGridRow();
        $this->addGridColumns($llColumns, $llWidths, $prefix, $suffix);
        $this->close(); # grid row
    }


    /********************************************************************
     *
     *  Lists
     *
     ********************************************************************/

    const LIST_UL = 1;
    const LIST_OL = 2;
    const LIST_NAV = 3;
    const LIST_NAV_PILLS = 4;
    const LIST_GROUP = 5;

    public function openList($type,                     //!< in: LIST_UL or LIST_OL
                             $id = NULL,
                             $extraClasses = NULL,
                             $role = NULL)
    {
        $aClasses = [];
        if ($type == self::LIST_NAV)
        {
            $aClasses[] = 'nav nav-tabs';
            $role = 'tablist';
        }
        else if ($type == self::LIST_NAV_PILLS)
        {
            $aClasses[] = 'nav nav-pills';
            $role = 'tablist';
        }
        else if ($type == self::LIST_GROUP)
            $aClasses[] = 'list-group';

        if ($extraClasses)
            $aClasses[] = $extraClasses;

        $attrs = $this->makeAttrs($id, $aClasses, $role);

        switch ($type)
        {
            case self::LIST_UL:
            case self::LIST_NAV:
            case self::LIST_NAV_PILLS:
            case self::LIST_GROUP:
                $this->addLine("<ul$attrs>");
                $this->push('</ul>');
            break;

            case self::LIST_OL:
                $this->addLine("<ol$attrs>");
                $this->push('</ol>');
            break;
        }
    }

    public function openListItem($id = NULL, $class = NULL)     //!< in: maybe "list-group-item"
    {
        $attrs = $this->makeAttrs($id, [ $class ]);
        $this->addLine("<li$attrs>");
        $this->push("</li>");
    }

    public function addListItem($id, $class, HTMLChunk $oContained = NULL)
        : HTMLChunk
    {
        return $this->appendElement('li', [ 'id' => $id, 'class' => $class ], $oContained);
    }

    /**
     *  Adds a list of strings in the given list style. The strings will be
     *  sanitized by this method.
     */
    public function addStringsList(array $llStrings,
                                   $type = self::LIST_UL,
                                   $aAttrs = NULL)
    {
        $this->openList($type);
        foreach ($llStrings as $str)
            $this->appendElement('li',
                                 $aAttrs,
                                 HTMLChunk::FromString($str));
        $this->close();
    }

    /**
     *  Adds a <ul class="nav nav-pills"> with the items in the given array.
     */
    public function addChunksList(array $llNavs,         //!< in: array of HTMLChunks
                                  $type = self::LIST_NAV_PILLS,
                                  $aAttrs = NULL)
    {
        $this->openList($type);
        foreach ($llNavs as $nav)
            $this->appendElement('li',
                                 $aAttrs,
                                 $nav);
        $this->close();
    }


    /********************************************************************
     *
     *  Generic DIVs
     *
     ********************************************************************/

    /**
     *  Opens a DIV or SPAN.
     *
     *  If $comment is NULL (the default), a comment is generated automatically after the DIV.
     *  If you absolutely do not want a comment, pass in an empty string, and the generation
     *  will be skipped.
     */
    public function openDiv(string $id = NULL,
                            $classes = NULL,                //!< in: either string or flat list of classes, or empty array, or NULL if none
                            string $comment = NULL,
                            string $elem = 'div',
                            array $aOtherAttrs = NULL)      //!< in: other attribute strings as key => value pairs
    {
        $o = self::MakeElement($elem, self::MakeAttrsArray($id, $classes, NULL, $aOtherAttrs), NULL);
        if ($comment)
            $o->append("<!-- $comment -->");

        $this->appendChunk($o);

        $commentClose = $comment;
        if ($comment === NULL)
            if ($id)
                $commentClose = "$elem #$id";
            else if ($classes)
            {
                $str = is_array($classes) ? implode(' ', $classes) : $classes;
                $commentClose = "$elem .$str";
            }

        $push = "</$elem>";
        if ($commentClose)
            $push .= "<!-- end of $commentClose -->";
        $this->push($push);
    }

    /**
     *  Opens a DIV or SPAN. Do not use in new code, use openDiv() instead.
     */
    public function openDivLegacy($attrs = NULL,
                                  $classes = NULL,
                                  $elem = 'div')
    {
        if ($classes)
            $classes = "class=\"$classes\"";

        if ($attrs)
            $attrs = " $attrs";
        $this->addLine("<$elem$attrs $classes>");
        $this->push("</$elem>");
    }

    public function openNAV(bool $fFixed = FALSE)
    {
        $this->addLine('<nav class="navbar navbar-default'.($fFixed ? ' navbar-fixed-top' : '').'" role="navigation">');
        $this->push('</nav>');
    }

    /**
     *  Adds a Bootstrap 'alert' box, by default with alert-info coloring, with the given HTML inside.
     */
    public function addAlert($html,
                             $id = NULL,
                             $colorClass = 'alert-info',
                             $extraClasses = NULL)
    {
        if ($id)
            $id = " id=\"$id\"";
        if ($extraClasses)
            $extraClasses = " $extraClasses";
        $this->addLine("<div class=\"alert $colorClass$extraClasses\"".$id.">");
        $this->addLine("  $html");
        $this->addLine("</div>");
    }

    /**
     *  Convenience wrapper that calls addAlert() with additional information for admins.
     */
    public function addAdminAlert($html,
                                  $htmlIfAdmin = NULL,
                                  $id = NULL,
                                  $colorClass = 'alert-warning',
                                  $extraClasses = NULL)
    {
        $html2 = L('{{L//Warning}}').':';
        $html2 = "<p><b>$html2</b> $html</p>";

        if (!LoginSession::IsCurrentUserAdmin())
            $html2 .= '<p>'.L('{{L//Please contact an administrator.}}').'</p>';
        else if ($htmlIfAdmin)
            $html2 .= $htmlIfAdmin;

        $this->addAlert($html2, $id, $colorClass, $extraClasses);
    }

    private static $idLastAlert = 1;

    /**
     *  Adds an alert with a button. When the user clicks the button, the attached JavaScript
     *  calls the POST /userkey REST value with the given key and value, which allows the calling
     *  code to display a message that the user can click away after one viewing.
     */
    public function addUserAlert($htmlMessage,
                                 $htmlButton,
                                 $userKey,
                                 $userValue,
                                 $class)        //!< in: one of 'info', 'warning', 'danger' etc.
    {
        $idAlertBox = "user-alert-box-".self::$idLastAlert;
        self::$idLastAlert++;

        $o2 = new HTMLChunk();
        $o2->addLine("$htmlMessage<br>");
        $o2->addButton("$htmlButton", NULL, "btn-block", "btn-$class");
        $this->addAlert($o2->html,
                        $idAlertBox,
                        "alert-$class");

        WholePage::AddJSAction('core', 'initUserAlert', [
            $idAlertBox,
            $userKey,
            $userValue
        ], TRUE);
    }


    /********************************************************************
     *
     *  Doreen tabbed administration pages
     *
     ********************************************************************/

    /**
     *  Opens a tab header to be used in conjunction with the front-end TabbedPage
     *  TypeScript class. The system establishes the following:
     *
     *   -- Each tab page is a DIV with an ID, the "tab ID".
     *
     *   -- The tab in the header has that ID prefixed with 'select-'. This way the
     *      front-end TabbedPage class can show/hide and activate pages automatically
     *      when tab headers are clicked on.
     *
     *   -- "Create" buttons can be added with \ref addTabHeaderButton() and shown
     *      and hidden automatically as well.
     *
     *  The functions in this section fill an $aTabs array, which must be JSON-encoded
     *  and passed to the TabbedPage constructor in the front-end.
     *
     *  To be closed with close().
     */
    public function openTabsHeader()
    {
        $this->openList(HTMLChunk::LIST_NAV, NULL, 'drn-padding-above');
    }

    /**
     *  Adds one tab header to the administration page. The ID given here must match the
     *  one later used in \ref openTabbedPage(). The tab will have that ID prefixed with
     *  'select-', which is what the TabbedPage front-end TypeScript class expects.
     */
    public function addTabHeader(&$aTabs,                 //!< in/out: flat list of tab IDs already added)
                                 $tabId,                  //!< in: new tab ID
                                 $lstr,                   //!< in: HTML for tab title
                                 $aLstrReplacements = NULL,
                                 $fActive = FALSE)        //!< in: whether to set tab active. Normally leave this to the GUI code.
    {
        $aTabs[] = $tabId;
        $aAttrs = [ 'id' => "select-$tabId",
                    'role' => 'presentation'
                  ];
        if ($fActive)
            $aAttrs['class'] = 'active';

        $this->appendElement('li',
                             $aAttrs,
                             HTMLChunk::MakeElement('a',
                                                    [ 'href' => "#$tabId",
                                                      'role' => 'tab',
                                                      'aria-controls' => $tabId ],
                                                    HTMLChunk::FromEscapedHTML(L($lstr, $aLstrReplacements))));
    }

    /**
     *  Adds a right-aligned button to the tab headers, which is hidden by default.
     *
     *  If the button ID is a tab ID prefixed with "create-", then the button gets shown
     *  and hidden automatically by the front-end TabbedPage code. Otherwise it is the
     *  responsibility of the front-end code to hide and show this button conditional
     *  on which page is shown.
     */
    public function addTabHeaderButton($id,
                                       $icon,
                                       $lstr,
                                       $fHidden = TRUE,     //!< in: whether to hide the button. Normally leave this to the GUI code.
                                       $fAddEllipse = FALSE)
    {
        $hidden = ($fHidden) ? ' hidden' : '';
        $this->addListItem($id,
                           "navbar-right$hidden",
                           HTMLChunk::MakeLink('#',
                                               Icon::GetH($icon)
                                                   ->append(Format::NBSP.Format::NBSP.L($lstr).( ($fAddEllipse) ? Format::HELLIP : ''))));

    }

    /**
     *  Opens a tab under a typical Doreen administration page. $tabId must be the same ID that
     *  was previously used with \ref addTabHeader(), or this will throw.
     *
     *  To be closed with close().
     */
    public function openTabbedPage($aTabs,                  //!< in: flat list of tab IDs already added
                                   $tabId,                  //!< in: new tab ID
                                   $htmlIntro,              //!< in: introductory paragraph for the tab, wrapped in <p>...</p>
                                   $fHidden = TRUE)         //!< in: if TRUE, page is initially hidden
    {
        if (!in_array($tabId, $aTabs))
            throw new DrnException("Internal error: tab ID ".Format::UTF8Quote($tabId)." is not in tab IDs list");
        $this->openDiv($tabId, ($fHidden) ? 'hidden' : '');
        $this->addLine("<p class=\"drn-padding-above\">$htmlIntro</p>");
    }


    /********************************************************************
     *
     *  HTML table pieces
     *
     ********************************************************************/

    /**
     *  Begins a HTML table, by default with the Bootstrap "table-striped" style.
     *
     *  Requires a matching close() call.
     */
    public function openTable($id = NULL,
                              string $extraClasses = NULL,
                              $fStriped = TRUE)
    {
        $classes = 'table';
        if ($fStriped)
            $classes .= ' table-striped';
        if ($extraClasses)
            $classes .= " $extraClasses";

        $this->appendChunk(self::MakeElement('table', self::MakeAttrsArray($id, $classes)));
        $this->push("</table>");
    }

    /**
     *  Begins a table head block for use with addTableHeading().
     *
     *  Must be inside openTable().
     *
     *  Not needed if you use the more convenient addTableHeadings() call.
     *
     *  Requires a matching close() call.
     */
    public function openTableHeadAndRow()
    {
        $this->addLine("<thead><tr>");
        $this->push("</tr></thead>");
    }

    /**
     *  Adds a single table heading block.
     *
     *  Must be inside openTableHeadAndRow().
     *
     *  Not needed if you use the more convenient addTableHeadings() call.
     *
     *  $attrs can be an HTML attributes string like 'style="text-align:center;" data-sort="int"'.
     */
    public function addTableHeading($htmlHead,
                                    $attrs = NULL)
    {
        if ($attrs)
            $attrs = " $attrs";
        $this->addLine("<th$attrs>$htmlHead</th>");
    }

    /**
     *  Convenience function to add all table headings in one go.
     *
     *  Calls openTableHeadAndRow() and addTableHeading() on every array (list) member.
     *
     *  Each item in $llCells can either be an HTML string or a sub-list with an
     *  array string and an $attrs parameter for addTableHeading().
     */
    public function addTableHeadings($llCells)
    {
        $this->openTableHeadAndRow();
        foreach ($llCells as $cell)
        {
            $attrs = NULL;
            if (is_array($cell))
                list($htmlhead, $attrs) = $cell;
            else
                $htmlhead = $cell;
            $this->addTableHeading($htmlhead, $attrs);
        }
        $this->close();
    }

    /**
     *  Begins a table body block for use with openTableRow().
     *
     *  Must be inside openTable().
     *
     *  Requires a matching close() call.
     */
    public function openTableBody()
    {
        $this->addLine("<tbody>");
        $this->push("</tbody>");
    }

    /**
     *  Begins a table body block for use with openTableRow().
     *
     *  Must be inside openTableBody().
     *
     *  Requires a matching close() call.
     */
    public function openTableRow(string $extraClasses = NULL,
                                 string $attrs = NULL)
    {
        if ($extraClasses)
            $extraClasses = " class=\"$extraClasses\"";
        if ($attrs)
            $attrs = " $attrs";
        $this->addLine("<tr$extraClasses$attrs>");
        $this->push("</tr>");
    }

    /**
     *
     *  Must be inside openTableRow().
     *
     *  Requires a matching close() call.
     */
    public function openTableCell(string $attrs = NULL)
    {
        if ($attrs)
            $attrs = " $attrs";
        $this->addLine("<td$attrs>");
        $this->push("</td>");
    }

    /**
     *  One-stop function to add a single table cell.
     *
     *  Must be inside openTableRow().
     */
    public function addTableCell(string $html = NULL,       //!< in: table cell contents; NULL or '' for an empty cell
                                 string $attrs = NULL)
    {
        if ($attrs)
            $attrs = " $attrs";
        $this->addLine("<td$attrs>$html</td>");
    }

    /**
     *  Convenience function to add a complete table row with cells.
     *
     *  Must be inside openTableBody().
     *
     *  Calls openTableRow() once and addTableCell() on every array (list) member.
     */
    public function addTableRow(array $aCells,
                                string $extraClasses = NULL,
                                string $attrs = NULL)
    {
        $this->openTableRow($extraClasses, $attrs);
        foreach ($aCells as $cell)
            $this->addTableCell($cell);
        $this->close();
    }


    /********************************************************************
     *
     *  Doreen AJAX Table management
     *
     ********************************************************************/

    /**
     *  Adds an "AjaxTable", which is an HTML table enhanced by Bootstrap Table
     *  and our own JavaScript. This simplifies dumping results from a
     *  JSON API into a table.
     *
     *  All three input arrays must have the same number of items.
     *
     *  This produces a DIV with #$idDialog, with the following THREE sub-elements:
     *
     *   -- a hidden error box via addHiddenErrorBox();
     *
     *   -- another hidden DIV with #$idDialog-template and a table with the given
     *      column headings and a single row with the given row cells;
     *
     *   -- an empty DIV in which JavaScript/TypeScript can generate the target table.
     *
     *  On the client side, use the fancy AjaxTableBase Typescript class in
     *  js/inc-ajaxtable.ts.
     *
     *  You can use "data-sort" attributes for stupidtable in $llColumnAttrs, which
     *  will make the table sortable automatically. That attribute can have the
     *  "int", "float", "string" or "string-ins" (for case-insensitive) values.
     *
     *  If sorting the data by the cell contents is not really working, you can add a
     *  "data-sort-value" to the individual table cells. This is useful, if you want
     *  to sort by an int or string value but the cells contain additional information.
     *  For example, a column should be sortable by date, but the dates are displayed
     *  in relative "an hour ago" format: then sorting by int or string does not work
     *  without that.
     */
    public function addAjaxTable($idDialog,                //!< in: HTML ID of main DIV
                                 $llColumnHeadings,        //!< in: list of table column headings (must be escaped HTML)
                                 $llColumnAttrs,           //!< in: attributes of those headings (e.g. 'data-align="center" data-valign="middle"')
                                 $llTemplateRowCells,      //!< in: template table row with placeholders
                                 $trAttribute = NULL,
                                 $fHidden = FALSE)
    {
        WholePage::Enable(WholePage::FEAT_JS_SUPERTABLE);

        $llClasses = [ 'drn-tickets-table' ];
        if ($fHidden)
            $llClasses[] = 'hidden';
        $this->openDiv($idDialog, $llClasses);
        $this->addHiddenErrorBox("$idDialog-error");

        $this->openDiv("$idDialog-template", "hidden");
        $this->openTable(NULL, "drn-margin-bottom");

        $this->openTableHeadAndRow();
        $i = 0;
        foreach ($llColumnHeadings as $heading)
            $this->addTableHeading($heading, getArrayItem($llColumnAttrs, $i++));
        $this->close();    # table head and row

        $this->openTableBody();
        $this->addTableRow($llTemplateRowCells, NULL, $trAttribute);
        $this->close();    # table body

        $this->close();    # table
        $this->close();    # div -template

        # This receives the visible table later.
        $this->openDiv("$idDialog-target");
        $this->close();    # DIV -target

        $this->close();     # div idDialog
    }

    const AJAX_TYPE_INT         = (1 <<  0);
    const AJAX_TYPE_STRING      = (1 <<  1);
    const AJAX_ALIGN_CENTER     = (1 <<  2);

    /**
     *  Convenience wrapper around \ref addAjaxTable() which builds the required arrays
     *  more easily, hopefully. Also this might be more readable in the source code.
     *
     *  The $llInfo array must be a list of sub-lists, where each item must have exactly
     *  three or four elements:
     *
     *   -- the L() string for the column title (this function will call L() on it);
     *
     *   -- the '%XXX%' placeholder;
     *
     *   -- an integer with AJAX_* flags ORed togehter:
     *
     *       -- AJAX_TYPE_INT: the contents are ints and should be sortable as such.
     *          Default is no sorting. (Cannot be used with AJAX_TYPE_STRING.)
     *
     *       -- AJAX_TYPE_STRING: the contents are strings and should be sortable as such.
     *          Default is no sorting. (Cannot be used with AJAX_TYPE_INT.)
     *
     *       -- AJAX_ALIGN_CENTER: table cell should be centered; default is to left-align;
     *
     *   -- optionally, a fourth element with a help topic identifier, which will be turned
     *      into a help link in the column header.
     *
     *  This returns the list of placeholders as an array for JSON encoding which can be
     *  passed to a TypeScript init call because the TypeScript AjaxTableRenderedBase
     *  class requires such an array as input. (This ensures that the two calls are
     *  always identical.)
     */
    public function addAjaxTable2(string $idDialog,
                                  array $llInfo)
        : array
    {
        $llColumnHeadings = $llColumnAttrs = $llTemplateRowCells = [];
        foreach ($llInfo as $a2)
        {
            list($lstr, $placeholder, $flags) = $a2;

            $oHeading = HTMLChunk::FromString(L($lstr));
            if ($helptopic = $a2[3] ?? NULL)
                $oHeading->append(Format::NBSP)->appendChunk(HTMLChunk::MakeHelpLink($helptopic));
            $llColumnHeadings[] = $oHeading->html;

            $llTemplateRowCells[] = $placeholder;

            $aAttrs = [];
            if ($flags & self::AJAX_TYPE_INT)
                $aAttrs[] = 'data-sort="int"';
            else if ($flags & self::AJAX_TYPE_STRING)
                $aAttrs[] = 'data-sort="string-ins"';

            if ($flags & self::AJAX_ALIGN_CENTER)
                $aAttrs[] = 'class="text-center"';

            $llColumnAttrs[] = implode(' ', $aAttrs);
        }

        $this->addAjaxTable($idDialog,
                            $llColumnHeadings,
                            $llColumnAttrs,
                            $llTemplateRowCells);

        return $llTemplateRowCells;
    }


    /********************************************************************
     *
     *  Bootstrap form management
     *
     ********************************************************************/

    /*
     *  Begins a <form> in the HTML with the Bootstrap "form-horizontal" class. This is
     *  the recommended way to use the Bootstrap grid system within a form, since the
     *  classes are named subtly differently.
     *
     *  The system implemented here creates a two-column form, with labels on the left
     *  and the form controls in a wider column on the right. The column widths are 2
     *  and 10 Bootstrap grid units.
     *
     *  Typically, this code is used as follows:
     *
     *  ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~{.php}
     *
     *  $html->openForm();
     *
     *  $html->openFormRow();
     *  $html->addLabelColumn("Label");
     *  $html->openWideColumn();
     *
     *  $html->addButton("Press me");
     *
     *  $html->close();     # wide column
     *  $html->close();     # form row
     *
     *  $html->close();     # form
     *
     *
     *  ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
     *
     *  Requires a matching close() call.
     */
    public function openForm($id = NULL,
                             $extraClasses = NULL,
                             $extraAttrs = NULL)
    {
        if ($id)
            $id = " id=\"$id\"";
        if (!$extraClasses || strpos($extraClasses, 'navbar-form') === FALSE)
            $extraClasses = "form-horizontal $extraClasses";
        if ($extraAttrs)
            $extraAttrs = " ".self::CollapseAttributes($extraAttrs);
        $this->addLine("<form class=\"$extraClasses\"".$id.$extraAttrs.">");
        $this->push("</form>");
    }

    /**
     *  Begins a form row block.
     *
     *  Must be inside openForm().
     *
     *  Requires a matching close() call.
     */
    public function openFormRow($id = NULL,
                                $extraClasses = NULL)
    {
        if ($id)
            $id = " id=\"$id\"";
        if ($extraClasses)
            $extraClasses = " $extraClasses";
        $attrs = "class=\"form-group$extraClasses\"$id";
        $this->addLine("<div $attrs>");
        $this->push("</div><!-- end .form-group -->");
    }

    /**
     *  Adds a label for a form row.
     *
     *  Must be inside openFormRow().
     */
    public function addLabelColumn(HTMLChunk $oLabel = NULL,
                                   $forid = NULL,
                                   $gridclass = 'xs',
                                   $extraClasses = NULL)
    {
        $extraClasses = ($extraClasses) ? "$extraClasses " : '';
        $extraClasses .= "col-$gridclass-2";
        $this->addLabel($oLabel,
                        $forid,
                        $extraClasses);
    }

    /**
     *  Opens the wide (right) column of a form row.
     *
     *  Must be inside openFormRow().
     */
    public function openWideColumn($id = NULL,
                                   $extraClasses = NULL,
                                   $gridclass = 'xs')
    {
        if ($id)
            $id = " id=\"$id\"";
        if ($extraClasses)
            $extraClasses = " $extraClasses";
        $this->addLine("<div$id class=\"col-$gridclass-10$extraClasses\">");
        $this->push("</div> <!-- end .col-$gridclass-10 -->");
    }


    /********************************************************************
     *
     *  Individual Bootstrap form controls
     *
     ********************************************************************/

    /**
     *  Adds an HTML label. As opposed to addLabelColumn(), this does not use the "col-md-2" class.
     */
    public function addLabel(HTMLChunk $oLabel = NULL,
                             $forid = NULL,
                             $extraClasses = NULL)
    {
        if ($extraClasses)
            $extraClasses = " $extraClasses";
        if ($forid)
            $forid = " for=\"$forid\"";
        $html = ($oLabel) ? $oLabel->html : '';
        $this->addLine("<label$forid class=\"control-label$extraClasses\"><b>".$html."</b></label>");
    }

    const INPUT_REQUIRED    = (1 <<  0);
    const INPUT_DISABLED    = (1 <<  1);
    const INPUT_PULLRIGHT   = (1 <<  2);
    const INPUT_READONLY    = (1 <<  3);

    /**
     *  Adds an HTML input form of the given type with the given ID.
     */
    public function addInput($type,                 //!< in: HTML input type, e.g. 'text' or 'password'
                             $id,
                             $placeholder = '',
                             $htmlValue = '',       //!< in: initial value for the input (MUST be html-escaped, will appear in value="...")
                             $fl = 0,
                             $icon = NULL,          //!< in: icon name for Icon::Get or NULL
                             $extraClasses = '',
                             $extraAttrs = [])
    {
        if (!empty($extraClasses))
            $extraClasses = " $extraClasses";
        $aAttrs = array_merge([
            "placeholder=\"$placeholder\"",
            "value=\"$htmlValue\""
        ], $extraAttrs);
        if ($fl & self::INPUT_REQUIRED)
            $aAttrs[] = 'required="yes"';
        if ($fl & self::INPUT_DISABLED)
            $aAttrs[] = 'disabled';
        if ($fl & self::INPUT_PULLRIGHT)
            $extraClasses = "$extraClasses text-right";
        if ($fl & self::INPUT_READONLY)
            $aAttrs[] = 'readonly';

        if ($type != 'file')
            $extraClasses = 'form-control'.$extraClasses;

        $icon2 = ($icon) ? "<div class=\"input-group margin-bottom-sm\"><span class=\"input-group-addon\">".Icon::Get($icon, TRUE)."</span>" : '';
        $icon3 = ($icon) ? "</div>" : '';
        $attrs = implode(' ', $aAttrs);
        $attrs = "type=\"$type\" class=\"$extraClasses\" name=\"$id\" id=\"$id\" $attrs";
        $this->addLine("$icon2<input $attrs>$icon3");
    }

    /**
     *  Wrapper around addInput() that also adds a DIV and a bootstrap grid row
     *  and column and hides the input.
     *
     *  This is useful for building additional entry fields below the input and
     *  then only using the hidden input for submitting, but writing the value into
     *  the input via javscript from other fields.
     */
    public function addHiddenInputDIV($idControl,             //!< in: ID for the HTML input
                                      $htmlValue,             //!< in: initial value for the input (MUST be html-escaped, will appear in value="...")
                                      $fDebug = FALSE)
    {
        $this->openDiv("$idControl-div");     // This DIV is used to append rows to.
        $this->openGridRow();
        $this->openGridColumn(12);
        $extraClasses = ($fDebug) ? '' : 'hidden';
        $this->addInput('text',
                        $idControl,
                        '',
                        $htmlValue,
                        HTMLChunk::INPUT_DISABLED,
                        '',
                        $extraClasses); //        hidden
        $this->close();  // column div
        $this->close();  // row div
        $this->close();  // $idControl-div
    }

    const DATETIME_DATEONLY = 1;
    const DATETIME_TIMEONLY = 2;
    const DATETIME_BOTH = 3;

    /**
     *  Adds a special input with an attached date/time picker (a bootstrap-datetimepicker control).
     *
     *  This actually adds three things. One hidden regular text entry field with the given $id,
     *  and a DIV for bootstrap-datetimepicker with an id of "$id-div-for-picker", and an event
     *  handler that updates the hidden entry field with an ISO date/time string whenever the
     *  selection changes. This is useful for forms because the date/time picker doesn't submit
     *  anything in forms; this way you can simply add "$id" to a list of controls to be submitted
     *  and read the ISO date/time stamp from the hidden entry field with that ID.
     *
     *  The hidden entry field receives the date in ISO YYYY-MM-DD format.
     *
     *  Requires bootstrap-datetimepicker, hah.
     */
    public function addDateTimePicker($id,
                                      $value,       //!< in: value (can be any string that the control can parse)
                                      $mode = self::DATETIME_BOTH)
    {
        $this->addInput('hidden', $id);

        $idForPicker = "$id-div-for-picker";
        $this->openDiv($idForPicker, 'input-group date');
        $this->addLine("<input type=\"text\" class=\"form-control\" />");
        $icon = Icon::Get('calendar');
        $this->addLine("<span class=\"input-group-addon\">$icon</span>");
        $this->close(); # div

        WholePage::Enable(WholePage::FEAT_JS_DATETIMEPICKER);

        switch ($mode)
        {
            case self::DATETIME_DATEONLY:
                $format = 'LL';
            break;
            case self::DATETIME_TIMEONLY:
                $format = 'LT';
            break;
            case self::DATETIME_BOTH:
                $format = 'LLLL';
            break;

            default:
                throw new DrnException("Internal error: invalid datetime mode $mode");
        }

        $locale = DrnLocale::GetItem(DrnLocale::MOMENTSJS_LOCALE);
        WholePage::AddJSAction('core', 'initDateTimePicker', [
            $id,
            $idForPicker,
            $format,
            $locale,
            $value
        ]);
    }

    /**
     *  Links two date-time pickers created by \ref addDateTimePicker() together for better
     *  "start time" and "end time" handling. This attaches a handler to $idStart which
     *  synchronizes the second picker with $idEnd with a time that is $delta hours in the future.
     *
     *  This has only been tested with DATETIME_TIMEONLY.
     */
    public function linkTimePicker($idStart,            //!< in: HTML ID of 'start' time picker
                                   $idEnd,              //!< in: HTML ID of 'end' time picker
                                   $deltaMinutes)       //!< in: no. of minutes to add to time in idStart for idEnd
    {
        $deltaMinutes = (int)$deltaMinutes;
        WholePage::AddJSAction('core', 'linkTimePickers', [
            $idStart,
            $idEnd,
            $deltaMinutes
        ], TRUE);
    }

    /**
     *  Adds a multi-line plain textarea without HTML formatting support. For the full-blown HTML editor,
     *  use addHtmlTextArea.
     */
    public function addTextArea($id,
                                $rows = 5,
                                $htmlValue = "",
                                $extraClasses = NULL,
                                $fDisabled = FALSE)
    {
        if ($extraClasses)
            $extraClasses = " $extraClasses";
        $disabled = ($fDisabled) ? ' disabled' : '';
        $attrs = "class=\"form-control$extraClasses\"$disabled name=\"$id\" id=\"$id\" rows=$rows";
        $this->addLine("<textarea $attrs>$htmlValue</textarea>");
    }

    /**
     *  Adds a single HTML checkbox.
     *
     *  HTML checkboxes can logically operate in one of two ways:
     *
     *   -- There can be a single checkbox for a boolean TRUE/FALSE value. That's easy: just add one,
     *      and the form will submit a TRUE or FALSE. For those you can use the same strings for
     *      $name, $value and $id.
     *
     *   -- There can be several checkboxes in a group which should operate as an array of values:
     *      for every box that is ticked, its value is added to the array -- for example for list
     *      of keywords. This can be done by using the same $name attribute for all checkboxes
     *      in the group, but different $value's.
     *
     *  In the second case, the form request string will then look like this: myname=val1&myname=val2.
     *  PHP (not HTML!) allows for combining these into an array by adding '[]' to the name string.
     *  in $_REQUEST['name'] will then be an array of checked checkbox values.
     *
     *  For this to work with TypeScript  AjaxForm and friends, use a DIV id=... class="drn-checkbox-group"
     *  instead.
     */
    public function addCheckbox(string $label,                   //!< in: descriptive text for checkbox (will be escaped)
                                string $id = NULL,               //!< in: HTML ID
                                string $name = NULL,             //!< in: name
                                string $value = NULL,            //!< in: value to submit for the checkbox (useful for groups)
                                bool $fChecked = FALSE)          //!< in: TRUE if checkbox should be checked
    {
        $aInputAttrs = [ 'type' => 'checkbox'  ];
        $aLabelAttrs = [];
        if ($id)
        {
            $aInputAttrs += [ 'id' => $id ];
            $aLabelAttrs += [ 'for' => $id ];
        }
        if ($name)
            $aInputAttrs += [ 'name' => $name ];
        if ($value)
            $aInputAttrs += [ 'value' => $value ];
        if ($fChecked)
            $aInputAttrs += [ 'checked' => 'checked' ];
        $this->appendElement('div',
                             [ 'class' => 'checkbox' ],
                             self::MakeElement('label',
                                               $aLabelAttrs,
                                               self::MakeElement('input',
                                                                 $aInputAttrs)->appendChunk(self::FromString(" $label"))));
    }

    /**
     *  Adds an HTML radio button. Even with standard HTML without bootstrap, radio boxes differ from checkboxes
     *  in that only ONE item in a group that has the same "name" attribute can be checked. On submit, the form
     *  then gets name=$value of the one that was checked.
     *
     *  This function calls that "name" attribute $groupname, so for radios to work, the radio buttons all need to
     *  have the same $groupname.
     *
     *  Additionally, for radios to work with our TypeScript AjaxForm and friends, use a
     *  DIV id=$(groupname) class="drn-radio-group" around the radios of the same group. That way the single selection
     *  will be submitted as if it was a select/option value.
     */
    public function addRadio(HTMLChunk $oLabel,          //!< in: descriptive text for checkbox
                             $id,                        //!< in: HTML ID, if needed (separate from $name here because one cannot have multiple with the same ID)
                             $groupname,                 //!< in: name of radio button group (must be the same for those radio buttons between which the value should switch)
                             $value,                     //!< in: value of this radio button
                             $fChecked)                  //!< in: TRUE if radio should be checked
    {
        if ($value)
            $value = " value=\"$value\"";
        if ($groupname)
            $groupname = " name=\"$groupname\"";
        if ($id)
            $id = " id=\"$id\"";
        $checked = ($fChecked) ? ' checked' : '';
        $attrs = "type=\"radio\"$groupname$value$id$checked";
        $this->addLine("<div class=\"radio\"><label><input $attrs> ".$oLabel->html.'</label></div>');
    }

    /**
     *  Adds a Bootstrap button.
     */
    public function addButton($htmlButton,
                              $id = NULL,
                              $extraClasses = NULL,
                              $colorClass = 'btn-primary',      //!< in: e.g. 'btn-info'
                              $extraAttrs = NULL)               //!< in: for dropzone attributes etc.
    {
        if ($id)
            $id = " id=\"$id\"";
        if ($extraClasses)
            $extraClasses = " $extraClasses";
        if ($extraAttrs)        # for dropzone
            $extraAttrs = " $extraAttrs";
        $attrs = "type=\"button\" class=\"btn $colorClass$extraClasses\"$id$extraAttrs";
        $this->addLine("<button $attrs>$htmlButton</button>");
    }

    /**
     *  Adds a Bootstrap "Submit" button.
     */
    public function addSubmit($htmlButton,               //!< in: for dropzone attributes etc.
                              $extraClasses = NULL)      //!< in: e.g. 'disabled'
    {
        if ($extraClasses)
            $extraClasses = " $extraClasses";
        $this->addLine("<button type=\"submit\" class=\"btn btn-primary$extraClasses\">$htmlButton</button>");
    }

    /**
     *
     *  Bootstrap has the "collapse" system but the animation is horrendously slow with large display items.
     *
     *  $idTarget must have the "hidden" class.
     */
    public function addShowHideButton($idTarget,
                                      $htmlTitleShow = NULL,
                                      $htmlTitleHide = NULL,
                                      $colorClass = 'btn-info')
    {
        if (!$htmlTitleShow)
            $htmlTitleShow = L('{{L//Show}}');
        if (!$htmlTitleHide)
            $htmlTitleHide = L('{{L//Hide}}');

        // Add the feature code to the JavaScript.
        WholePage::Enable(WholePage::FEAT_JS_DRN_SHOW_HIDE);

        $this->addButton($htmlTitleShow,
                         NULL,
                         'drn-show-hide',
                         $colorClass,
                         "data-drn-target=\"$idTarget\" data-drn-hide=\"$htmlTitleHide\"");
    }

    public static $lastClipboardButtonID = 0;
    const CLASS_COPY_CLIPBOARD_BACKEND = 'copy-clipboard-back';

    /**
     *  Adds a "Copy to clipboard" button via the backend and triggers the necessary javascript
     *  to make it work. $idCopyFrom must be the ID of an element (e.g. an input or a span) whose
     *  contents should be copied.
     *
     *  Alternatively, "copy to clipboard" buttons can be added via TypeScript; see APIHandler::makeCopyToClipboardButton()
     *  in the front-end. They work identically using the same JavaScript library.
     */
    public function addCopyToClipboardButton(string $idCopyFrom)        //<! in: source ID without '#'
    {
        ++self::$lastClipboardButtonID;

        $this->appendElement('button',
                             [ 'class' => "btn btn-default btn-sm ".self::CLASS_COPY_CLIPBOARD_BACKEND,
                               'title' => self::GetNLSCopyToClipboard(),
                               'data-clipboard-target' => "#$idCopyFrom",
                             ],
                             Icon::GetH('clipboard'));

        WholePage::Enable(WholePage::FEAT_JS_COPYTOCLIPBOARD);
    }

    public static $lastCopyFromId = 0;

    /**
     *  Helper to generate a unique string ID that might be useful to assign to an element
     *  for use with \ref addCopyToClipboardButton().
     */
    public static function MakeCopyFromId()
        : string
    {
        return 'copy-clip-from-'.++self::$lastCopyFromId;
    }

    /**
     *  Required for the Javascript Dialog items to work.
     */
    public function addHiddenErrorBox($id)
    {
        $this->addLine("<div class=\"alert alert-danger hidden drn-error-box\" role=\"alert\" id=\"$id\"><p>Error message</p></div>");
    }

    /*
     *  Adds an <input type="hidden"> to the HTML.
     */
    public function addHidden($nameId,
                              $value,                   # in: can be empty
                              $combineClass = '')       # in: can be empty
    {
        $aAttrs = [ 'type' => 'hidden',
                    'class' => 'form-control',
                    'name' => $nameId,
                    'id' => $nameId,
                    'value' => $value ];
        if ($combineClass)
            $aAttrs['combineClass'] = $combineClass;
        $this->appendElement('input',
                             $aAttrs);
    }

    /**
     *  Adds a complete Bootstrap progress bar.
     *
     *  To change the progress from JavaScript, use something like this:
     *
     *  document.querySelector("#{$id} .progress-bar").style.width = progress + "%";
     */
    public function addProgressBar($id = NULL,
                                   $extraClasses = NULL,
                                   $fInDropZone = FALSE)
    {
        if ($id)
            $id = " id=\"$id\"";
        if ($extraClasses)
            $extraClasses = " $extraClasses";
        $dropzone = ($fInDropZone) ? " data-dz-uploadprogress" : '';
        $this->addLine("<div$id class=\"progress progress-striped$extraClasses\" role=\"progressbar\" aria-valuemin=\"0\" aria-valuemax=\"100\" aria-valuenow=\"0\">");
        $attrs = "class=\"progress-bar progress-bar-info\" style=\"min-width:2em; width:0;\"$dropzone";
        $this->addLine("  <div $attrs></div>");
        $this->addLine("</div>");
    }

    private function addOptions(HTMLChunk $oHtml,
                                array $aOptions,        //!< in: options to add as value -> string pairs (will be escaped) or sub-arrays
                                $select)
    {
        foreach ($aOptions as $value => $str)
        {
            if (is_array($str))
            {
                $oSubOptions = new HTMLChunk();
                $this->addOptions($oSubOptions,
                                  $str,     # array, really
                                  $select);
                $optGroupAttrs = [
                    'label' => $value
                ];
                $oHtml->appendElement("optgroup",
                                      $optGroupAttrs,
                                      $oSubOptions);
            }
            else
            {
                $fSelect = FALSE;
                if ($select !== NULL)
                {
                    if (is_array($select))
                    {
                        if (in_array($value, $select))
                            $fSelect = TRUE;
                    }
                    else
                        if ($select == $value)
                            $fSelect = TRUE;
                }
                $attrs = [
                    'value' => $value
                ];
                if ($fSelect)
                    $attrs['selected'] = 'selected';
                $oHtml->appendElement("option",
                                      $attrs,
                                      HTMLChunk::FromString($str));
            }
        }
    }

    /**
     *  Adds a SELECT/OPTION block with the given data fields.
     *
     *  If aOptions is specified, it is assumed to be an array of value => string pairs,
     *  which will be added as <option value=$value>$string</option> entries.
     *
     *  As a special feature, you can add <OPTGROUP> elements in addition to options by
     *  nesting arrays like this:
     *
     *  ```javascript
     *       [  "Optgroup title" => [ 1 => "value", 2 => "value ],
     *          "Another optgroup" => ... ]
     *  ```
     *
     *  Strings will be HTML-escaped.
     */
    public function addSelect(string $id = NULL,
                              array $aOptions = NULL,   //!< in: options to add (optional), as value -> string pairs (will be escaped) or sub-arrays
                              $select = NULL,           //!< in: value or list of values to preselect if any
                              array $attrs = NULL)      //!< in: associative array of attributes
    {
        $options = new HTMLChunk();
        if ($aOptions)
        {
            if (!is_array($aOptions))
                throw new DrnException("Internal error: options in select are not an array");

            $this->addOptions($options,
                              $aOptions,
                              $select);
        }

        if ($attrs === NULL)
        {
            $attrs = [];
        }

        $attrs["name"] = $id;
        $attrs["id"] = $id;
        $this->appendElement("select",
                             $attrs,
                             $options);
    }

    public static function GetNLSPleaseSelect()
    {
        return L("{{L//— Please select —}}");
    }

    public static function GetNLSCopyToClipboard()
    {
        return L("{{L//Copy to clipboard}}");
    }

    /**
     *  Similar to addSelect() except we add a 'bootstrap-multiselect' control
     *  (https://github.com/davidstutz/bootstrap-multiselect, node_modules).
     *
     *  This calls addSelect() simply with the given id and options, and then
     *  multiselect JavaScript which does the rest.
     *
     *  We set enableHTML = true on the multiselect, so you must escape HTML in
     *  the options list properly. OTOH this is the same behavior as with a regular
     *  select/options list.
     *
     *  $nlsNothingSelected can be a custom string to be displayed if nothing is
     *  selected in the multiselect. If NULL, we use self::GetNLSPleaseSelect().
     *
     *  $manySelectedThreshold must be an integer that specifies when the control
     *  begins to display $nlsManySelected instead of a list of options.
     *
     *  $nlsManySelected is the string that gets displayed if the no. of selections
     *  exceed $manySelectedThreshold. If NULL, we'll use a default.
     */
    public function addMultiSelect(string $id,
                                   $aOptions = NULL,
                                   $llSelected = NULL,
                                   string $nlsNothingSelected = NULL,
                                   int $manySelectedThreshold = 10,
                                   string $nlsManySelected = NULL)
    {
        WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_MULTISELECT);

        $this->addSelect($id,
                         $aOptions,
                         $llSelected,
                         [ 'multiple' => "multiple"
                         ] );

        if (!$nlsNothingSelected)
            $nlsNothingSelected = self::GetNLSPleaseSelect();
        if (!$nlsManySelected)
            $nlsManySelected = L("{{L//%SELECTED% selected}}");

        WholePage::AddNLSStrings([ 'selectAll' => L("{{L//Select all}}")
                                 ]);

        WholePage::AddJSAction('core', 'initMultiSelect', [
            $id,
            $nlsNothingSelected,
            $manySelectedThreshold,
            $nlsManySelected,
        ]);
    }

    /**
     *  Gets called from \ref addTicketPicker() to add the NLS strings for the select2 control.
     */
    public static function EnableSelect2()
    {
        WholePage::Enable(WholePage::FEAT_JS_SELECT2);
        WholePage::AddNLSStrings(
            [ 'select2-searching' => L("{{L//Searching}}").Format::HELLIP,
              'select2-searching-more' => L("{{L//Fetching more results}}").Format::HELLIP,
              'select2-error' => L("{{L//An error occured loading the results.}}"),
              'select2-too-short' => L("{{L//Type at least %MIN% characters to begin searching}}"),
              'select2-nothing-found' => L("{{L//Sorry, nothing found.}}" )
            ] );
    }

    /**
     *  Adds a text entry field which acts as a multiselect that allows for adding
     *  ticket references, with AJAX searching.
     *
     *  The input field submits a comma-separated list of integer ticket IDs
     *  (if multiple tickets are allowed by $fMultiple).
     *
     *  $aFilters can be NULL, but should really be an array of filters for the
     *  GET /api/tickets REST API, which gets called by the control. The array
     *  here must consist of numeric field ID => value subarray pairs, for example
     *  [ FIELD_TYPE => [ 1, 2, 3 ] ] to limit the result set to those three ticket
     *  types. See the API documentation for valid filters. This function expects
     *  numeric values and will turn them into strings for the API and URL-encode them.
     */
    public function addTicketPicker($idControl,
                                    $llTickets,            //!< in: array of Ticket instances to add as <option> values (keys are ignored)
                                    $aFilters,
                                    $fMultiple = TRUE,      //!< in: if TRUE, the select box will allow multiple selections, otherwise only a single one
                                    $aTitleFields = [ 'htmlLongerTitle' ],
                                    SearchOrder $oSort = NULL)
    {
        $attrs = "class=\"form-control\" id=\"$idControl\"";
        if ($fMultiple)
            $attrs .= ' multiple="multiple"';
        $this->addLine("<select $attrs>");
        if ($llTickets)
        {
            foreach ($llTickets as $oTicket)
            {
                /** @var Ticket $oTicket */
                $oIcon = $oTicket->getIcon();
                $this->appendElement('option',
                                     [   'value' => '#'.$oTicket->id,
                                         'selected' => 'selected',
                                         'title' => $oTicket->makeGoToFlyover(),
                                         'data-icon' => $oIcon ? $oIcon->html : '',
                                         'data-href' => $oTicket->makeUrlTail()
                                     ],
                                     $oTicket->makeLongerHtmlTitle());

                // $this->addLine("<option value=\"#$idTicket\" selected title=\"$flyOver\" data-icon=\"$icon\" data-href=\"$href\">$htmlTitle</option>");
            }
        }
        $this->addLine('</select>');

        self::EnableSelect2();

        $minSearch = 3;

        // This receives the extra parameters for GET /api/tickets; if not empty, must start with '?'
        $urlFilters = '';
        if ($aFilters)
        {
            $urlFilters = '?';
            $c = 0;
            $aDrillDown = TicketField::GetDrillDownIDs();
            foreach ($aFilters as $field_id => $aValues)
            {
                if (!($oField = TicketField::Find($field_id)))
                    throw new DrnException("Invalid field ID ".Format::UTF8Quote($field_id)." in ticket picker filters");
                if (!is_array($aValues))
                    throw new DrnException("Expected sub-array as value for ticket filter with field ID ".Format::UTF8Quote($field_id));
                if ($c++)
                    $urlFilters .= '&';
                $urlFilters .= (in_array($field_id, $aDrillDown) ? 'drill_' : '').$oField->name.'='.implode(',', $aValues);
            }
        }

        if ($oSort)
            $urlFilters .= ($urlFilters ? '&' : '?').'sortby='.$oSort->getFormattedParam();

        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initTicketPicker',
                                             [ $idControl,
                                               $minSearch,
                                               Globals::$cPerPage,
                                               $urlFilters,
                                               $aTitleFields ] );
    }

    /**
     *  You want to wrap this in a btn-group DIV.
     */
    public function addDropdownMenu($htmlButton,
                                    $aMenuItems)
    {
        $this->addButton($htmlButton,
                         NULL,      # ID
                         'dropdown-toggle',     # extra classes
                         NULL,
                         'data-toggle="dropdown"');  # extra attrs
        $this->addLine('<ul class="dropdown-menu dropdown-menu-right">');
        foreach ($aMenuItems as $id => $item)
            $this->addLine("  <li><a href=\"javascript:;\" id=\"$id\">$item</a></li>");
        $this->addLine('</ul>');
    }

    /**
     * Creates a button with a dropdown
     */
    public function addDropdownButton($buttonTitle,
                                      $aMenuItems,
                                      $extraClasses = [])
    {
        if (sizeof($aMenuItems) == 1)
        {
            foreach ($aMenuItems as $link => $item)
                $this->addLine("  <a href=\"$link\" class=\"btn btn-".implode(', ',$extraClasses)."\"> $buttonTitle</a>");

        }
        else
        {
            $this->openDiv(NULL, 'dropdown');
            $this->addButton($buttonTitle . ' <span class="caret"></span>',
                         NULL,      # ID
                         'dropdown-toggle ',     # extra classes
                         'btn-'.implode(', ', $extraClasses),
                         'data-toggle="dropdown"');  # extra attrs
            $this->addLine('<ul class="dropdown-menu dropdown-menu-right">');
        foreach ($aMenuItems as $link => $item)
            $this->addLine("  <li><a href=\"$link\">$item</a></li>");
            $this->addLine('</ul>');
            $this->close();     # div
        }
    }


    /********************************************************************
     *
     *  Helpers for the above
     *
     ********************************************************************/

    /**
     *  Helper that adds a complete dialog row with label and an input box.
     *
     * @return void
     */
    public function addEntryFieldRow($type,                 //!< in: HTML input type, e.g. 'text' or 'password'
                                     $id,                   //!< in: HTML ID of entry field
                                     $htmlLabel,
                                     $placeholder,
                                     $fRequired = FALSE,
                                     $htmlHelpLine = NULL,
                                     $icon = NULL)
    {
        $this->openFormRow();
        $this->addLabelColumn(HTMLChunk::FromEscapedHTML($htmlLabel),
                              $id);
        $this->openWideColumn();
        $this->addInput($type,
                        $id,
                        $placeholder,
                        '',            # value
                        $fRequired ? HTMLChunk::INPUT_REQUIRED : 0,
                        $icon);
        if ($htmlHelpLine)
            $this->addLine(L("<p class=\"help-block\">$htmlHelpLine</p>"));
        $this->close(); # wideColumn
        $this->close(); # formRow
    }

    /**
     *  Helper for the usual password / confirm password rows.
     *
     * @return void
     */
    public function addPasswordRows($idDialog)
    {
        $htmlPlaceholder = L("{{L//Password}}");

        $this->addEntryFieldRow('password',
                                "$idDialog-password",
                                L("{{L//New password}}"),  # label
                                $htmlPlaceholder,
                                FALSE,                     # $fRequired; we validate this in the API
                                NULL,                      # help line
                                'password');               # icon

        $this->addEntryFieldRow('password',
                                "$idDialog-password-confirm",
                                L("{{L//Confirm new password}}"),  # label
                                $htmlPlaceholder,
                                FALSE,                     # $fRequired; we validate this in the API
                                NULL,                      # help line
                                'password');               # icon
    }

    /**
     *  Helper that adds a complete dialog row with our error box and "Save" button.
     *  This combines openFormRow(), addHiddenErrorBox() and addSaveButton().
     */
    public function addErrorAndSaveRow($idDialog,           //!< in: stem for HTML IDs (will append '-error' and '-save' to this)
                                       HTMLChunk $oSave)    //!< in: HTML to appear on the "Save" button
    {
        $this->openFormRow();
        $this->addLabelColumn(NULL);
        $this->openWideColumn();
        $this->addHiddenErrorBox("$idDialog-error");
        $this->appendElement('button',
                             [
                                 'type' => 'button',
                                 'class' => 'btn btn-primary',
                                 'id' => "$idDialog-save",
                                 'autocomplete' => 'off',
                             ],
                             $oSave);
        $this->close(); # wideColumn
        $this->close(); # formRow
    }

    /**
     *  Insert a blockquote, optionally with a citation.
     */
    public function addBlockquote(HTMLChunk $quote,         //!< in: content of the quote
                                  HTMLChunk $cite = NULL)   //!< in: optional quote source, HTMLChunk so it can be a link
    {
        $this->appendElement('blockquote', [], $quote);
        if (!empty($cite))
        {
            $this->appendElement('cite',
                                 [ 'class' => 'pull-right'
                                 ],
                                 $cite);
        }
    }

    /**
     *  Adds a footer element to the page with the given array of links on the left plus
     *  an additional "Back to top" link at the right.
     */
    public function addFooter(array $contents,  //!< in: URL -> link title strings (will be HTML-escaped)
                              bool $fShowBackToTop)
    {
        $footerContent = new HTMLChunk($this->cIndentSpaces);
        $footerContent->openGridRow();
        $footerContent->openGridColumn([ 'col-sm-9', 'col-xs-8' ]);
        $footerContent->append("<p>");
        $first = TRUE;
        foreach ($contents as $href => $title)
        {
            if (!$first)
                $footerContent->append(" &middot; ");
            else
                $first = FALSE;

            $link = self::MakeLink(WebApp::MakeUrl($href), HTMLChunk::FromString($title));
            $footerContent->appendChunk($link);
        }
        $footerContent->append("</p>");
        $footerContent->close(); // grid column sm-9 xs-8

        $footerContent->openGridColumn( [ 'col-sm-3', 'col-xs-4' ], NULL, 'text-right');
        if ($fShowBackToTop)
        {
            $linkContent = new HTMLChunk($this->cIndentSpaces);
            $linkContent->addPara(HTMLChunk::FromString(L('{{L//Back to top}}'))->html.Format::NBSP.Icon::Get('arrow-up'));
            $bttLink = self::MakeLink('#top', $linkContent, NULL, [ 'class' => 'hidden' ]);
            $footerContent->appendChunk($bttLink);
            // JS to show this and the icon are regsitered in WholePage.
        }

        $footerContent->close(); // grid column sm-3 xs-4
        $footerContent->close(); // grid row

        $this->appendElement('footer', [], $footerContent);
    }


    /********************************************************************
     *
     *  Bootstrap modals
     *
     ********************************************************************/

    private $idDialog = NULL;

    /**
     *  Opens a bootstrap modal dialog, if you prefer to construct it procedurally.
     *
     *  Note that as opposed to other open* functions, do not close this with close(),
     *  but with the special closeModel() function since there are too many nesting
     *  levels involved to get that right easily.
     */
    public function openModal(string $idDialog,
                              string $htmlTitle,
                              bool $fCanClose = FALSE)
    {
        $this->addLine();
        $this->addLine("<!-- begin modal $idDialog -->");
        $this->openDiv($idDialog, 'modal fade', NULL, 'div', [ 'tabindex' => '-1' ] );
        $this->openDiv(NULL, 'modal-dialog modal-lg');
        $this->openDiv(NULL, 'modal-content');

        $this->openDiv(NULL, 'modal-header');
        $oClose = self::MakeElement('span',
                          [ 'class' => 'sr-only' ],
                          HTMLChunk::FromEscapedHTML("Close"));
        if ($fCanClose)
            $oClose->append('&times;');
        $this->appendElement('button',
                             [ 'type' => 'button',
                               'class' => 'close',
                               'data-dismiss' => 'modal'
                             ],
                             $oClose);
        $this->appendElement('h1',
                             [ 'class' => 'modal-title',
                               'id' => "$idDialog--title"
                             ],
                             HTMLChunk::FromEscapedHTML($htmlTitle));

        $this->close(); # modal-header

        $this->openDiv(NULL, 'modal-body');

        $this->idDialog = $idDialog;
    }

    /**
     *  Closes the modal with the given two buttons: one for close/cancel,
     *  one for submit.
     */
    public function closeModal(string $close,
                               string $submit,
                               bool $fSubmitEnabled = TRUE)     //!< in: if FALSE, the submit button is initially disabled
    {
        $this->close(); # modal-body

        $this->openDiv(NULL, 'modal-footer');
        $this->addHiddenErrorBox($this->idDialog.'-error');
        $this->appendElement('button',
                             [ 'type' => 'button',
                               'class' => 'btn btn-default',
                               'id' => $this->idDialog.'-close',
                               'data-dismiss' => 'modal' ],
                             HTMLChunk::FromEscapedHTML($close));

        $disabled = $fSubmitEnabled ? '' : ' disabled';
        $aAttrsButton = [ 'type' => 'button',
                          'class' => 'btn btn-primary'.$disabled,
                          'id' => $this->idDialog.'-submit',
                          'autocomplete' => 'off',
                          # 'data-dismiss' => 'modal'
        ];
        if (!$fSubmitEnabled)
            $aAttrsButton += [ 'disabled' => 'disabled' ];
        $this->appendElement('button',
                             $aAttrsButton,
                             HTMLChunk::FromEscapedHTML($submit));

        $this->close(); # modal-footer
        $this->close(); # modal-content
        $this->close(); # modal-dialog
        $this->close(); # modal
        $this->addLine("<!-- end modal $this->idDialog -->");
    }


    /********************************************************************
     *
     *  Other high-level controls
     *
     ********************************************************************/

    /**
     *  Adds a complete WYSIHTML text area (HTML editor) to the HTML, including the toolbar and required
     *  JavaScript and CSS bits. See \ref drn_html_editor for background.
     *
     *  if ($fInitJS == FALSE), in addition to this function, you MUST either call enableWYSIHTML($idEditor)
     *  from PHP or call the equivalent in your client JS.
     *
     *  Unfortunately this is the only way to reliably get WYSIHTML to work within Bootstrap modal dialogs.
     *  Our TS AjaxModal has a initWYSIHTML() helper to help with this.
     */
    public function addWYSIHTMLEditor($idEditor,                //!< in: HTML ID. This is required for the WYSIHTML JavaScript to work.
                                      $value = '',
                                      $rows = 5,
                                      $fInitJS = TRUE,
                                      $fResizable = TRUE)
    {
//        $indent = $this->indent2;

        # Documentation for WYSIHTML5: http://wysihtml.com/
        $this->openDiv("$idEditor-toolbar",
                       'btn-group',
                       'WYSIHTML editor block',
                       'div',
                       [ 'role' => 'group',
                         'style' => "display: none;" // Says wysihtml documentation, reduces flicker indeed
                       ] );
//        $this->append("$indent<div id=\"$idEditor-toolbar\" class=\"btn-group\" role=\"group\">\n");
        foreach ( [ 'formatBlock|h1' => '<b>H1</b>',
                    'formatBlock|h2' => '<b>H2</b>',
                    'bold' => Icon::Get('bold'),
                    'italic' => Icon::Get('italic'),
                    'underline' => Icon::Get('underline'),
                    'formatInline|s' => Icon::Get('strikethrough'),
                    'formatInline|code' => Icon::Get('code'),     # TODO. find a better icon, and add PRE as well
                    'formatInline|sup' => 'm<sup>2</sup>',
                    'formatInline|sub' => 'H<sub>2</sub>O',
                    'insertOrderedList' => Icon::Get('list-ol'),
                    'insertUnorderedList' => Icon::Get('list-ul'),
                    'createTable' => Icon::Get('table').Format::NBSP.'<span class="caret"></span>'
//                         'justifyLeft' => 'fa-align-left',                not included in simple.js
//                         'justifyCenter' => 'fa-align-center',
//                         'justifyRight' => 'fa-align-right',
//                         'justifyFull' => 'fa-align-justify',
                  ] as $cmd => $icon)                                       # TODO nice tooltips for all!
        {
            $a = explode('|', $cmd);
            if (count($a) == 2)
            {
                $cmd = $a[0];
                $data = $a[1];
                $this->addLine("<a class=\"btn btn-sm btn-default\" role=\"button\" data-wysihtml-command=\"$cmd\" data-wysihtml-command-value=\"$data\">$icon</a>");
            }
            else if ($cmd === 'createTable')
            {
                $this->openDiv(NULL, 'btn-group');
                $this->addLine("<button type=\"button\" class=\"btn btn-sm btn-default dropdown-toggle\" data-toggle=\"dropdown\" aria-expanded=\"false\">$icon</button>");
                $this->addLine('<table class="dropdown-menu" role="menu"><tbody>'); // do not use bootstrap methods as this won't work

                for ($row = 1; $row <= 5; ++$row)
                {
                    $this->openTableRow();
                    for ($col = 1; $col <= 5; ++$col)
                        $this->addTableCell("<a class=\"btn btn-sm btn-default drn-wysi-table-button\" role=\"button\" data-row=\"$row\" data-column=\"$col\">&nbsp;</a>");
                    $this->close(); // table row
                }
                $this->addLine('</tbody></table>');
                $this->close(); // div btn-group
            }
            else
                $this->addLine("<a class=\"btn btn-sm btn-default\" role=\"button\" data-wysihtml-command=\"$cmd\">$icon</a>");
        }
        $this->close(); // div
        if ($fResizable)
            $this->openDiv(NULL, 'drn-resizable');
        $this->addLine("<textarea class=\"form-control\" name=\"$idEditor\" id=\"$idEditor\" rows=\"$rows\">$value</textarea>");
        if ($fResizable)
            $this->close();

        WholePage::Enable(WholePage::FEAT_JS_EDITOR_WYSIHTML);
        if ($fInitJS)
            $this->enableWYSIHTMLEditor($idEditor);
    }

    /**
     *  Companion to addWYSIHTMLEditor(); see remarks there.
     */
    public function enableWYSIHTMLEditor($idEditor)
    {
        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initTextEditor',
                                             [ $idEditor ] );
    }

    /**
     *  Adds a Trix editor to the chunk. The given ID is used for a hidden input field
     *  and passed to the Trix editor JavaScript, which fills the input with HTML according
     *  to the edited and formatted text in the editor.
     *
     *  As a result, from a client code perspective, this acts completely like an
     *  <input type="hidden"...> field.
     */
    public function addTrixEditor($id)
    {
        WholePage::Enable(WholePage::FEAT_JS_EDITOR_TRIX);

        $this->append(<<<EOD
<input id="$id" type="hidden" name="$id">
<trix-editor input="$id" style="min-height: 10em;"></trix-editor>

EOD
                        );
    }

    /**
     *  Adds a complete Dropzone (fancy HTML upload) area to the HTML, including the required JavaScript and CSS.
     *
     *  References: https://github.com/enyo/dropzone/wiki/Combine-normal-form-with-Dropzone .
     */
    public function addDropzone($id,                    //!< in: HTML ID for the control (required for the JavaScript to work)
                                $url,                   //!< in: Upload URL to pass with the Dropzone options, without /api but with leading slash (e.g. /upload)
                                $maxUploadMB)           //!< in: Maximum upload size to pass with the Dropzone options (1000-based, not 1024).
    {
        $iconUpload = Icon::Get('upload');
        $iconCancel = Icon::Get('cancel');
        $iconRemove = Icon::Get('remove');
        $iconAddAnother = Icon::Get('add-another');

        # For the upload rows, we do a three-column layout with 2/4/6 grid items (totalling Bootstrap's 12 columns).
        $aWidths = [ 3, 5, 4 ];

        # 1) The "actions" block, which is always visible and will also show the total progress bar.
        #    We insert another row directly into the dialog's column, without a container in between, for
        #    correct spacing.
        $this->openDiv("{$id}Actions");

        $this->openGridRow(); # "{$id}PreviewTemplate", 'drn-padding-above drn-margin-topbottom');

        $this->openGridColumn($aWidths[0]); # first column
        $this->addLine("<span class=\"btn btn-success {$id}AddFileButton\">$iconAddAnother ".L('{{L//Pick files}}').Format::HELLIP.'</span>');
        $this->close();     # first column

        $this->openGridColumn($aWidths[1], NULL, 'drn-margin-top drn-margin-bottom'); # second column
        $this->addLine(L("<span class=\"drn-droparea\">{{L//Or simply drop files here.}}</span>"));
        $this->close();     # second column

        $this->openGridColumn($aWidths[2]); # second column
        $this->addButton($iconUpload,
                         $id.'-upload-queue',      # ID
                         'start hidden',    # extra classes
                         'btn-primary',      # color class
                         'data-toggle="tooltip" title="'.L('{{L//Start uploading all files}}').'"'); # extra attrs
        WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_TOOLTIPS);
        WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_POPOVERS);

        WholePage::AddNLSStrings( [ 'dzqueue' => L("{{L//When you are done adding files, press this button to start uploading all files at once.}}"),
                                    'dzFallback' => L('{{L//Your browser does not support drag-and-drop file uploads.}}'),
                                    'dzInvalidFileType' => L('{{L//Invalid file type.}}'),
                                    'dzFileTooBig' => L('{{L//File too big! Maximum allowed file size is %MAX% MB, but the file has %SIZE% MB.}}',
                                                        [ '%MAX%' => '{{maxFilesize}}',
                                                          '%SIZE%' => '{{filesize}}'
                                                        ] ),
                                    'dzMaxFilesExceeded' => L('{{L//Too many files!}}'),
                                    'dzServerError' => L("{{L//An error occured uploading the file. The server reported:}}"),
                                  ] );
        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initDropzone',
                                             [ $id,
                                               $url,
                                               $maxUploadMB ] );

        $this->addButton($iconCancel,
                         NULL,
                         'cancel hidden',
                         'btn-warning',
                         'data-toggle="tooltip" title="'.L('{{L//Cancel all}}').'"'); # extra attrs
        $this->close();     # second column

        $this->close();     # row

        $this->addProgressBar("{$id}TotalProgress", 'drn-margin-above hidden', TRUE);

        # 2) The preview area, for which we supply a template that is hidden and that the Dropzone JS code will
        #    remove and store internally and then re-insert for every file that is added to the queue. Note
        #    that again we insert another row directly into the dialog's column, without a container in between.
        $this->openDiv("{$id}PreviewArea", 'drn-margin-above files', '');
        $this->openGridRow("{$id}PreviewTemplate", 'drn-margin-top drn-margin-bottom');

        $this->openGridColumn($aWidths[0]); # first column
        $this->addLine('<span class="preview"><img data-dz-thumbnail /></span>');
        $this->close(); # first column

        $this->openGridColumn($aWidths[1]); # second column
        $this->addLine('<p class="name" data-dz-name></p>');
        $this->addLine('<strong class="error text-danger" data-dz-errormessage></strong>');
        $this->addLine('<p class="size" data-dz-size></p>');
        $this->addProgressBar(NULL, NULL, TRUE);
        $this->close(); # second column

        $this->openGridColumn($aWidths[2]);   # third column
        $this->addButton($iconUpload,
                         NULL,
                         'start',
                         'btn-primary',
                         'data-toggle="tooltip" title="'.L('{{L//Start uploading this file only}}').'"'); # extra attrs
        $this->addButton($iconCancel,
                         NULL,
                         'cancel',
                         'btn-warning',
                         'data-dz-remove data-toggle="tooltip" title="'.L('{{L//Cancel this file}}').'"'); # extra attrs
        $this->addButton($iconRemove,
                         NULL,
                         'delete',
                         'btn-danger',
                         'data-dz-remove data-toggle="tooltip" title="'.L('{{L//Remove this file}}').'"'); # extra attrs
        $this->close(); # third column

        $this->close(); # PreviewTemplate grid row
        $this->close(); # preview area DIV

        $this->close(); # Actions DIV

//        $jsDropzoneObject = "{$id}Dropzone";
    }

    private function makePage($baseCommand,
                              $aParticles,
                              $page)
    {
        return Globals::BuildURL($baseCommand, array_merge($aParticles, [ 'page' => $page ] ) );
    }

    /**
     *  Produces a Bootstrap pagination chunk from a list of results, with gaps and increments computed
     *  automatically.
     *
     *  You must pass in the request URI as parsed by Globals::ParseURL() in the $baseComamnd and $aParticles parameters.
     */
    public function addPagination($baseCommand,         //!< in: typically Globals::$rootpage.WebApp::$command
                                  $aParticles,          //!< in: from Globals::ParseURL()
                                  $iCurrentPage,        //!< in: current page (one-based!)
                                  $cPages)              //!< in: total no. of pages
    {
        $cInterval = 5;
        $cLogarithmicLimit = 100;
        $cPageBase = 5;

        $cPagesToEnd = $cPages - $iCurrentPage;
        if (    ($iCurrentPage > $cLogarithmicLimit)
             && ($cPagesToEnd > $cLogarithmicLimit))
            $cInterval = 2;

        $this->addLine("<ul class=\"pagination pagination-sm\">");
        if ($iCurrentPage == 1)
        {
            $backDisabled = ' class="disabled"';
            $link = '#';
        }
        else
        {
            $backDisabled = '';
            $link = $this->makePage($baseCommand, $aParticles, $iCurrentPage - 1);
        }
        $this->addLine(L("  <li$backDisabled><a href=\"$link\" rel=\"prev\" title=\"{{L//Previous}}\">&laquo;</a></li>"));

        # Always print a link to the first page.
        $active = (1 == $iCurrentPage) ? ' class="active"' : '';
        $current = (1 == $iCurrentPage) ? ' current' : '';
        $this->addLine("  <li$active><a href=\"".$this->makePage($baseCommand, $aParticles, 1)."\" rel=\"start first$current\">1</a></li>");

        # Print some dots if there is a gap.
//        if ($iCurrentPage > $cInterval + 2)
//            $this->addLine("  <li class=\"disabled\"><a href=\"#\">&hellip;</a></li>");

        if ($iCurrentPage > $cLogarithmicLimit)
        {
            $cLogBase = log($iCurrentPage, $cPageBase);
            for ($i = 2; $i < $cLogBase; ++$i)
            {
                $factor = log($i, $cLogBase);
                $pageNumber = floor($factor * $iCurrentPage);
                $this->addLine("  <li><a href=\"{$this->makePage($baseCommand, $aParticles, $pageNumber)}\">$pageNumber</a></li>");
                //$this->addLine("  <li class=\"disabled\"><a href=\"#\">&hellip;</a></li>");
            }
//            $this->addLine("  <li class=\"disabled\"><a href=\"#\">&hellip;</a></li>");
        }
        else if ($iCurrentPage > 20)
        {
            # If there are more than 30 pages in the gap, then add another median link in between.
            $i = floor($iCurrentPage / 2);
            $this->addLine("  <li><a href=\"".$this->makePage($baseCommand, $aParticles, $i)."\">$i</a></li>");
//            $this->addLine("  <li class=\"disabled\"><a href=\"#\">&hellip;</a></li>");
        }

        # Print at most $cInterval links to the left and to the right of the current page, respectively.
        $cFirst = max(2, ($iCurrentPage - $cInterval));
        $cLast = min(($iCurrentPage + $cInterval), $cPages - 1);
        for ($p = $cFirst; $p <= $cLast; ++$p)       # one-based
        {
            $active = ($p == $iCurrentPage) ? ' class="active"' : '';
            $current = ($p == $iCurrentPage) ? ' rel="current"' : '';
            $this->addLine("  <li$active><a href=\"".$this->makePage($baseCommand, $aParticles, $p)."\"$current>$p</a></li>");
        }

        # Print some dots if there is a gap.
//        if ($iCurrentPage < $cPages - $cInterval - 1)
//            $this->addLine("  <li class=\"disabled\"><a href=\"#\">&hellip;</a></li>");

        if ($cPagesToEnd > $cLogarithmicLimit)
        {
            $cLogBase = log($cPagesToEnd, $cPageBase);
            for ($i = $cLogBase - 1; $i > 1; --$i)
            {
                $factor = log($i, $cLogBase);
                $pageNumber = $cPages - floor($factor * $cPagesToEnd);
                $this->addLine("  <li><a href=\"{$this->makePage($baseCommand, $aParticles, $pageNumber)}\">$pageNumber</a></li>");
                //$this->addLine("  <li class=\"disabled\"><a href=\"#\">&hellip;</a></li>");
            }
//            $this->addLine("  <li class=\"disabled\"><a href=\"#\">&hellip;</a></li>");
        }
        else if ($cPagesToEnd > 20)
        {
            # If there are more than 30 pages in the gap, then add another median link in between.
            $i = $iCurrentPage + floor($cPagesToEnd / 2);
            $this->addLine("  <li><a href=\"".$this->makePage($baseCommand, $aParticles, $i)."\">$i</a></li>");
//            $this->addLine("  <li class=\"disabled\"><a href=\"#\">&hellip;</a></li>");
        }

        # Print a link to the last page.
        if ($cPages > 1)
        {
            $active = ($cPages == $iCurrentPage) ? ' class="active"' : '';
            $current = ($cPages == $iCurrentPage) ? ' current' : '';
            $this->addLine("  <li$active><a href=\"".$this->makePage($baseCommand, $aParticles, $cPages)."\" rel=\"last$current\">$cPages</a></li>");
        }

        if ($iCurrentPage >= $cPages)
        {
            $nextDisabled = ' class="disabled"';
            $link = '#';
        }
        else
        {
            $nextDisabled = '';
            $link = $this->makePage($baseCommand, $aParticles, $iCurrentPage + 1);
        }
        $this->addLine(L("  <li$nextDisabled><a href=\"$link\" rel=\"next\" title=\"{{L//Next}}\">&raquo;</a></li>"));
        $this->addLine("</ul>");
    }

    /**
     *  Adds a picture gallery to the HTML chunk. This used to use Bootstrap Carousel but now
     *  we use https://github.com/michaelsoriano/bootstrap-photo-gallery.
     */
    public function addCarousel($aImages,           //!< in: href => title pairs (will be escaped)
                                $id)
    {
        $this->openGridRow();
        $this->openDiv(NULL, 'drn-gallery');
        $this->openList(self::LIST_UL, $id, 'first');

        $llImageHosts = [];

        foreach ($aImages as $href => $title)
        {
            $htmlTitle = toHTML($title);
            $this->addLine("<li><img alt=\"$htmlTitle\" src=\"".toHTML($href)."\"><p class=\"text\">$htmlTitle</p></li>");
            if (preg_match('/^https?:/i', $href))
                $llImageHosts[ContentSecurityPolicy::MakeSourceFromURL($href)] = 1;
        }

        $this->close(); # list
        $this->close(); # div
        $this->close();

        WholePage::Enable(WholePage::FEAT_JS_GALLERY);

        foreach ($llImageHosts as $host => $one)
            WholePage::Allow(ContentSecurityPolicy::DIRECTIVE_IMG, $host);
    }

    /**
     *  Adds an input entry field with the given ID and converts it to a bootstrap-slider.
     */
    public function addSlider($id,
                              int $value,
                              int $min,
                              int $max)
    {
        WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_SLIDER);

        $aAttrs = [
            'type' => 'text',
            'name' => $id,
            'id' => $id,
            'class' => 'form-control input-md',
            'value' => $value
        ];
        $this->addLine(self::MakeElement('input', $aAttrs)->html);

        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initSlider',
                                             [ $id,
                                               $value,
                                               $min,
                                               $max ] );
    }

    /**
     *  Opens a bootstrap panel.
     */
    public function openPanel(string $title) //!< in: title HTML
    {
        $this->append(<<<HTML
        <div class="panel panel-default">
            <div class="panel-heading">$title</div>
            <div class="panel-body">
HTML
        );
        $this->push([ "</div> <!-- .panel-body -->",
                      "</div> <!-- .panel -->"
                    ]);
    }


    /********************************************************************
     *
     *  XML dialog resources
     *
     ********************************************************************/

    /**
     *  Calls \ref Dialog::Load() to load and parse an XML dialog resource and appends the result to the HTML.
     *
     *  Use dirname(__FILE__).'/file.xml' to load an XML file relative to the calling PHP code.
     */
    public function addXmlDlg($file,                    //!< in: Dialog XML input file to parse.
                              $aValuesForL = NULL)
    {
        $this->append(Dialog::Load($file,
                                   $aValuesForL,
                                   $this->cIndentSpaces));
    }


    /********************************************************************
     *
     *  Private helpers
     *
     ********************************************************************/

    private function changeIndent($c)
    {
        if (    ($this->cIndentSpaces === NULL)
             || ($this->cIndentSpaces <= 0)
           )
            throw new DrnException("cIndentSpaces cannot be < 0");

        $this->cIndentSpaces += $c;
        $this->indent2 = str_repeat(' ', $this->cIndentSpaces);
    }

    private function push($str)
    {
        $this->aStack[] = $str;
        $c = +2;
        if (is_array($str))
            $c = count($str) * 2;
        $this->changeIndent($c);
    }

    /**
     *  Returns an attribute attray as key/value pairs.
     */
    private static function MakeAttrsArray($id,                         //!< in: value of id= attribute or NULL
                                           $classes = NULL,             //!< in: either string or flat list of classes, or empty array, or NULL if none
                                           $role = NULL,                //!< in: value of role= attribute or NULL
                                           array $aOtherAttrs = NULL)   //!< in: other attribute strings as key => value pairs
        : array
    {
        $aAttrs = [];
        if ($id)
            $aAttrs['id'] = $id;
        if ($classes)
        {
            if (is_array($classes))
                $aAttrs['class'] = implode(' ', $classes);
            else
                $aAttrs['class'] = $classes;
        }
        if ($role)
            $aAttrs['role'] = $role;

        if ($aOtherAttrs)
            $aAttrs += $aOtherAttrs;

        return $aAttrs;
    }

    /**
     *  Returns an empty string if $aAttrs is empty or NULL. Otherwise it returns a leading
     *  space and the array items as key="value" pairs, each separated by a space.
     *  Calls toHTML on each value.
     */
    private static function CollapseAttributes($aAttrs = NULL)
        : string
    {
        $str = '';
        if ($aAttrs)
        {
            if (is_array($aAttrs))
            {
                foreach ($aAttrs as $key => $value)
                    $str .= ' '.$key.'="'.toHTML($value).'"';
            }
            else
                $str = $aAttrs;
        }


        return $str;
    }

    /**
     *  Returns an attribute string with a leading space from the given parameters, or an empty string.
     */
    private function makeAttrs($id,                         //!< in: value of id= attribute or NULL
                               $classes = NULL,             //!< in: either string or flat list of classes, or empty array, or NULL if none
                               $role = NULL,                //!< in: value of role= attribute or NULL
                               array $aOtherAttrs = NULL)   //!< in: other attribute strings as key => value pairs
        : string
    {
        return self::CollapseAttributes(self::MakeAttrsArray($id, $classes, $role, $aOtherAttrs));
    }


    /********************************************************************
     *
     *  Static methods
     *
     ********************************************************************/

    /**
     *  If href is given we make a tooltip that can be clicked on with the given link target.
     *  Otherwise it's a dotted line with hover info only.
     *
     *  Automatically sets the FEAT_JS_BOOTSTRAP_TOOLTIPS or FEAT_JS_BOOTSTRAP_POPOVERS correctly.
     *
     *  This is a legacy method with fuzzy semantics; use MakeFlyoverInfo() or MakeFlyoverLink()
     *  instead.
     */
    public static function MakeTooltip($htmlLinkName,
                                       $tooltip,
                                       $href = NULL,
                                       $fTargetBlank = FALSE)
        : string
    {
        if ($href)
        {
            WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_TOOLTIPS);
            $attrs = "data-toggle=\"tooltip\" title=\"$tooltip\" href=\"$href\"";
            if ($fTargetBlank)
                $attrs .= ' target="_blank"';
            return "<a $attrs>$htmlLinkName</a>";
        }

        return self::MakeFlyoverInfo(HTMLChunk::FromEscapedHTML($htmlLinkName),
                                     $tooltip)->html;
    }

    const TYPE_TOOLTIP = 1;
    const TYPE_POPOVER = 2;

    /**
     *  Creates a tooltip over the given link, but only as a flyover; the link cannot be clicked on.
     *
     *  Bootstrap provides two types of flyovers, which are both supported here with the $type
     *  option:
     *
     *   -- "tooltips" have a smaller font with a dark background and cannot contain formatting;
     *
     *   -- "popovers" are said to be designed for larger amounts of text and can contain HTML
     *      formatting, but that can look crappy in practice.
     *
     *  @return self
     */
    public static function MakeFlyoverInfo(HTMLChunk $oLinkName,
                                           $tooltip,
                                           $type = self::TYPE_TOOLTIP,
                                           $placement = 'auto left')        //!< in: for TYPE_TOOLTIP only
        : HTMLChunk
    {
        if ($type == self::TYPE_TOOLTIP)
        {
            WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_TOOLTIPS);
            return self::MakeElement('u',
                                     [ 'class' => 'drn-dotted',
                                       'data-toggle' => 'tooltip',
                                       'title' => $tooltip,
                                     ],
                                     $oLinkName);
        }

        WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_POPOVERS);
        return self::MakeElement('u',
                                 [ 'class' => 'drn-dotted',
                                   'data-toggle' => 'popover',
                                   'data-trigger' => 'hover',
                                   'data-container' => 'body',
                                   'data-content' => $tooltip,
                                   'data-placement' => $placement,
                                 ],
                                 $oLinkName);
    }

    /**
     *  Returns an <a...>...</a> block for a help link that displayes help from GET /api/help when clicked on.
     *
     *  @return self
     */
    public static function MakeHelpLink($topic,         //!< help topic for which content is fetched from GET /api/help
                                        $extraClasses = NULL,
                                        $align = 'auto top')
        : HTMLChunk
    {
        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initHelpLink',
                                             [ $topic, $align ],
                                             TRUE);
        $classes = '';
        if ($extraClasses)
            $classes = " $extraClasses";

        return self::MakeElement('a',
                                 [ 'id' => "$topic-help",
                                   'class' => $classes,
                                   'data-topic' => $topic,
                                   'rel' => 'help',
                                   'href' => '#' ],
                                 Icon::GetH('help'));
    }

    private static $cMoreLinks = 0;

    /**
     *  Adds $htmlInitial surrounded with a link to which we attach JavaScript so that it
     *  expands to $htmlReplace when clicked on.
     *
     *  This is useful if you have some long text that you only want to display on demand.
     *
     *  $htmlInitial should be a string like "and X more". This will be prefixed with a space
     *  and then turn into ` <a href="#">and X more</a>`.
     */
    public static function AddMoreLink($htmlInitial,        //!< in: e.g. 'and 123 more"
                                       $htmlReplace)        //!< in: replacement string including prefix
    {
        ++self::$cMoreLinks;
        $id1 = "show-more-initial-".self::$cMoreLinks;
        $id2 = "show-more-onClick-".self::$cMoreLinks;

        WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initShowMore',
                                             [ $id1,
                                               $id2 ]);
        return <<<HTML
<span id='$id1'> <a>$htmlInitial</a></span><span id="$id2" style="display: none">$htmlReplace</span>
HTML;
    }
}
