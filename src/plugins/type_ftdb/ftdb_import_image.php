<?php

/********************************************************************
 *
 *  Global constants
 *
 ********************************************************************/

namespace Doreen;


class FTImportFile
{
    public $uuid;
    public $filename;
    public $size;
    public $mimetype;
    public $attachmentType;

    public function __construct($row)
    {
        $this->uuid = $row['file_id'];
        $this->filename = $row['originalfilename'];
        $this->size = $row['size'];
        $this->mimetype = $row['mimetype'];
        $this->attachmentType = ATTACHTYPE_UNDEFINED;
    }

    public function describe()
    {
        return "\"$this->filename\" ($this->mimetype, $this->size bytes)";
    }
}

class FTImportImage extends FTImportFile
{
    const TYPE_KIT_EXTERIOR = "c91cead5-25a2-4769-9a8f-306f499e53b7";    #   Baukasten-Außenansicht
    const TYPE_KIT_INTERIOR = "c56cbb7e-2f7f-4d38-acf6-60effd680616";    #   Baukasten-Innenansicht
    const TYPE_KIT_SORTPLAN = "65f71827-b3a6-45a9-ae02-7d5151e4efcd";    #   Baukasten-Sortierplan
    const TYPE_PART_PHOTO = "e299780a-79ab-4664-9b6d-e148c58e557c";      #   Bauteil-Foto
    const TYPE_PART_CAD = "4da644fe-3613-4b06-bf1a-a8357e6b253f";        #   CAD-Zeichnung oder älteres Foto eines Bauteils
    const TYPE_DEFAULT = "a92f889b-d379-4420-b09f-97749cfa3fb8";         #   Standardansicht

    public $width;

    public $stemWithoutExt;
    public $stemWithoutSizeSuffix;

    public function __construct($row)
    {
        parent::__construct($row);

        $this->width = $row['width'];

        if (!(preg_match('/^(.*)\.[a-zA-Z]+$/', $this->filename, $aMatches)))
            throw new DrnException("Cannot determine file stem of image file \"$this->filename\"");

        $this->stemWithoutExt = $aMatches[1];

        if (preg_match('/^(.*)__(?:XS|S|L|XL|IN)$/', $this->stemWithoutExt, $aMatches))
            $this->stemWithoutSizeSuffix = $aMatches[1];
        else
            $this->stemWithoutSizeSuffix = $this->stemWithoutExt;

        switch ($subj = $row['imagesubject'])
        {
            case self::TYPE_KIT_EXTERIOR:
                $this->attachmentType = ATTACHTYPE_IMAGE_KIT_EXTERIOR;
            break;

            case self::TYPE_KIT_INTERIOR:
                $this->attachmentType = ATTACHTYPE_IMAGE_KIT_INTERIOR;
            break;

            case self::TYPE_KIT_SORTPLAN:
                $this->attachmentType = ATTACHTYPE_IMAGE_KIT_SORTPLAN;
            break;

            case self::TYPE_PART_PHOTO:
                $this->attachmentType = ATTACHTYPE_IMAGE_PART_PHOTO;
            break;

            case self::TYPE_PART_CAD:
                $this->attachmentType = ATTACHTYPE_IMAGE_PART_CAD;
            break;

            case NULL:
            case self::TYPE_DEFAULT:
                $this->attachmentType = ATTACHTYPE_IMAGE_DEFAULT;
            break;

            default:
                throw new DrnException("Invalid image type \"$subj\" in row ".print_r($row, TRUE));
        }
    }
}