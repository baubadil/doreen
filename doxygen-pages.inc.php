<?php

/**
 *  \mainpage Welcome to Doreen!
 *
 *  Doreen is a universal ticket system with plugins that can track and organize almost anything and make it
 *  searchable at lightning speed.
 *
 *  The core system without plugins already supports the following types of data:
 *
 *   *  Tasks, for project management.
 *
 *   *  Wiki pages, to document FAQs and similar things like other Wiki managers.
 *
 *  Plugins for Doreen can be found in the `src/plugins` directory. Doreen already ships with the following plugins:
 *
 *   *  type_ftdb, a plugin for the fischertechnik database. This is behind the ft-datenbank.de website; see PluginFTDB.
 *
 *   *  type_office, a plugin for office management, providing contact, task and file management; see PluginOffice.
 *
 *   *  type_school, a plugin for sending out school newsletters and managing related parent contacts; see PluginSchool.
 *
 *   *  type_vcard, providing ticket types for VCard contacts; see VCardTicket.
 *
 *  Doreen organizes most data into tickets. At the minimum, every ticket has a unique number (unique in the database
 *  that Doreen uses), an access control list that determines which user groups can create, read, update and delete
 *  tickets, and probably a title and more text, but even that is not required.
 *
 *  Doreen has a number of features that make it stand out among the many ticket systems that already exist:
 *
 *  <ol><li>Doreen uses the concept of "ticket types" to organize at run-time which fields are visible for tickets.
 *      A ticket type contains a list of ticket fields (e.g. "title", "description", "priority" etc.), and all tickets
 *      of that type will accept and display such data. This is not hard-coded, but can be configured by an
 *      administrator.
 *
 *      Doreen uses many tables in the actual database, but if you are familiar with relational databases in general,
 *      it may help to think of Doreen as one big table, wherein tickets are the rows and the ticket fields are the
 *      columns. The interesting bit is that each such "row" may use only some of the "columns", whereas another one
 *      may use other columns, and data is managed intelligently according to which columns are used.
 *
 *      See \ref intro_ticket_types for an introduction how data is pulled together.</li>
 *
 *  <li>Doreen has a very fine-grained, but flexible access control system, while remaining very intuitive to the end user.
 *      Every user has a user account and can belong to an arbitrary number of groups, and all access control is based
 *      on groups. See \ref access_control for details.</li>
 *
 *  <li>Doreen has a modern user interface using the latest web technologies, with a responsive, themeable Bootstrap
 *      design that looks great on mobile devices as well.</li>
 *
 *  <li>Arbitrary files can be attached to tickets. Multiple files can be uploaded in parallel, with a useful and
 *      pretty user interface with progress reports.</li>
 *
 *  <li>Doreen can use Elasticsearch to provide a powerful full-text search engine that can index binary attachments
 *      (e.g. PDF, ODT, DOC, PPT) as well.</li>
 *
 *  <li>To help with organizing lots of tickets, Doreen can display visualizations of ticket relationships.</li>
 *
 *  <li>Doreen has been designed from the ground up to be extensible through plugins. See \ref plugins for more.</li>
 *
 *  <li>Doreen's entire API is exposed through an HTTP REST interface. As a result, in addition to using the
 *      pre-defined user interface in the browser, other programs can control Doreen through the REST API as well.
 *      See \ref rest_api for details.</li>
 *
 *  <li>Doreen is fast and scales well; it is regularly tested on databases with about a million tickets, on commodity
 *      hardware.</li>
 *
 * </ol>
 */

/**
 *  \page debugging_logging Debugging and logging
 *
 *  Doreen uses the regular PHP logging functions for logging. The Debug class helps in formatting logs nicely.
 *  You will find \ref Debug::Log(), \ref Debug::FuncEnter() and \ref Debug::FuncLeave() calls all over the code.
 *
 *  One way to make this useful is to put `error_log = syslog` into PHP.INI so that all logging goes into your syslog.
 *  If you are on a recent Linux distribution with systemd, you can then run something like
 *  `sudo journalctl -u apache2 -b -f` to have Doreen's messages dumped and followed live. (The "apache2" bit
 *  filters the output to messages from Apache, which includes PHP running inside Apache. This seems to work
 *  on both Gentoo and Debian.)
 *
 *  You can enable or disable flags in Debug::$flDebugPrint to get more or less logging. If you don't want
 *  to modify the code in the tree, you can also set that variable in your `/var/www/.../doreen-optional-vars.inc.php`
 *  file, like so:
 *
 *  ```php
 *      Debug::$flDebugPrint |= Debug::FL_DRILLDOWN;
 *  ```
 *
 *  In particular, `Debug::FL_SQL` dumps all SQL queries into the log, which can be very helpful.
 */

/**
 *  \page rest_api The Doreen REST API
 *
 *  Doreen has two HTTP interfaces: one for the graphical user interface, which starts at / and is typically not used except by clicking
 *  on links in the browser, and an API under /api/ which could be used by other applications. The API is also used from the JavaScript
 *  generated by Doreen and executed in the browser.
 *
 *  All API endpoints take a "lang" parameter to localize response contents. If it is not provided,
 *  doreen tries to get the best match based on the "Accept-Language" header.
 *
 *  See \ref api_authentication for details on how to gain user privileges for API requests.
 *
 *  See the "REST APIs" at the top of this page for a list of documented REST APIs.
 *
 *  Here are some conventions used by Doreen REST APIs (not all calls may conform to these yet, but new ones should.
 *
 *   -- All API calls start with '/api/'.
 *
 *   -- In addition to the HTTP response code, all calls should JSON with at least a 'status' field, which should be
 *      'OK' if HTTP 200 was returned. In the case of 'error', there should also be an error message under 'message'.
 *
 *   -- For typical resources, there should be a GET /api/resources call (with the plural), and the response should
 *      have a top-level 'results' key, under which an array of resource objects can be found. See GetUsersApiResult
 *      in the front-end for an example.
 *
 *   -- For a typical POST /api/resource call (with the singular) to create a new resource, on success, the response
 *      will contain a 'result' key (in the singular) with the newly created resource object. This way GUIs can
 *      quickly display the new object (especially when using our new AjaxTableRenderedBase TypeScript class in the
 *      front-end).
 *
 *   -- For a typical PUT /api/resource call (with the singular) to modify an existing resource, the same applies
 *      for the modified object.
 */

/**
 *  \page drn_html_editor Doreen HTML editor GUI control
 *
 *  Doreen's rich-text entry fields use the WYSIHTML text editor. Please see \ref HTMLChunk::addWYSIHTMLEditor() for
 *  details and https://github.com/Voog/wysihtml for the sources.
 *
 *  Available open-source editor controls fall into two categories:
 *
 *   -- Fairly lightweight editors that turn a generic DIV into an editor,
 *      extending the generic HTML5 'contendetiable' feature in some way.
 *      These normally cannot provide HTML tables.
 *
 *   -- More complete implementations, which have to reinvent the wheel
 *      to support tables, but as a result have much more code (and
 *      possibly bugs).
 *
 *  Several editors were tested, and WYSIHTML was chosen mostly because it's flexible and powerful
 *  (it supports tables) and can be restricted in its HTML output.
 *
 *  A good overview over free alternatives: https://news.ycombinator.com/item?id=10410879
 *  Another huge list: https://github.com/cheeaun/mooeditable/wiki/Javascript-WYSIWYG-editors
 */

/**
 *  \page drn_themes Doreen themes
 *
 *  Get free Bootstrap themes from here: http://bootswatch.com/
 */

/**
 *  \page drn_build_system Doreen build system
 *
 *  See the README file in the main directory for now.
 */

/**
 *  \page drn_nls National Language Support
 *
 *  Doreen's PHP code uses gettext for translations, which is supported by PHP.
 *  See http://php.net/manual/en/book.gettext.php and
 *  https://lingohub.com/blog/2013/07/php-internationalization-with-gettext-tutorial/
 *  for an introduction.
 *
 *  The short description is: There is NLS support in the PHP half of Doreen, but not
 *  in the JavaScript/TypeScript client side. Prepare all NLS strings in your PHP code
 *  by wrapping them in L() calls and marker strings, and use the Format and DrnLocale
 *  classes for additional features. The user's locale is determined at runtime depending
 *  on browser settings and the user's language selection from the languages drop down menu.
 *  Again, see DrnLocale.
 *
 *  The long description follows.
 *
 *  Reasoning for the decisions made regarding localization / internationalization:
 *
 *   -- The PHP 'intl' extension is huge and seems a bit too heavy for what we're doing.
 *
 *   -- GNU gettext files (PO etc.) are the de-facto standard (for example WordPress
 *      uses it) and therefore supported by a lot of tools. In particular, POEDIT is
 *      available for a lot of platforms for free. With gettext, translators seem to
 *      have a low barrier to entering the game.
 *
 *   -- GNU gettext has compiled MO files for faster access.
 *
 *   -- GNU gettext has plurals support. This is a complex topic: see
 *      https://www.gnu.org/software/gettext/manual/html_node/Plural-forms.html for more.
 *
 *  Drawbacks:
 *
 *   -- Server caching: PHP's `gettext()` function seems to do caching in Apache server
 *      memory. Updates to translations are not picked up until apache is restarted.
 *
 *   -- PHP locale support seems to cause more trouble than it's worth (see \ref DrnLocale::Set()).
 *      Also there are some things that I like that are not supported by other libraries
 *      so I'd like a wrapper around those that can implement that functionality.
 *      In particular, I like pretty quotes. As a result, we only set the locale for
 *      LC_MESSAGES and do all other NLS formatting (such as numbers
 *      and units) ourselves. See the Format and DrnLocale classes.
 *
 *  One complication is Doreen's JavaScript/TypeScript client-side code.
 *  We have all our strings in the server-side PHP back-end code ONLY. If the front-end
 *  code needs to display something that is dependent on the user's locale, such as strings
 *  and formatted dates and numbers, they need to be supplied by the PHP back end. See
 *  \ref drn_javascript for more.
 *
 *  A further complication is the Doreen plugin system (see \ref plugins). The system currently works as follows:
 *
 *   -- GNU gettext must be installed on the development server. To test, make sure
 *      that the `msgfmt` and `msgmerge` binary programs exist; these get called by
 *      the build system transparently.
 *
 *   -- All translatable strings in the PHP (and XML) source files must be marked
 *      with `{{`, then a literal `L/`, then optionally a message ID, then another `/`,
 *      then the text in US English, then `}}`. If the message ID is missing (i.e.
 *      there are two `//` directly after the L), then the message ID is the same as the
 *      message text (the default for gettext).
 *
 *   -- At runtime, do not call PHP's `gettext()`, but use the global L() function in
 *      `globalfuncs.php` ONLY. This calls `gettext()` in turn.
 *
 *   -- As with GNU gettext, translating is a two-step process: first, translation
 *      needs to be prepared (all NLS strings need to be extracted from the PHP code), then
 *      they need to actually be translated.
 *      To prepare translations, instead of GNU gettext's `xgettext`, we have our own
 *      `tools/dgettext.pl` program, which scans all
 *      files for the L strings, extracts them and writes POT files with English strings
 *      into `/out/pot`, one for the core and one for each plugin; it then calls
 *      msginit or msgmerge to write those into `src/core/po` and `src/plugins/{plugin}/po`,
 *      updating existing translations, if any.
 *
 *  Translators should work on the files in `src/core/po` and `src/plugins/{plugin}/po` ONLY.
 *
 *  The \ref drn_build_system manages everything else transparently. The makefiles merge the
 *  translated PO files from `src/` together, compile the MO file and put it into
 *  `/locale/xx_XX/LC_MESSAGES`, which which get read by PHP's `gettext()` function at runtime.
 *
 *  Again, the L() mechanism works only in PHP code. For how to emit localized text from JavaScript,
 *  see \ref drn_javascript.
 */

/*
 *  Some notes about gettext and PHP:
 *
 *  The workflow for a translation in Doreen is as follows:
 *
 *   1. Create the path locale/xx_XX/LC_MESSAGES.
 *
 *   2. In that directory, execute: msginit --locale=xx_XX --input ../../../doreen.pot -o doreen.po
 *
 *   3. Translate the doreen.po file.
 *
 *   4. In the directory, execute: msgfmt doreen.po -o doreen.mo
 *
 */

/**
 *  \page drn_javascript JavaScript and TypeScript in Doreen
 *
 *  Even though the back-end is written in PHP, Doreen sends a lot of JavaScript to the user's browser.
 *  Compared to what you may be familiar with from other web projects, Doreen is more complicated because
 *  of its plugins architecture. In 2019 Doreen finally got rid of all legacy JavaScript code and all
 *  client-side code is now using TypeScript modules, which is compiled to JavaScript by the
 *  \ref drn_build_system. We use ES2015 module syntax (import/export statements).
 *
 *  If you want to add a new TypeScript code file to have JavaScript execute in the user's browser, do the following:
 *
 *   1. Ensure your plugin has an entry point file with a class that implements entry/entrypoint.EntryPoint and
 *      registers the entry point to the entry/entry module under the module name. The plugin entry point should
 *      be the name of the plugin with the file extension ts in your main plugin folder.
 *
 *   2. Add an action in the plugin entry point action handler. Possibly import the function to run from a
 *      separate TypeScript module from withing your plugin. The name you choose for the action will be used
 *      to call into your JS from PHP.
 *      This action can do whatever it likes, but commonly it instantiates an object which installs some event
 *      handler (e.g. on an entry field) or makes an AJAX request back to Doreen. For starters, just have the
 *      function alert("test") you.
 *
 *   3. In your plugin back-end PHP code, call \ref WholePage::AddJSAction(), with the first parameter being
 *      the name of your plugin, the second parameter the name of your action and the third parameter an array
 *      of arguments you need for your action. If your action should only be called once the document is ready,
 *      pass TRUE as the fourth parameter. Your (Typescript compiled to JavaScript) code will then get called
 *      when the page has loaded.
 *
 *  You should now see your alert("test") on page load.
 *
 *  Note that the L() translation mechanism described in \ref drn_nls works only in PHP code. For
 *  JavaScript or TypeScript code which needs to emit localized text, do one of the following:
 *
 *   -- Use the Doreen template system and put all the translatable text into an XML template.
 *      See \ref HTMLChunk::addXmlDlg().
 *
 *   -- Use L() in PHP code and emit the result in generated JavaScript code, e.g. via array in the third param to
 *      \ref WholePage::AddJSAction().
 *
 *   -- Use \ref WholePage::AddNLSStrings() to add ID => value pairs which are emitted as a
 *      global JavaScript g_nlsStrings variable. Use the "nls" module to then retrieve the translation.
 *
 *  You can also have Doreen run code whenever your plugin JS code is loaded; see \ref WholePage::AddPluginChunks().
 *
 *  The above should work without having to know the details. But here they are.
 *
 *  The Doreen TypeScript loader is very different from the Doreen JavaScript loader, so let's
 *  explain JavaScript first.
 *
 *  The build system bundles several JS files into bigger bundles
 *  (under the `out/9_final` directory), then copies those to `htdocs/js`. What gets bundled and what
 *  remains separate is mostly governed by 1) feature flags in \ref WholePage::Enable(), to allow
 *  certain bundles to be left out if they're fairly big (e.g. VisJS); 2) plugins, because a plugin
 *  may or may not be enabled.
 *
 *  The TypeScript modules are bundled by webpack. Webpack gets all the entry point files and resolves all the
 *  other modules required for a plugin from there and bundles them into one or more files. It splits the code
 *  into multiple files if there's code that's used by multiple plugins or for external depencies from NPM.
 *
 *  There is a special alias module "core", which refers to src/js. It is used in external plugins to compile
 *  against the modules from doreen or from other plugins. To refer to another plugin, use "core/../plugins/<plugin_name>/etc".
 *  All module paths in this repository can be relative and shouldn't need the core alias.
 *
 *  Webpack tries to optimize the output by inlining functions, concatenating modules and
 *  sharing modules between entry points. To properly decide what can be removed and what is needed, it uses
 *  tree-shaking. Currently only a few specific files are marked as side-effect-free, so can be properly
 *  tree-shook. See the "sideEffects" property in the package.json of doreen.
 *
 *  Webpack then emits .stats.json which lists all the files required for a plugin's JS code. This is used by
 *  PHP to figure out which files to load with a <script> tag.
 *
 *  To summarize, there is
 *
 *   1. `core.js` which bundles stuff from the core together that is likely to be used often;
 *
 *   2. legacy feature code like `select2.js` which can be loaded with \ref WholePage::Enable();
 *
 *   3. `<plugin>_mod.js` for every plugin's JavaScript and more compiled from TypeScript (which can be loaded with \ref WholePage::AddPluginChunks());
 */

/**
 *  \page drn_debugging_db Debugging database performance
 *
 *  Doreen uses standard PHP logging; see \ref debugging_logging. The \ref Globals::Profile() function can be used to find performance bottlenecks.
 *
 *  To clear all file-system and database caches in order to test performance on a "cold" system, the following
 *  is useful on a Linux host (as root):
 *
 *  ```
 *
 *      sync && echo 3 > /proc/sys/vm/drop_caches
 *      systemctl restart postgresql-9.4
 *
 *  ```
 *
 *  See https://linux-mm.org/Drop_Caches .
 *
 *  I have found no noticible difference between PostgreSQL 9.4 and 9.5.
 *
 *  In /etc/postgres.../postgresql.conf, the following tuning parameters help a little bit:
 *
 *   -- shared_buffers: increasing from 128MB to 1024MB drops cold cache query from 12.5s to 11.5s.
 *
 *   -- Raising work_mem to 256MB makes no difference.
 *
 *   -- Raising effective_cache_size to 4GB makes no difference.
 */

/**
 *  \page api_authentication API access authorization
 *
 *  Doreen uses a bearer token mechanism to check access privileges of an API
 *  request. The tokens are JSON Web Tokens and signed using SHA256, so Doreen
 *  can ensure it should trust the contents of the token.
 *
 *  An authorization token is always bound to a user, so you can perform whatever
 *  that user could with the token.
 *
 *  Every authorization token has to be generated from an API token. Currently
 *  a Doreen user can optain their personal API token from their account settings
 *  if they are privileged to do so (currently only admins can generate tokens,
 *  and gurus can read them)
 *
 *  There is currently no endpoint to turn your API token into an authorization
 *  token.
 *  To implement such an endpoint, the token can be obtained from \ref JWT::GetUserAuth()
 *
 *  The front-end generates authorization tokens with a special API token that does
 *  not belong to a user. It will also stores the current session ID in the token
 *  so session altering actions can be performed with API methods.
 *
 *  The authorization token should be sent in the "Authorization" header and should
 *  be following the keyword "Bearer".
 */
