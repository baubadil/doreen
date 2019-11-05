<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


class BackupRestore
{
    /*
     *  Name of the file containing meta information of the backup.
     */
    const BACKUP_META_FILE = "doreen-backup-meta.json";
    /*
     *  RegExp to find configuration files.
     */
    const CONFIG_REGEXP = '/^doreen-.+\.inc\.php$/';

    /**
     *  Back up the configuration, attachments and database of the current
     *  installation in a xz archive.
     *  Includes an additional doreen-backup-meta.json file with information on
     *  the backup's origin.
     *
     *  The backup will be prepared in a subfolder of the target directory that
     *  is deleted once the backup archive is complete.
     */
    public static function Backup(string $dir,
                                  string $installDir)
    {
        Globals::EchoIfCli("Backing up");
        if (!is_dir($dir))
            throw new DrnException(Format::UTF8Quote($dir)." is not a directory");

        $dbname = Database::$defaultDBName;

        $otsNow = Timestamp::Now();
        $date = $otsNow->toDateString();
        $particle = "backup-$dbname-$date";
        $subdirPath = FileHelpers::MakePath($dir, $particle);

        $datadir = DOREEN_DATA_DIR;
        $attachdir = DOREEN_ATTACHMENTS_BASENAME;

        if (!is_readable($datadir.'/'.$attachdir.'/'.$dbname))
            throw new DrnException("Cannot read attachments directory ".Format::UTF8Quote($datadir.'/'.$attachdir.'/'.$dbname));

        if (!is_writable($dir))
            throw new DrnException("Cannot write to the backup target directory ".Format::UTF8Quote($dir));

        /*
         *  Make directory
         */
        FileHelpers::MakeDirectory($subdirPath);
        Globals::EchoIfCli("Created subdirectory $subdirPath");

        /*
         *  Create postgres dump
         */
        $outfile = "$subdirPath/$particle.sql";
        Database::GetDefault()->generateDump($outfile);

        /*
         *  Write doreen-backup-meta.json
         */
        $metaname = self::BACKUP_META_FILE;
        $metafile = "$subdirPath/$metaname";
        $aMeta = [ '_comment' => "THIS IS A DOREEN BACKUP METAFILE. IT HAS BEEN GENERATED AUTOMATICALLY, DO NOT EDIT",
                   'version' => DATABASE_VERSION,
                   'dt-created-utc' => $otsNow->toDateTimeString(),
                   'hostname' => trim(`hostname`),
                   'db-name' => $dbname
                 ];
        Globals::EchoIfCli("Creating backup meta file");
        FileHelpers::FilePutContents($metafile, json_encode( $aMeta, JSON_PRETTY_PRINT ));

        /*
         *  Find all relevant configuration files
         */
        Globals::EchoIfCli("Collecting configuration files");
        $configFiles = FileHelpers::GlobRE($installDir, self::CONFIG_REGEXP);
        $configFileNames = implode("' '", $configFiles);

        /*
         *  tar.xz everything
         */
        $outfile = "$dir/$particle.tar.xz";
        // Make install dir an absolute path if it is not yet.
        if ($installDir[0] === '/')
            $configdir = $installDir;
        else
            $configdir = getcwd().'/'.$installDir;

        $cmd = "tar cJv --transform 's|$attachdir/$dbname|$attachdir|' -f '$outfile' -C '$subdirPath' '$metaname' '$particle.sql' -C '$datadir' '$attachdir/$dbname' -C '$configdir' '$configFileNames'";
        Globals::EchoIfCli("Creating archive: ".Format::UTF8Quote($cmd));
        system($cmd);

        /**
         *  Clean up backup dir
         */
        FileHelpers::RemoveDirectory($subdirPath);
    }

    /**
    *  Read the configuration file and extract the database parameters.
    *  @return array
    */
    private static function ReadDBConfig(string $configPath) : array
    {
        $dbConfigContents = FileHelpers::GetContents($configPath);
        preg_match_all('/define\(\s*"([^"]+)"\,\s*"([^"]+)"\s*\)/', $dbConfigContents, $aMatches);
        $dbParams = [];
        foreach ($aMatches[0] as $i => $m)
            $dbParams[$aMatches[1][$i]] = $aMatches[2][$i];

        return $dbParams;
    }

    /**
     *  Restore a backup created with \ref BackupRestore::Backup as a deactivated
     *  installation.
     */
    public static function Restore(string $backupPath, string $installDir, $fForce = false)
    {
        /*
         *  Extract the backed up files
         */
        $basename = basename($backupPath, '.tar.xz');
        $tempPath = dirname($backupPath).'/'.$basename;

        Globals::EchoIfCli("Creating temp directory to extract into: ".Format::UTF8Quote($tempPath));
        FileHelpers::MakeDirectory($tempPath);

        $cmd = "tar xJvf '$backupPath' -C '$tempPath'";
        Globals::EchoIfCli("Extracting backup: ".Format::UTF8Quote($cmd));
        system($cmd);

        /*
         *  Check if the backup is compatible with this installation.
         */
        $metaContents = FileHelpers::GetContents($tempPath.'/'.self::BACKUP_META_FILE);
        $dbConfig = self::ReadDBConfig($tempPath.'/doreen-install-vars.inc.php');
        $meta = json_decode($metaContents, true);

        Globals::EchoIfCli("Backup meta data:\n"
                           ."  Version: ".$meta['version']."\n"
                           ."  DB Name: ".$meta['db-name']."\n"
                           ."  Created: ".$meta['dt-created-utc']."\n"
                           ."  Hostname: ".$meta['hostname']);

        if ($meta['version'] > DATABASE_VERSION)
            throw new DrnException("Backup is from a newer database version than this doreen installation supports");

        if ($meta['db-name'] !== $dbConfig['DBNAME'])
            throw new DrnException("Invalid backup. Database name does not match.");

        $attachDir = DOREEN_ATTACHMENTS_BASENAME;
        if (!is_writable(DOREEN_DATA_DIR.'/'.$attachDir))
            throw new DrnException("Cannot restore attachments, please ensure you have writing permissions to ".Format::UTF8Quote(DOREEN_DATA_DIR.'/'.$attachDir));

        $installations = Install::Get($installDir);
        $fIsCurrent = false;
        $fExists = false;
        foreach ($installations as $installation)
            if ($dbConfig['DBNAME'] === $installation->dbname)
            {
                if (!$fForce)
                    throw new DrnException("Instance already has an installation with the DB name ".Format::UTF8Quote($installation->dbname).". If you have a backup of the current installation with the same name, use --force to override it.");
                else
                {
                    $fIsCurrent = $installation->fIsCurrent;
                    $fExists = true;

                    if (constant('DBTYPE') !== $dbConfig['DBTYPE'])
                        throw new DrnException("Existing installation for ".Format::UTF8Quote($installation->dbname)." uses a different DB type. Cannot restore backup.");
                    else if (constant('DBHOST') !== $dbConfig['DBHOST'])
                        throw new DrnException("Existing installation for ".Format::UTF8Quote($installation->dbname)." is on a different database server. Cannot restore backup.");
                }
            }

        /*
         *  Restore backup
         */
        // Collect config files
        $llConfigFiles = FileHelpers::GlobRE($tempPath, self::CONFIG_REGEXP);
        $llCopyOperations = [];
        $suffix = '';

        // If this is not the currently activated install, restore the backup as alternative install.
        if (!$fIsCurrent)
            $suffix = '_'.$dbConfig['DBNAME'];
        foreach ($llConfigFiles as $file)
        {
            $llCopyOperations[$tempPath.'/'.$file] = $installDir.'/'.$file.$suffix;
        }

        // Collect attachments
        $attachTargetDir = DOREEN_DATA_DIR.'/'.$attachDir.'/'.$dbConfig['DBNAME'];

        if (!is_dir($attachTargetDir))
        {
            Globals::EchoIfCli("Creating sub-directory for attachments");
            FileHelpers::MakeDirectory($attachTargetDir);
        }

        $attachTempDir = $tempPath.'/'.$attachDir;
        $llAttachments = FileHelpers::GlobRE($attachTempDir, '/.{3,}/');
        foreach ($llAttachments as $file)
            $llCopyOperations[$attachTempDir.'/'.$file] = $attachTargetDir.'/'.$file;

        // Restore backed up files
        foreach ($llCopyOperations as $source => $dest)
        {
            Globals::EchoIfCli("Restoring ".Format::UTF8Quote($dest)." from ".Format::UTF8Quote($source));
            FileHelpers::CopyFile($source, $dest, $fExists);
        }

        // Restore database dump
        $dumpFiles = FileHelpers::GlobRE($tempPath, '/.+\.sql$/');
        if (count($dumpFiles) > 1)
            throw new DrnException('More than one candidate for the dump to restore found');
        else
            $dumpFile = $tempPath.'/'.$dumpFiles[0];

        if ($fIsCurrent)
            $db = Database::GetDefault();
        else
            $db = Plugins::InitDatabase('db_'.$dbConfig['DBTYPE']);

        $dbadminpwd = FileHelpers::PromptPassword("Database administrator password: ");
        $db->connectAdmin($dbConfig['DBHOST'], $dbadminpwd);

        if ($fExists)
        {
            Globals::EchoIfCli("Dropping existing database");
            $db->delete($dbConfig['DBNAME'], $dbConfig['DBUSER']);
        }

        Globals::EchoIfCli("Creating Database and user");
        $db->createUserAndDB($dbConfig['DBNAME'], $dbConfig['DBUSER'], $dbConfig['DBPASSWORD']);

        $db->restoreDump($dbConfig['DBNAME'], $dumpFile);

        /*
         * Remove extracted temporary files
         */
        Globals::EchoIfCli("Removing temporary directory");
        FileHelpers::RemoveDirectory($tempPath);

        $condReenable = $fIsCurrent ? '' : ' when the installation is activated';
        Globals::EchoIfCli("Successfully restored installation ".Format::UTF8Quote($dbConfig['DBNAME']).". A full re-index will have to be performed$condReenable.");
        if (!$fIsCurrent)
            Globals::EchoIfCli('To enable the restored installation, use '.Format::UTF8Quote('install-switch '.$dbConfig['DBNAME']));
        else
            GlobalConfig::FlagNeedReindexAll();
    }
}
