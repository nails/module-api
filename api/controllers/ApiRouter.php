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
        $this->aOutputValidFormats = [
            'TXT',
            'JSON',
        ];
        $this->bOutputSendHeader   = true;

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

        $uriString = uri_string();

        //  Get the format, if any
        $formatPattern = '/\.([a-z]*)$/';
        preg_match($formatPattern, $uriString, $matches);

        if (!empty($matches[1])) {

            $this->sOutputFormat = strtoupper($matches[1]);

            //  Remove the format from the string
            $uriString = preg_replace($formatPattern, '', $uriString);

        } else {
            $this->sOutputFormat = 'JSON';
        }

        //  Remove the module prefix (i.e "api/") then explode into segments
        //  Using regex as some systems will report a leading slash (e.g CLI)
        $uriString = preg_replace('#/?api/#', '', $uriString);
        $uriArray  = explode('/', $uriString);

        //  Work out the sModuleName, sClassName and method
        $this->sModuleName = array_key_exists(0, $uriArray) ? $uriArray[0] : null;
        $this->sClassName  = array_key_exists(1, $uriArray) ? $uriArray[1] : $this->sModuleName;
        $this->sMethod     = array_key_exists(2, $uriArray) ? $uriArray[2] : 'index';

        //  What's left of the array are the parameters to pass to the method
        $this->aParams = array_slice($uriArray, 3);

        //  Configure logging
        $oDateTime     = Factory::factory('DateTime');
        $this->oLogger = Factory::service('Logger');
        $this->oLogger->setFile('api-' . $oDateTime->format('y-m-d') . '.php');
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

            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: x-access-token, content, origin, content-type');
            header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
            exit;

        } else {

            /**
             * If an access token has been passed then verify it
             *
             * Passing the token via the header is preferred, but fallback to the GET
             * and POST arrays.
             */

            $oInput                = Factory::service('Input');
            $oUserAccessTokenModel = Factory::model('UserAccessToken', 'nailsapp/module-auth');
            $accessToken           = $oInput->header('X-Access-Token');

            if (!$accessToken) {
                $accessToken = $oInput->post('accessToken');
            }

            if (!$accessToken) {
                $accessToken = $oInput->get('accessToken');
            }

            if ($accessToken) {

                $accessToken = $oUserAccessTokenModel->getByValidToken($accessToken);

                if ($accessToken) {
                    $oUserModel = Factory::model('User', 'nailsapp/module-auth');
                    $oUserModel->setLoginData($accessToken->user_id, false);
                }
            }

            // --------------------------------------------------------------------------

            $aOut = [];

            if ($this->outputSetFormat($this->sOutputFormat)) {

                /**
                 * Look for a controller, app version first then the first one we
                 * find in the modules.
                 */
                $controllerPaths = [
                    APPPATH . 'modules/api/controllers/',
                ];

                $nailsModules = _NAILS_GET_MODULES();

                foreach ($nailsModules as $module) {
                    $controllerPaths[] = $module->path . 'api/controllers/';
                }

                //  Look for a valid controller
                $controllerName = ucfirst($this->sClassName) . '.php';

                foreach ($controllerPaths as $path) {

                    $fullPath = $path . $controllerName;

                    if (is_file($fullPath)) {
                        $controllerPath = $fullPath;
                        break;
                    }
                }

                if (!empty($controllerPath)) {

                    //  Load the file and try and execute the method
                    require_once $controllerPath;

                    $this->sModuleName = 'Nails\\Api\\' . ucfirst($this->sModuleName) . '\\' . ucfirst($this->sClassName);

                    if (class_exists($this->sModuleName)) {

                        $sClassName = $this->sModuleName;
                        $mAuth      = $sClassName::isAuthenticated($this->sRequestMethod, $this->sMethod);
                        if ($mAuth !== true) {
                            $oHttpCodes     = Factory::service('HttpCodes');
                            $aOut['status'] = !empty($mAuth['status']) ? $mAuth['status'] : 401;
                            $aOut['error']  = !empty($mAuth['error']) ? $mAuth['error'] : $oHttpCodes::STATUS_401;
                        }

                        /**
                         * If no errors and a scope is required, check the scope
                         */
                        if (empty($aOut) && !empty($sClassName::$requiresScope)) {
                            if (!$oUserAccessTokenModel->hasScope($accessToken, $sClassName::$requiresScope)) {
                                $aOut['status'] = 401;
                                $aOut['error']  = 'Access token with "' . $sClassName::$requiresScope;
                                $aOut['error']  .= '" scope is required.';
                            }
                        }

                        /**
                         * If no errors so far, begin execution
                         */
                        if (empty($aOut)) {

                            //  New instance of the controller
                            $instance = new $this->sModuleName($this);

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

                            $aMethods = [
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

                            $bDidFindRoute = false;

                            foreach ($aMethods as $aMethodName) {

                                if (is_callable([$instance, $aMethodName[0]])) {

                                    /**
                                     * If the method we're trying to call is a remap method, then the first
                                     * param should be the name of the method being called
                                     */

                                    if ($aMethodName[1]) {
                                        $aParams = array_merge([$this->sMethod], $this->aParams);
                                    } else {
                                        $aParams = $this->aParams;
                                    }

                                    $bDidFindRoute = true;
                                    $aOut          = call_user_func_array([$instance, $aMethodName[0]], $aParams);
                                    break;
                                }
                            }

                            if (!$bDidFindRoute) {
                                $aOut['status'] = 404;
                                $aOut['error']  = '"' . $this->sRequestMethod . ': ' . $this->sModuleName . '/';
                                $aOut['error']  .= $this->sClassName . '/' . $this->sMethod;
                                $aOut['error']  .= '" is not a valid API route.';
                            }
                        }

                    } else {

                        $aOut['status'] = 500;
                        $aOut['error']  = '"' . $this->sModuleName . '" is incorrectly configured; class does not exist.';
                        $this->writeLog($aOut['error']);
                    }

                } else {

                    $aOut['status'] = 404;
                    $aOut['error']  = '"' . $this->sModuleName . '/' . $this->sMethod . '" is not a valid API route.';
                    $this->writeLog($aOut['error']);
                }

            } else {

                $aOut['status'] = 400;
                $aOut['error']  = '"' . $this->sOutputFormat . '" is not a valid format.';
                $this->writeLog($aOut['error']);
                $this->sOutputFormat = 'JSON';
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
        $oInput  = Factory::service('Input');
        $oOutput = Factory::service('Output');

        //  Set cache headers
        $oOutput->set_header('Cache-Control: no-store, no-cache, must-revalidate');
        $oOutput->set_header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        $oOutput->set_header('Pragma: no-cache');

        //  Set access control headers
        $oOutput->set_header('Access-Control-Allow-Origin: *');
        $oOutput->set_header('Access-Control-Allow-Headers: x-access-token, content, origin, content-type');
        $oOutput->set_header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');

        $sServerProtocol = $oInput->server('SERVER_PROTOCOL');

        // --------------------------------------------------------------------------

        //  Send the correct status header, default to 200 OK
        $oHttpCodes = Factory::service('HttpCodes');

        if (isset($aOut['status'])) {

            $aOut['status'] = (int) $aOut['status'];
            $sHttpCode      = $aOut['status'];
            $sHttpString    = $oHttpCodes->getByCode($aOut['status']);

            if (empty($sHttpString)) {
                $aOut['status'] = 200;
                $sHttpCode      = $aOut['status'];
                $sHttpString    = $oHttpCodes->getByCode($aOut['status']);
            }

        } elseif (is_array($aOut)) {

            $aOut['status'] = 200;
            $sHttpCode      = $aOut['status'];
            $sHttpString    = $oHttpCodes->getByCode($aOut['status']);

        } else {

            $sHttpCode   = 200;
            $sHttpString = $oHttpCodes::STATUS_200;
        }

        if ($this->bOutputSendHeader) {
            $oOutput->set_header($sServerProtocol . ' ' . $sHttpCode . ' ' . $sHttpString);
        }

        // --------------------------------------------------------------------------

        //  Output content
        switch ($this->sOutputFormat) {
            case 'TXT':
                $aOut = $this->outputTxt($aOut);
                break;

            case 'JSON':
                $aOut = $this->outputJson($aOut);
                break;
        }

        $oOutput->set_output($aOut);
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
        return in_array(strtoupper($sFormat), $this->aOutputValidFormats);
    }

    // --------------------------------------------------------------------------

    /**
     * Write a line to the API log
     *
     * @param string $sLine The line to write
     */
    public function writeLog($sLine)
    {
        $sLine = ' [' . $this->sModuleName . '->' . $this->sMethod . '] ' . $sLine;
        $this->oLogger->line($sLine);
    }
}
