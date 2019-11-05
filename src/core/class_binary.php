<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Binary class
 *
 ********************************************************************/

/**
 *  Encapsulates a binary file attached to a ticket as stored in the \ref ticket_binaries table.
 *
 *  This is used in three ways:
 *
 *   -- via CreateUploading() when a file gets uploaded as a ticket attachment (this is also simulated
 *      when tickets are imported from another database);
 *
 *   -- via CreateFromChangelogRow() to create a binary that represents a ticket attachment from
 *      a Changelog row;
 *
 *   -- via Load(), which is mostly used when the binary contents need to be fully loaded, e.g.
 *      when they need to be dumped to the user as a download.
 */
class Binary
{
    public $filename;       /* The actual filename as specified in the upload which should be stored
                               in the table and returned when the file is downloaded again. This must
                               not have a path. */
    public $mimetype;       /* MIME type, e.g. image/jpeg or application/pdf */
    public $size;           /* Size of upload in bytes. */

    // The following are only set while processing a fresh upload, not when getting existing binaries.
    public $tmp_name;       /* The fully qualified location (probably under /tmp) where the file can
                               be currently found. */

    // The following are set after the file has been stored as an attachment.
    public $idBinary = NULL;        /* Row ID in the ticket_binaries table */
    public $localFile = NULL;       /* NULL if the file was stored in the database, or the fully qualified file name under DOREEN_ATTACHMENTS_DIR */
    public $cx = NULL;              /* Width of an image file, or NULL */
    public $cy = NULL;              /* Height of an image file, or NULL */
    public $idTicket = NULL;

    private function __construct()
    {
    }

    /**
     *  Factory method to create a binary for during the "uploading a file" case.
     *
     *  This sets the filename, mimetype, tmp_name, size members.
     */
    public static function CreateUploading($filename,
                                           $mimetype,
                                           $size,
                                           $tmp_name)       //!< in: temporary file with data or NULL
        : Binary
    {
        $o = new self();
        $o->filename = $filename;
        $o->mimetype = $mimetype;
        $o->size = $size;
        $o->tmp_name = $tmp_name;

        return $o;
    }

    private static function CreateImpl($filename,
                                       $mimetype,
                                       $size,
                                       $idBinary,
                                       $cx = NULL,
                                       $cy = NULL)
        : Binary
    {
        $o = new self();

        $o->filename = basename($filename);
        $o->mimetype = $mimetype;

        $o->idBinary = $idBinary;

        $o->localFile = NULL;

        # Negative filesize means that file is not in database, but in DOREEN_ATTACHMENTS_DIR.
        if ($size < 0)
        {
            $o->size = -$size;
            $o->localFile = $filename;

            if (preg_match('/(?:.*\/)?\d\d\d\d-\d\d-\d\d-\d\d-\d\d-\d\d_[^_]+_([^\/]+)$/', $o->localFile, $aMatches))
            {
                $o->localFile = DOREEN_ATTACHMENTS_DIR.'/'.basename($o->localFile);
                $o->filename = urldecode($aMatches[1]);
            }
        }
        else
            $o->size = $size;

        $o->cx = $cx;
        $o->cy = $cy;

        return $o;
    }

    public static function CreateFromChangelogRow(ChangelogRow $oRow)
        : Binary
    {
        return self::CreateImpl($oRow->filename,
                                $oRow->mime,
                                $oRow->filesize,
                                $oRow->value_1,
                                $oRow->cx,
                                $oRow->cy);
    }

    public static function Load($idBinary)
        : Binary
    {
        if (    (!($dbres = Database::DefaultExec(<<<EOD
SELECT
    ticket_id,
    filename,
    mime,
    size AS filesize,
    cx,
    cy
FROM ticket_binaries WHERE i = $1
EOD
                , [ $idBinary ] )))
            || (!($dbrow = Database::GetDefault()->fetchNextRow($dbres)))
        )
            throw new DrnException(L('{{L//Invalid binary ID %ID%}}', [ '%ID%' => $idBinary ]));

        $o = self::CreateImpl($dbrow['filename'],
                              $dbrow['mime'],
                              $dbrow['filesize'],
                              $idBinary,
                              $dbrow['cx'],
                              $dbrow['cy']);

        $o->idTicket = $dbrow['ticket_id'];

        return $o;
    }

    /**
     *  Called after an upload has been successfully converted into a ticket attachment
     *  and written into \ref ticket_binaries.
     */
    public function setInfo($idBinary,          //!< in: binary ID (row ID in tickets_binary)
                            $localFile,         //!< in: NULL if the file was stored in the database, or the fully qualified file name under DOREEN_ATTACHMENTS_DIR
                            $cx,                //!< in: width of image file or NULL
                            $cy)                //!< in: height of image file or NULL
    {
        $this->idBinary = $idBinary;
        $this->localFile = $localFile;
        $this->cx = $cx;
        $this->cy = $cy;
    }

    /**
     *  Echoes the contents of this binary attachment to the client browser and calls exit.
     *  Does not return.
     *
     *  This is part of the implementation for the GET /binary GUI request.
     *
     *  This calls \ref WholePage::EmitBinaryHeaders().
     */
    public function emitAndExit()
    {
        if ($this->localFile)
            if (!is_readable($this->localFile))
                throw new DrnException("Internal error: local file ".Format::UTF8Quote(basename($this->localFile))." has been lost. Please contact the administrator.");

        WholePage::EmitBinaryHeaders($this->filename, $this->mimetype, $this->size);

        # 2) Just echo the binary data and leave.
        if ($this->localFile)
            # readfile() reads the contents of a file and echoes it to the client browser directly.
            readfile($this->localFile);
        else
        {
            # Actually load the data from the database.
            if (    (!($dbres = Database::DefaultExec('SELECT data FROM ticket_binaries WHERE i = $1', [ $this->idBinary ] )))
                 || (!($dbrow = Database::GetDefault()->fetchNextRow($dbres)))
               )
                throw new DrnException(L("{{L//Cannot fetch data for binary ID %ID%}}", [ '%ID%' => $this->idBinary ] ));

            echo Database::GetDefault()->decodeBlob($dbrow['data']);
        }

        exit;
    }

    /**
     *  Ensure the passed-in file name is a valid filename for this binary.
     *  Returns the validated file name.
     *
     *  @return string
     */
    public function generateFilename(string $filename)
        : string
    {
        $newEnding = pathinfo($filename, PATHINFO_EXTENSION);
        if (!$newEnding)
        {
            $oldEnding = pathinfo($this->filename, PATHINFO_EXTENSION);
            $filename .= '.'.$oldEnding;
        }

        if ($this->localFile)
        {
            $basedName = basename($filename);
            if (preg_match('/(?:.*\/)?(\d\d\d\d-\d\d-\d\d-\d\d-\d\d-\d\d_[^_]+_)[^\/]+$/', $this->localFile, $aMatches))
                $basedName = $aMatches[1].$basedName;
            $filename = DOREEN_ATTACHMENTS_DIR.'/'.$basedName;
        }
        return $filename;
    }

    /**
     *  Rename the binary. Writes a changelog entry with the old name.
     */
    public function rename(string $newName,
                           User $oUserChanging = NULL)
    {
        $chg_uid = $oUserChanging ? $oUserChanging->uid : NULL;
        $dtNow = gmdate('Y-m-d H:i:s');

        Database::GetDefault()->beginTransaction();

        $newName = $this->generateFilename($newName);

        $aChange = [
            'oldName' => basename($this->localFile ?? $this->filename),
            'newName' => basename($newName),
        ];

        if (isset($this->localFile))
            rename($this->localFile, $newName);

        Database::DefaultExec("UPDATE ticket_binaries SET filename=$1 WHERE i=$2",
                              [ $newName,
                                $this->idBinary ]);

        Changelog::AddTicketChange(FIELD_ATTACHMENT_RENAMED,
                                   $this->idTicket,
                                   $chg_uid,
                                   $dtNow,
                                   $this->idBinary,
                                   NULL,
                                   json_encode($aChange));

        Database::GetDefault()->commit();
    }

    /**
     *  Hides the binary. Writes a changelog entry.
     */
    public function hide(User $oUserChanging)
    {
        $chg_uid = $oUserChanging ? $oUserChanging->uid : NULL;
        $dtNow = gmdate('Y-m-d H:i:s');

        Database::DefaultExec("UPDATE ticket_binaries SET hidden=TRUE WHERE i=$1",
                              [ $this->idBinary ]);

        Changelog::AddTicketChange(FIELD_ATTACHMENT_DELETED,
                                   $this->idTicket,
                                   $chg_uid,
                                   $dtNow,
                                   $this->idBinary,
                                   NULL);
    }

    /**
     *  Returns a link address for the given binary.
     */
    public static function MakeHREF($idBinary)
    {
        return WebApp::MakeUrl("/binary/$idBinary");
    }

}
