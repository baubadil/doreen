<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  DrnThumbnailer class
 *
 ********************************************************************/

/**
 *
 */
class DrnThumbnailer
{
    /**
     *  Clears the entire thumbnails cache.
     */
    public static function ClearCache()
    {
        Database::GetDefault()->tryExec("DELETE FROM thumbnails");
    }

    /**
     *  Emits the binary data for the thumbnail. This is the implementation
     *  for the HTTP GET /thumbnail request.
     *
     *  If $fSquare = TRUE, we create a $thumbsize x $thumbsize square and
     *  center the source image in it if it is not square.
     *
     *  If $fSquare = FALSE, we create a thumbnail with the same proportions
     *  as the source image that is at most $thumbsize pixels wide (if
     *  landscape orientation) or high (if portrait orientation).
     */
    public static function Output($idBinary,
                                  $thumbsize,
                                  $fSquare = FALSE)
    {
        $imageData = NULL;

        $res = Database::GetDefault()->tryExec(<<<SQL
SELECT * FROM thumbnails WHERE binary_id = $1 AND thumbsize = $2
SQL
                                       , [ $idBinary,         $thumbsize ] );
        if (Database::GetDefault()->numRows($res))
        {
            Debug::Log(Debug::FL_THUMBNAILER, "Image found in DB");

            $row = Database::GetDefault()->fetchNextRow($res);
            $imageData = Database::GetDefault()->decodeBlob($row['data']);
        }
        else
        {
            Debug::Log(Debug::FL_THUMBNAILER, "Image not in DB: creating new thumbnail");
            $oBinary = Binary::Load($idBinary);

            $cxSrc = $cySrc = $type = $cxThumb = $cyThumb = $xTarget = $yTarget = 0;
            $cxTarget = $cyTarget = $thumbsize;

            // Preparations
            if ($localfile = $oBinary->localFile)
            {
                $aSrcSize = getimagesize($localfile);
                $cxSrc = $aSrcSize[0];
                $cySrc = $aSrcSize[1];
                $type = $aSrcSize[2];

                if ($cxSrc > $cySrc)
                {
                    # Landscape (wide image):
                    $cxThumb = $thumbsize;
                    if ($fSquare)
                    {
                        $cyThumb = $thumbsize;
                        # height needs to be smaller than $thumbsize
                        $cyTarget = $thumbsize * $cySrc / $cxSrc;
                        # Center horizontally:
                        $yTarget = ($thumbsize - $cyTarget) / 2;
                    }
                    else
                    {
                        $cyThumb = $thumbsize * $cySrc / $cxSrc;
                        $cyTarget = $cyThumb;
                    }
                }
                else
                {
                    # Portrait (tall image):
                    $cyThumb = $thumbsize;
                    if ($fSquare)
                    {
                        $cxThumb = $thumbsize;
                        $cxTarget = $thumbsize * $cxSrc / $cySrc;
                        # Center vertically:
                        $xTarget = ($thumbsize - $cxTarget) / 2;
                    }
                    else
                    {
                        $cxThumb = $thumbsize * $cxSrc / $cySrc;
                        $cxTarget = $cxThumb;
                    }
                }
            }

            $thumb = imagecreatetruecolor($cxThumb, $cyThumb);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $col = imagecolorallocatealpha($thumb,255,255,255,127);
            imagefill($thumb, 0, 0, $col);

            if ($localfile)
            {
                $srcImage = NULL;

                switch ($type)       # type
                {
                    // 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP, 7 = TIFF(intel byte order), 8 = TIFF(motorola byte order), 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF, 15 = WBMP, 16 = XBM
                    case 1: // GIF
                        $srcImage = imagecreatefromgif($localfile);
                    break;

                    case 2: // JPEG
                        $srcImage = imagecreatefromjpeg($localfile);
                    break;

                    case 3: // PNG
                        $srcImage = imagecreatefrompng($localfile);
                    break;
                }

                if ($srcImage)
                {
                    imagecopyresampled($thumb,      # destination
                                       $srcImage,   # source
                                       $xTarget,    # dst_x
                                       $yTarget,    # dst_x
                                       0,           # src_x
                                       0,           # srx_y
                                       $cxTarget,   # dst_w
                                       $cyTarget,   # dst_h
                                       $cxSrc,      # src_w
                                       $cySrc       # src_h
                                      );
                }
            }

            /* Get the image data by echoing into a temporary output buffer. */
            ob_start();
            imagepng($thumb);
            $imageData = ob_get_contents();
            ob_end_clean();
            imagedestroy($thumb);

            Database::DefaultExec(<<<SQL
INSERT INTO thumbnails ( binary_id,  thumbsize,  data )
                VALUES ( $1,         $2,         $3 )
SQL
                     , [ $idBinary,  $thumbsize, Database::GetDefault()->encodeBlob($imageData) ] );
        }

        header('Content-Type: image/png');
        echo $imageData;
    }
}
