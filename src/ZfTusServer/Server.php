<?php

/**
 * This file is part of the  package.
 *
 * (c) Jarosław Wasilewski <orajo@windowslive.com>
 * based on PhpTus by
 * (c) Simon Leblanc <contact@leblanc-simon.eu>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ZfTusServer;

use Zend\Http\PhpEnvironment\Request as PhpRequest;
use Zend\Http\PhpEnvironment\Response as PhpResponse;
use Zend\Http\Headers;
use Zend\Json\Json;

class Server {

    const TIMEOUT = 30;
    const TUS_VERSION = '1.0.0';

    private $uuid = null;
    private $directory = '';
    private $realFileName = '';
    private $request = null;
    private $response = null;
    private $allowGetMethod = true;
    /**
     * TODO: handle this limit in patch method
     * @var int
     */
    private $allowMaxSize = 2147483648; // 2GB

    /**
     *
     * @var Zend\Session\Container
     */
    private $metaData = null;

    /**
     * Switches debug mode.
     * In this mode downloading info files is allowed (usefull for testing)
     * @var bool
     */
    private $debugMode = false;

    /**
     * Constructor
     *
     * @param string $directory The directory to use for save the file
     * @param \Zend\Http\PhpEnvironment\Request  $request  Request object
     * @param bool $debug switches debug mode - {@see Server::debugMode}
     * @access public
     */
    public function __construct($directory, \Zend\Http\PhpEnvironment\Request $request, $debug = false) {
        $this->setDirectory($directory);
        $this->request = $request;
        $this->debugMode = $debug;
    }

    /**
     * Process the client request
     *
     * @param bool $send True to send the response, false to return the response
     * @return void|Symfony\Component\HttpFoundation\Response  void if send = true else Response object
     * @throws \\Exception\Request If the method isn't available
     * @access public
     */
    public function process($send = false) {
        try {

            $method = $this->getRequest()->getMethod();

            switch ($method) {
                case 'POST':
                    if(!$this->checkTusVersion()) {
                        throw new Exception\Request('The requested protocol version is not supported', \Zend\Http\Response::STATUS_CODE_405);
                    }
                    $this->buildUuid();
                    $this->processPost();
                    break;

                case 'HEAD':
                    if(!$this->checkTusVersion()) {
                        throw new Exception\Request('The requested protocol version is not supported', \Zend\Http\Response::STATUS_CODE_405);
                    }
                    $this->getUserUuid();
                    $this->processHead();
                    break;

                case 'PATCH':
                    if(!$this->checkTusVersion()) {
                        throw new Exception\Request('The requested protocol version is not supported', \Zend\Http\Response::STATUS_CODE_405);
                    }
                    $this->getUserUuid();
                    $this->processPatch();
                    break;

                case 'OPTIONS':
                    $this->processOptions();
                    break;

                case 'GET':
                    $this->getUserUuid();
                    $this->processGet($send);
                    break;

                default:
                    throw new Exception\Request('The requested method ' . $method . ' is not allowed', \Zend\Http\Response::STATUS_CODE_405);
            }

            $this->addCommonHeader($this->getRequest()->isOptions());

            if ($send === false) {
                return $this->response;
            }
        }
        catch (Exception\BadHeader $e) {
            if ($send === false) {
                throw $e;
            }

            $this->getResponse()->setStatusCode(\Zend\Http\Response::STATUS_CODE_400);
            $this->addCommonHeader();
        }
        catch (Exception\Request $e) {
            if ($send === false) {
                throw $e;
            }

            $this->getResponse()->setStatusCode($e->getCode())
                    ->setContent($e->getMessage());
            $this->addCommonHeader(true);
        }
        catch (\Exception $e) {
            if ($send === false) {
                throw $e;
            }

            $this->getResponse()->setStatusCode(\Zend\Http\Response::STATUS_CODE_500)
                    ->setContent($e->getMessage());
            $this->addCommonHeader();
        }

        $this->getResponse()->sendHeaders();
        $this->getResponse()->sendContent();

        // The process must only sent the HTTP headers and content: kill request after send
        exit;
    }

    /**
     * Checks compatibility with requested Tus protocol
     *
     * @return boolean
     */
    private function checkTusVersion() {
        $tusVersion = $this->getRequest()->getHeader('Tus-Resumable');
        if ($tusVersion instanceof \Zend\Http\Header\HeaderInterface) {
            return $tusVersion->getFieldValue() === self::TUS_VERSION;
        }
        return false;
    }

    /**
     * Build a new UUID (use in the POST request)
     * @return void
     */
    private function buildUuid() {
        $this->uuid = hash('md5', uniqid(mt_rand() . php_uname(), true));
    }

    /**
     * Get the UUID of the request (use for HEAD and PATCH request)
     *
     * @return string The UUID of the request
     * @throws \InvalidArgumentException If the UUID is empty
     * @access private
     */
    private function getUserUuid() {
        if ($this->uuid === null) {

            $path = $this->getRequest()->getUri()->getPath();
            $uuid = substr($path, strrpos($path, '/')+1);
            if (strlen($uuid) === 32 && preg_match('/[a-z0-9]/', $uuid)) {
                $this->uuid = $uuid;
            }
            else {
                throw new \InvalidArgumentException('The uuid cannot be empty.');
            }
        }
        return $this->uuid;
    }

    /**
     * Process the POST request
     *
     * @throws  \Exception                      If the uuid already exists
     * @throws  \ZfTusServer\Exception\BadHeader     If the final length header isn't a positive integer
     * @throws  \ZfTusServer\Exception\File          If the file already exists in the filesystem
     * @throws  \ZfTusServer\Exception\File          If the creation of file failed
     */
    private function processPost() {

        if ($this->existsInMetaData($this->uuid, 'ID') === true) {
            throw new \Exception('The UUID already exists');
        }

        $headers = $this->extractHeaders(array('Upload-Length', 'Upload-Metadata'));

        if (is_numeric($headers['Upload-Length']) === false || $headers['Upload-Length'] < 0) {
            throw new Exception\BadHeader('Upload-Length must be a positive integer');
        }

        $final_length = (int) $headers['Upload-Length'];

        $this->setRealFileName($headers['Upload-Metadata']);

        $file = $this->directory . $this->getFilename();

        if (file_exists($file) === true) {
            throw new Exception\File('File already exists : ' . $file);
        }

        if (touch($file) === false) {
            throw new Exception\File('Impossible to touch ' . $file);
        }


        $this->setMetaDataValue($this->uuid, 'ID', $this->uuid);
        $this->saveMetaData($final_length, 0, false, true);

        $this->getResponse()->setStatusCode(201);

        $uri = $this->getRequest()->getUri();
        $this->getResponse()->setHeaders(
            (new \Zend\Http\Headers())->addHeaderLine('Location', $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath() . '/' . $this->uuid)
        );
        unset($uri);
    }

    /**
     * Process the HEAD request
     *
     * @link http://tus.io/protocols/resumable-upload.html#head Description of this reuest type
     *
     * @throws \Exception If the uuid isn't know
     */
    private function processHead() {
        if ($this->existsInMetaData($this->uuid, 'ID') === false) {
            $this->getResponse()->setStatusCode(PhpResponse::STATUS_CODE_404);
            return;
        }

        // if file in storage does not exists
        if (!file_exists($this->directory . $this->getFilename())) {
            // allow new upload
            $this->removeFromMetaData($this->uuid);
            $this->getResponse()->setStatusCode(PhpResponse::STATUS_CODE_404);
            return;
        }

        $this->getResponse()->setStatusCode(PhpResponse::STATUS_CODE_200);

        $offset = $this->getMetaDataValue($this->uuid, 'Offset');
        $headers = $this->getResponse()->getHeaders();
        $headers->addHeaderLine('Upload-Offset', $offset);

        $length = $this->getMetaDataValue($this->uuid, 'Size');
        $headers->addHeaderLine('Upload-Length', $length);

        $headers->addHeaderLine('Cache-Control', 'no-store');
    }

    /**
     * Process the PATCH request
     *
     * @throws \Exception If the uuid isn't know
     * @throws \ZfTusServer\Exception\BadHeader If the Upload-Offset header isn't a positive integer
     * @throws \ZfTusServer\Exception\BadHeader If the Content-Length header isn't a positive integer
     * @throws \ZfTusServer\Exception\BadHeader If the Content-Type header isn't "application/offset+octet-stream"
     * @throws \ZfTusServer\Exception\BadHeader If the Upload-Offset header and session offset are not equal
     * @throws \ZfTusServer\Exception\Required If the final length is smaller than offset
     * @throws \ZfTusServer\Exception\File If it's impossible to open php://input
     * @throws \ZfTusServer\Exception\File If it's impossible to open the destination file
     * @throws \ZfTusServer\Exception\File If it's impossible to set the position in the destination file
     */
    private function processPatch() {
        // Check the uuid
        if ($this->existsInMetaData($this->uuid, 'ID') === false) {
            throw new \Exception('The UUID doesn\'t exists');
        }

        // Check HTTP headers
        $headers = $this->extractHeaders(array('Upload-Offset', 'Content-Length', 'Content-Type'));

        if (is_numeric($headers['Upload-Offset']) === false || $headers['Upload-Offset'] < 0) {
            throw new Exception\BadHeader('Upload-Offset must be a positive integer');
        }

        if (is_numeric($headers['Content-Length']) === false || $headers['Content-Length'] < 0) {
            throw new Exception\BadHeader('Content-Length must be a positive integer');
        }

        if (is_string($headers['Content-Type']) === false || $headers['Content-Type'] !== 'application/offset+octet-stream') {
            throw new Exception\BadHeader('Content-Type must be "application/offset+octet-stream"');
        }

        // ffset of current PATCH request
        $offset_header = (int) $headers['Upload-Offset'];
        // Length of data of the current PATCH request
        $content_length = isset($headers['Content-Length']) ? (int) $headers['Content-Length'] : null;
        // Last offset, taken from session
        $offset_session = (int) $this->getMetaDataValue($this->uuid, 'Offset');
        // Total length of file (expected data)
        $length_session = (int) $this->getMetaDataValue($this->uuid, 'Size');

        $this->setRealFileName($this->getMetaDataValue($this->uuid, 'FileName'));

        // Check consistency (user vars vs session vars)
        if ($offset_session === null || $offset_session !== $offset_header) {
            $this->getResponse()->setStatusCode(PhpResponse::STATUS_CODE_409);
            $this->getResponse()->setHeaders((new Headers())->addHeaderLine('Upload-Offset', $offset_session));
            return;
        }

        // Check if the file is already entirely write
        if ($offset_session === $length_session || $length_session === 0) {
            // the whole file was uploaded
            $this->getResponse()->setStatusCode(PhpResponse::STATUS_CODE_204);
            $this->getResponse()->setHeaders((new Headers())->addHeaderLine('Upload-Offset', $offset_session));
            return;
        }

        // Read / Write data
        $handle_input = fopen('php://input', 'rb');
        if ($handle_input === false) {
            throw new Exception\File('Impossible to open php://input');
        }

        $file = $this->directory . $this->getFilename();
        $handle_output = fopen($file, 'ab');
        if ($handle_output === false) {
            throw new Exception\File('Impossible to open file to write into');
        }

        if (fseek($handle_output, $offset_session) === false) {
            throw new Exception\File('Impossible to move pointer in the good position');
        }

        ignore_user_abort(false);

        /* @var $current_size Int Total received data lenght, including all chunks */
        $current_size = $offset_session;
        /* @var $total_write Int Length of saved data in current PATCH request */
        $total_write = 0;

        $return_code = PhpResponse::STATUS_CODE_204;

        try {
            while (true) {
                set_time_limit(self::TIMEOUT);

                // Manage user abort
                // according to comments on PHP Manual page (http://php.net/manual/en/function.connection-aborted.php)
                // this method doesn't work, but we cannot send 0 to browser, becouse it's not compatible with TUS.
                // But maybe some day (some PHP version) it starts working. Thath's why I leave it here.
//                echo "\n";
//                ob_flush();
//                flush();
                if (connection_status() != CONNECTION_NORMAL) {
                    throw new Exception\Abort('User abort connexion');
                }

                $data = fread($handle_input, 8192);
                if ($data === false) {
                    throw new Exception\File('Impossible to read the datas');
                }

                $size_read = strlen($data);

                // If user sent 0 bytes and we do not write all data yet, abort
                if ($size_read === 0) {
                    if (!is_null($content_length) && $total_write < $content_length) {
                        throw new Exception\Abort('Stream unexpectedly ended. Mayby user aborted?');
                    }
                    else {
                        // end of stream
                        break;
                    }
                }

                // If user sent more datas than expected (by POST Final-Length), abort
                if (!is_null($content_length) && ($size_read + $current_size > $length_session)) {
                    throw new Exception\Max('Size sent is greather than max length expected');
                }


                // If user sent more datas than expected (by PATCH Content-Length), abort
                if (!is_null($content_length) && ($size_read + $total_write > $content_length)) {
                    throw new Exception\Max('Size sent is greather than max length expected');
                }

                // Write datas
                $size_write = fwrite($handle_output, $data);
                if ($size_write === false) {
                    throw new Exception\File('Unable to write data');
                }

                $current_size += $size_write;
                $total_write += $size_write;
                $this->setMetaDataValue($this->uuid, 'Offset', $current_size);

                if ($current_size === $length_session) {
                    $this->saveMetaData($length_session, $current_size, true, false);
                    break;
                } else {
                    $this->saveMetaData($length_session, $current_size, false, true);
                }
            }
            $this->getResponse()->getHeaders()->addHeaderLine('Upload-Offset', $current_size);
        }
        catch (Exception\Max $e) {
            $return_code = PhpResponse::STATUS_CODE_400;
            $this->getResponse()->setContent($e->getMessage());
        }
        catch (Exception\File $e) {
            $return_code = PhpResponse::STATUS_CODE_500;
            $this->getResponse()->setContent($e->getMessage());
        }
        catch (Exception\Abort $e) {
            $return_code = PhpResponse::STATUS_CODE_100;
            $this->getResponse()->setContent($e->getMessage());
        }
        catch(\Exception $e) {
            $return_code = PhpResponse::STATUS_CODE_500;
            $this->getResponse()->setContent($e->getMessage());
        }
        finally {
            fclose($handle_input);
            fclose($handle_output);
        }

        return $this->getResponse()->setStatusCode($return_code);
    }

    /**
     * Process the OPTIONS request
     *
     * @access  private
     */
    private function processOptions() {
        $this->uuid = null;
        $this->getResponse()->getStatusCode(204);
    }

    /**
     * Process the GET request
     *
     * FIXME: check and eventually remove $send param
     * @param bool $send Description
     * @access  private
     */
    private function processGet($send) {
        if (!$this->allowGetMethod) {
            throw new Exception\Request('The requested method ' . $method . ' is not allowed', \Zend\Http\Response::STATUS_CODE_405);
        }
        $file = $this->directory . $this->getFilename();
        if (!file_exists($file)) {
            throw new Exception\Request('The file ' . $this->uuid . ' doesn\'t exist', 404);
        }

        if (!is_readable($file)) {
            throw new Exception\Request('The file ' . $this->uuid . ' is unaccessible', 403);
        }

        if (!file_exists($file . '.info') || !is_readable($file . '.info')) {
            throw new Exception\Request('The file ' . $this->uuid . ' has no metadata', 500);
        }

        $fileName = $this->getMetaDataValue($file, 'FileName');

        if ($this->debugMode) {
            $isInfo = $this->getRequest()->getQuery('info', -1);
            if ($isInfo !== -1) {
                FileToolsService::downloadFile($file . '.info', $fileName . '.info');
            }
            else {
                $mime = FileToolsService::detectMimeType($file);
				FileToolsService::downloadFile($file, $fileName, $mime);
			}
        }
        else {
            $mime = FileToolsService::detectMimeType($file);
            FileToolsService::downloadFile($file, $fileName, $mime);
        }
        exit;
    }

    /**
     * Add the commons headers to the HTTP response
     *
     * @param bool $isOption Is OPTION request
     * @access private
     */
    private function addCommonHeader($isOption = false) {
        $headers = $this->getResponse()->getHeaders();
        $headers->addHeaderLine('Tus-Resumable', self::TUS_VERSION);
        $headers->addHeaderLine('Access-Control-Allow-Origin', '*');
        $headers->addHeaderLine('Access-Control-Expose-Headers', 'Upload-Offset, Location, Upload-Length, Tus-Version, Tus-Resumable, Tus-Max-Size, Tus-Extension, Upload-Metadata');

        if ($isOption) {
            $allowedMethods = 'OPTIONS,HEAD,POST,PATCH';
            if ($this->getAllowGetMethod()) {
                $allowedMethods .= ',GET';
            }

            $headers->addHeaders([
                'Tus-Version' => self::TUS_VERSION,
                'Tus-Extension' => 'creation',
                'Tus-Max-Size' => $this->allowMaxSize,
                'Allow' => $allowedMethods,
                'Access-Control-Allow-Methods' => $allowedMethods,
                'Access-Control-Allow-Headers' => 'Origin, X-Requested-With, Content-Type, Accept, Final-Length, Upload-Offset, Upload-Length, Tus-Resumable, Upload-Metadata',
            ]);
        }
        return $this->response->setHeaders($headers);
    }

    /**
     * Extract a list of headers in the HTTP headers
     *
     * @param array $headers A list of header name to extract
     * @return array A list if header ([header name => header value])
     * @throws \InvalidArgumentException If headers isn't array
     * @throws \\Exception\BadHeader If a header sought doesn't exist or are empty
     * @access private
     */
    private function extractHeaders($headers) {
        if (is_array($headers) === false) {
            throw new \InvalidArgumentException('Headers must be an array');
        }

        $headers_values = array();
        foreach ($headers as $headerName) {
            $headerObj = $this->getRequest()->getHeader($headerName);
            if ($headerObj instanceof \Zend\Http\Header\HeaderInterface) {
                $value = $headerObj->getFieldValue();

                // \Zend\Http\Header\ContentLength has a bug in initialization
                // if header value is 0 then it sets value as null
                if (is_null($value) && $headerObj instanceof \Zend\Http\Header\ContentLength) {
                    $value = 0;
                }

                if (trim($value) === '') {
                    throw new Exception\BadHeader($headerName . ' can\'t be empty');
                }

                $headers_values[$headerName] = $value;
            }
        }

        return $headers_values;
    }

    /**
     * Set the directory where the file will be store
     *
     * @param string $directory The directory where the file are stored
     * @return \\Server The current Server instance
     * @throws \InvalidArgumentException If directory isn't string
     * @throws \\Exception\File If directory isn't writable
     * @access private
     */
    private function setDirectory($directory) {
        if (is_string($directory) === false) {
            throw new \InvalidArgumentException('Directory must be a string');
        }

        if (is_dir($directory) === false || is_writable($directory) === false) {
            throw new Exception\File($directory . ' doesn\'t exist or isn\'t writable');
        }

        $this->directory = $directory . (substr($directory, -1) !== DIRECTORY_SEPARATOR ? DIRECTORY_SEPARATOR : '');

        return $this;
    }

    /**
     * Get the session info
     *
     * @return  \Zend\Session\Container
     * @access  private
     */
    private function getMetaData() {

        if ($this->metaData === null) {
            $this->metaData = $this->readMetaData($this->getUserUuid());
        }

        return $this->metaData;
    }

    /**
     * Set a value in the session
     *
     * @param   string      $id     The id to use to set the value (an id can have multiple key)
     * @param   string      $key    The key for wich you want set the value
     * @param   mixed       $value  The value for the id-key to save
     * @return void
     * @access  private
     */
    private function setMetaDataValue($id, $key, $value) {
        $data = $this->getMetaData($id);
        if (isset($data[$key])) {
            $data[$key] = $value;
        }
        else {
            throw new \Exception($key . ' is not defined in medatada');
        }
    }

    /**
     * Get a value from session
     *
     * @param string $id The id to use to get the value (an id can have multiple key)
     * @param string $key The key for wich you want value
     * @return mixed The value for the id-key
     * @throws \Exception key is not defined in medatada
     * @access private
     */
    private function getMetaDataValue($id, $key) {
        $data = $this->getMetaData($id);
        if (isset($data[$key])) {
            return $data[$key];
        }
        throw new \Exception($key . ' is not defined in medatada');
    }

    /**
     * Check if $key an $id exists in the session
     *
     * @param string $id The id to test
     * @return bool True if the id exists, false else
     * @access private
     */
    private function existsInMetaData($id, $key) {
        $data = $this->getMetaData($id);
        return isset($data[$key]) && !empty($data[$key]);
    }

    /**
     * Remove selected $id from database
     *
     * @param string $id The id to test
     * @return void
     * @access private
     */
    private function removeFromMetaData($id) {
        $storageFileName = $this->directory . $id . '.info';
        if (file_exists($storageFileName) && is_writable($storageFileName)) {
            unset($storageFileName);
            return true;
        }
        return false;
    }


    /**
     * Saves metadata about uploaded file.
     * Metadata are saved into a file with name mask 'uuid'.info
     *
     * @param int $size
     * @param int $offset
     * @param bool $isFinal
     * @param bool $isPartial
     */
    private function saveMetaData($size, $offset = 0, $isFinal = false, $isPartial = false) {
        $this->setMetaDataValue($this->getUserUuid(), 'ID', $this->getUserUuid());
        $this->metaData['ID'] = $this->getUserUuid();
        $this->metaData['Offset'] = $offset;
        $this->metaData['IsPartial'] = (bool) $isPartial;
        $this->metaData['IsFinal'] = (bool) $isFinal;

        if ($this->metaData['Size'] === 0) {
            $this->metaData['Size'] = $size;
        }

        if (empty($this->metaData['FileName'])) {
            $this->metaData['FileName'] = $this->getRealFileName();
            $info = new \SplFileInfo($this->getRealFileName());
            $ext = $info->getExtension();
            $this->metaData['Extension'] = $ext;
        }
        if ($isFinal) {
            $this->metaData['MimeType'] = FileToolsService::detectMimeType(
                $this->directory . $this->getUserUuid(),
                $this->getRealFileName()
            );
        }

        $json = Json::encode($this->metaData);
        file_put_contents($this->directory . $this->getUserUuid() . '.info', $json);
    }

    /**
     * Reads or initialize metadata about file.
     *
     * @param string $name
     * @return array
     */
    private function readMetaData($name) {
        $refData = [
            'ID' => '',
            'Size' => 0,
            'Offset' => 0,
            'Extension' => '',
            'FileName' => '',
            'MimeType' => '',
            'IsPartial' => true,
            'IsFinal' => false,
            'PartialUploads' => null, // unused
        ];

        $storageFileName = $this->directory . $name . '.info';
        if (file_exists($storageFileName)) {
            $json = file_get_contents($storageFileName);
            $data = Json::decode($json, Json::TYPE_ARRAY);
            if (is_array($data)) {
                return array_merge($refData, $data);
            }
        }
        return $refData;
    }

    /**
     * Get the filename to use when save the uploaded file
     *
     * @return string  The filename to use
     * @throws \DomainException If the uuid isn't define
     * @access private
     */
    private function getFilename() {
        if ($this->uuid === null) {
            throw new \DomainException('Uuid can\'t be null when call ' . __METHOD__);
        }

        return $this->uuid;
    }

    /**
     * Get the HTTP Request object
     *
     * @return \Zend\Http\PhpEnvironment\Request The HTTP Request object
     * @access private
     */
    private function getRequest() {
        return $this->request;
    }

    /**
     * Get the HTTP Response object
     *
     * @return  \Zend\Http\PhpEnvironment\Response The HTTP Response object
     * @access  private
     */
    public function getResponse() {
        if ($this->response === null) {
            $this->response = new PhpResponse();
        }

        return $this->response;
    }

    /**
     * Get real name of transfered file
     *
     * @return string Real name of file
     * @access public
     */
    public function getRealFileName() {
        return $this->realFileName;
    }

    /**
     * Sets real file name
     *
     * @param string $value plain or base64 encoded file name
     * @return \ZfTusServer\Server object
     * @access private
     */
    private function setRealFileName($value) {
        $base64FileNamePos = strpos($value, 'filename ');
        if (is_int($base64FileNamePos) && $base64FileNamePos >= 0) {
            $value = substr($value, $base64FileNamePos + 9); // 9 - length of 'filename '
            $this->realFileName = base64_decode($value);
        } else {
            $this->realFileName = $value;
        }
        return $this;
    }

    /**
     * Allows GET method (it means allow download uploded files)
     * @param bool $allow
     * @return \ZfTusServer\Server
     */
    public function setAllowGetMethod($allow) {
        $this->allowGetMethod = (bool) $allow;
        return $this;
    }

    /**
     * Is GET method allowed
     * @return bool
     */
    public function getAllowGetMethod() {
        return $this->allowGetMethod;
    }

    /**
     * Sets upload size limit
     * @param int $value
     * @return \ZfTusServer\Server
     * @throws \BadMethodCallException
     */
    public function setAllowMaxSize($value) {
        $value = intval($value);
        if ($value > 0) {
            $this->allowMaxSize = $value;
        } else {
            throw new \BadMethodCallException('given $value must be integer, greater them 0');
        }
        return $this;
    }
}
