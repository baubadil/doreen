<?php

/********************************************************************
 *
 *  Global constants
 *
 ********************************************************************/

namespace Doreen;


/********************************************************************
 *
 *  Helpers
 *
 ********************************************************************/

require_once INCLUDE_PATH_PREFIX.'/plugins/type_ftdb/ftdb_import.php';

/*
 *  GET/import gets called as the first thing when a user wants to import.
 *
 */
function ftdbImportGet()
{
    require_once INCLUDE_PATH_PREFIX.'/core/class_dialog.php';
    $htmlTitle = L("{{L//Import from FTDB}}");

    $oHTML = new HTMLChunk();
    $oHTML->openPage($htmlTitle, TRUE, 'magic');

    $oHTML->addXmlDlg(dirname(__FILE__).'/ftdb_import_gui.xml',
                      [ '%IMPORTDBNAME%'   => (DBNAME == 'ftdb') ? 'ftdbread' : 'ftdb',
                      ]
                     );

    WholePage::Emit($htmlTitle, $oHTML);
}

function ftdbImportPost($dbhost,
                        $dbname,
                        $fExecute)
{
    ini_set('max_execution_time', 0);       # no time limit
    set_time_limit(0);
    ini_set('memory_limit', '-1');          # no memory limit

    require_once INCLUDE_PATH_PREFIX.'/core/class_dialog.php';
    $htmlTitle = L("{{L//Import from FTDB}}");

    WholePage::EmitHeader($htmlTitle);

    $oHTML = new HTMLChunk();
    $oHTML->openPage($htmlTitle, TRUE, 'magic');

    $oImport = new FTDBImport($dbhost,
                              $dbname,
                              'ftdbread',
                              $fExecute);
    $oImport->aUser['oHTML'] = $oHTML;

    $oHTML->openTable();
    $oHTML->addTableHeadings( [ 'Article',
                                'Variant ID',
                                'Article numbers',
                                'Remarks',
                                'Year',
                                'Color',
                                'Language',
//                                'Contained in',
                                'Images' ] );
    $oHTML->openTableBody();

    $oImport->importArticleVariants(function($that, $c, $aColumns)
                {
                    /** @var HTMLChunk $oHTML */
                    $oHTML = $that->aUser['oHTML'];
                    $oHTML->addTableRow($aColumns);
                    if (($c % 50) == 0)
                        $oHTML->flush();
                });

    $oHTML->close(); # table body
    $oHTML->close(); # table

    $oHTML->addLine("<h2>Resolving kit contents...</h2>");
    $oHTML->flush();

    $oImport->resolveKitContents();

    $oHTML->addLine("<p>Done!</p>");

    $oHTML->addLine("<h2>Loading images...</h2>");

    $oHTML->openTable();
    $oHTML->addTableHeadings( [ 'Variant ID', 'filename', 'mimetype', 'size' ] );
    $oHTML->openTableBody();

    $oHTML->flush();

    $oImport->importImages(function($that, $c, $aColumns)
                {
                    /** @var HTMLChunk $oHTML */
                    $oHTML = $that->aUser['oHTML'];
                    $oHTML->addTableRow($aColumns);
                    if (($c % 50) == 0)
                        $oHTML->flush();
                });

    $oHTML->close(); # table body
    $oHTML->close(); # table

    if (!$fExecute)
    {
        $oHTML->openForm(NULL, NULL, "method=\"post\" action=\"".Globals::$rootpage."/import-ftdb\"");
        $oHTML->addAlert("<b>Warning:</b> Pressing the below button will create lots and lots of records in your database, which cannot be undone except by deleting the database as a whole.",
                     NULL,
                     'alert-warning');
        $oHTML->addHidden('import-dbhost', $dbhost);
        $oHTML->addHidden('import-dbname', $dbname);
        $oHTML->addHidden('execute', 1);
        $oHTML->addSubmit("Yes, do the import!");
        $oHTML->addLine("<br>");
        $oHTML->close();    # form
    }
    else
        $oHTML->addAlert("<b>All done!</b>", NULL, 'alert-info');

    $oHTML->flush();
    WholePage::EmitFooter();
}
