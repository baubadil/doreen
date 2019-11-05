<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */


namespace Doreen;


/********************************************************************
 *
 *  WholePage class
 *
 ********************************************************************/

/**
 *  The WholePage class encapsulates the HTML that is eventually sent to the client.
 *
 *  All methods and variables in here are static. If you must build and emit a complete
 *  HTML page for some reason instead of filling in bits via plugin interfaces, use
 *  \ref WholePage::Emit().
 *
 *  However, since Emit() gets called eventually one way or another, some other methods
 *  in here might be useful to know:
 *
 *   -- \ref Enable() is for enabling optional features (such as pulling in certain JavaScript libraries).
 *
 *   -- \ref AddJSAction() can be used to have arbitrary JS code added to be
 *                         executed when the page has loaded.
 */
abstract class WholePage
{
    private static $fl = 0;

    public static $hrefCookieGuidelines = NULL;

    private static $aXMLDialogStrings     = [];
    private static $aScripts              = [];
    private static $aNLSStrings           = [];
    private static $aScriptsDocumentReady = [];
    private static $aScriptFiles          = [];                   # file name => 1 to avoid duplicates
    /** @var HTMLChunk[] $llHTMLChunks */
    private static $llHTMLChunks          = [];
    private static $aJSActions            = [];

    private static $aIconNames = [
        'check',
        'checkbox_checked',
        'checkbox_unchecked',
        'crash',
        'edit',
        'mail',
        'password',
        'refresh',
        'remove',
        'share',
        'spinner',
        'thumbsup',
        'info-circle',
        'arrow-down'
    ];

    public static $additionalCSS = '';
    public static $aStyleFiles = [];

    private static $CSP = [];
    private static $fCSPFinalized = FALSE;

    const FEAT_JS_BOOTSTRAP_TOOLTIPS        = 1 <<  0;      # Must be set to activate Bootstrap tooltips.
    const FEAT_JS_BOOTSTRAP_POPOVERS        = 1 <<  1;      # Must be set to activate Bootstrap popovers.
    const FEAT_JS_JAVASCRIPT_VISJS          = 1 <<  2;      # If set, the vis.js graph visualizer code gets loaded. This is quite expensive.
    const FEAT_JS_DROPZONE                  = 1 <<  4;      # If set, the "Dropzone" (drag&drop uploads) code gets loaded. See http://www.dropzonejs.com/
    const FEAT_JS_DRN_SHOW_HIDE             = 1 <<  5;      # Required for using drn-show-hide button class.
    const FEAT_JS_HIDEWHENDONE              = 1 <<  6;      # Required for using drn-hide-when-done class.
    const FEAT_JS_AUTOCOMPLETESEARCH        = 1 <<  7;      # Include autocomplete. https://github.com/bassjobsen/Bootstrap-3-Typeahead
                                                             # Alternatively: https://github.com/twitter/typeahead.js/
    const WHOLEPAGEFL_HEADER_EMITTED        = 1 <<  8;
    const FEAT_CSS_ANIMATIONS               = 1 <<  9;      # Include css/animate.css (# https://daneden.github.io/animate.css/). Add 'animated wobble' or similar classes.
    const FEAT_CSS_FULLWIDTHBAR             = 1 << 10;      # Adds a nasty CSS trick for full-width bars. Only for plugins.
    const FEAT_JS_SUPERTABLE                = 1 << 11;      # Enables bootstrap-table (node_modules)
    const FEAT_JS_BOOTSTRAP_MULTISELECT     = 1 << 13;      # Enables bootstrap-multiselect (node_modules)
    const FEAT_JS_FADEOUT_READMORE          = 1 << 14;      # Adds a bit of JS to document-ready to attach code to "read more" buttons with the drn-fade-out class.
    const FEAT_JS_GALLERY                   = 1 << 15;      # Adds JS and CSS for gallery features.
    const FEAT_JS_EDITOR_TRIX               = 1 << 16;
    const FEAT_JS_EDITOR_WYSIHTML           = 1 << 17;
    const FEAT_JS_DATETIMEPICKER            = 1 << 18;
    const FEAT_JS_SELECT2                   = 1 << 19;      # https://select2.github.io/
    const FEAT_JS_BOOTSTRAP_SLIDER          = 1 << 22;      # https://github.com/seiyria/bootstrap-slider
    const FEAT_JS_COPYTOCLIPBOARD           = 1 << 23;      # Copy to clipboard. See HTMLChunk::addCopyToClipboardButton() and https://clipboardjs.com/
    const FEAT_JS_AUTOFOCUS_SEARCH_BAR      = 1 << 24;      # Simple flag to add autofocus=yes to the search bar.

    /**
     *  Returns a list of theme names from the bootstrap include dir.
     *
     *  The theme names must match theme-(NAME).css and are produced by
     *  the gulp build process from the less files under /less.
     *
     *  This function returns a list of NAME's only, which should never
     *  be empty.
     */
    public static function GetThemes()
    {
        $aThemes = [];
        /** @noinspection PhpUnusedParameterInspection */
        FileHelpers::ForEachFile(TO_HTDOCS_ROOT.'/css/',
                                 '/theme-(.*)\.css/',
                                function($name, $aMatches) use(&$aThemes)
                                {
                                    $t = $aMatches[1];
                                    $aThemes[$t] = $t;
                                });
        return $aThemes;
    }

    /**
     *  Returns the theme setting from GlobalConfig, if any, or SansSerif otherwise.
     */
    public static function GetTheme()
        : string
    {
        return GlobalConfig::Get('theme', 'SansSerif');
    }

    /**
     *  Calls the onThemeChanged() method on all plugins with CAPSFL_WORKFLOW,
     *  performing a limited less parse on the theme file first to submit the
     *  current color values.
     *
     * @return void
     */
    public static function RefreshPluginsForTheme($theme)
    {
        $themeFileName = "theme-$theme.less";
        if (!($aLines = @file(INCLUDE_PATH_PREFIX."/themes/$themeFileName")))
            throw new DrnException("Failed to read theme file $themeFileName");

        $aVariables = [];

        $lineNo = 1;
        foreach ($aLines as $thisLine)
        {
            if (preg_match('/^\s*\@(\S+):\s+(.*);/', $thisLine, $aMatches))
            {
                $varname = $aMatches[1];
                $value = $aMatches[2];

                // Resolve values with @identifier to other identifiers.
                if (preg_match('/\@([-a-zA-Z_]+)/', $value, $aMatches2))
                {
                    $ref = $aMatches2[1];
                    if (!($v2 = $aVariables[$ref] ?? NULL))
                        throw new DrnException("Error in $themeFileName line $lineNo: cannot resolve variable reference $ref");
                    $value = str_replace("@$ref", $v2, $value);
                }

                $aVariables[$varname] = $value;
            }
            ++$lineNo;
        }

        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_WORKFLOW) as $oImpl)
        {
            /** @var IWorkflowPlugin $oImpl  */
            $oImpl->onThemeChanged($theme, $aVariables);
        }
    }

    /**
     *  Sets the given theme as a globalsettings config key.  Throws if $theme is
     *  not a valid theme name.
     *
     * @return void
     */
    public static function SetTheme($theme)
    {
        $aThemes = self::GetThemes();
        if (!in_array($theme, $aThemes))
            throw new DrnException("There is no theme named \"$theme\" installed");

        // Give the new colors to plugins, if they care.
        self::RefreshPluginsForTheme($theme);

        GlobalConfig::Set('theme', $theme);
        GlobalConfig::Save();
    }

    /**
     *  Use this to enable a page feature. This is basically a shortcut for
     *  \ref AddScriptFile() which saves typing for certain script things that
     *  are used frequently.
     *
     *  The $f feature must be one of the const FEAT_JS_* bits defined in this
     *  class.
     *
     *  Oftentimes this function gets called automatically from an HTMLChunk
     *  method; for example WholePage::FEAT_JS_BOOTSTRAP_MULTISELECT gets set
     *  when \ref HTMLChunk::addMultiSelect() is called.
     *
     * @return void
     */
    public static function Enable($f)
    {
        self::$fl |= $f;
    }

    /**
     *  Checks for whether the given page feature has been enabled. See \ref Enable().
     */
    public static function IsEnabled($f)
        : bool
    {
        return ((self::$fl & $f) != 0);
    }

    /**
     *  Adds the given file name to the list of XML dialogs to load by the emitter;
     *  this will cause the \ref Emit() to call \ref Dialog::Load() the file.
     *
     * @return void
     */
    public static function LoadXMLDialog($file)
    {
        self::$aXMLDialogStrings[] = Dialog::Load($file,
                                                  NULL,
                                                  2);
    }

    /**
     *  Adds the given file name to the list of XML dialogs to load by the emitter;
     *  this will cause the \ref Emit() to call \ref Dialog::Load() the file.
     *
     * @return void
     */
    public static function AddXMLDialogString($str)
    {
        self::$aXMLDialogStrings[] = L($str);
    }

    /**
     *  Queues an HTML chunk for adding at the bottom of the page. This is useful for
     *  adding a hidden form that should get incorporated from somewhere.
     */
    public static function AddHTMLChunk(HTMLChunk $oHTML)
    {
        self::$llHTMLChunks[] = $oHTML;
    }

    /**
     *  Adds the given chunk of JavaScript code to the page. This will
     *  be output at the end of the whole page HTML.
     *
     *  This will cause the code to be executed by the browser during
     *  page load, when it is encountered.  If your code depends on the
     *  page having been loaded fully, use \ref AddToDocumentReady()
     *  instead. But this is preferred for writing out global constants
     *  and the like.
     *
     *  See \ref drn_javascript for more about how this works.
     *
     * @return void
     */
    public static function AddScript(string $str,
                                     bool $fPrio = FALSE)
    {
        if (self::$fCSPFinalized)
            throw new DrnException("Cannot add inline scripts after CSP has been sent");

        if ($fPrio)
            array_unshift(self::$aScripts, $str);
        else
            self::$aScripts[] = $str;
    }

    private static $aWebpackStats = NULL;

    /**
     *  Returns the .stats.json file generated by Webpack during the build process.
     *
     *  See \ref drn_javascript for more about how this works.
     */
    private static function GetWebpackStats()
        : array
    {
        if (self::$aWebpackStats == NULL)
        {
            $rawData = file_get_contents(__DIR__.'/../../.stats.json');
            $json = json_decode($rawData, TRUE);
            self::$aWebpackStats = $json;
        }
        return self::$aWebpackStats;
    }

    private static $aPluginChunksAdded = [];

    /**
     *  Calls \ref AddScriptFile() with a script file name determined from
     *  the webpack statistics generated during the build.
     *
     *  See \ref drn_javascript for more about how this works.
     */
    public static function AddPluginChunks(string $plugin)
    {
        if (!(self::$aPluginChunksAdded[$plugin] ?? NULL))
        {
            try
            {
                $aStats = self::GetWebpackStats();
                foreach ($aStats['namedChunkGroups'][$plugin]['assets'] as $asset)
                {
                    if (substr($asset, -3) == '.js')
                    {
                        self::AddScriptFile('js/'.$asset);
                    }
                }

                $aPluginChunksAdded[$plugin] = 1;
            }
            catch (\Throwable $e)
            {
                throw new DrnException("Error finding chunks for $plugin. Try running webpack to generate the JS assets. Original error: ".$e->getMessage(), $e->getTraceAsString(), $e);
            }
        }
    }

    /**
     *  Helper for \ref AddTypescriptCall() to encode arguments for the JavaScript call safely.
     */
    public static function EncodeArgs($a,
                                      int $cIndent = 4)
    {
        $aReturn = [];
        if ($a)
        {
            if (!is_array($a))
                $a = [ $a ];

            foreach ($a as $arg)
                $aReturn[] = json_encode($arg);
        }

        return implode(",\n".str_repeat(' ', $cIndent),
                       $aReturn);
    }

    /**
     *  Adds the given lines of JavaScript code to the page and also makes sure that
     *  the relevant JavaScript modules from WebPack are loaded via <script> tags (by calling
     *  \ref AddPluginChunks() ).
     *
     *  See \ref drn_javascript for more about how this works.
     */
    public static function AddTypescriptCall(string $plugin = NULL,
                                             string $code,
                                             bool $fDocumentReady = FALSE)
    {
        if ($plugin)
            self::AddPluginChunks($plugin);

        if ($fDocumentReady)
            self::AddToDocumentReady($code);
        else
            self::AddScript($code);
    }

    /**
     *  Like \ref AddTypescriptCall(), but calls \ref EncodeArgs() on the given
     *  array as well.
     */
    public static function AddTypescriptCallWithArgs(string $plugin = NULL,
                                                     string $functionName,          //!< in: function name without parentheses
                                                     array $aArgs,
                                                     bool $fDocumentReady = FALSE)
    {
        $code = $functionName.'(';
        if ($aArgs)
        {
            $cIndent = strlen($functionName) + 1;
            if ($fDocumentReady)
                $cIndent += 4;
            $code .= self::EncodeArgs($aArgs, $cIndent);
        }
        $code .= ');';

        self::AddTypescriptCall($plugin, $code, $fDocumentReady);
    }

    /*
     *  Queue an action to be executed on the client side when the page loads.     *
     *  Every function call results in a JS call.
     *
     *  DO NOT USE IN NEW CODE! This is for the EntryPoint system. Use \ref AddTypescriptCallWithArgs() instead.
     *
     *  @return void
     */
    public static function AddJSAction(string $component,       //!< in: name of the entry point this action is in; plugin name or 'core'
                                       string $action,          //!< in: name of the action to execute
                                       array $args = [],        //!< in: arguments to pass to the action. Will be JSON serialized.
                                       bool $onReady = FALSE)   //!< in: whether this action should be executed immediately or wait for the document to be ready. TRUE is equivalent to \ref AddToDocumentReady() but for actions.
    {
        self::AddPluginChunks($component);
        self::$aJSActions[] = [
            'component' => $component,
            'action' => $action,
            'args' => $args,
            'onReady' => $onReady,
        ];
    }

    private static $aScriptOnceIDs = [];

    /**
     *  Returns TRUE if called for the first time for the given identifier;
     *  returns FALSE on subsequent runs.
     */
    public static function RunOnce(string $id)
        : bool
    {
        if (!isset(self::$aScriptOnceIDs[$id]))
        {
            self::$aScriptOnceIDs[$id] = 1;
            return TRUE;
        }

        return FALSE;
    }

    /**
     *  Returns a string for the "show all details" button for FEAT_JS_FADEOUT_READMORE.
     */
    public static function GetShowAllDetails()
        : string
    {
        return L("{{L//Show all details}}").Format::NBSP.Icon::Get('arrow-down');
    }

    /**
     *  Adds the given icon name to the global g_aDrnIcons array emitted with javascript
     *  code.
     *
     * @return void
     */
    public static function AddIcon($icon)
    {
        if (self::$fCSPFinalized)
            throw new DrnException("Cannot add new icons after CSP has been sent");
        self::$aIconNames[] = $icon;
    }

    /**
     *  Adds the given key/value pairs to the list of NLS strings to be emitted
     *  as JavaScript. These are available as a global g_nlsStrings JS object
     *  with the same keys and translated values in JS code. For example:
     *
     *  ```php
     *      WholePage::AddNLSStrings( [ 'my_id' => L("{{L//Text to be translated}}") ] );
     *  ```
     *
     *  This will add the translation of "Text to be translated" to the global
     *  g_nlsStrings JavaScript variable which is emitted during \ref EmitFooter().
     *  In your JavaScript or TypeScript code, you can then simply use g_nlsStrings['my_id']
     *  to access the translation.
     *
     *  See \ref drn_javascript for context.
     *
     * @return void
     */
    public static function AddNLSStrings($a)        //!< in: array of key/value pairs with values being translated texts
    {
        if (self::$fCSPFinalized)
            throw new DrnException("Cannot add new strings for JS after CSP has been sent");
        self::$aNLSStrings += $a;
    }

    /**
     *  Adds the given legacy script file to the list of script files to be emitted
     *  with the whole page.
     *
     *  This makes sure each file gets loaded only once, so calling this
     *  several times for the same source file causes no significant overhead.
     *
     *  See \ref drn_javascript for context.
     *
     * @return void
     */
    public static function AddScriptFile($file)
    {
        self::$aScriptFiles[$file] = 1;
    }

    /**
     *  Adds the given CSS file to the list of CSS files to be loaded from the page.
     *
     * @return void
     */
    public static function AddStyleSheet($file)
    {
        self::$aStyleFiles[] = $file;
    }

    /**
     *  Convenience function that adds the given bit of JavaScript code to
     *  a special chunk that is added when the document has loaded. All the bits
     *  added this way are emitted in a JQuery on-document-ready block.
     *
     *  For maximum prettiness, the code should come intended with four spaces.
     *
     *  This should be used for code that depends on the page and all libraries
     *  having been fully loaded. By contrast, if you want to just write out
     *  global constants or other code that has no such dependency, use
     *  \ref AddScript() instead.
     *
     *  See \ref drn_javascript for context.
     *
     * @return void
     */
    public static function AddToDocumentReady($str)
    {
        if (self::$fCSPFinalized)
            throw new DrnException("Cannot add inline scripts after CSP has been sent");
        self::$aScriptsDocumentReady[] = $str;
    }

    /**
     *  Allow loading of a resource as a certain type (directive) in the CSP.
     */
    public static function Allow(string $directive,
                                 string $source)
    {
        if (self::$fCSPFinalized)
            throw new DrnException("Cannot allow new sources after CSP has been sent");
        self::$CSP[$directive][] = $source;
    }

    /**
     *  Returns the combined script chunks added with AddScript() as a string.
     *  Called from \ref EmitFooter().
     */
    public static function GetAllScripts()
        : string
    {
        $aNLSStrings = self::$aNLSStrings;
        $aNLSStrings += [
            'error' => L("{{L//Error}}"),
            'close' => L("{{L//Close}}"),
            'openq' => DrnLocale::GetItem(DrnLocale::OPENQUOTE),
            'closeq' => DrnLocale::GetItem(DrnLocale::CLOSEQUOTE),
            'nothingfound' => L("{{L//Nothing found}}"),
            'done' => L('{{L//Done!}}')

        ];
        $str = "\nvar g_nlsStrings = ".json_encode($aNLSStrings, JSON_PRETTY_PRINT).";\n";

        foreach (self::$aScripts as $script)
        {
            $script = str_replace('%ROOTPAGE%', Globals::$rootpage.'/', $script);
            $str .= "$script\n";
        }

        if (count(self::$aScriptsDocumentReady))
        {
            $str .= "\n$(document).ready(function()\n{\n";

            foreach (self::$aScriptsDocumentReady as $docready)
                $str .= "    $docready\n";

            $str .= "});\n";
        }

        return $str;
    }

    /**
     *  Used by BuildMainMenu(). Adds a submenu item or a full menu item.
     *
     * @return void
     */
    public static function AddSubmenu(HTMLChunk $oHTML,
                                      $htmlTitle,               //!< in: title of new menu
                                      $aSubmenu,                //!< in: array of submenu items ( title => action ) or single item with "title|||flyover|||action" where the flyover is optional
                                      $id = NULL,
                                      $extraClasses = '')
    {
        # Insert a submenu.
        if (!is_array($aSubmenu))
        {
            if ($id)
                $id = " id=\"$id\"";
            if (    (!($a2 = explode('|||', $aSubmenu)))
                 || (!($htmlTitle = getArrayItem($a2, 0)))
                 || (!($action = getArrayItem($a2, 2)))
               )
                throw new DrnException("Internal error: invalid menu action item syntax");

            $href = Globals::$rootpage."/$action";

            $htmlFlyover = getArrayItem($a2, 1, '');
            if ("/$action" == WebApp::$command)
                $extraClasses .= ' active';
            $oHTML->addLine("<li$id class=\"$extraClasses\"><a title=\"$htmlFlyover\" href=\"$href\">".L($htmlTitle).'</a></li>');
        }
        else
        {
            $dropdownarrow = '<span class="caret"></span>';
            $oHTML->openListItem($id, "dropdown $extraClasses");
            $oHTML->addLine("<a href=\"#\" class=\"dropdown-toggle\" data-toggle=\"dropdown\" role=\"button\" aria-expanded=\"false\">"
                            .L($htmlTitle)." $dropdownarrow</a>");
            $oHTML->openList(HTMLChunk::LIST_UL, NULL, 'dropdown-menu', 'menu');

            ksort($aSubmenu);
            foreach ($aSubmenu as $action1 => $item2)
            {
                if (preg_match('/^([0-9]+)-(.*)$/', $action1, $aMatches))
                    $action2 = $aMatches[2];
                else
                    $action2 = $action1;

                if ($item2 == "SEPARATOR")
                    $oHTML->addLine("<li class=\"divider\" role=\"separator\"></li>");
                else if ($item2 instanceof HTMLChunk)
                {
                    $oHTML->openListItem();
                    $oHTML->appendChunk($item2);
                    $oHTML->close();
                }
                else
                {
                    $item2 = L($item2);
                    $classes = '';
                    if ("/$action2" == WebApp::$command)
                        $classes .= ' class="active"';
                    $oHTML->addLine("<li$classes><a href=\"".Globals::$rootpage."/$action2\">$item2</a></li>");
                }
            }

            $oHTML->close(); // UL
            $oHTML->close(); // LI
        }
    }

    private static $fShowStandardMenuItems = TRUE;

    /**
     *  Can be called by plugins to suppress all standard menu items for special pages.
     */
    public static function HideStandardMenu()
    {
        self::$fShowStandardMenuItems = FALSE;
    }

    /**
     *  Builds the always visible left menu.
     */
    private static function BuildToolbarLeft(HTMLChunk $oHTML)
    {
        $aAllMenusLeft = [ 'MAILMENU' => [],
                           'NEWTICKETMENU' => [],
                         ];

        if (LoginSession::IsUserLoggedIn())
        {
            $llMaiClientPlugins = Plugins::GetWithCaps(IPlugin::CAPSFL_MAILCLIENT);
            foreach ($llMaiClientPlugins as $oImpl)
            {
                /** @var IMailClientPlugin $oImpl */
                $oImpl->modifyMailMenu($aAllMenusLeft);
            }
        }

        $oHTML->openList(HTMLChunk::LIST_UL, NULL, 'nav navbar-nav drn-navbar-toolbar');

        $i = 0;
        foreach ($aAllMenusLeft as $item => $aSubmenu)
        {
            if ($item == 'MAILMENU')
            {
                if (count($aSubmenu))
                {
                    self::AddSubmenu($oHTML,
                                     Icon::Get('mail'), # '{{L//Mail}}',
                                     Icon::Get('mail').'||||||#',        # empty for now
                                     'drn-mail-menu');
                }
            }
            else if ($item == 'NEWTICKETMENU')
            {
                if (    (LoginSession::IsUserLoggedIn())        # can be NULL during install
                     && (GlobalConfig::$installStatus == GlobalConfig::STATUS99_ALL_GOOD)
                   )
                {
                    $idNewSubmenu = 'drn-new-submenu';
                    self::AddSubmenu($oHTML,
                                     '<span class="visible-xs-inline">'.Icon::Get('plus').'</span><span class="hidden-xs">{{L//New}}</span>',
                                     [],        # empty for now
                                     $idNewSubmenu);
                    /* Lazy-load the "new" menu items when the user clicks on them.
                       This calls the GET /templates REST API. */
                    WholePage::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initNewSubmenu',
                                                         [ $idNewSubmenu ],
                                                         TRUE);
                }
            }
            else
                self::AddSubmenu($oHTML,
                                 $item,
                                 $aSubmenu,
                                 'drn-submenu-'.++$i);
        }

        $oHTML->close(); // UL
    }

    /**
     *  Builds the left side of the collapsable main menu (but not the language or user menu).
     *
     * @return void
     */
    private static function BuildMainMenuLeft(HTMLChunk $oHTML,
                                              $aAllMenusLeft)
    {
        if (count($aAllMenusLeft))
        {
            $oHTML->openList(HTMLChunk::LIST_UL, NULL, 'nav navbar-nav');

            $i = 0;
            foreach ($aAllMenusLeft as $item => $aSubmenu)
            {
                self::AddSubmenu($oHTML,
                                 $item,
                                 $aSubmenu,
                                 'drn-submenu-'.++$i);
            }

            $oHTML->close(); // UL
        }
    }

    /**
     *  Used by EmitHeader().
     *
     *  This inspects the FEAT_JS_AUTOFOCUS_SEARCH_BAR flag for whether to add autofocus=yes to the bar.
     *
     * @return void
     */
    private static function AppendSearchBar(HTMLChunk $oHTML)
    {
        $oHTML->openForm(NULL,
                         'navbar-form navbar-left',
                         [ 'method' => 'GET',
                           'action' => Globals::$rootpage.'/tickets',
                           'role' => 'search' ] );
        $oHTML->openDiv(NULL, 'form-group');
        $oHTML->openDiv(NULL, 'input-group');
        $aInput = [ 'type' => 'search',
                   'name' => 'fulltext',
                   'id' => 'drn-search-bar',
                   'class' => 'form-control',
                   'value' => WebApp::FetchParam('fulltext',
                                                 FALSE),
                   'placeholder' => L("{{L//Search}}"),
                   'size' => 50 ];
        if (self::IsEnabled(self::FEAT_JS_AUTOFOCUS_SEARCH_BAR))
            $aInput['autofocus'] = 'yes';
        $oHTML->appendElement('input',
                              $aInput);

        // Remember the current format
        if ($format = WebApp::FetchParam('format', FALSE))
            $oHTML->addLine('<input type="hidden" name="format" value="'.toHTML($format).'">');

        $oHTML->appendElement('span',
                              [ 'class' => 'input-group-btn' ],
                              HTMLChunk::MakeElement('button',
                                                     [ 'type' => 'submit',
                                                       'class' => 'btn btn-default' ],
                                                     Icon::GetH('search')));
        $oHTML->close(); // .input-group
        $oHTML->appendChunk(HTMLChunk::MakeHelpLink('searchbar', 'navbar-link hidden-xs'));
        $oHTML->close(); // .form-group
        $oHTML->close(); // form

        self::Enable(self::FEAT_JS_AUTOCOMPLETESEARCH);
    }

    const MENU_NARROW_ONLY = '~';
    const MENU_WIDE_ONLY = '*';

    /**
     * Builds the right main menu and allows plugins to modify it.
     *
     * @return void
     */
    private static function BuildMainMenuRight(HTMLChunk $oHTML)
    {
        if (!GlobalConfig::$installStatus)
            return;

        $returnTo = toHTML(Globals::GetRequestOnly());
        $aMenu = [];

        /*
         * Guru menu
         */
        if (LoginSession::IsCurrentUserAdminOrGuru())
            $aMenu['GURUMENU'] = [ '100-settings' => Icon::Get('cog', TRUE)." {{L//System settings}}".Format::HELLIP,
                                   '101' => "SEPARATOR",
                                   '200-users'    => Icon::Get('user', TRUE)." {{L//Users and groups}}".Format::HELLIP
                                 ];

        // Let plugins modify the menu. The language and user menu are added afterward, so they are always at the end.
        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_MAINMENU) as $oImpl)
        {
            /** @var IMainMenuPlugin $oImpl */
            $oImpl->modifyMainMenuRight($aMenu);
        }

        // Rename GURUMENU pseudoentry to localized value and preserve its position.
        if (array_key_exists('GURUMENU', $aMenu))
        {
            $offset = 0;
            foreach ($aMenu as $m => $content)
            {
                if ($m === 'GURUMENU')
                    break;
                else
                    ++$offset;
            }
            $aMenu = array_slice($aMenu, 0, $offset, TRUE)
                     + [ Icon::Get('cog').'<span class="visible-xs-inline">'.Format::NBSP.'{{L//Administration}}</span>' => $aMenu['GURUMENU'] ]
                     + array_slice($aMenu, $offset + 1, NULL, TRUE);
        }

        /*
         * Language
         */
        $currentLocale = DrnLocale::Get();
        $currentLocaleMajor = DrnLocale::Get(TRUE);        # major

        $aLocales = DrnLocale::A_LOCALES;
        $fCompactList = count($aLocales) < 6;

        $localesIndex = ($fCompactList ? self::MENU_WIDE_ONLY : '').$currentLocaleMajor;
        foreach ($aLocales as $locale => $a2)
        {
            $name = $a2[0];
            $htmlName = str_replace('_', '-', $locale);
            $check = '<div style="float: left; width: 25px;">'.(($locale == $currentLocale) ? Icon::Get('check') : Format::NBSP).'</div>';
            $aMenu[$localesIndex]['lang/'.$locale.'/'.$returnTo.'" rel="alternate" hreflang="'.$htmlName] = $check.Format::NBSP.$name;
        }

        if ($fCompactList)
        {
            $oCompactLocales = new HTMLChunk();
            $oCompactLocales->openDiv(NULL, 'text-center');
            $idBtnGroup = 'drn-lang-group';
            $oCompactLocales->addLabel(HTMLChunk::FromString(L('{{L//Language:}}')), $idBtnGroup, 'sr-only');
            $oCompactLocales->openDiv($idBtnGroup, 'btn-group');
            $rootpage = Globals::$rootpage;
            ksort($aLocales);
            foreach ($aLocales as $locale => $a2)
            {
                $htmlName = str_replace('_', '-', $locale);
                $majorName = strtoupper(substr($locale, 0, 2));
                $current = $locale == $currentLocale ? ' active' : '';
                $oCompactLocales->addLine("<a href=\"$rootpage/lang/$locale/$returnTo\" rel=\"alternate\" hreflang=\"$htmlName\" class=\"btn btn-default navbar-btn$current\">$majorName</a>");
            }
            $oCompactLocales->close(); // btn-group
            $oCompactLocales->close(); // li
            $aMenu[self::MENU_NARROW_ONLY.'LANGUAGES'] = $oCompactLocales;
        }

        /*
         *  User (login form or currently logged in user)
         */
        $ouserImpersonator = NULL;
        $aUserMenu = [];
        $editicon = Icon::Get('edit', TRUE); # menu

        if (LoginSession::IsUserLoggedIn())
        {
            $fCloseSpan = FALSE;
            if ($ouserImpersonator = LoginSession::GetImpersonator())
            {
                $htmlIconUser = "<span class='text-warning'>".Icon::Get('user-secret', TRUE);
                $fCloseSpan = TRUE;
            }
            else if (LoginSession::IsCurrentUserAdmin())
            {
                $htmlIconUser = "<span class='text-danger'>".Icon::Get('user-secret', TRUE);
                $fCloseSpan = TRUE;
            }
            else
                $htmlIconUser = Icon::Get('user', TRUE);

            $htmlCurrentUser0 = $htmlIconUser.Format::NBSP.toHTML(LoginSession::$ouserCurrent->longname);

            if ($fCloseSpan)
                $htmlCurrentUser0 .= "</span>";

            $powerofficon = Icon::Get('poweroff', TRUE);
            $idImpersonateMenuItem = 'mainmenu-impersonate';

            if ($ouserImpersonator)
            {
                $iconImpersonate = Icon::Get('user', TRUE);
                $nlsImpersonate = L("{{L//Stop impersonating, return to %USER%}}", [ '%USER%' => $ouserImpersonator->longname]);
                $impersonate = '<span class="text-danger">'.$iconImpersonate.Format::NBSP.Format::NBSP.$nlsImpersonate.'</span>';
                $aUserMenu += [ '100-'.$returnTo.'#" id="'.$idImpersonateMenuItem => $impersonate ];
                $aUserMenu['101'] = 'SEPARATOR';
                self::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */'core_initStopImpersonating',
                                                [ $idImpersonateMenuItem ], TRUE);
            }
            /* Add "Impersonate" for admins. */
            else if (LoginSession::IsCurrentUserAdmin())
            {
                $iconImpersonate = Icon::Get('user', TRUE);
                $impersonate = '<span class="text-danger">'.$iconImpersonate.Format::NBSP.Format::NBSP.'{{L//Impersonate}}'.Format::HELLIP.'</span>';
                $aUserMenu += [ '100-'.$returnTo.'#impersonateDialog" id="'.$idImpersonateMenuItem => $impersonate ];
                $aUserMenu['101'] = 'SEPARATOR';
                self::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initImpersonate',
                                                [ $idImpersonateMenuItem ], TRUE);
                self::LoadXMLDialog(dirname(__FILE__).'/view_impersonate/view_impersonate.xml');
            }

            /* Other items for all logged-in users. */
            $myaccount = $editicon.Format::NBSP.Format::NBSP.'{{L//Manage my user account}}'.Format::HELLIP;
            $signout = $powerofficon.Format::NBSP.Format::NBSP.'{{L//Sign out}}';
            $aUserMenu += [ '102-myaccount' => $myaccount,
                            '998' => 'SEPARATOR',
                            '999-logout' => $signout,
                          ];
        }
        else
        {
            # Not logged in:
            $htmlCurrentUser0 = Format::HtmlPill(L("{{L//Not signed in}}"), 'bg-danger');

            $passicon = Icon::Get('password', TRUE); # menu
            $gobackto = Globals::GetRequestOnly();

            $oLoginForm = new HTMLChunk();
            $oLoginForm->openDiv(NULL,
                            'container-fluid',
                            NULL,
                            'div',
                            [ 'style' => 'margin-left: 13px; margin-right: 13px; margin-top: 10px; margin-bottom: 10px;' ] );
            $oLoginForm->openForm(NULL, 'form-horizontal', 'method="post"');

            $oLoginForm->openDiv(NULL, 'form-group');
            $capitalizeAttrs = '';
            if (Globals::$fCapitalizeLogins)
                $capitalizeAttrs = ' autocapitalize="characters"';

            $oLoginForm->addLine(L("<input name=\"login\" id=\"drn-mainbar-login\" type=\"text\" class=\"form-control\" placeholder=\"{{L//Login name}}\" required autofocus autocorrect=\"off\" autocapitalize=\"off\" autocomplete=\"nickname\"$capitalizeAttrs>"));
            $oLoginForm->close(); // DIV form-group

            $oLoginForm->openDiv(NULL, 'form-group');
            $oLoginForm->addLine(L("<input name=\"password\" type=\"password\" class=\"form-control\" placeholder=\"{{L//Password}}\" required autocomplete=\"current-password\">"));
            $oLoginForm->close(); // DIV form-group

            $oLoginForm->openDiv(NULL, 'form-group');
            $oLoginForm->addLine(L("<div class=\"checkbox\"><label><input name=\"cookie\" value=\"yes\" type=\"checkbox\"> {{L//Remember me}}</label></div>"));
            $oLoginForm->close(); // DIV form-group

            $oLoginForm->addLine("<input type=\"hidden\" name=\"gobackto\" value=\"$gobackto\">");

            $oLoginForm->addLine(L("<button class=\"btn btn-lg btn-primary btn-block\" type=\"submit\">{{L//Sign in}}</button>"));

            $oLoginForm->close(); // form
            $oLoginForm->close(); // DIV

            $aUserMenu['100'] = $oLoginForm;
//            $aMenu['101-register'] = $editicon.Format::NBSP.Format::NBSP.'{{L//Register}}'.Format::HELLIP;

            $aUserMenu['200-lostpass'] = $passicon.Format::NBSP.Format::NBSP.'{{L//Lost password?}}';

            if (Globals::$fCapitalizeLogins)
                self::AddJSAction('core', 'upperCaseEntryField', [ 'drn-mainbar-login' ], TRUE);
        }

        $aMenu[self::MENU_WIDE_ONLY.$htmlCurrentUser0] = $aUserMenu;

        // Mobile login/user management
        if (LoginSession::IsUserLoggedIn())
        {
            if (isset($impersonate))
            {
                $idImpersonateMenu2 = $idImpersonateMenuItem.'-mobile';
                if ($ouserImpersonator)
                {
                    $nlsTitle = L('{{L//Stop impersonating %USER%}}', [
                        '%USER%' => LoginSession::$ouserCurrent->longname,
                    ]);
                    $aMenu[self::MENU_NARROW_ONLY.'STOP-IMPERSONATE#'.$idImpersonateMenu2] = "<span class=\"text-danger\">$iconImpersonate $nlsTitle</span>|||$nlsImpersonate|||".$returnTo.'#impersonateDialog';
                    self::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */'core_initStopImpersonating',
                                                    [ $idImpersonateMenu2 ], TRUE);
                }
                else
                {
                    $aMenu[self::MENU_NARROW_ONLY.'IMPERSONATE#'.$idImpersonateMenu2] = $impersonate.'||||||'.$returnTo.'#impersonateDialog';
                    self::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_initImpersonate',
                                                    [ $idImpersonateMenu2 ], TRUE);
                }
            }
            $aMenu[self::MENU_NARROW_ONLY.'MY-ACCOUNT'] = $myaccount.'||||||myaccount';
            $aMenu[self::MENU_NARROW_ONLY.'LOGOUT'] = $signout.'||||||logout';
        }
        else
        {
            $idLogin = 'login-modal';
            $aMenu[self::MENU_NARROW_ONLY.'LOGIN'] = '{{L//Sign in}}'.Format::HELLIP.'||||||#'.$idLogin.'" data-toggle="modal"';
            // Add login modal
            $oModal = new HTMLChunk();
            $oModal->openModal($idLogin, L('{{L//Sign in}}'), TRUE);
            $oModal->appendChunk($oLoginForm);
            $oLostPass = Icon::GetH('password', TRUE);
            $oLostPass->append(Format::NBSP.Format::NBSP);
            $oLostPass->appendChunk(HTMLChunk::FromString(L('{{L//Lost password?}}')));
            $oModal->appendChunk(HTMLChunk::MakeLink(Globals::$rootpage.'/lostpass', $oLostPass));
            $oModal->close(); # modal-body
            $oModal->close(); # modal-content
            $oModal->close(); # modal-dialog
            $oModal->close(); # modal
            self::AddHTMLChunk($oModal);
        }

        /*
         * Output the actual menu
         */

        $oHTML->openList(HTMLChunk::LIST_UL, NULL, "nav navbar-nav navbar-right");
        foreach ($aMenu as $item => $content)
        {
            $aMatches = [];
            preg_match('/^([~*]?)([^#]+)(?:#(.+))?$/', $item, $aMatches);

            $extraClasses = '';
            if ($aMatches[1] == self::MENU_NARROW_ONLY)
                $extraClasses = 'visible-xs';
            else if ($aMatches[1] == self::MENU_WIDE_ONLY)
                $extraClasses = 'hidden-xs';

            $id = getArrayItem($aMatches, 3);
            $name = $aMatches[2];

            if ($content instanceof HTMLChunk)
            {
                $oHTML->openListItem($id, $extraClasses);
                $oHTML->appendChunk($content);
                $oHTML->close();
            }
            else
                self::AddSubmenu($oHTML, $name, $content, $id, $extraClasses);
        }
        $oHTML->close(); // UL
    }

    /**
     *  Constructs the HTML for the Doreen header and echoes it to the user. Normally called from \ref Emit().
     *
     *  The Doreen header consists of the HTML header and the Bootstrap navbar for the main menu. The menu
     *  contents are variable, and parts of it are lazily constructed on demand via JavaScript. The menu
     *  also contains the language and user profile entries.
     *
     * @return void
     */
    public static function EmitHeader(string $title0 = NULL,
                                      bool $fDoreenNameInBrowserTitle = TRUE)
    {
        # myDie() calls this explicitly, and maybe in that case the header may already have
        # been printed, so don't do this twice.
        if (self::$fl & self::WHOLEPAGEFL_HEADER_EMITTED)
            return;

        $fNavbarFixedToTop = GlobalConfig::IsTopNavbarFixedEnabled();

        if (!$fDoreenNameInBrowserTitle && $title0)
            $title = $title0;
        else
            $title = (($title0) ? "$title0 &mdash; " : '').Globals::$doreenName;

        $oHTML = new HTMLChunk(2);
        $oHTML->addAnchor('top');
        $oHTML->openNAV($fNavbarFixedToTop);

        $idCollapse1 = 'drn-navbar-collapse1';

        $oHTML->openDiv(NULL, 'container-fluid');
        $oHTML->openDiv(NULL, 'navbar-header');
        $oHTML->addLine('<button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#'.$idCollapse1.'">');
        $oHTML->append('        <span class="sr-only">Toggle navigation</span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>'."\n");
        $oHTML->addLine('</button>');

        $brand = Globals::$doreenName;
        if (Globals::$doreenWordmark)
            $brand = '<img src="'.Globals::$rootpage.'/'.Globals::$doreenWordmark.'" alt="'.$brand.'" height="34">';
        $oHTML->addLine('<a class="navbar-brand" href="'.Globals::$rootpage.'/">'.$brand.'</a>');

        $aAllMenusLeft = [];

        # Now go through all plugins to allow them to change the menu.
        foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_MAINMENU) as $oImpl)
        {
            /** @var IMainMenuPlugin $oImpl */
            $oImpl->modifyMainMenu($aAllMenusLeft);
        }

        if (self::$fShowStandardMenuItems)
            self::BuildToolbarLeft($oHTML);

        $oHTML->close(); // .navbar-header

        $oHTML->openDiv($idCollapse1, 'collapse navbar-collapse');

        self::BuildMainMenuLeft($oHTML, $aAllMenusLeft);

        if (    (GlobalConfig::$installStatus == GlobalConfig::STATUS99_ALL_GOOD)
             && (    LoginSession::IsUserLoggedIn()
                  || (!GlobalConfig::Get(GlobalConfig::KEY_SEARCH_REQUIRES_LOGIN))
                )
             && (self::$fShowStandardMenuItems)
           )
            self::AppendSearchBar($oHTML);

        if (self::$fShowStandardMenuItems)
            if (LoginSession::HasCookieConsent())
                self::BuildMainMenuRight($oHTML);

        $oHTML->close(); // DIV
        $oHTML->close(); // DIV

        $oHTML->close(); // NAV

        $oHTML->addLine("<!-- end of Doreen header -->");

        if (!LoginSession::HasCookieConsent())
        {
            $htmlCookie = '<b>'.L(<<<HTML
{{L/COOKIECONSENT/This website uses cookies. For you to be able to use all the features of this website, we need your permission to set cookies in your browser.}}
HTML
            ).'</b>';
            if (WholePage::$hrefCookieGuidelines)
                $htmlCookie .= " <a href='".WebApp::MakeUrl(WholePage::$hrefCookieGuidelines)."'>".L("{{L//More information...}}")."</a>";

            $idCookiesAlert = 'cookies-alert';
            $rootpage = Globals::$rootpage;
            $currentPage = Globals::GetRequestOnly();
            $htmlCookie .= " <form action='$rootpage/cookies-ok'><button type='submit' class='btn btn-primary'>OK!</button><input type='hidden' name='from' value='$currentPage'></form>";

            $oHTML->addAlert($htmlCookie,
                             $idCookiesAlert,
                             'alert-danger');
            if (!self::$fShowStandardMenuItems)
                self::AddJSAction('core', 'handleAcceptCookies', [ $idCookiesAlert ], TRUE);
        }

        $htmlHeader = $oHTML->html;

//        if (self::IsEnabled(self::FEAT_CSS_ANIMATIONS))
//            self::$aStyleFiles[] = '3rdparty/css/animate.css';

        if (self::IsEnabled(self::FEAT_JS_JAVASCRIPT_VISJS))
            self::$aStyleFiles[] = 'css/vis.css';
        if (self::IsEnabled(self::FEAT_JS_SUPERTABLE))
            self::$aStyleFiles[] = 'css/bootstrap-table.css';
        if (self::IsEnabled(self::FEAT_JS_BOOTSTRAP_MULTISELECT))
            self::$aStyleFiles[] = 'css/bootstrap-multiselect.css';
        if (self::IsEnabled(self::FEAT_JS_GALLERY))
            self::$aStyleFiles[] = 'css/bootstrap-photo-gallery.css';
        if (self::IsEnabled(self::FEAT_JS_EDITOR_TRIX))
            self::$aStyleFiles[] = 'css/trix.css';
        if (self::IsEnabled(self::FEAT_JS_DATETIMEPICKER))
            self::$aStyleFiles[] = 'css/bootstrap-datetimepicker.css';
        if (self::IsEnabled(self::FEAT_JS_SELECT2))
            self::$aStyleFiles[] = 'css/select2.css';
        if (self::IsEnabled(self::FEAT_JS_BOOTSTRAP_SLIDER))
            self::$aStyleFiles[] = 'css/bootstrap-slider.css';

        $stylefiles = '';
        $aStyleFiles = [  'css/theme-'.self::GetTheme().'.css',
                          'css/bundle.css'
                       ];
        foreach (array_merge($aStyleFiles, self::$aStyleFiles)  as $file)
            $stylefiles .= "\n  <link rel=\"stylesheet\" href=\"".Globals::$rootpage."/$file\">";

        $morecss = <<<EOD

  <style type="text/css"><!--
EOD;

    if (self::IsEnabled(self::FEAT_CSS_FULLWIDTHBAR))
        # See https://css-tricks.com/full-browser-width-bars/.
        $morecss .= <<<CSS

html, body {
  overflow-x: hidden;
  /* changing the overflow behavior breaks the box sizing for vertically overflowing items */
  min-height: 100%;
  min-height: 100vh;
}
CSS;

    if ($fNavbarFixedToTop)
        # See https://getbootstrap.com/docs/3.3/components/#navbar
        $morecss .= <<<CSS

body { padding-top: 70px; }
CSS;

    if (self::$additionalCSS)
        $morecss .= "\n".self::$additionalCSS."\n";

    $morecss .= "\n--></style>";

    $lang = str_replace('_', '-', DrnLocale::Get());

    $links = "\n\n";

    if (isset(Globals::$doreenLogo))
    {
        $links .= "  <link rel=\"shortcut icon\" sizes=\"16x16\" href=\"".Globals::$doreenLogo.".ico\" type=\"image/x-icon\">\n";
        foreach (Globals::$doreenLogoSizes as $size)
            $links .= "  <link rel=\"icon\" sizes=\"${size}x$size\" href=\"".Globals::$rootpage."/".Globals::$doreenLogo."-$size.png\" type=\"image/png\">\n";
    }


    //TODO need to add domain to hrefs.
    foreach (DrnLocale::A_LOCALES as $locale => $stuff)
    {
        $linkType = 'alternate';
        if ($locale === DrnLocale::Get())
            $linkType = 'canonical';

        $links .= "  <link rel=\"$linkType\" hreflang=\"".str_replace('_', '-', $locale)."\" href=\"".Globals::$rootpage."/lang/$locale".WebApp::$command."\">\n";
    }

    self::$fl |= self::WHOLEPAGEFL_HEADER_EMITTED;

    //TODO this shouldn't be in here but menu building adds document ready stuff.
    self::PrepareScripts();
    $csp = self::BuildCSP();
    header('Content-Security-Policy: '.$csp->emit());
    header('X-Frame-Options: '.$csp->emitFrameOptions());

    echo <<<HTML
<!DOCTYPE html>
<html lang="$lang">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>$title</title>$links$stylefiles$morecss
</head>
<body>
$htmlHeader
  <noscript>
  <div class="alert alert-danger" role="alert"><b>JavaScript is disabled!</b> &mdash; Very little of this site will work without JavaScript, so please enable it in your browser settings.</div>
  </noscript>


HTML;
    }

    /**
     *  Gets the inline script for the end of the page. Should be called as late
     *  as possible, to obtain identical results.
     *
     *  @return string
     */
    private static function GetFinalScript()
        : string
    {
        $rootpage = Globals::$rootpage;
        $cDecimal = DrnLocale::GetItem(DrnLocale::DECIMAL);
        $locale = DrnLocale::Get();
        $adminLevel = LoginSession::IsCurrentUserAdminOrGuru(); # returns 0, 1 or 2
        if (!$adminLevel)
            $adminLevel = 0;
        $spinner = Globals::CSS_SPINNER_CLASS;
        $serializedActions = '';
        if(count(self::$aJSActions)) {
            $serializedActions = "window.drnEntryPoint.action('system', 'register', ".json_encode(self::$aJSActions, JSON_PRETTY_PRINT).");";
        }

        $start = <<<JS
"use strict";

var g_rootpage = "$rootpage";
var g_adminLevel = $adminLevel;
g_globals.cDecimal = '$cDecimal';
g_globals.locale = '$locale';
g_globals.cssSpinnerClass = "$spinner";

JS;
        return $start.WholePage::GetAllScripts()."\n".
        $serializedActions."\n";
    }

    /**
     *  This should be run once before the first usage of GetAllScripts.
     */
    private static function PrepareScripts()
    {
        if (self::IsEnabled(self::FEAT_JS_DATETIMEPICKER))
        {
            $icons = json_encode( [ 'time' => "fa fa-clock-o",
                                    'date' => "fa fa-calendar",
                                    'up' => "fa fa-arrow-up",
                                    'down' => "fa fa-arrow-down",
                                    'previous' => 'fa fa-chevron-left',
                                    'next' => 'fa fa-chevron-right',
                                    'today' => 'fa fa-hand-o-down',
                                    'clear' => 'fa fa-trash',
                                    'close' => 'fa fa-remove'] );
            $tooltips = json_encode( [ 'today' => L('{{L//Go to today}}'),
                                       'clear' => L('{{L//Clear selection}}'),
                                       'close' => L('{{L//Close}}'),
                                       'selectMonth' => L('{{L//Select month}}'),
                                       'prevMonth' => L('{{L//Previous month}}'),
                                       'nextMonth' => L('{{L//Next month}}'),
                                       'selectYear' => L('{{L//Select year}}'),
                                       'prevYear' => L('{{L//Previous year}}'),
                                       'nextYear' => L('{{L//Next year}}'),
                                     ] );

            WholePage::AddScript(<<<JS
var g_dateTimePickerIcons = $icons;
var g_dateTimePickerTooltips = $tooltips;
JS
            , TRUE);        // Definitions must come first.
        }

        if (self::IsEnabled(self::FEAT_JS_COPYTOCLIPBOARD))
        {
            self::AddNLSStrings( [ 'clipboard' => HTMLChunk::GetNLSCopyToClipboard() ] );
            self::AddIcon('clipboard');
            // If HTMLChunk added any clipboard buttons, initialize them.
            if (HTMLChunk::$lastClipboardButtonID)
                self::AddJSAction('core', 'clipboard', [ HTMLChunk::CLASS_COPY_CLIPBOARD_BACKEND ]);
        }
        if (self::IsEnabled(self::FEAT_JS_BOOTSTRAP_TOOLTIPS))
        {
            self::AddToDocumentReady(<<<JS
$('[data-toggle=\"tooltip\"]').tooltip();
JS
            );
        }
        if (self::IsEnabled(self::FEAT_JS_BOOTSTRAP_POPOVERS))
        {
            self::AddToDocumentReady(<<<JS
$('[data-toggle=\"popover\"]').popover();
JS
            );
        }
        if (self::IsEnabled(self::FEAT_JS_HIDEWHENDONE))
        {
            self::AddToDocumentReady(<<<JS
$(".drn-hide-when-done").addClass("hidden");
JS
            );
        }
        if (self::IsEnabled(WholePage::FEAT_JS_DRN_SHOW_HIDE))
            self::AddJSAction('core', 'initShowHide', [], TRUE);

        if (self::IsEnabled(self::FEAT_JS_AUTOCOMPLETESEARCH))
            self::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */'core_enableAutoComplete',
                                            [ 'drn-search-bar' ]);

        if (self::IsEnabled(self::FEAT_JS_FADEOUT_READMORE))
            self::AddJSAction('core', 'initFadeOutReadMores', [], TRUE);

        if (self::$fShowBackToTopInFooter)
        {
            self::AddIcon('arrow-up');
            self::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */'core_addBackToTop',
                                            [], TRUE);
        }

        if (LoginSession::IsUserLoggedIn())
        {
            $lifetime = LoginSession::GetSessionLifetime();
            self::AddTypescriptCallWithArgs(NULL, /** @lang JavaScript */ 'core_keepSessionAlive',
                                            [ $lifetime ]);

            self::LoadXMLDialog(dirname(__FILE__).'/view_expiredsession/view_expiredsession.xml');
        }

        $aIcons = [];
        # big icons
        foreach (self::$aIconNames as $i)
            if (!isset($aIcons[$i]))
                $aIcons[$i] = Icon::Get($i);
        # small icons
        foreach (array( 'sort_amount_asc', 'sort_amount_desc', 'sort_alpha_asc', 'sort_alpha_desc', 'menu') as $i)
            $aIcons[$i] = Icon::Get($i, TRUE);

        WholePage::AddScript("var g_aDrnIcons = ".json_encode($aIcons, JSON_PRETTY_PRINT), TRUE);
    }

    private static $aFooter = NULL;
    private static $fShowBackToTopInFooter = TRUE;
    private static $fDoShowFooterItems = TRUE;

    /**
     *  To be called by plugins if they would like the Doreen pages to have a footer.
     *  Argument must be an array for \ref HTMLChunk::addFooter().
     */
    public static function SetFooterItems(array $a)
    {
        self::$aFooter = $a;
    }

    /**
     *  Can be called by certain page emitters, like admin pages, that do not want plugins to emit the footer
     *  items.
     */
    public static function SilenceFooter()
    {
        self::$fDoShowFooterItems = FALSE;
    }

    /**
     *  Can be called by plugins to explicitly hide the "back to top" link at the bottom of the footer.
     */
    public static function HideBackToTopInFooter()
    {
        self::$fShowBackToTopInFooter = FALSE;
    }

    /**
     *  Constructs the HTML for the Doreen footer and echoes it to the user. Normally called from \ref Emit().
     *
     *  The Doreen footer is everything under the main page DIV. That includes all the script tags with JS and TS
     *  files that have been configured to load, plus explicit JS code including the document-ready code that
     *  has been added.
     *
     * @return void
     */
    public static function EmitFooter()
    {
        $str = '';

        if (self::$aFooter && self::$fDoShowFooterItems)
        {
            $oHTML = new HTMLChunk();
            // HTMLChunk::openPage() remembers whether the last container was fluid or not.
            $oHTML->openContainer(HTMLChunk::$fLastPageContainerWasFluid);
            $oHTML->addFooter(self::$aFooter,
                              self::$fShowBackToTopInFooter);
            $oHTML->close();

            $str = $oHTML->html;
        }

        $rootpage = Globals::$rootpage;

        # Append the template that gets used by core_enableAutoComplete().
        $str .= <<<HTML
  <script type="text/template" id="drn-autocomplete-template">
    <ul class="list-group">
      <div class="drn-dataset-d1"></div>
    </ul>
  </script>

  <script src="$rootpage/js/mainbundle.js"></script>
HTML;

        $aScriptFiles2 = [];
        if (self::IsEnabled(self::FEAT_JS_SUPERTABLE))
            $aScriptFiles2[] = 'js/bootstrap-table.js';
        if (self::IsEnabled(self::FEAT_JS_BOOTSTRAP_MULTISELECT))
            $aScriptFiles2[] = 'js/bootstrap-multiselect.js';
        if (self::IsEnabled(self::FEAT_JS_GALLERY))
            $aScriptFiles2[] = 'js/bootstrap-photo-gallery.js';
        if (self::IsEnabled(self::FEAT_JS_EDITOR_TRIX))
            $aScriptFiles2[] = 'js/trix.js';
        if (self::IsEnabled(self::FEAT_JS_EDITOR_WYSIHTML))
            $aScriptFiles2[] = 'js/wysihtml.js';
        if (self::IsEnabled(self::FEAT_JS_SELECT2))
            $aScriptFiles2[] = 'js/select2.js';

        // Always load core chunk
        self::AddPluginChunks('core');

        foreach (array_keys(self::$aScriptFiles) as $file2)
            $aScriptFiles2[] = $file2;

        foreach ($aScriptFiles2 as $scriptfile)
            $str .= "\n  <script src=\"$rootpage/$scriptfile\"></script>";

        echo $str;

        foreach (self::$aXMLDialogStrings as $dlg)
            echo "\n".$dlg;

        foreach (self::$llHTMLChunks as $oHTML)
            echo "\n".$oHTML->html;

        $globalScript = self::GetFinalScript();

        # Add a hidden anchor so that we can detect the CSS link color from JavaScript.
        echo <<<EOD

  <div class="hidden"><a id="drn-testlink-for-css" href="#">Test</a></div>

  <div class="modal fade" tabindex="-1" role="dialog" id="drn-message-box">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          <h4 class="modal-title">TBR</h4>
        </div>
        <div class="modal-body">
        TBR
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default btn-primary" data-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script type="text/javascript">$globalScript</script>
</body>
</html>

EOD;
    }

    /**
     *  Called from \ref EmitHeader() when generating the content security policy,
     *  which gets emitted with the Content-Security-Policy HTTP header.
     *  See ContentSecurityPolicy for details.
     */
    private static function BuildCSP()
        : ContentSecurityPolicy
    {
        $csp = new ContentSecurityPolicy();
        // Allow the inline script that we use as entry point to be executed.
        $csp->addSourceHash(ContentSecurityPolicy::DIRECTIVE_SCRIPT,
                            self::GetFinalScript());
        // Allow image upload previews for dropzone, which are data: encoded.
        if (self::$fl & self::FEAT_JS_DROPZONE)
            self::$CSP[ContentSecurityPolicy::DIRECTIVE_IMG][] = 'data:';
        foreach (self::$CSP as $directive => $sources)
        {
            foreach ($sources as $source)
                $csp->allow($directive, $source);
        }
        self::$fCSPFinalized = TRUE;
        return $csp;
    }

    /**
     *  Takes $htmlPage, wraps it in the header and footer and sends it to the user.
     *
     *  Typically used with an instance of HTMLChunk to build the HTML for the page
     *  first (so that the page title appears both in the header and the top heading),
     *  like so:
     *
     *  ```php
     *      $htmlTitle = "My title";
     *      $oPage = new HTMLChunk();
     *      $oPage->openPage($htmlTitle);
     *      ...
     *      $oPage->close();    # page
     *      WholePage::Emit($htmlTitle, $oPage);
     *  ```
     *
     *  This is only needed when writing a complete GUI request handler, like
     *  for the /ticket/123 page. This does get called on every such GUI request
     *  one way or the other, but typically you will want to add functionality
     *  by filling in some FieldHandler method instead.
     *
     * @return void
     */
    public static function Emit(string $htmlTitle = NULL,     //!< in: title for HTML <title> or NULL or ''
                                HTMLChunk $oPage,
                                bool $fDoreenNameInBrowserTitle = TRUE)
    {
        // Don't send Referrer information to other origins in modern browsers.
        header('Referrer-Policy: same-origin');
        // Inform reciever of intended target language of content.
        header('Content-Language: '.str_replace('_', '-', DrnLocale::Get()));
        self::EmitHeader($htmlTitle,
                         $fDoreenNameInBrowserTitle);
        echo $oPage->html;
        self::EmitFooter();
    }

    /**
     *  Emits headers to announce delivery of a binary file, as opposed to plain-text HTML as usual.
     *  This is to be used instead of the other Emit* functions.
     *
     *  After this, simply echo() the binary data, or use readfile() or something similar.
     *  Best use \ref Binary::emitAndExit() instead, which calls this in turn.
     *
     * @return void
     */
    static function EmitBinaryHeaders($filename,
                                      $mime,
                                      $filesize,
                                      $ticket_id = NULL)
    {
        # Here is how to dump a binary file to the duser via PHP:
        # 1) set the HTTP headers like this:
        header("Content-Length: $filesize");
        header("Content-Type: $mime");
        header("Content-Disposition: inline; filename=\"$filename\"");
        # 'attachment' forces a save-as dialog;
        # 'inline' will attempt to display the file in the browser, unless the browser doesn't know what to do with it
        header('Content-Transfer-Encoding: binary');
        header('Cache-control: private'); // xTracker code says: another fix for old IE; without this, the save as dialog displays no filename

        if ($ticket_id)
            header("Content-Description: Attachment to ticket $ticket_id");
    }
}
