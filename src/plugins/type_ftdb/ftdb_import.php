<?php

/********************************************************************
 *
 *  Global constants
 *
 ********************************************************************/

namespace Doreen;


class FTDBCategory
{
    public $uuid;
    public $uuidParent;     // or NULL if none
    public $name;

    /** @var  FTDBCategory $oParent */
    public $oParent;        // or NULL if none

    /** @var FTCategory $oCategory */
    public $oCategory;
}

/********************************************************************
 *
 *  FTDBImport class
 *
 ********************************************************************/

/**
 *  Create an instance with the connection data of the FTDB database to import into Doreen
 *  and then call the methods in the order given here.
 */
class FTDBImport
{
    /** @var DatabasePostgres $oFTDB */
    public $oFTDB;
    public $fExecute;
    public $aUser;

    /** @var Ticket $oTemplateFTArticle */
    public $oTemplateFTArticle;

    /** @var Ticket[] $aCreatedByVariantID */
    public $aCreatedByVariantID = [];

    /** @var FTCategory[] $aCategoriesByUUID */
    public $aCategoriesByUUID = [];     // UUID => FTCategory

//    public $aKits = [];            # oTicket => string list of kit UUIDs (to be resolved)

    /**
     *  Constructor.
     */
    public function __construct($dbhost,
                                $dbname,
                                $dbuser,
                                $fExecute)
    {
        $this->oFTDB = Plugins::InitDatabase('db_postgres');
        $this->oFTDB->connectAs($dbhost, $dbuser, $dbuser, $dbname);

        $this->oTemplateFTArticle = Ticket::FindTemplateFromConfigKeyOrThrow(PluginFTDB::CONFIGKEY_PART_TEMPLATE_ID);

        $this->fExecute = $fExecute;

        Globals::$fImportingTickets = TRUE;         # disable checks in SelectFromSetHandler
    }

    private function importCategory(FTDBCategory $o)
    {
        if (!($oReturn = getArrayItem($this->aCategoriesByUUID, $o->uuid)))
        {
            $oParent = NULL;
            if ($o->uuidParent)
                if (!($oParent = getArrayItem($this->aCategoriesByUUID, $o->uuidParent)))
                    // Recurse to create parent first.
                    $oParent = $this->importCategory($o->oParent);

            $oReturn = FTCategory::Create($o->name, $oParent);
            echo "Created FTCategory $o->name for $o->uuid\n";
            $this->aCategoriesByUUID[$o->uuid] = $oReturn;
        }

        return $oReturn;
    }

    /**
     * Step 1 of import.
     */
    public function importArticleVariants(Callable $pfnCallback)
    {
        # Get the article numbers first. We can have several per article variant.
        if (!($res = $this->oFTDB->exec(<<<SQL
SELECT
    articlevariant,
    fromyear,
    number
FROM articlenumber
SQL
        )))
            throw new DrnException("Cannot find article numbers in source FTDB");

        $aArticleNos = [];           # key = articlevariant, value = [ [ fromyear, articleno], ... ]
        while ($row = $this->oFTDB->fetchNextRow($res))
        {
            $variantID = $row['articlevariant'];
            $fromyear = $row['fromyear'];
            $number = $row['number'];

            $articleno = [ $fromyear, $number, ];

            if (!isset($aArticleNos[$variantID]))
                # first articleno for this variant: store as array with one item
                $aArticleNos[$variantID] = [ $articleno ];
            else
                # subsequent: then append to array
                $aArticleNos[$variantID][] = $articleno;
        }

        /* Get categories next. */
        $aAllCategories = [];
        if (!($res = $this->oFTDB->exec(<<<SQL
SELECT 
    id,
    parentcategory,
    caption
FROM category
SQL
        )))
            throw new DrnException("Cannot find categories in source FTDB");

        while ($row = $this->oFTDB->fetchNextRow($res))
        {
            $o = new FTDBCategory();
            $o->uuid = $row['id'];
            $o->uuidParent = $row['parentcategory'];
            $o->name = $row['caption'];

            $aAllCategories[$o->uuid] = $o;
        }

        /* Now resolve parents. */
        foreach ($aAllCategories as $uuid => $o)
            if ($o->uuidParent)
                $o->oParent = $aAllCategories[$o->uuidParent];

        /* Now create FTCategories accordingly. */
        foreach ($aAllCategories as $uuid => $o)
            $this->importCategory($o);

        $groupConcatKits = $this->oFTDB->makeGroupConcat('partslist.articlevariant');

        # articlevariant
        $res = $this->oFTDB->exec(<<<SQL
SELECT
    articlevariant.id,
    articlevariant.article  AS article_uuid,
    article.caption         AS article_name,
    article.remarks         AS article_remarks,
    article.category        AS category_uuid,
    article.weighting       AS article_weight,
    articlevariant.year,
    articlevariant.color    AS color_uuid,
    color.caption           AS color_name,
    articlevariant.language AS language_uuid,
    language.caption        AS language_name,
    articlevariant.remarks,
--    (SELECT $groupConcatKits FROM partslist
--        WHERE partslist.containedarticle = articlevariant.id
--    ) AS kits,
--     JSON_AGG(SELECT fromyear, number FROM articlenumber WHERE articlenumber.articlevariant = articlevariant.id) AS articlenumbers,
    COUNT(image.articlevariant) AS c_images
FROM articlevariant
LEFT JOIN article ON articlevariant.article = article.id
LEFT JOIN color ON articlevariant.color = color.id
LEFT JOIN language ON articlevariant.language = language.id
LEFT JOIN image ON image.articlevariant = articlevariant.id
GROUP BY articlevariant.id, article.caption, article.remarks, article.category, color.caption, language.caption, article.weighting
ORDER BY article_name
SQL
        );

        $c = 0;
        while ($row = $this->oFTDB->fetchNextRow($res))
        {
            $variantID = $row['id'];
            $name = $row['article_name'];
            $year = $row['year'];
            $remarks = $row['article_remarks'];
            $uuidCategory = $row['category_uuid'];
            $color = $row['color_name'];
            $lang = $row['language_name'];
            $weight = $row['article_weight'];
//            $kitslist = $row['kits'];

            $title = $name;
            if ($year)
                $title .= " ($year)";
            if ($color)
                $title .= " ($color)";
            if ($lang)
                $title .= " ($lang)";

            $articlenos = NULL;
            if ($a = getArrayItem($aArticleNos, $variantID))
                $articlenos = json_encode($a);

            if (!($oCategoryLeaf = getArrayItem($this->aCategoriesByUUID, $uuidCategory)))
                throw new DrnException("Cannot find category for UUID $uuidCategory");

            $oCategoryRoot = $oCategoryLeaf;
            while ($oParentCategory = $oCategoryRoot->getParent())
                $oCategoryRoot = $oParentCategory;

            $aData = [  'title' => $title,
                        'description' => $remarks,
                        'ft_variant_uuid' => $variantID,
                        'ft_article_nos' => $articlenos,
                        'ft_cat_all' => $oCategoryLeaf->id,
                        'ft_weight' => $weight,
                     ];

            $print = $variantID;

            if ($this->fExecute)
            {
                $oCreated = $this->oTemplateFTArticle->createAnother(LoginSession::$ouserCurrent,
                                                                     LoginSession::$ouserCurrent,
                                                                     $aData,
                                                                     FALSE,
                                                                     Ticket::CREATEFL_NOCHANGELOG | Ticket::CREATEFL_NOMAIL);

                $this->aCreatedByVariantID[$variantID] = $oCreated;
//                if ($kitslist)
//                    $this->aKits[$oCreated->id] = $kitslist;

                $ticket_id = $oCreated->id;
                $print = "<a href=\"".Globals::$rootpage."/ticket/$ticket_id\">$print</a>";
            }

            $artnos = ''; # toHTML($row['articlenumbers']);

            ++$c;
            $pfnCallback($this,
                         $c,
                         [ $name,
                           $print,
                           $remarks,
                           $artnos,
                           $year,
                           $color,
                           $lang,
//                           implode(', ', explode(',', $kitslist)),      # add extra space after every comma
                           $row['c_images']
                         ]);
        }
    }

    /**
     * Step 2 of import.
     */
    public function resolveKitContents()
    {
        TicketField::GetAll();

        if (!($res = $this->oFTDB->exec(<<<SQL
SELECT 
  articlevariant AS kit, 
  containedarticle AS part, 
  amount AS c
FROM partslist
SQL
        )))
            throw new DrnException("Failed to get partslist");

        # 'contains' field handler expects "ticketid:count,..." syntax
        $aContentsByKit = [];           // kit ticket ID => [ part ticket object, count], ...
        while ($row = $this->oFTDB->fetchNextRow($res))
        {
            $kit = $row['kit'];
            $part = $row['part'];
            $c = $row['c'];

            if (!($oKit = getArrayItem($this->aCreatedByVariantID, $kit)))
                throw new DrnException("Cannot find kit for UUID $kit");
            if (!($oPart = getArrayItem($this->aCreatedByVariantID, $part)))
                throw new DrnException("Cannot find kit for UUID $part");

            $pair = [ $oPart, $c ];
            arrayMakeOrPush($aContentsByKit, $oKit->id, $pair);
        }

        foreach ($aContentsByKit as $idKit => $llPairs)
        {
            $aStrings = [];
            foreach ($llPairs as $pair)
            {
                /** @var Ticket $oPart */
                $oPart = $pair[0];
                $c = $pair[1];
                $aStrings[] = $oPart->id.":$c";
            }

            if (count($aStrings))
            {
                if (!($oKit = Ticket::$aAwakened[$idKit]))
                    throw new DrnException("Cannot find ticket for kit ID $idKit");

                $str = implode(',', $aStrings);
                Debug::FuncEnter(0, "Setting \"$str\"");
                $aData['ft_contains'] = $str;
                $oKit->update(LoginSession::$ouserCurrent,
                              $aData,
                              Ticket::CREATEFL_NOCHANGELOG | Ticket::CREATEFL_IGNOREMISSING);
                Debug::FuncLeave();
            }
        }
    }

    private function attachFileToTicket(Ticket $oTicket,
                                        $tblname,
                                        FTImportFile $oFile,
                                        &$print)
    {
        $print = "<a href=\"".Globals::$rootpage."/ticket/$oTicket->id\">$print</a>";
        $res2 = $this->oFTDB->exec("SELECT attachment FROM $tblname WHERE id = $1",
                                                                             [ $oFile->uuid ]);
        $row2 = $this->oFTDB->fetchNextRow($res2);
        $contents = $this->oFTDB->decodeBlob($row2['attachment']);
        $oBinary = Binary::CreateUploading($oFile->filename,
                                           $oFile->mimetype,
                                           $oFile->size,
                                           NULL);
        $oTicket->createOneAttachment($oBinary,
                                      $contents,
                                      json_encode( [ 'plugin' => 'ftdb',
                                                     'ft_attach_type' => $oFile->attachmentType
                                                   ] ));
        Changelog::AddTicketChange(FIELD_ATTACHMENT,
                                   $oTicket->id,
                                   NULL,
                                   gmdate('Y-m-d H:i:s'),
                                   $oBinary->idBinary,
                                   NULL);
    }

    /**
     *  Step 3 of import.
     */
    public function importImages(Callable $pfnCallback)
    {
        # images
        if (!($res = $this->oFTDB->exec(<<<SQL
SELECT
    image.id AS file_id,
    articlevariant,     -- uuid
    description,        -- varchar
    -- attachment,         -- bytea
    octet_length(attachment) AS size,
    imagesubject,     -- references imagesubject (Baukasten-Außenansicht, Baukasten-Innenansicht, Baukasten-Sortierplan, Bauteil-Foto, CAD-Zeichnung oder älteres Foto eines Bauteils, Standardansicht)
    originalfilename,   -- varchar
    attachmentsource,   -- references attachmentsource
    imagetype.mimetype AS mimetype,
    width,
    height,
    year
FROM image
JOIN imagetype ON imagetype.id = image.imagetype
ORDER BY articlevariant;
SQL
        )))
            throw new DrnException("Failed to get images");

        $c = 0;
        /** @var FTImportImage[] $aImages */
        $aImages = [];      // key = variantID, value = list of images
        while ($row = $this->oFTDB->fetchNextRow($res))
        {
            $variantID = $row['articlevariant'];
            arrayMakeOrPush($aImages,
                            $variantID,
                            new FTImportImage($row));
        }

        foreach ($aImages as $variantID => $llImages)
        {
            $oTicket = $this->aCreatedByVariantID[$variantID];

            /** @var FTImportImage[] $llImages */

            /* Now make buckets for all file name stems for this articlevariant UUID.
               For each stem, pick the one with the largest width. */
            $aBestFileByStem = [];
            foreach ($llImages as $oFile)
            {
                if (!($oExisting = getArrayItem($aBestFileByStem, $oFile->stemWithoutSizeSuffix)))
                    # First one for this stem:
                    $aBestFileByStem[$oFile->stemWithoutSizeSuffix] = $oFile;
                else
                    # Compare resolutions.
                    if ($oFile->width > $oExisting->width)
                        $aBestFileByStem[$oFile->stemWithoutSizeSuffix] = $oFile;
                    # else: existing is better, drop this
            }

            foreach ($aBestFileByStem as $oFile)
            {
                /** @var FTImportImage $oFile */
                $print = $oFile->filename;

                ++$c;
                $pfnCallback($this,
                             $c,
                             $print,
                             $oFile);

                if ($this->fExecute)
                    $this->attachFileToTicket($oTicket,
                                              'image',  # tablename
                                              $oFile,
                                              $print);
            }
        }

        # Delete entire thumbnails cache.
        FTIconHandler::ClearCache();
    }

    /**
     *  Step 3 of import.
     */
    public function importDocuments(Callable $pfnCallback)
    {
        # images
        if (!($res = $this->oFTDB->exec(<<<SQL
SELECT
    document.id AS file_id,
    articlevariant,     -- uuid
    description,        -- varchar
    -- attachment,         -- bytea
    octet_length(attachment) AS size,
    originalfilename,   -- varchar
    documenttype.mimetype AS mimetype,
    year
FROM document
JOIN documenttype ON documenttype.id = document.documenttype
ORDER BY articlevariant;
SQL
                                 )))
            throw new DrnException("Failed to get attachments");

        $c = 0;
        /** @var FTImportFile[] $aDocuments */
        $aDocuments = [];      // key = variantID, value = list of images
        while ($row = $this->oFTDB->fetchNextRow($res))
        {
            $variantID = $row['articlevariant'];
            arrayMakeOrPush($aDocuments, $variantID, new FTImportFile($row));
        }

        foreach ($aDocuments as $variantID => $llDocs)
        {
            $oTicket = $this->aCreatedByVariantID[$variantID];

            /** @var FTImportFile[] $llDocs */

            foreach ($llDocs as $oFile)
            {
                /** @var FTImportFile $oFile */
                $print = $oFile->filename;

                ++$c;
                $pfnCallback($this,
                             $c,
                             $print,
                             $oFile);

                if ($this->fExecute)
                    $this->attachFileToTicket($oTicket,
                                              'document', # tablename
                                              $oFile,
                                              $print);
            }

        }
    }

}

