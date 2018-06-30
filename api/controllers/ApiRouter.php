<?php

/**
 * Routes requests to the API to the appropriate controllers
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

use Nails\Api\Exception\ApiException;
use Nails\Api\Factory\ApiResponse;
use Nails\Environment;
use Nails\Factory;

// --------------------------------------------------------------------------

/**
 * Allow the app to add functionality, if needed
 */
if (class_exists('\App\Api\Controller\BaseRouter')) {
    abstract class BaseMiddle extends \App\Api\Controller\BaseRouter
    {
    }
} else {
    abstract class BaseMiddle extends \Nails\Common\Controller\Base
    {
    }
}

// --------------------------------------------------------------------------

class ApiRouter extends BaseMiddle
{
    const VALID_FORMATS                = [
        'TXT',
        'JSON',
    ];
    const ACCESS_CONTROL_ALLOW_ORIGIN  = '*';
    const ACCESS_CONTROL_ALLOW_HEADERS = [
        'X-Access-Token',
        'content',
        'origin',
        'content-type',
    ];
    const ACCESS_CONTROL_ALLOW_METHODS = [
        'GET',
        'PUT',
        'POST',
        'DELETE',
        'OPTIONS',
    ];
    const OUTPUT_FORMAT_PATTERN        = '/\.([a-z]*)$/';

    // --------------------------------------------------------------------------

    private $sRequestMethod;
    private $sModuleName;
    private $sClassName;
    private $sMethod;
    private $aParams;
    private $aOutputValidFormats;
    private $sOutputFormat;
    private $bOutputSendHeader;
    private $oLogger;

    // --------------------------------------------------------------------------

    /**
     * Constructs the router, defining the request variables
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        //  Set defaults
        $this->bOutputSendHeader = true;

        // --------------------------------------------------------------------------

        //  Work out the request method
        $oInput               = Factory::service('Input');
        $this->sRequestMethod = $oInput->server('REQUEST_METHOD');
        $this->sRequestMethod = $this->sRequestMethod ? $this->sRequestMethod : 'GET';

        /**
         * In order to work out the next few parts we'll analyse the URI string manually.
         * We're doing this because of the optional return type at the end of the string;
         * it's easier to regex that quickly, remove it, then split up the segments.
         */

        $this->sOutputFormat = static::getOutputFormat();
        $sUri                = preg_replace(static::OUTPUT_FORMAT_PATTERN, '', uri_string());

        //  Remove the module prefix (i.e "api/") then explode into segments
        //  Using regex as some systems will report a leading slash (e.g CLI)
        $sUri = preg_replace('#/?api/#', '', $sUri);
        $aUri = explode('/', $sUri);

        //  Work out the sModuleName, sClassName and method
        $this->sModuleName = getFromArray(0, $aUri, null);
        $this->sClassName  = ucfirst(getFromArray(1, $aUri, $this->sModuleName));
        $this->sMethod     = getFromArray(2, $aUri, 'index');

        //  What's left of the array are the parameters to pass to the method
        $this->aParams = array_slice($aUri, 3);

        //  Configure logging
        $oNow          = Factory::factory('DateTime');
        $this->oLogger = Factory::service('Logger');
        $this->oLogger->setFile('api-' . $oNow->format('y-m-d') . '.php');
    }

    // --------------------------------------------------------------------------

    public static function getOutputFormat()
    {
        preg_match(static::OUTPUT_FORMAT_PATTERN, uri_string(), $aMatches);
        return !empty($aMatches[1]) ? strtoupper($aMatches[1]) : 'JSON';
    }

    // --------------------------------------------------------------------------

    /**
     * Route the call to the correct place
     * @return void
     */
    public function index()
    {
        //  Handle OPTIONS CORS pre-flight requests
        if ($this->sRequestMethod === 'OPTIONS') {

            $oOutput = Factory::service('Output');
            $oOutput->set_header('Access-Control-Allow-Origin: ' . static::ACCESS_CONTROL_ALLOW_ORIGIN);
            $oOutput->set_header('Access-Control-Allow-Headers: ' . implode(', ', static::ACCESS_CONTROL_ALLOW_HEADERS));
            $oOutput->set_header('Access-Control-Allow-Methods: ' . implode(', ', static::ACCESS_CONTROL_ALLOW_METHODS));
            return;

        } else {

            try {
                /**
                 * If an access token has been passed then verify it
                 *
                 * Passing the token via the header is preferred, but fallback to the GET
                 * and POST arrays.
                 */

                $oInput                = Factory::service('Input');
                $oHttpCodes            = Factory::service('HttpCodes');
                $oUserAccessTokenModel = Factory::model('UserAccessToken', 'nailsapp/module-auth');
                $sAccessToken          = $oInput->header('X-Access-Token');

                if (!$sAccessToken) {
                    $sAccessToken = $oInput->post('accessToken');
                }

                if (!$sAccessToken) {
                    $sAccessToken = $oInput->get('accessToken');
                }

                if ($sAccessToken) {
                    $oAccessToken = $oUserAccessTokenModel->getByValidToken($sAccessToken);
                    if ($oAccessToken) {
                        $oUserModel = Factory::model('User', 'nailsapp/module-auth');
                        $oUserModel->setLoginData($oAccessToken->user_id, false);
                    } else {
                        throw new ApiException(
                            'Invalid access token',
                            $oHttpCodes::STATUS_UNAUTHORIZED
                        );
                    }
                }

                // --------------------------------------------------------------------------

                if (!$this->outputSetFormat($this->sOutputFormat)) {
                    throw new ApiException(
                        '"' . $this->sOutputFormat . '" is not a valid format.',
                        $oHttpCodes::STATUS_BAD_REQUEST
                    );
                }

                // --------------------------------------------------------------------------

                //  Register API modules
                $aNamespaces = [
                    'app' => (object) [
                        'namespace' => 'App\\'
                    ],
                ];
                foreach (_NAILS_GET_MODULES() as $oModule) {
                    if (!empty($oModule->data->{'nailsapp/module-api'}->namespace)) {
                        $sNamespace = $oModule->data->{'nailsapp/module-api'}->namespace;
                        if (array_key_exists($sNamespace, $aNamespaces)) {
                            throw new \Exception(
                                'Conflicting API namespace "' . $sNamespace . '" in use by ' .
                                '"' . $oModule->slug . '" and "' . $aNamespaces[$sNamespace]->slug . '"'
                            );
                        }
                        $aNamespaces[$sNamespace] = $oModule;
                    }
                }

                $i404Status = $oHttpCodes::STATUS_NOT_FOUND;
                $s404Error  = '"' . strtolower($this->sModuleName . '/' . $this->sClassName . '/' . $this->sMethod) . '" is not a valid API route.';

                if (!array_key_exists($this->sModuleName, $aNamespaces)) {
                    throw new ApiException($s404Error, $i404Status);
                }

                $oNamespace          = $aNamespaces[$this->sModuleName];
                $sOriginalController = $this->sClassName;

                //  Do we need to remap the controller?
                if (!empty($oNamespace->data->{'nailsapp/module-api'}->{'controller-map'})) {

                    $aMap             = (array) $oNamespace->data->{'nailsapp/module-api'}->{'controller-map'};
                    $this->sClassName = getFromArray($this->sClassName, $aMap, $this->sClassName);

                    //  This prevents users from accessing the "correct" controller, so we only have one valid route
                    $sRemapped = array_search($sOriginalController, $aMap);
                    if ($sRemapped !== false) {
                        $this->sClassName = $sRemapped;
                    }
                }

                $sController = $oNamespace->namespace . 'Api\\Controller\\' . $this->sClassName;

                if (!class_exists($sController)) {
                    throw new ApiException($s404Error, $i404Status);
                }

                $mAuth = $sController::isAuthenticated($this->sRequestMethod, $this->sMethod);
                if ($mAuth !== true) {

                    if (is_array($mAuth)) {
                        $sError  = getFromArray('error', $mAuth, $oHttpCodes::getByCode($oHttpCodes::STATUS_UNAUTHORIZED));
                        $iStatus = (int) getFromArray('status', $mAuth, $oHttpCodes::STATUS_UNAUTHORIZED);
                    } else {
                        $sError  = 'You must be logged in to access this resource';
                        $iStatus = $oHttpCodes::STATUS_UNAUTHORIZED;
                    }

                    throw new ApiException($sError, $iStatus);
                }

                if (!empty($sController::REQUIRE_SCOPE)) {
                    if (!$oUserAccessTokenModel->hasScope($oAccessToken, $sController::REQUIRE_SCOPE)) {
                        throw new ApiException(
                            'Access token with "' . $sController::REQUIRE_SCOPE . '" scope is required.',
                            $oHttpCodes::STATUS_UNAUTHORIZED
                        );
                    }
                }

                //  New instance of the controller
                $oInstance = new $sController($this);

                /**
                 * We need to look for the appropriate method; we'll look in the following order:
                 *
                 * - {sRequestMethod}Remap()
                 * - {sRequestMethod}{method}()
                 * - anyRemap()
                 * - any{method}()
                 *
                 * The second parameter is whether the method is a remap method or not.
                 */
                $bDidFindRoute = false;
                $aMethods      = [
                    [
                        strtolower($this->sRequestMethod) . 'Remap',
                        true,
                    ],
                    [
                        strtolower($this->sRequestMethod) . ucfirst($this->sMethod),
                        false,
                    ],
                    [
                        'anyRemap',
                        true,
                    ],
                    [
                        'any' . ucfirst($this->sMethod),
                        false,
                    ],
                ];

                foreach ($aMethods as $aMethodName) {

                    $sMethod  = getFromArray(0, $aMethodName);
                    $bIsRemap = (bool) getFromArray(1, $aMethodName);
                    if (is_callable([$oInstance, $sMethod])) {

                        $bDidFindRoute = true;

                        /**
                         * If the method we're trying to call is a remap method, then the first
                         * param should be the name of the method being called
                         */
                        if ($bIsRemap) {
                            $aParams = array_merge([$this->sMethod], $this->aParams);
                        } else {
                            $aParams = $this->aParams;
                        }

                        $oResponse = call_user_func_array([$oInstance, $sMethod], $aParams);
                        break;
                    }
                }

                if (!$bDidFindRoute) {
                    throw new ApiException(
                        '"' . $this->sRequestMethod . ': ' . $this->sModuleName . '/' . $this->sClassName . '/' . $this->sMethod . '" is not a valid API route.',
                        $oHttpCodes::STATUS_NOT_FOUND
                    );
                }

                if (!($oResponse instanceof ApiResponse)) {
                    //  This is a misconfiguration error, which we want to bubble up to the error handler
                    throw new \Exception(
                        'Return object must be an instance of \Nails\Api\Factory\ApiResponse',
                        $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
                    );
                }

                $aOut = [
                    'status' => $oHttpCodes::STATUS_OK,
                    'data'   => $oResponse->getData(),
                    'meta'   => $oResponse->getMeta(),
                ];

            } catch (ApiException $e) {

                $aOut = [
                    'status' => $e->getCode() ?: $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR,
                    'error'  => $e->getMessage() ?: 'An unkown error occurred',
                ];
                if (isSuperuser()) {
                    $aOut['exception'] = (object) array_filter([
                        'type'  => get_class($e),
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                    ]);
                }

                $this->writeLog($aOut);

            } catch (\Exception $e) {
                /**
                 * When running in PRODUCTION we want the global error handler to catch exceptions so that they
                 * can be handled proeprly and reported if necessary. In other environments we want to show the
                 * developer the error quickly and with as much info as possible.
                 */
                if (Environment::is('PRODUCTION')) {
                    throw $e;
                } else {
                    $aOut = [
                        'status'    => $e->getCode() ?: $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR,
                        'error'     => $e->getMessage() ?: 'An unkown error occurred',
                        'exception' => (object) array_filter([
                            'type'  => get_class($e),
                            'file'  => $e->getFile(),
                            'line'  => $e->getLine(),
                        ]),
                    ];

                    $this->writeLog($aOut);
                }
            }

            $this->output($aOut);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Sends $aOut to the browser in the desired format
     *
     * @param  array $aOut The data to output to the browser
     *
     * @return void
     */
    protected function output($aOut = [])
    {
        $oInput     = Factory::service('Input');
        $oOutput    = Factory::service('Output');
        $oHttpCodes = Factory::service('HttpCodes');

        //  Set cache headers
        $oOutput->set_header('Cache-Control: no-store, no-cache, must-revalidate');
        $oOutput->set_header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        $oOutput->set_header('Pragma: no-cache');

        //  Set access control headers
        $oOutput->set_header('Access-Control-Allow-Origin: ' . static::ACCESS_CONTROL_ALLOW_ORIGIN);
        $oOutput->set_header('Access-Control-Allow-Headers: ' . implode(', ', static::ACCESS_CONTROL_ALLOW_HEADERS));
        $oOutput->set_header('Access-Control-Allow-Methods: ' . implode(', ', static::ACCESS_CONTROL_ALLOW_METHODS));

        // --------------------------------------------------------------------------

        //  Send the correct status header, default to 200 OK
        if ($this->bOutputSendHeader) {
            $sProtocol   = $oInput->server('SERVER_PROTOCOL');
            $iHttpCode   = getFromArray('status', $aOut, $oHttpCodes::STATUS_OK);
            $sHttpString = $oHttpCodes::getByCode($iHttpCode);
            $oOutput->set_header($sProtocol . ' ' . $iHttpCode . ' ' . $sHttpString);
        }

        // --------------------------------------------------------------------------

        //  Output content
        switch ($this->sOutputFormat) {
            case 'TXT':
                $sOut = $this->outputTxt($aOut);
                break;

            case 'JSON':
                $sOut = $this->outputJson($aOut);
                break;
        }

        $oOutput->set_output($sOut);
    }

    // --------------------------------------------------------------------------

    /**
     * Formats $aOut as a plain text string formatted as JSON (for easy reading)
     * but a plaintext contentType
     *
     * @param  array $aOut The result of the API call
     *
     * @return string
     */
    private function outputTxt($aOut)
    {
        $oOutput = Factory::service('Output');
        $oOutput->set_content_type('text/html');
        if (Environment::not('PRODUCTION') && defined('JSON_PRETTY_PRINT')) {
            return json_encode($aOut, JSON_PRETTY_PRINT);
        } else {
            return json_encode($aOut);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Formats $aOut as a JSON string
     *
     * @param  array $aOut The result of the API call
     *
     * @return string
     */
    private function outputJson($aOut)
    {
        $oOutput = Factory::service('Output');
        $oOutput->set_content_type('application/json');
        if (Environment::not('PRODUCTION') && defined('JSON_PRETTY_PRINT')) {
            return json_encode($aOut, JSON_PRETTY_PRINT);
        } else {
            return json_encode($aOut);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Sets the output format
     *
     * @param  string $format The format to use
     *
     * @return boolean
     */
    public function outputSetFormat($format)
    {
        if ($this->isValidFormat($format)) {
            $this->sOutputFormat = strtoupper($format);
            return true;
        }

        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets whether the status header should be sent or not
     *
     * @param  boolean $sendHeader Whether the header should be sent or not
     *
     * @return void
     */
    public function outputSendHeader($sendHeader)
    {
        $this->bOutputSendHeader = !empty($sendHeader);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the format is valid
     *
     * @param string $sFormat The format to check
     *
     * @return boolean
     */
    private function isValidFormat($sFormat)
    {
        return in_array(strtoupper($sFormat), static::VALID_FORMATS);
    }

    // --------------------------------------------------------------------------

    /**
     * Write a line to the API log
     *
     * @param string $sLine The line to write
     */
    public function writeLog($sLine)
    {
        if (!is_string($sLine)) {
            $sLine = print_r($sLine, true);
        }
        $sLine = ' [' . $this->sModuleName . '->' . $this->sMethod . '] ' . $sLine;
        $this->oLogger->line($sLine);
    }
}
