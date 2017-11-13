<?php

namespace LargeFile;

class Uploader
{
    /**
     * $_SERVER to use
     *
     * @var array[string]
     */
    protected $_server;

    /**
     * Read buffer
     *
     * @var string
     */
    protected $buffer;

    /**
     * Handle of the input file
     *
     * @var resource
     */
    protected $inputHandle;

    /**
     * MIME boundary
     *
     * @var string
     */
    protected $boundary;

    /**
     * Temporary directory
     *
     * @var string
     */
    protected $tmpDir;

    /**
     * Size of the read buffer
     *
     * @see fread()
     * @var int
     */
    protected $bufferSize;

    /**
     * Uploader constructor.
     *
     * Optionally provide :
     *  - a $_SERVER array
     *  - a TEMP directory
     *  - a buffer size to use with fread calls
     *
     * @param array|null $_server
     * @param string|null $tmpDir
     * @param int|null $bufferSize
     */
    public function __construct(array $_server = null, $tmpDir = null, $bufferSize = null)
    {
        $this->_server = $_server ?: $_SERVER;
        $this->tmpDir = $tmpDir ?: sys_get_temp_dir();
        $this->bufferSize = $bufferSize ?: 8192;

        if (ini_get('enable_post_data_reading')) {
            $this->handleError("'enable_post_data_reading' setting must be off");
        }
    }

    /**
     * Read input file for MIME parts
     *
     * @param string $inputFile
     * @return array
     */
    public function read($inputFile = 'php://input')
    {
        if ($this->_server['REQUEST_METHOD'] !== 'POST') {
            $this->handleError("HTTP request is not 'POST'");
        }

        //$totalLength = $this->_server['CONTENT_LENGTH'];
        $contentTypeParts = preg_split('/; boundary=/', $this->_server['CONTENT_TYPE']);

        if ($contentTypeParts[0] !== 'multipart/form-data') {
            $this->handleError("Bad content type");
        }

        if (!isset($contentTypeParts[1]) || empty($contentTypeParts[1])) {
            $this->handleError("No boundary defined");
        }

        $this->boundary = '--' . $contentTypeParts[1];

        // Open file handle
        $this->inputHandle = fopen($inputFile, 'rb');
        if (!$this->inputHandle) {
            $this->handleError("Unable to open input file");
        }

        $parts = array();
        $this->buffer = '';
        do {
            $part = $this->readMimePart();
            if ($part === null) {
                break;
            }
            $parts[] = $part;
        } while (1);

        return $parts;
    }

    protected function readMimePart()
    {
        if (strlen($this->buffer) < strlen($this->boundary) + 2) {
            $this->buffer .= rtrim(fgets($this->inputHandle));
        }

        // Part
        $part = array();

        // Check end
        if (strpos($this->buffer, $this->boundary . '--') === 0) { // End
            return null;
        }

        // Check that it starts with the boundary
        if (strpos($this->buffer, $this->boundary) !== 0) { // read boundary
            $this->handleError("Input data does not start with the boundary");
        }

        // Get buffer w/o boundary
        $this->buffer = substr($this->buffer, strlen($this->boundary));

        // Read headers
        $headers = array();
        while (!feof($this->inputHandle)) {
            $header = rtrim(fgets($this->inputHandle));
            if ($header == '') { // Skip headers if the line is empty
                break;
            }

            $headerParts = preg_split('/; /', $header);
            if (!is_array($headerParts) || !count($headerParts)) {
                $this->handleError("Invalid MIME header");
            }

            // First header line
            $mainHeader = preg_split('/: /', $headerParts[0]);
            if (!is_array($mainHeader) || count($mainHeader) != 2) {
                $this->handleError("Invalid MIME header");
            }
            $headers[$mainHeader[0]] = $mainHeader[1];

            // Other header lines
            for ($i = 1; $i < count($headerParts); $i++) {
                $headerLineParts = preg_split('/=/', $headerParts[$i]);
                if (!is_array($headerLineParts) || count($headerLineParts) != 2) {
                    $this->handleError("Invalid MIME header");
                }
                $headers[$headerLineParts[0]] = $headerLineParts[1];
            }
        }

        // Check header
        if (!isset($headers['name'])) {
            $this->handleError("No 'name' header");
        }
        $headers['name'] = trim($headers['name'], '"');

        $isFile = false;
        if (isset($headers['filename'])) {
            $isFile = true;
            $headers['filename'] = basename(trim($headers['filename'], '"'));
        }

        $part['headers'] = $headers;

        $copyEnd = false;
        $outputFile = null;
        $outFileName = null;

        if ($isFile) {
            $outFileName = tempnam($this->tmpDir, 'lfu');
            $outputFile = fopen($outFileName, 'wb');
            $part['file'] = $outFileName;
        } else {
            $part['content'] = '';
        }

        // Read content
        while (!$copyEnd && !feof($this->inputHandle)) {
            $this->buffer .= fread($this->inputHandle, $this->bufferSize);

            // Check if boundary can be found
            $boundaryPos = strpos($this->buffer, $this->boundary);
            if ($boundaryPos !== false) {
                $strWrite = substr($this->buffer, 0, $boundaryPos - 2);
                $this->buffer = substr($this->buffer, $boundaryPos);
                $copyEnd = true;
            } else if (strlen($this->buffer) >= $this->bufferSize - strlen($this->boundary)) {
                // Copy until an eventual '-' (to avoid boundary truncation)
                $minusPos = strpos($this->buffer, '-', $this->bufferSize - strlen($this->boundary) + 2);
                if ($minusPos !== false) {
                    $strWrite = substr($this->buffer, 0, $minusPos);
                    $this->buffer = substr($this->buffer, $minusPos);
                } else {
                    $strWrite = $this->buffer;
                    $this->buffer = '';
                }
            } else {
                $strWrite = $this->buffer;
                $this->buffer = '';
            }

            if ($isFile) {
                fwrite($outputFile, $strWrite);
            } else {
                $part['content'] .= $strWrite;
            }
        }

        if ($isFile) {
            fclose($outputFile);
        }

        return $part;
    }


    protected function handleError($error = false)
    {
        if ($error instanceof \Exception) {
            throw $error;
        }

        throw new \Exception($error ?: 'Unable get MIME data');
    }
}
