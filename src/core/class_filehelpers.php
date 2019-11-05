<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Format class
 *
 ********************************************************************/

/**
 *  File-related helper code.
 */
abstract class FileHelpers
{

    public static function MakePath($dir, $file)
    {
        if (substr($dir, -1) != "/")
            return "$dir/$file";

        return $dir.$file;
    }

    /**
     *  An alternative to realpath() that doesn't return NULL if path particles don't exist.
     *  This simply removes "." and ".." particles from the given pathname without hitting
     *  the disk.
     */
    public static function RealPath($path)
    {
        $aParticles = [];

        if ($path[0] != DIRECTORY_SEPARATOR)
            $path = getcwd().DIRECTORY_SEPARATOR.$path;

        foreach (preg_split("/[\/\\\\]+/", $path) as $part)
        {
            if ('.' == $part)
                continue;

            if ('..' == $part)
                array_pop($aParticles);
            else
                $aParticles[] = $part;
        }
        return implode(DIRECTORY_SEPARATOR, $aParticles);
    }

    /**
     *  A variation of file_get_contents that can hopefully do without PHP errors or warnings.
     *  Instead this throws exceptions if the file does not exist or cannot be read.
     */
    public static function GetContents($filename)
        : string
    {
        if (!@file_exists($filename))
            throw new DrnException("Cannot open file $filename: file not found.");

        if (!($fp = @fopen($filename, "rb")))
            throw new DrnException("Cannot open file $filename: unknown error.");

        $str = @stream_get_contents($fp);
        fclose($fp);
        return $str;
    }

    /**
     *  Quick implementation of the unix "tail" program functionality. Useful for logfiles.
     *
     *  Returns an array of the last $lines in the given file.
     *
     *  http://tekkie.flashbit.net/php/tail-functionality-in-php
     */
    public static function Tail($file, $lines)
    {
        $cLines = 0;
        $aLines = [];
        if ($fh = fopen($file, "r"))
        {
            while (($line = fgets($fh)) !== false)
            {
                $aLines[] = $line;
                if ($cLines > $lines)
                    array_shift($aLines);
                ++$cLines;
            }
            fclose($fh);
            return $aLines;
        }

        return NULL;
    }

    /**
     *  Convenience wrapper around fopen() / flock() / fwrite().
     */
    static function WriteLocked($filename,
                                $contents)
    {
        if (!($fp = @fopen($filename, 'w')))
            throw new DrnException("Cannot open file $filename for writing");

        if (flock($fp, LOCK_EX))
        {
            fwrite($fp, $contents);
            flock($fp, LOCK_UN);
        }
        else
            throw new DrnException("Cannot lock file $filename");

        fclose($fp);
    }

    /**
     *  Calls pfn for every file in the directory $path whose filename matches
     *  the regular expression $re.
     *
     *  This skips '.' and '..' automatically.
     */
    public static function ForEachFile($path,             //!< in: directory to search
                                       $re,               //!< in: regex (must be enclosed in slashes like '/.../')
                                       $pfn)              //!< in: function($filename) callback
    {
        if (is_dir($path))
        {
            if ($dh = opendir($path))
            {
                while (($filename = readdir($dh)) !== false)
                {
                    if (    ($filename == '.')
                         || ($filename == '..')
                       )
                        continue;

                    if (preg_match($re, $filename, $aMatches))
                        $pfn($filename, $aMatches);
                }
                closedir($dh);
            }
        }
    }

    /*
     *  A quick and dirty glob function to get all files from $path whose file name matches the
     *  regular expression $re. Calls ForEachFile() in turn.
     *
     *  Returns a flat list of file basenames (without paths) or NULL if none were found.
     *
     *  Example:
     *
     *  $configFiles = FileHelpers::GlobRE("/dir", '/^doreen-.+\.inc\.php$/');
     *
     */
    public static function GlobRE($path,             //!< in: directory to search
                                  $re)               //!< in: regex (must be enclosed in slashes like '/.../')
    {
        $llReturn = [];

        /** @noinspection PhpUnusedParameterInspection */
        self::ForEachFile($path,
                          $re,
                            function($name, $aMatches) use(&$llReturn)
                            {
                                $llReturn[] = $name;
                            });
        return (count($llReturn) ? $llReturn : NULL);
    }

    /**
     *  Calculates the size of $path recursively. Returns bytes.
     */
    static function FolderSize($path)
    {
        $total_size = 0;
        $files = scandir($path);
        $cleanPath = rtrim($path, '/'). '/';

        foreach($files as $t)
        {
            if ($t <> "." && $t <> "..")
            {
                $currentFile = $cleanPath . $t;
                if (is_dir($currentFile))
                {
                    # Recurse!
                    $size = self::FolderSize($currentFile);
                    $total_size += $size;
                }
                else
                {
                    $size = filesize($currentFile);
                    $total_size += $size;
                }
            }
        }

        return $total_size;
    }

    /**
     *  Returns a value from ini_get() and translates shorthands like "1M" into bytes. Can be
     *  used with upload_max_filesize and post_max_size.
     *
     *  The available options are K (for Kilobytes), M (for Megabytes) and G (for Gigabytes; available since PHP 5.1.0),
     *  these are case insensitive. Anything else assumes bytes. 1M equals one Megabyte or 1048576 bytes.
     *  1K equals one Kilobyte or 1024 bytes. You may not use these shorthand notations outside of php.ini,
     *  instead use an integer value of bytes.
     *
     *  @return int
     */
    public static function GetIniBytes($key)
    {
        $val = ini_get($key);

        $aMatches = [];
        if (preg_match('/([0-9]+)([kmg])/i', $val, $aMatches))
            switch (strtoupper($aMatches[2]))
            {
                case 'K':
                    return $aMatches[1] * 1024;

                case 'M':
                    return $aMatches[1] * 1024 * 1024;

                case 'G':
                    return $aMatches[1] * 1024 * 1024 * 1024;
            }

        throw new DrnException("Cannot parse value \"$val\" from php.ini key \"$key\" ".print_r($aMatches, TRUE));
    }

    /**
     *  Get the max file size for uploads in mega bytes.
     *
     *  This is a mess. The maximum upload size is limited by the lowest of these two PHP.INI settings,
     *  which can take 1024-based shortcut values like "8M". But Dropzone will only pay attention to a integer
     *  "MB" value, which is 1000-based. So we'll have to round the PHP.INI setting down.
     *
     *  @return int
     */
    public static function GetMaxUploadSizeInMB()
        : int
    {
        $uploadMaxFilesize = FileHelpers::GetIniBytes('upload_max_filesize');
        $postMaxSize = FileHelpers::GetIniBytes('post_max_size');
        return round(min( $uploadMaxFilesize, $postMaxSize ) / 1000 / 1000);
    }

    /**
     *  It is not easy to prompt for a password in PHP. fgets() on STDIN would work but it echoes
     *  every character which is undesirable. http://stackoverflow.com/questions/187736/command-line-password-prompt-in-php
     *  suggests calling stty. Unfortunately this works on Unix only.
     */
    public static function PromptPassword($prompt = '')
    {
        echo $prompt;

        system('stty -echo');
        $pass = fgets(STDIN);
        system('stty echo');

        echo PHP_EOL;
        return rtrim($pass, PHP_EOL);
    }

    /**
     *  Replaces strings in the file pointed to by $fname.
     *
     *  This assumes that every key in $aRegexen is a valid regular expression,
     *  reads the file line by name and matches every line against all of the
     *  regular expressions. If one matches, then the value corresponding to the
     *  regex key must be an array with the following items:
     *
     *   0: a replacement string, which may include backreferences into the regex
     *      (this is passed to preg_replace);
     *
     *   1: a boolean; if TRUE, then the replacement string is appended to the end
     *      of the file if the regex did not match any line.
     *
     *  If you want to replace the entire line, use ^ and $ in the regex.
     *  If anything was replaced or appended, the modified file is written back to disk.
     *
     *  This throws exceptions on errors. It returns TRUE if the file was modified,
     *  FALSE if it was not.
     *
     * @return bool
     */
    public static function ReplaceInFile($fname,
                                         $aRegexen)
    {
        $fModified = FALSE;

        $quotedFile = Format::UTF8Quote($fname);
        # Read existing optional vars file, if it exists, and keep the important lines.
        $llLinesInFile = [];
        if (!@is_readable($fname))
            throw new DrnException("File $quotedFile is not readable");

        $aLinesNeedAdding = [];
        foreach ($aRegexen as $re => $a2)
        {
            if (!is_array($a2))
                throw new DrnException("Missing array");
            $repl = $a2[0];
            if ($a2[1])
                $aLinesNeedAdding[$re] = $repl;
        }

        if (!($fh = @fopen($fname, 'r')))
            throw new DrnException("Cannot open existing ".Format::UTF8Quote($fname)." for reading: $php_errormsg");
        while ($line = fgets($fh))
        {
            $line = trim($line, "\n\r");
            foreach ($aRegexen as $re => $a2)
            {
                $repl = $a2[0];
                $cReplaced = 0;
                $newline = preg_replace("/$re/", $repl, $line, -1, $cReplaced);
                if ($line != $newline)
                {
                    $line = $newline;
                    $fModified = TRUE;
                    # Do not add this line, in case it was added to the list of lines to be added above.
                    unset($aLinesNeedAdding[$re]);
                }
            }

            # Keep all other lines (e.g. PREFILL_* definitions) intact.
            $llLinesInFile[] = $line;
        }
        fclose($fh);

        foreach ($aLinesNeedAdding as $re => $line)
        {
            $llLinesInFile[] = $line;
            $fModified = TRUE;
        }

        if ($fModified)
        {
            if (!($fh = @fopen($fname, 'w')))
                throw new DrnException("Cannot open ".toHTML($fname, 'code')." for writing: $php_errormsg");
            foreach ($llLinesInFile as $line)
                fwrite($fh, "$line\n");
            fclose($fh);
        }

        return $fModified;
    }

    /**
     *  Wrapper around PHP's mkdir() which throws a DrnException on errors.
     *
     * @return void
     */
    public static function MakeDirectory(string $path)
    {
        if (!@mkdir($path))
            throw new DrnException("Failed to create ".Format::UTF8Quote($path).": ".print_r(error_get_last()['message'], TRUE));
    }

    /**
     *  Wrapper around PHP's symlink(), which creates a symbolic link to $target in the given $linkfile, which throws a DrnException on errors.
     *
     * @return void
     */
    public static function CreateSymlink(string $target, string $linkfile)
    {
        if (!@symlink($target, $linkfile))
            throw new DrnException("Failed to create symlink ".Format::UTF8Quote($linkfile).": ".print_r(error_get_last()['message'], TRUE));
    }

    public static function FilePutContents(string $path,
                                           string $str)
    {
        if (@file_put_contents($path, $str) === FALSE)
            throw new DrnException("Failed to write to ".Format::UTF8Quote($path).": ".print_r(error_get_last()['message'], TRUE));
    }

    /**
     *  Delete the given path if it is a directory. Also deletes all files and
     *  folders within the directory.
     */
    public static function RemoveDirectory(string $path)
    {
        if (is_dir($path) && !is_link($path))
        {
            $files = self::GlobRE($path, '/.{3,}/');
            foreach ($files as $file)
            {
                $filePath = $path.'/'.$file;
                if (is_dir($filePath) && !is_link($filePath))
                    self::RemoveDirectory($filePath);
                else
                    @unlink($filePath);
            }
            if (!@rmdir($path))
                throw new DrnException("Failed to remove ".Format::UTF8Quote($path).": ".print_r(error_get_last()['message'], TRUE));
        }
    }

    /**
     *  Copies a file, optionally overriding the destination.
     */
    public static function CopyFile(string $sourcePath, string $destinationPath, $fOverwrite = false)
    {
        if (is_file($destinationPath) && !$fOverwrite)
            throw new DrnException(Format::UTF8Quote($destinationPath)." already exists, copying of ".Format::UTF8Quote($sourcePath)." aborted");

        if (!@copy($sourcePath, $destinationPath))
            throw new DrnException("Failed to copy ".Format::UTF8Quote($sourcePath)." to ".Format::UTF8Quote($destinationPath).": ".print_r(error_get_last()['message'], TRUE));
    }
}
