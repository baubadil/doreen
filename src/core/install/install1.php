<?php

/*
 *  install1.php gets called when Globals::$fnameInstallVars (with database
 *  type, name, user, password) does not exist. This means this is a completely
 *  fresh install.
 *
 *  This file handles that bit, in two steps:
 *
 *  1)  Screen 1 lets the user select database type, name, user, password
 *      in a form, which then reloads.
 *
 *  2)  We get to this file again, because Globals::$fnameInstallVars still doesn't
 *      exist, but then we have the 'dbtype' etc. request args from the form,
 *      and we create
 *       a) the file,
 *       b) the database user with password,
 *       c) the database.
 *
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Active included code
 *
 ********************************************************************/

if (!($dbtype = getRequestArg('dbtype')) )
{
    #
    # SCREEN 1: Globals::$fnameInstallVars doesn't exist, and we're not responding
    #           to the form of screen 1 yet (that would have set the 'dbtype'
    #           request arg).
    #

    require_once INCLUDE_PATH_PREFIX.'/3rdparty/class_pwdgen.inc.php';

    $oHTML = new HTMLChunk();

    $htmlTitle = 'Welcome to Doreen!';
    WholePage::EmitHeader($htmlTitle);
    $oHTML->openPage($htmlTitle, FALSE);

    if (!file_exists(TO_HTDOCS_ROOT.'/css/bundle.css'))
        throw new DrnException("Doreen cannot find its CSS files. It looks like you haven't run the build yet.");

    $htmlDatabaseRadios = '';
    foreach (Plugins::A_DB_PLUGINS as $plugin => $dbname)
    {
        $db = substr($plugin, 3);       # strip 'db_' prefix
        require_once INCLUDE_PATH_PREFIX."/plugins/$plugin.php";

        $disabled = $select = '';
        $inner = $dbname;
        # If the plugin has a "can-activate" function and it returns an error message,
        # then disable that plugin.
        if ($errCannotActivate = Plugins::TestCanActivate($plugin))
        {
            $disabled = ' disabled';
            $htmlErr = "This database cannot be used. The $db plugin reported: ".toHTML($errCannotActivate);
            $inner = "<a href=\"#\" data-toggle=\"tooltip\" data-placement=\"top\" data-original-title=\"$htmlErr\">$dbname</a></li>";
            WholePage::Enable(WholePage::FEAT_JS_BOOTSTRAP_TOOLTIPS);
        }
        else if ($db == 'postgres')
            $select = ' checked="checked"'; # default to PostgreSQL

        $attrs = "value=\"$db\"$disabled$select";
        $htmlDatabaseRadios .= <<<EOD
          <div class="radio-inline">
            <label class="radio-inline"><input type="radio" id="$db" name="dbtype" $attrs>$inner</label>
          </div>
EOD;
    }

    $pwdsuggest = \PasswordGenerator::getAlphaNumericPassword(20);
    $htmlDataDir = toHTML(DOREEN_DATA_DIR, 'code');
    $htmlFnameInstallvars = toHTML(Globals::$fnameInstallVars, 'code');

    $oHTML->append(L(<<<EOD
      <p>The file %INSTALLVARS% does not exist. This probably means that Doreen has not yet been installed on this machine.</p>
      <p>Installation of Doreen involves the following steps:</p>
      <ol>
        <li>Setting up a <b>database</b> for Doreen on this machine (including a database user). Doreen supports both MySQL and PostgreSQL, as indicated below.
            We will then create the file %INSTALLVARS% with the database connection information listed below.
            <br>We will also set up a <b>data directory</b> at %DATADIR% for attachments and other files.</li>
        <li>Installing the <b>default tables</b> and values into that database, after which Doreen becomes operational.</li>
        <li>Creating an <b>administrator account</b> for you, which allows you to add additional users later.</li>
        <li>Setting up Doreen <b>plugins</b>.</li>
      </ol>

EOD
            , [ '%INSTALLVARS%' => $htmlFnameInstallvars,
                '%DATADIR%' => $htmlDataDir ]));

    $htmlOkIHaveFixed = <<<EOD
<form method="post">
  <input type="hidden">
  <button type="submit" class="btn btn-primary">OK, I have fixed the problem</button>
</form>
EOD;

    $fOK = TRUE;
    # Make sure the install vars file is writable.
    $dirOfInstallVars = dirname(Globals::$fnameInstallVars);
    if (!is_writable($dirOfInstallVars))
    {
        $oHTML->append(L(<<<EOD
<div class="alert alert-danger">Doreen needs to be able to write to the file <code>%INSTALLVARS%</code> on the server, but the directory
<code>$dirOfInstallVars</code> is not currently writable by the webserver.
If your server is a Linux machine, to temporarily make that directory writable, please run the following commands as root:</p><br>

<pre>
chown %USERGROUP% %DIROFINSTALLVARS%
chmod 775 %DIROFINSTALLVARS%
</pre>

<p>This only needs to be done temporarily during installation, and we will undo that change afterwards.</p>
</div>
$htmlOkIHaveFixed
EOD
            , [ '%USERGROUP%' => toHTML(Process::GetProcessUserAndGroupString()),
                '%INSTALLVARS%' => toHTML(Globals::$fnameInstallVars),
                '%DIROFINSTALLVARS%' => toHTML($dirOfInstallVars)
              ] ));
        $fOK = FALSE;
    }
    else
        $oHTML->append(L("<div class=\"alert alert-success\">Great! It looks like we can write to %INSTALLVARS%.</div>", [ '%INSTALLVARS%' => $htmlFnameInstallvars ] ));

    if (!is_writable(DOREEN_DATA_DIR))
    {
        $htmlDir = toHTML(DOREEN_DATA_DIR);
        if (!is_dir(DOREEN_DATA_DIR))
        {
            $htmlCommand = "<pre>mkdir -p $htmlDir && chown ".Process::GetProcessUserAndGroupString()." $htmlDir && chmod 775 $htmlDir</pre>";
            $oHTML->append(<<<HTML
<div class="alert alert-danger">The doreen data directory $htmlDataDir does not exist, and Doreen does not have sufficient 
permissions to create it. Please create it yourself by running the following command as root:
$htmlCommand
$htmlOkIHaveFixed
</div>
HTML
            );
        }
        else
        {
            $htmlCommand = "<pre>chown ".Process::GetProcessUserAndGroupString()." $htmlDir && chmod 775 $htmlDir</pre>";
            $oHTML->append(<<<HTML
<div class="alert alert-danger">The doreen data directory $htmlDataDir exists, but is not writable by Doreen. 
Please change the permissions by running the following command as root::
$htmlCommand
$htmlOkIHaveFixed
</div>
HTML
                                );
        }

        $fOK = FALSE;
    }
    else
    {
        $oHTML->append(L("<div class=\"alert alert-success\">Great! It looks like we can write to %DATADIR%.</div>", [ '%DATADIR%' => $htmlDataDir ] ));

        if (!is_dir(DOREEN_ATTACHMENTS_PARENT_DIR))
            @mkdir(DOREEN_ATTACHMENTS_PARENT_DIR, 0700);
        if (!is_dir(DOREEN_ATTACHMENTS_PARENT_DIR))
            throw new DrnException("Failed to create attachments directory ".DOREEN_ATTACHMENTS_DIR);
    }

    if ($fOK)
    {
        $htmlPlugins = '';
        foreach (Install::GetAllPlugins() as $name => $data)
        {
            $htmlDescr = '<b>'.$name.'</b>';
            if (isset($data['author']))
                $htmlDescr .= ' ('.toHTML($data['author']).') ';
            $htmlDescr .= ' '.Format::MDASH.' '.toHTML($data['descr']);
            if ($htmlPlugins)
                $htmlPlugins .= "\n";
            $checked = '';

            if (strpos($data['defaults'], 'required') !== FALSE)
                $checked = ' checked disabled';
            # If we have an "optional vars" file from a previous install, use the values from there.
            else if ($data['enabled'])
                $checked = ' checked';
            else if (strpos($data['defaults'], 'enabled') !== FALSE)
                $checked = ' checked';

            $attrs = "value=\"$name\"$checked";
            $htmlPlugins .= "                <div class=\"checkbox\"><label><input type=\"checkbox\" name=\"plugins[]\" $attrs> $htmlDescr</label></div>";
        }

        $postgresPwd = (defined('PREFILL_POSTGRES_PASSWORD')) ? PREFILL_POSTGRES_PASSWORD : '';
        $doreenDBName = (defined('PREFILL_DOREEN_DB_NAME')) ? PREFILL_DOREEN_DB_NAME : 'doreen';
        $oHTML->append(L(<<<EOD
      <p>In order to complete step 1, please provide the following information:
        <form class="form-horizontal" method="post">
          <div class="form-group" id="dbtype-group">
            <label class="col-sm-2 control-label">Database</label>
            <div class="col-sm-10">
$htmlDatabaseRadios
            </div>
          </div>
          <div class="form-group">
            <label for="dbhost" class="col-sm-2 control-label">Database host</label>
            <div class="col-sm-10">
            <input type="text" class="form-control" name="dbhost" id="dbhost" value="localhost" required>
            <p class="help-block">Where the database server is located. Use %{localhost}% unless you want the database server to be different from the Doreen web server.</p>
            </div>
          </div>
          <div class="form-group">
            <label for="dbadminpwd" class="col-sm-2 control-label">Database administrator password</label>
            <div class="col-sm-10">
            <input type="password" class="form-control" name="dbadminpwd" id="dbadminpwd" placeholder="{{L//Password}}" value="$postgresPwd" required>
            <p class="help-block">Please enter the password for the database's administrator account here (e.g. for the MySQL %{root}% user, or the PostgreSQL %{postgres}% user).
            Doreen will use this password only during installation; the password will not be stored.</p>
            </div>
          </div>
          <div class="form-group">
            <label for="dbname" class="col-sm-2 control-label">Doreen database name</label>
            <div class="col-sm-10">
            <input type="text" class="form-control" name="dbname" id="dbname" value="$doreenDBName" required>
            <p class="help-block">
            Please enter a name for the database that Doreen will use. This database will be created anew. 
            You can use the default unless you want to run multiple instances of Doreen on the same machine.
            </p>
            </div>
          </div>
          <div class="form-group">
            <label for="dbuser" class="col-sm-2 control-label">Doreen database user account</label>
            <div class="col-sm-10">
            <input type="text" class="form-control" name="dbuser" id="dbuser" value="$doreenDBName" required>
            <p class="help-block">
              Please enter a name for the user account under which Doreen will contact the database.
              This is a <i>database</i> user account, not a system account. The account will be created in the database, using the administrator password given above.
              Doreen will only ever use this account to connect to the database in the future for added security. You will not need to use this user account yourself.
              You can use the default value, unless you want to run multiple instances of Doreen on the same machine with different databases.
            </p>
            </div>
          </div>
          <div class="form-group">
            <label for="dbpassword" class="col-sm-2 control-label">Password for the above account</label>
            <div class="col-sm-10">
            <input type="text" class="form-control" name="dbpassword" id="dbpassword" value="$pwdsuggest" required>
            <p class="help-block">
              This password will be set for the new Doreen database user account. An automatic password has been generated for you, but you can specify a different one.
              It will be stored in cleartext in the %INSTALLVARS% file, and Doreen will use it with the account specified above to connect to the database
              in the future for added security. You will probably never need to enter it manually.
            </p>
            </div>
          </div>
          <div class="form-group">
            <label for="dbpassword" class="col-sm-2 control-label">Plug-ins to activate</label>
            <div class="col-sm-10">
                <p class="help-block">Please select which plugins should be installed for additional functionality.</p>
$htmlPlugins
            </div>
          </div>
          <div class="form-group">
            <div class="col-sm-offset-2 col-sm-10">
            <button type="submit" class="btn btn-primary">Submit</button>
            </div>
          </div>
        </form>

EOD
        , [ '%INSTALLVARS%' => $htmlFnameInstallvars ] ));
    }

    # return to index.php and let it do the output

    $oHTML->close();    # page
    $oHTML->flush();
    WholePage::EmitFooter();

    exit;
}
else
{
    #
    # SCREEN 2: Globals::$fnameInstallVars doesn't exist, but we're now responding
    #           to the form of screen 1. Create the file, the database user,
    #           and the database.
    #

    try
    {
        Install::CreateDatabase($dbtype,
                                getRequestArgOrDie('dbhost'),
                                getRequestArgOrDie('dbadminpwd'),
                                $dbname = getRequestArgOrDie('dbname'),
                                getRequestArgOrDie('dbuser'),
                                getRequestArgOrDie('dbpassword'));

        $aPlugins = getRequestArg('plugins');

        $attachmentsDir = DOREEN_ATTACHMENTS_PARENT_DIR.'/'.$dbname;
        if (!is_dir($attachmentsDir))
            @mkdir($attachmentsDir, 0700);
        if (!is_dir($attachmentsDir))
            throw new DrnException("Failed to create attachments directory ".$attachmentsDir);

        # Read existing optional vars file, if it exists, and keep the important lines.
        $aExistingLines = [];
        if (@is_readable(Globals::$fnameOptionalVars))
        {
            if (!($fh = @fopen(Globals::$fnameOptionalVars, 'r')))
                myDie("Cannot open existing ".toHTML(Globals::$fnameInstallVars, 'code')." for reading: $php_errormsg");
            while ($line = fgets($fh))
            {
                # Swallow these lines:
                if (    (preg_match('/^<\?php/', $line))
                     || (preg_match('/^\?>/', $line))
                     || (preg_match('/^\s*\$g_aPlugins\s*=/', $line))      # we'll rewrite this one
                   )
                    continue;

                # Keep all other lines (e.g. PREFILL_* definitions) intact.
                $aExistingLines[] = trim($line, "\n\r");
            }
            fclose($fh);
        }

        if ($aExistingLines || $aPlugins)
        {
            if (!($fh = @fopen(Globals::$fnameOptionalVars, 'w')))
                myDie("Cannot open ".toHTML(Globals::$fnameOptionalVars, 'code')." for writing: $php_errormsg");
            fwrite($fh, "<?php\n");
            foreach ($aExistingLines as $line)
                fwrite($fh, "$line\n");
            fwrite($fh, "\n\$g_aPlugins = [ '".implode("', '", $aPlugins)."' ];\n");
            fclose($fh);
        }

        # After this we should no longer require the DB administrator password.
        WebApp::Reload();
    }
    catch(\Exception $e)
    {
        myDie(toHTML($e));
    }
}
