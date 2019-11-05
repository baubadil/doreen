<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FTDB command line implementations
 *
 ********************************************************************/

/**
 *  Implementation for PluginFTDB::processArguments() to keep it out of the main file.
 */
function ftdbcliParseArguments(&$aArgv,
                               &$paStorage)            //!< in/out: temporary storage
{
    $rcCommandLineHandled = FALSE;

    foreach ($aArgv as $i => $arg)
    {
        switch ($arg)
        {
            case 'ftdb-import':
            case 'ftdb-reset':
            case 'ftdb-clear-caches':
                $paStorage['command'] = $arg;
                unset($aArgv[$i]);
                $rcCommandLineHandled = TRUE;
            break;
        }
    }

    return $rcCommandLineHandled;
}

function ftdbcliImport()
{
    Process::AssertRunningAsApacheSystemUser();

    Globals::DisableExecutionLimits();

    require_once INCLUDE_PATH_PREFIX.'/plugins/type_ftdb/ftdb_import.php';
    echo "Importing...\n";

    $oImport = new FTDBImport('localhost',
                              'ftdb',
                              'ftdbread',
                              $GLOBALS['g_fExecute']);           # --execute | -x

    $oImport->importArticleVariants(function($that, $c, $aColumns)
    {
        echo "$c: importing articleVariant: ".implode(' ', $aColumns)."\n";
    });

    echo "Resolving kit contents...\n";
    $oImport->resolveKitContents();
    echo "Done resolving kit contents!\n";

    $oImport->importImages(function($that,
                                    $c,
                                    $print,
                                    FTImportFile $oFile)
    {
        echo "$c: importing image: ".$oFile->describe()."\n";
    });

    $oImport->importDocuments(function($that,
                                       $c,
                                       $print,
                                       FTImportFile $oFile)
    {
        echo "$c: importing document: ".$oFile->describe()."\n";
    });

}

function ftdbcliReset()
{
    Process::AssertRunningAsApacheSystemUser();

    if (!($idType = GlobalConfig::GetIntegerOrThrow(PluginFTDB::CONFIGKEY_PART_TYPE_ID)))
        cliDie('Cannot find '.PluginFTDB::CONFIGKEY_PART_TYPE_ID.' in global config');

    if (!($fr = Ticket::FindMany([ SearchFilter::FromTicketTypes([$idType]),
                                   SearchFilter::NonTemplates()
                                 ])))
        cliDie("No fischertechnik tickets found.");

    $chunksize = 100;

    $cTickets = count($fr->aTickets);
    echo "Found ".Format::Number($cTickets)." tickets, deleting first $chunksize...\n";
    $c = 0;

    Globals::DisableExecutionLimits();

    while ($aChunk = $fr->fetchChunk($chunksize))
    {
        $strTicketIDs = Database::MakeInIntList(array_keys($aChunk));
        Database::DefaultExec(<<<SQL
DELETE FROM changelog 
WHERE what IN ($strTicketIDs);
SQL
        );

        Database::DefaultExec(<<<SQL
DELETE FROM tickets 
WHERE i IN ($strTicketIDs);
SQL
        );

        $c += $chunksize;
        echo "Deleted ".Format::Number($c)."\n";
    }

    Database::DefaultExec(<<<SQL
DELETE FROM categories
WHERE field_id = $1
SQL
             , [ FIELD_FT_CATEGORY_ALL ]);

    if ($oSearch = Plugins::GetSearchInstance())
        # Force reindex-all.
        SearchEngineBase::ReindexAllCli(5, 'reindex-all');
}

function ftdbcliProcessCommands(PluginFTDB $oPlugin,
                                $idSession)
{
    switch ($oPlugin->aStorage['command'])
    {
        case 'ftdb-import':
            ftdbcliImport();
        break;

        case 'ftdb-reset':
            ftdbcliReset();
        break;

        case 'ftdb-clear-caches':
            FTIconHandler::ClearCache();
        break;
    }
}

/**
 *  Implementation for PluginEV::addHelp().
 *
 */
function ftdbcliAddHelp(&$aHelpItems)
{
    $aHelpItems['ftdb-import [--execute|-x]'] = 'Import the FTDB from the ftdb PostgreSQL database into %DOREEN%';
    $aHelpItems['ftdb-reset [--execute|-x]'] = 'Delete all tickets imported from FTDB';
    $aHelpItems['ftdb-clear-caches'] = 'Reset all FTDB icon caches. Not harmful.';
}
