Doreen is (C) 2015--2019 Baubadil GmbH and, as of Nov 5 2019, released under the GNU GNU Affero General Public License version 3. See the LICENSE.TXT file.


Introduction
------------

Welcome to Doreen, a universal ticket system with plugins that can track and
organize almost anything and make it searchable at lightning speed.

Doreen has a number of features that make it stand out among the many ticket systems that already exist:

1. Doreen has a modern user interface using the latest web technologies, with a responsive, themeable Bootstrap
       design that looks great on mobile devices as well.

2. Doreen is *fast* and scales well; it is regularly tested on databases with about a million tickets, on 
	commodity  hardware.

3. Arbitrary files can be attached to tickets. Multiple files can be uploaded in parallel, with a useful and
	pretty user interface with progress reports.
	Doreen can use Elasticsearch to provide a powerful full-text search engine that can index binary attachments
     (e.g. PDF, ODT, DOC, PPT) as well.

3. Doreen has been designed from the ground up to be extensible through plugins. Four plugins ship with Doreen
   by default (see the `src/plugins` directory); one of them is the code to run the fischertechnik database at 
	https://ft-datenbank.de (a good showcase of what Doreen can do).

4. Doreen has a very fine-grained, but flexible access control system, while remaining very intuitive to the end user.
   Users can have user accounts and belong to an arbitrary number of groups, and all access control is based
   on groups.

5. Doreen's entire API is exposed through an HTTP REST interface. As a result, in addition to using the
   pre-defined user interface in the browser, other programs can get, post and put tickets and their configuration 
	through the REST API as well.

7. 	Doreen uses the concept of "ticket types" to organize *at run-time* which fields are visible for tickets.
 	A ticket type contains a list of ticket fields (e.g. "title", "description", "priority" etc.), and all tickets
    of that type will accept and display such data. This is not hard-coded, but can be configured by an
   administrator, and extended through plugins.

8. Doreen uses many tables in the actual database, but if you are familiar with relational databases in general,
	it may help to think of Doreen as one big table, wherein tickets are the rows and the ticket fields are the
	columns. The interesting bit is that each such "row" may use only some of the "columns", whereas another one
	may use other columns, and data is managed intelligently according to which columns are used.

9. To help with organizing lots of tickets, Doreen can display visualizations of ticket relationships.



Requirements before installation
--------------------------------

Requirements:

 *  A Linux host. Doreen does not currently run on Windows or other hosts, as
    it uses some Linux-specific code for process management.

 *  A web server with PHP 7. Only Apache 2 is being tested at the moment; others 
	may or may not work.

	Doreen started using PHP 7 type annotations in August 2017, so PHP 7 is 
	required. (We don't use PHP 7.1 features yet.)

    If you're upgrading from PHP 5 to PHP 7 on Debian, see notes below.

    As always with modern PHP, you must set the date.timezone in php.ini:

        date.timezone = Europe/Berlin

 *  Enable `mod_rewrite` with Apache for Doreen's .htaccess files to work (see below).

 *  The PHP `gettext`, `iconv`, `session`, `curl`, `sysvmsg`, `xmlreader`, `mbstring`
    and `mcrypt` extensions must be installed.

    On Debian, curl, mbstring and mcrypt are probably not installed by default; the
    package names are `php7.0-curl`, `php7.0-mbstring` and `php7.0-mcrypt` for PHP 7.

	(mcrypt will need to be replaced soon as PHP is removing it.)

 *  PostgreSQL >= 9.4. There is some MySQL support code in Doreen but it hasn't
    been tested in a long time.

 *  GNU Make >= 3.82. (Check with `make --version`. Should already be present
    on Gentoo; on Debian, install `make`.)

 *  GNU gettext. The build system uses `msgfmt` and other programs. Should
    already be present on Gentoo; on Debian, install `gettext`.

 *  Node.js. We use `npm` for JavaScript library management.

    To get node.js, on Gentoo, `emerge -av nodejs`. On Debian, the package is
    pretty old. Please see https://nodejs.org/en/download/package-manager/ for
    how to get a more recent one.

On a stock Debian 9 "Stretch" install with PHP 7, the following should accomplish
the above:

        apt-get update && apt-get upgrade
        apt-get install git apache2 postgresql make gettext php7.0-pgsql
                    php7.0-curl php7.0-mbstring php7.0-mcrypt postgresql-client
        systemctl enable apache2
        systemctl start apache2
        systemctl enable postgresql
        systemctl start postgresql

Check that going to "localhost" in your browser displays an Apache test page.

During installation (below), Doreen will ask for the 'postgres' user's password
once to set up a database user and the Doreen database, so it is assumed that
your `/etc/postgresql-9.X/pg_hba.conf` specifies password authentication. Doreen
probably cannot install itself otherwise.

On Debian, these steps should do:

 1. As root, set the password for the system postgres user: `passwd postgres`

 2. Open a command shell, `su - postgres` and enter that password.

 3. Start `psql` under that user, then enter the following commands to set a password:

        postgres=# \password
        Enter new password: *********
        Enter it again:     *********
        postgres=# \q

 4. In `/etc/postgresql/9.4/main/pg_hba.conf`, change all connections to `password`.

 5. `systemctl restart postgresql`

 6. To test, run `psql -U postgres` from your regular user account, and you
    should be prompted for the above password.

A couple of additional tips: On Debian, `sudo` is not installed by default.
`apt-get install sudo` and then add your user account to the 'sudo' group
(with `usermod -a -G sudo $USER` as root). Also, add your own user account
to the `www-data` group so that you can modify files in the document
root without having to be root.

Remember that on Linux, user group changes only take effect after logging out and back in again.


Upgrading to PHP 7 on Debian
----------------------------

Debian 9.0 "stretch" comes with PHP 7.0 so there is no need to upgrade.

Otherwise, you must upgrade. Doreen uses both the Apache PHP module (mod_php)
and the PHP command line interface. The versions MUST match:

 *  Type `php --version` to make sure that PHP 7 is active on the CLI.
    If not, you may have to use Debian's alternatives system; try
    `update-alternatives --list php` and `update-alternatives --set php <path>`.

 *  Use `apt-get install libapache2-mod-php7.0` and `a2dismod php5` and
    `a2enmod php7.0` to switch to PHP 7 in Apache. Check Doreen's "System settings"
    page to ensure PHP 7 is active.


Setting up Doreen for the web server
------------------------------------

The Doreen installation is a web page, so you need to get Apache to display Doreen
firest. 

You should set up Apache to serve documents from the `htdocs/` under where your 
Doreen code resides. The name was chosen s the default name for things on 
Gentoo Linux. On Debian, the default Apache install serves documents from 
/var/www/html, so for testing with the default install, you may want to edit 
`/etc/apache2/sites-enabled/000-default.conf` and change 
`DocumentRoot /var/www/html` to point to Doreen's `/path/to/doreen/htdocs`
instead.

Alternatively, place a symlink from your web server's document directory to the
`htdocs/` directory of the Doreen source tree, which has Doreen's `index.php` file.
For symlinks to work, you need `Options FollowSymLinks` in your `<Directory>` statement
in Apache's config.

There is also a hidden `.htaccess` file in that directory to configure Apache's
`mod_rewrite` for Doreen's pretty URLs to work. This requires `AllowOverride All`
in your `<Directory>` statement in Apache's config. Something like this:

        <Directory "/var/www/doreen-share/htdocs">
            AllowOverride All
        </Directory>

Also make sure Apache can read the files the symlink points to (directory and
file permissions; on Debian, Apache runs as the `www-data` user).

Enable Apache's mod_rewrite. On Debian:

        a2enmod rewrite
        systemctl restart apache2

Then you need to install necessary libraries and run the Doreen build for the
first time to produce the CSS and JS files. `cd` to the Doreen source directory
(where this README file resides) and run the following command:

        npm install

npm is configured in package.json to run `make` (GNU make), which runs the Doreen
build process (details below). Once the build works, you can run `make -j8` 
(replace 8 with the no. of cores to build with) to speed things up. 
See the instructions below for explanations for what all that means.

The main `htdocs/index.php` file includes a ton of other PHP files from
`htdocs/../src/`. So copying only the `htdocs/` tree to your web server's document
root will NOT work.

After restarting your web server, you should be able to type Doreen's address
into the web browser (e.g. 'localhost'), and Doreen should welcome you with a
detailed installation process that will set up the necessary tables in the
database and create an administrator user account for you.

The installation writes three configuration files to the parent directory of
your web server's document root, so this needs to be writable at least
temporarily. The installation web page will test for this and instruct you 
which commands to run exactly.


Troubleshooting
---------------

 *  If Doreen's installation routine aborts between the several installations
    steps, there may be broken config files in the parent of your document root
    (/var/www/...).

    Doreen writes three files: `doreen-install-vars.inc.php`, `doreen-key.inc.php`,
    and `doreen-optional-vars.inc.php`. Delete them, and this will trigger the
    install to restart from the beginning. (Do this only if the install failed.
    *Don't do this* if you have valuable data in Doreen as this will destroy your
    installation.)

 *  If you get a blank page in the browser with an HTTP error message, make
    sure all PHP.INI error settings are cranked up all the way. In particular
    (on Debian, /etc/php5/apache2/php.ini):

        display_errors = 1
        error_reporting = E_ALL

    and check your PHP error logs. Doreen gets especially upset this way if the
    PostgreSQL PHP module is missing.

 *  If you get an HTTP 500 error, then probably something's wrong with your
    Apache setup. Is mod_rewrite enabled? Are permissions correct? Including
    the hidden `.htaccess` files? Are <Directory> settings correct? Check
    /var/log/apache2/error.log and revisit the instructions above.

 *  If the Doreen main page works after installation but subpages like /settings
    or /tickets fail with a 404, then the Apache `mod_rewrite` mechanism isn't
    working. Again, check your Apache setup: there are two hidden `.htaccess`
    files for Apache in `htdocs/` and `htdocs/api/`, and your document root must
    have `AllowOverride All` (see above).

 *  If your web browser complains about infinite redirects, try deleting your 
    cookies for localhost. This sometimes happens after a fresh install.


Plug-ins
--------

Doreen has a core system, which cannot do very much by itself, but is extremely
flexible and can be extended with plugins. Several of those plugins are included
with the open-source repository; more plugins are closed source. 
All plugins must reside or symlinked to `src/plugins`. 

During installation, Doreen will ask you which of the plugins in that directory 
should be activated. This decision cannot easily be changed without a reinstall:

 * you can probably add additional plugins later (although this is not well tested);

 * deactivating a plugin that was in use will probably ruin your installation
   since plugins often insert table data that cannot be understood without the 
   plugin code.


Entry points and other files
----------------------------

The main entry point is `htdocs/index.php`.

A second PHP entry point is `htdocs/api/api.php`, which implements Doreen's public
REST interfaces. This also has its own `.htaccess` file.

The files in `css/`, `fonts/` and `js/` under `htdocs/` all get generated during the build
process.

The entire `locale/` directory tree under the root also gets generated during
the build process and contains translations (gettext MO files compiled from the PO files
in the PHP sources).

A third PHP entry point is `cli/cli.php` *above* the `htdocs/` directory. This is the
Doreen command-line interface for background and advanced configuration tasks.
You can run `php cli/cli.php --install-dir /var/www/localhost` for available
commands. Many of these are destructive, so be careful.


Code documentation for developers
------------------

Doreen uses doxygen/PHPDoc-like markup all over the code to document itself.
This is useful if you use a smart IDE like PHPStorm, which parses these tags.
Additionally, you can generate HTML and LaTeX documentation from that markup
using the external phoxygen tool (also by Baubadil), which is on Github:

https://github.com/baubadil/phoxygen

This C++ tool is really written only for Doreen and probably only works there.
Simply run path/to/phoxygen in the Doreen root, and it will create a doc/html
directory with lots of documentation from the code. Open doc/html/index.html
and start reading, that's really the best introduction to the Doreen internals.


Requirements for developing
---------------------------

Doreen is PHP server-side and JavaScript client-side, with a lot of libraries on
both sides. All recent client-side code is written in TypeScript and compiled to
JavaScript, so there is a build process for developers (see below).

 1. We manage PHP libraries with PHP Composer (https://getcomposer.org/), but the
    PHP libraries ARE checked into the tree under `vendor/` so you already have
    everything required, and you may not even need Composer.

    Otherwise, as said on https://getcomposer.org/doc/00-intro.md#installation-linux-unix-osx,
    you may want to  `mv composer.phar /usr/local/bin/composer` for a global install.

 2. Additionally, Doreen produces a lot of client-side code to run the user's
    browser. JavaScript is transpiled in the tree from TypeScript source code via
    the Doreen build system (see below).

    To manage JavaScript packages, we use npm from node.js.

 3. Doreen requires Typescript to be installed globally on your machine. Typescript
    is a new open-source language by Microsoft (yes, Microsoft) that compiles to
    JavaScript. See http://www.typescriptlang.org/ for details.

    Currently, the build system assumes that `tsc`, the TypeScript compiler, is
    installed globally, hence the `npm install -g typescript` in the instructions.

    Since Feb 5, 2017, we've been using Typescript 2.0; as a result, `typings`
    (https://github.com/typings/typings), which provided TypeScript bindings for
    many JavaScript libraries, is no longer needed. All the references in the sources
    have been updated accordingly.

 4. Finally, in the Doreen source root, "npm install" will install all packages
    from Doreen's package.json locally. This will create `node_modules/`, which is
    already in Doreen's `.gitignore`, so it must always be updated locally.

    As a result, JavaScript packages are NOT in the git tree, but must be fetched
    locally. (This is different from the PHP packages mentioned above. If you get
    funny build errors after 'git pull', another 'npm install' often helps.)

 5. We don't use grunt or gulp or any of that sort for building, but good old GNU
    Make, which controls and launches Webpack if needed. If you're on a Unixish
    system, you most likely have `make` installed already.

    The Doreen build system consists of the main `Makefile`, which includes other
    files in `dBuild/` and in the subdirectories in `src/core`, `src/plugins` 
	and possibly elsewhere.

    Run `make` in the Doreen source root, and everything should run magically.

    An `out/` subdirectory should appear with temporary files in it. You can
    always safely delete the entire `out/` directory tree, it will get rebuilt 
	on the next `make` run.

    The final output files are then copied to the proper locations under `htdocs/`.

    By default, the build system will compress and mangle the JavaScript output
    files, which may hinder debugging. Create a file `config.in` in the Doreen
    root with a single line saying `DEBUG=1` and run `make clean; make`. That will
    rebuild everything without JavaScript uglification.

 6. When programming, you should run `make` on the command line (which will run 
	webpack as needed) and then hit reload in the browser. You may want to configure 
	your editor to do that with a keystroke.

 7. The build process also builds translations using the GNU `gettext` command 
	line utilities, in particular `msgfmt`. This pulls PO files for all locales
    (currently only de_DE) from the sources and compiles a single MO file from
    them, creating `locale/de_DE/LC_MESSAGES` automatically. You can always
    delete the entire `locale/` tree, it will be regenerated by `make`.

    Note that the MO file is loaded and kept open in the Apache PHP process.
    If you change translations, they might not be picked up until you restart
    Apache (although this seems to have been fixed in recent PHP versions).
