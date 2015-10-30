<?php
/**
 * This file is part of the  package.
 *
 * (c) Simon Leblanc <contact@leblanc-simon.eu>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ZfTusServer;

use \Zend\Http\PhpEnvironment\Request as PhpRequest;
use \Zend\Http\PhpEnvironment\Response as PhpResponse;
use Zend\Http\Headers;
use Zend\Session\Container;

class Server
{
    const TIMEOUT = 30;

    const POST      = 'POST';
    const HEAD      = 'HEAD';
    const PATCH     = 'PATCH';
    const OPTIONS   = 'OPTIONS';
    const GET       = 'GET';

    const SESSION_CONTAINER = 'tus';

    const TUS_VERSION = '1.0.0';

    private $uuid       = null;
    private $directory  = null;
    private $host       = null;

    private $request    = null;
    private $response   = null;

    /**
     *
     * @var Zend\Session\Container
     */
    private $session    = null;


    /**
     * Constructor
     *
     * @param   string      $directory      The directory to use for save the file
     * @param   null|array  $redis_options  Override the default Redis options
     * @access  public
     */
    public function __construct($directory, \Zend\Http\PhpEnvironment\Request $request)
    {
        $this->setDirectory($directory);
        $this->request = $request;
    }


    /**
     * Process the client request
     *
     * @param   bool    $send                                   True to send the response, false to return the response
     * @return  void|Symfony\Component\HttpFoundation\Response  void if send = true else Response object
     * @throws  \\Exception\Request                       If the method isn't available
     * @access  public
     */
    public function process($send = false)
    {
        try {
            if(!$this->checkTusVersion()) {
                throw new Exception\Request('The requested protocol verison is not supported', \Zend\Http\Response::STATUS_CODE_405);
            }

            $method = $this->getRequest()->getMethod();

            if ($this->getRequest()->isOptions()) {
                $this->uuid = null;
            } elseif ($this->getRequest()->isPost()) {
                $this->buildUuid();
            } else {
                $this->getUserUuid();
            }

            switch ($method) {
                case self::POST:
                    $this->processPost();
                    break;

                case self::HEAD:
                    $this->processHead();
                    break;

                case self::PATCH:
                    $this->processPatch();
                    break;

                case self::OPTIONS:
                    $this->processOptions();
                    break;
//
//                case self::GET:
//                    $this->processGet($send);
//                    break;

                default:
                    throw new Exception\Request('The requested method '.$method.' is not allowed', \Zend\Http\Response::STATUS_CODE_405);
            }

            $this->addCommonHeader();

            if ($send === false) {
                return $this->response;
            }
        } catch (Exception\BadHeader $e) {
            if ($send === false) {
                throw $e;
            }

            $this->getResponse()->setStatusCode(\Zend\Http\Response::STATUS_CODE_400);
            $this->addCommonHeader();
        } catch (Exception\Request $e) {
            if ($send === false) {
                throw $e;
            }

            $this->getResponse()->setStatusCode($e->getCode())
                    ->setContent($e->getMessage());
            $this->addCommonHeader(true);
        } catch (\Exception $e) {
            if ($send === false) {
                throw $e;
            }

            $this->getResponse()->setStatusCode(\Zend\Http\Response::STATUS_CODE_500);
            $this->addCommonHeader();
        }

        $this->getResponse()->sendHeaders();

        // The process must only sent the HTTP headers : kill request after send
        exit;
    }

    private function checkTusVersion() {
        $tusVersion = $this->getRequest()->getHeader('Tus-Resumable');
        if ($tusVersion instanceof \Zend\Http\Header\HeaderInterface) {
            return $tusVersion->getFieldValue()  === self::TUS_VERSION;
        }
        return false;
    }


    /**
     * Build a new UUID (use in the POST request)
     *
     * @access  private
     */
    private function buildUuid()
    {
        $this->uuid = hash('md5', uniqid(mt_rand().php_uname(), true));
    }


    /**
     * Get the UUID of the request (use for HEAD and PATCH request)
     *
     * @return  string                      The UUID of the request
     * @throws  \InvalidArgumentException   If the UUID is empty
     * @access  private
     */
    private function getUserUuid()
    {
        if ($this->uuid === null) {
           $uuid = $this->getRequest()->getQuery('uuid');
            if (empty($uuid)) {
                throw new \InvalidArgumentException('The uuid cannot be empty: ');
            }
            $this->uuid = $uuid;
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
     * @access  private
     */
    private function processPost()
    {
        if ($this->existsInSession($this->uuid) === true) {
            throw new \Exception('The UUID already exists');
        }

        $headers = $this->extractHeaders(array('Upload-Length'));

        if (is_numeric($headers['Upload-Length']) === false || $headers['Upload-Length'] < 0) {
            throw new Exception\BadHeader('Upload-Length must be a positive integer');
        }

        $final_length = (int)$headers['Upload-Length'];

        $file = $this->directory.$this->getFilename();

        if (file_exists($file) === true) {
            throw new Exception\File('File already exists : '.$file);
        }

        if (touch($file) === false) {
            throw new Exception\File('Impossible to touch '.$file);
        }

        $this->setSessionData($this->uuid, 'Upload-Length', $final_length);
        $this->setSessionData($this->uuid, 'Upload-Offset', 0);
        $this->setSessionData($this->uuid, 'UUID', $this->uuid);

        $this->getResponse()->setStatusCode(201);
        $this->getResponse()->setHeaders(
                (new \Zend\Http\Headers())->addHeaderLine('Location',
                         $this->getRequest()->getRequestUri().'?uuid='.$this->uuid)
                );
    }


    /**
     * Process the HEAD request
     *
     * @throws  \Exception      If the uuid isn't know
     * @access  private
     */
    private function processHead()
    {
        if ($this->existsInSession($this->uuid, 'UUID') === false) {
            throw new \Exception('The UUID doesn\'t exists');
        }

        $offset = $this->getSessionValue($this->uuid, 'Upload-Offset');

        $this->getResponse()->setStatusCode(PhpResponse::STATUS_CODE_200);
        $this->getResponse()->setHeaders((new Headers())->addHeaderLine('Upload-Offset', $offset));
    }


    /**
     * Process the PATCH request
     *
     * @throws  \Exception                      If the uuid isn't know
     * @throws  \ZfTusServer\Exception\BadHeader     If the Offset header isn't a positive integer
     * @throws  \ZfTusServer\Exception\BadHeader     If the Content-Length header isn't a positive integer
     * @throws  \ZfTusServer\Exception\BadHeader     If the Content-Type header isn't "application/offset+octet-stream"
     * @throws  \ZfTusServer\Exception\BadHeader     If the Offset header and Offset Redis are not equal
     * @throws  \ZfTusServer\Exception\Required      If the final length is smaller than offset
     * @throws  \ZfTusServer\Exception\File          If it's impossible to open php://input
     * @throws  \ZfTusServer\Exception\File          If it's impossible to open the destination file
     * @throws  \ZfTusServer\Exception\File          If it's impossible to set the position in the destination file
     */
    private function processPatch()
    {
        // Check the uuid
        if ($this->existsInSession($this->uuid, 'UUID') === false) {
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

        // Initialize vars
        $offset_header = (int)$headers['Upload-Offset'];
        $offset_redis = $this->getSessionValue($this->uuid, 'Upload-Offset');
        $max_length = $content_length = (int)$headers['Content-Length'];

        // Check consistency (user vars vs database vars)
        if ($offset_redis === null || (int)$offset_redis !== $offset_header) {
            throw new Exception\BadHeader('Upload-Offset header isn\'t the same as in Redis');
        }

        // Check if the file isn't already entirely write
        if ((int)$offset_redis === (int)$max_length) {
            $this->getResponse()->setStatusCode(200);
            return;
        }

        // Read / Write datas
        $handle_input = fopen('php://input', 'rb');
        if ($handle_input === false) {
            throw new Exception\File('Impossible to open php://input');
        }

        $file = $this->directory.$this->getFilename();
        $handle_output = fopen($file, 'ab');
        if ($handle_output === false) {
            throw new Exception\File('Impossible to open file to write into');
        }

        if (fseek($handle_output, (int)$offset_redis) === false) {
            throw new Exception\File('Impossible to move pointer in the good position');
        }

        ignore_user_abort(true);

        $current_size = (int)$offset_redis;
        $total_write = 0;

        try {
            while (true) {
                set_time_limit(self::TIMEOUT);

                // Manage user abort
                if(connection_status() != CONNECTION_NORMAL) {
                    throw new Exception\Abort('User abort connexion');
                }

                $data = fread($handle_input, 8192);
                if ($data === false) {
                    throw new Exception\File('Impossible to read the datas');
                }

                $size_read = strlen($data);

                // If user sent more datas than expected (by POST Final-Length), abort
                if ($size_read + $current_size > $max_length) {
                    throw new Exception\Max('Size sent is greather than max length expected');
                }


                // If user sent more datas than expected (by PATCH Content-Length), abort
                if ($size_read + $total_write > $content_length) {
                    throw new Exception\Max('Size sent is greather than max length expected');
                }

                // Write datas
                $size_write = fwrite($handle_output, $data);
                if ($size_write === false) {
                    throw new Exception\File('Impossible to write the datas');
                }

                $current_size += $size_write;
                $total_write += $size_write;
                $this->setSessionData($this->uuid, 'Upload-Offset', $current_size);

                if ($total_write === $content_length) {
                    fclose($handle_input);
                    fclose($handle_output);
                    break;
                }
            }
        } catch (Exception\Max $e) {
            fclose($handle_input);
            fclose($handle_output);
            $this->getResponse()->setStatusCode(PhpResponse::STATUS_CODE_400);
        } catch (Exception\File $e) {
            fclose($handle_input);
            fclose($handle_output);
            $this->getResponse()->setStatusCode(PhpResponse::STATUS_CODE_500);
        } catch (Exception\Abort $e) {
            fclose($handle_input);
            fclose($handle_output);
            $this->getResponse()->setStatusCode(PhpResponse::STATUS_CODE_100);
        }

        $this->getResponse()->setStatusCode(PhpResponse::STATUS_CODE_200);
        $this->getResponse()->setHeaders((new Headers())->addHeaderLine('Upload-Offset', $current_size));
    }


    /**
     * Process the OPTIONS request
     *
     * @access  private
     */
    private function processOptions()
    {
        $this->getResponse()->getStatusCode(200);
    }

    /**
     * Add the commons headers to the HTTP response
     *
     * @access  private
     */
    private function addCommonHeader($isOption = false)
    {
        $headers = $this->getResponse()->getHeaders();
        $headers->addHeaderLine('Tus-Resumable', self::TUS_VERSION);

        if ($isOption) {
            $headers->addHeaders([
                'Allow' => 'OPTIONS,HEAD,POST,PATCH',
                'Access-Control-Allow-Methods' => 'OPTIONS,HEAD,POST,PATCH',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Headers' => 'Origin, X-Requested-With, Content-Type, Accept, Final-Length, Offset',
                'Access-Control-Expose-Headers' => 'Location, Range, Content-Disposition, Upload-Offset',
            ]);
        }
        return $this->response->setHeaders($headers);
    }


    /**
     * Extract a list of headers in the HTTP headers
     *
     * @param   array       $headers        A list of header name to extract
     * @return  array                       A list if header ([header name => header value])
     * @throws  \InvalidArgumentException   If headers isn't array
     * @throws  \\Exception\BadHeader If a header sought doesn't exist or are empty
     * @access  private
     */
    private function extractHeaders($headers)
    {
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
                    throw new Exception\BadHeader($headerName.' can\'t be empty');
                }

                $headers_values[$headerName] = $value;
            }
        }

        return $headers_values;
    }


    /**
     * Set the directory where the file will be store
     *
     * @param   string      $directory      The directory where the file are stored
     * @return  \\Server              The current Server instance
     * @throws  \InvalidArgumentException   If directory isn't string
     * @throws  \\Exception\File      If directory isn't writable
     * @access  private
     */
    private function setDirectory($directory)
    {
        if (is_string($directory) === false) {
            throw new \InvalidArgumentException('Directory must be a string');
        }

        if (is_dir($directory) === false || is_writable($directory) === false) {
            throw new Exception\File($directory.' doesn\'t exist or isn\'t writable');
        }

        $this->directory = $directory.(substr($directory, -1) !== DIRECTORY_SEPARATOR ? DIRECTORY_SEPARATOR : '');

        return $this;
    }

    /**
     * Get the Redis connection
     *
     * @return  \Zend\Session\Container
     * @access  private
     */
    private function getSessionData()
    {

        if ($this->session === null) {
            $this->session = new \Zend\Session\Container(self::SESSION_CONTAINER);
        }

        return $this->session;
    }


    /**
     * Set a value in the Redis database
     *
     * @param   string      $id     The id to use to set the value (an id can have multiple key)
     * @param   string      $key    The key for wich you want set the value
     * @param   mixed       $value  The value for the id-key to save
     * @access  private
     */
    private function setSessionData($id, $key, $value)
    {
        $this->getSessionData()->offsetSet(self::getSessionKey($id, $key), $value);
    }


    /**
     * Get a value in the Redis database
     *
     * @param   string      $id     The id to use to get the value (an id can have multiple key)
     * @param   string      $key    The key for wich you want value
     * @return  mixed               The value for the id-key
     * @access  private
     */
    private function getSessionValue($id, $key)
    {
        return $this->getSessionData()->offsetGet(self::getSessionKey($id, $key));
    }

    private static function getSessionKey($id, $key) {
        return $id . '_'. $key;
    }


    /**
     * Check if an id exists in the Redis database
     * FIXME: w sesji to ma być id i key
     *
     * @param   string      $id     The id to test
     * @return  bool                True if the id exists, false else
     * @access  private
     */
    private function existsInSession($id, $key = 'UUID')
    {
        return $this->getSessionData()->offsetExists(self::getSessionKey($id, $key));
    }


    /**
     * Get the filename to use when save the uploaded file
     *
     * @return  string              The filename to use
     * @throws  \DomainException    If the uuid isn't define
     * @access  private
     */
    private function getFilename()
    {
        if ($this->uuid === null) {
            throw new \DomainException('Uuid can\'t be null when call '.__METHOD__);
        }

        return $this->uuid;
    }


    /**
     * Get the HTTP Request object
     *
     * @return  \Zend\Http\Request       the HTTP Request object
     * @access  private
     */
    private function getRequest()
    {
        return $this->request;
    }


    /**
     * Get the HTTP Response object
     *
     * @return  \Zend\Http\Response      the HTTP Response object
     * @access  private
     */
    public function getResponse()
    {
        if ($this->response === null) {
            $this->response = new PhpResponse();
        }

        return $this->response;
    }
}
