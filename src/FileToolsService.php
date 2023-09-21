<?php

namespace ZfTusServer;

use Laminas\I18n\Filter\NumberFormat;
use League\Flysystem\Filesystem;
use NumberFormatter;

/**
 * Service with tools for file download support
 *
 * @author Jarosław Wasilewski <orajo@windowslive.com>
 * @access public
 */
class FileToolsService {

    /**
     * Download using Content-Disposition: Attachment
     */
    const OPEN_MODE_ATTACHMENT = 'Attachment';

    /**
     * Download using Content-Disposition: Inline (open in browser, if possible)
     */
    const OPEN_MODE_INLINE = 'Inline';

    /**
     * Handles file download to browser
     *
     * @link https://gist.github.com/854168 this method is based on this code
     * @access public
     * @api
     * @param string $filePath full local path to downloaded file (typically contains hashed file name)
     * @param string $fileName original file name
     * @param string|null $mime MIME type; if null tries to guess using @see FileToolsService::downloadFile()
     * @param int $size file size in bytes
     * @return boolean
     * @throws \Symfony\Component\Filesystem\Exception\FileNotFoundException
     */
    public static function downloadFile($filePath, $fileName, Filesystem $remoteDisk, $mime = '', $size = -1, $openMode = self::OPEN_MODE_ATTACHMENT)
    {
        if (!$remoteDisk->fileExists($filePath)) {
            throw new Exception(null, 0, null, $filePath);
        }
        // Fetching File
        $mtime = $remoteDisk->lastModified($filePath) ?: gmtime();

        if ($mime === '') {
            header("Content-Type: application/force-download");
            header('Content-Type: application/octet-stream');
        }
        else {
            if(is_null($mime)) {
                $mime = $remoteDisk->mimeType($filePath);
            }
            header('Content-Type: ' . $mime);
        }

        if (strstr(htmlspecialchars($_SERVER['HTTP_USER_AGENT']), "MSIE") != false) {
            header("Content-Disposition: ".$openMode."; filename=" . urlencode($fileName) . '; modification-date="' . date('r', $mtime) . '";');
        }
        else {
            header("Content-Disposition: ".$openMode."; filename=\"" . $fileName . '"; modification-date="' . date('r', $mtime) . '";');
        }

        if (function_exists('apache_get_modules') && in_array('mod_xsendfile', apache_get_modules())) {
            // Sending file via mod_xsendfile
            header("X-Sendfile: " . $filePath);
        }
        else {
            // Sending file directly via script
            // according memory_limit byt not higher than 1GB
            $memory_limit = ini_get('memory_limit');
            // get file size
            if ($size === -1) {
                $size = $remoteDisk->fileSize($filePath);
            }

            if (intval($size + 1) > self::toBytes($memory_limit) && intval($size * 1.5) <= 1073741824) {
                // Setting memory limit
                ini_set('memory_limit', intval($size * 1.5));
            }

            @ini_set('zlib.output_compression', 0);
            header("Content-Length: " . $size);
            // Set the time limit based on an average D/L speed of 50kb/sec
            set_time_limit(min(7200, // No more than 120 minutes (this is really bad, but...)
                ($size > 0) ? intval($size / 51200) + 60 // 1 minute more than what it should take to D/L at 50kb/sec
                    : 1 // Minimum of 1 second in case size is found to be 0
            ));
            $chunkSize = 1 * (1024 * 1024); // how many megabytes to read at a time
            if ($size > $chunkSize) {
                // Chunking file for download
                $handle = fopen($filePath, 'rb');
                if ($handle === false) {
                    return false;
                }
                $buffer = '';
                while (!feof($handle)) {
                    $buffer = fread($handle, $chunkSize);
                    echo $buffer;

                    // if somewhare before was ob_start()
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }
                fclose($handle);
            } else {
                try {
                    $handle = $remoteDisk->readStream($filePath);
                } catch (Exception) {
                    throw new Exception(sprintf('File %s is not readable', $filePath), 0, null, $filePath);
                }
                $buffer = fread($handle, $chunkSize);
                echo $buffer;

                // if somewhare before was ob_start()
                if (ob_get_level() > 0) ob_flush();
                flush();
                fclose($handle);
            }
        }
        exit;
    }

    /**
     * Converts {@see memory_limit} result to bytes
     *
     * @param string $val
     * @return int
     */
    private static function toBytes($val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int)$val;
        switch ($last) {
            // The 'G' modifier is available since PHP 5.1.0
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        return $val;
    }

    /**
     * Internal method to detect the mime type of a file
     *
     * @param string $fileName File name on storage; could be a hash or anything
     * @param string $userFileName Real name of file, understandable for users. If ommited $fileName will be used.
     * @return string Mimetype of given file
     */
    public static function detectMimeType($fileName, $userFileName = ''): string
    {
        if (!file_exists($fileName)) {
            return '';
        }

        $mime = '';

        if (class_exists('finfo', false)) {
            $const = defined('FILEINFO_MIME_TYPE') ? FILEINFO_MIME_TYPE : FILEINFO_MIME;

            if (empty($mime)) {
                $mime = @finfo_open($const);
            }

            if (!empty($mime)) {
                $result = finfo_file($mime, $fileName);
            }
            unset($mime);
        }

        if (empty($result) && (function_exists('mime_content_type') && ini_get('mime_magic.magicfile'))) {
            $result = mime_content_type($fileName);
        }

        // dodatkowe sprawdzenie i korekta dla docx, xlsx, pptx
        if (empty($result) || $result == 'application/zip') {
            if (empty($userFileName)) {
                $userFileName = $fileName;
            }

            $pathParts = pathinfo($userFileName);
            if (isset($pathParts['extension'])) {
                switch ($pathParts['extension']) {
                    case '7z':
                        $result = 'application/x-7z-compressed';
                        break;
                    case 'xlsx':
                    case 'xltx':
                    case 'xlsm':
                    case 'xltm':
                    case 'xlam':
                    case 'xlsb':
                        $result = 'application/msexcel';
                        break;
                    case 'docx':
                    case 'dotx':
                    case 'docm':
                    case 'dotm':
                        $result = 'application/msword';
                        break;
                    case 'pptx':
                    case 'potx':
                    case 'ppsx':
                    case 'ppam':
                    case 'pptm':
                    case 'potm':
                    case 'ppsm':
                        $result = 'application/mspowerpoint';
                        break;
                    case 'vsd':
                    case 'vsdx':
                        $result = 'application/x-visio';
                        break;
                }
            }
        }

        if (empty($result)) {
            $result = 'application/octet-stream';
        }

        return $result;
    }
}
