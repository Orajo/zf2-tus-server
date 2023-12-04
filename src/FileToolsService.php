<?php

namespace ZfTusServer;

use Laminas\I18n\Filter\NumberFormat;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use NumberFormatter;
use ZfTusServer\Exception\FileNotFoundException;

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
    public const OPEN_MODE_ATTACHMENT = 'Attachment';

    /**
     * Download using Content-Disposition: Inline (open in browser, if possible)
     */
    public const OPEN_MODE_INLINE = 'Inline';

    /**
     * Handles file download to browser
     *
     * @link https://gist.github.com/854168 this method is based on this code
     * @access public
     * @api
     * @param string $filePath full local path to downloaded file (typically contains hashed file name)
     * @param string $fileName original file name
     * @param Filesystem $remoteDisk
     * @param string $mime MIME type; if null tries to guess using @see FileToolsService::downloadFile()
     * @param int $size file size in bytes
     * @param string $openMode
     * @return boolean
     * @throws FilesystemException
     */
    public static function downloadFile($filePath, $fileName, Filesystem $remoteDisk, $mime = '', $size = -1, $openMode = self::OPEN_MODE_ATTACHMENT)
    {
        if (!$remoteDisk->fileExists($filePath)) {
            throw new FileNotFoundException(null, 0, null, $filePath);
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

        if (strpos(htmlspecialchars($_SERVER['HTTP_USER_AGENT']), "MSIE") !== false) {
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
            $memoryLimit = ini_get('memory_limit');
            // get file size
            if ($size === -1) {
                $size = $remoteDisk->fileSize($filePath);
            }

            if (((int)($size + 1)) > self::toBytes($memoryLimit) && (((int)($size * 1.5)) <= 1073741824)) {
                // Setting memory limit
                ini_set('memory_limit', intval($size * 1.5));
            }

            @ini_set('zlib.output_compression', 0);
            header("Content-Length: " . $size);
            // Set the time limit based on an average D/L speed of 50kb/sec
            set_time_limit(min(7200, // No more than 120 minutes (this is really bad, but...)
                ($size > 0) ? (int)($size / 51200) + 60 // 1 minute more than what it should take to D/L at 50kb/sec
                    : 1 // Minimum of 1 second in case size is found to be 0
            ));
            $chunkSize = 1 * (1024 * 1024); // how many megabytes to read at a time

            // Chunking file for download
            $handle = $remoteDisk->readStream($filePath);
            if ($handle === false) {
                return false;
            }
            
            while (!feof($handle)) {
                $buffer = fread($handle, $chunkSize);
                echo $buffer;

                // if somewhare before was ob_start()
                if (ob_get_level() > 0) ob_flush();
                flush();
            }
            fclose($handle);
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
        $value = (int)$val;
        switch ($last) {
            // The 'G' modifier is available since PHP 5.1.0
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        return $value;
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

    /**
     * Format file size according to specified locale
     *
     * @param int|null $size               File size in [B] bytes
     * @param string $locale          name of locale settings
          * @param string $emptyValue waht is returned if $size is empty or zero
     *
     * @return string value and unit
     *
     * @assert (1024, 'pl_PL') == '1 kB'
     * @assert (356, 'pl_PL') == '356 B'
     * @assert (6587, 'pl_PL') == '6,43 kB'
     */
    public static function formatFileSize($size, string $locale, string $emptyValue = '-'): string
    {
        $sizes = array(' B', ' kB', ' MB', ' GB', ' TB', ' PB');
        if (is_null($size) || $size == 0) {
            return($emptyValue);
        }

        $precision = 2;
        if ($size == (int)$size && $size < 1024) { // < 1MB
            $precision = 0;
        }

        $result = round($size / pow(1024, ($i = floor(log($size, 1024)))), $precision);
        if (class_exists('NumberFormat')) {
            $filter = new NumberFormat($locale, NumberFormatter::DECIMAL, NumberFormatter::TYPE_DOUBLE);
            return $filter->filter($result) . $sizes[$i];
        }
        return $result . $sizes[$i];
    }
}
