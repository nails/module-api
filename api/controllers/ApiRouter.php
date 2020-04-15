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

use Nails\Auth;
use Nails\Api\Constants;
use Nails\Api\Exception\ApiException;
use Nails\Api\Factory\ApiResponse;
use Nails\Common\Exception\NailsException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Factory\Logger;
use Nails\Components;
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

/**
 * Class ApiRouter
 */
class ApiRouter extends BaseMiddle
{
    const FORMAT_JSON                  = 'JSON';
    const FORMAT_TXT                   = 'TXT';
    const DEFAULT_FORMAT               = 'JSON';
    const REQUEST_METHOD_GET           = 'GET';
    const REQUEST_METHOD_PUT           = 'PUT';
    const REQUEST_METHOD_POST          = 'POST';
    const REQUEST_METHOD_DELETE        = 'DELETE';
    const REQUEST_METHOD_OPTIONS       = 'OPTIONS';
    const VALID_FORMATS                = [
        self::FORMAT_TXT,
        self::FORMAT_JSON,
    ];
    const ACCESS_TOKEN_HEADER          = 'X-Access-Token';
    const ACCESS_TOKEN_POST_PARAM      = 'accessToken';
    const ACCESS_TOKEN_GET_PARAM       = 'accessToken';
    const ACCESS_CONTROL_ALLOW_ORIGIN  = '*';
    const ACCESS_CONTROL_ALLOW_HEADERS = [
        self::ACCESS_TOKEN_HEADER,
        'content',
        'origin',
        'content-type',
    ];
    const ACCESS_CONTROL_ALLOW_METHODS = [
        self::REQUEST_METHOD_GET,
        self::REQUEST_METHOD_PUT,
        self::REQUEST_METHOD_POST,
        self::REQUEST_METHOD_DELETE,
        self::REQUEST_METHOD_OPTIONS,
    ];
    const OUTPUT_FORMAT_PATTERN        = '/\.([a-z]*)$/';

    // --------------------------------------------------------------------------

    private $sRequestMethod;
    private $sModuleName;
    private $sClassName;
    private $sMethod;
    private $aOutputValidFormats;
    private $sOutputFormat;
    private $bOutputSendHeader;
    private $oLogger;
    private $sAccessToken;

    // --------------------------------------------------------------------------

    /**
     * ApiRouter constructor.
     *
     * @throws \Nails\Common\Exception\FactoryException
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
        $this->sRequestMethod = $this->sRequestMethod ? $this->sRequestMethod : static::REQUEST_METHOD_GET;

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

        //  Configure logging
        $oNow          = Factory::factory('DateTime');
        $this->oLogger->setFile('api-' . $oNow->format('y-m-d') . '.php');
        $this->oLogger = Factory::factory('Logger');
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the output format
     *
     * @return string|null
     */
    public static function getOutputFormat()
    {
        preg_match(static::OUTPUT_FORMAT_PATTERN, uri_string(), $aMatches);
        $sFormat = !empty($aMatches[1]) ? strtoupper($aMatches[1]) : null;
        return static::isValidFormat($sFormat) ? $sFormat : static::DEFAULT_FORMAT;
    }

    // --------------------------------------------------------------------------

    /**
     * Route the call to the correct place
     */
    public function index()
    {
        //  Handle OPTIONS CORS pre-flight requests
        if ($this->sRequestMethod === static::REQUEST_METHOD_OPTIONS) {

            /** @var \Nails\Common\Service\Output $oOutput */
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

                /** @var \Nails\Common\Service\Input $oInput */
                $oInput = Factory::service('Input');
                /** @var \Nails\Common\Service\HttpCodes $oHttpCodes */
                $oHttpCodes = Factory::service('HttpCodes');
                /** @var Auth\Model\User\AccessToken $oUserAccessTokenModel */
                $oUserAccessTokenModel = Factory::model('UserAccessToken', Auth\Constants::MODULE_SLUG);


                $sAccessToken = $oInput->header(static::ACCESS_TOKEN_HEADER);

                if (!$sAccessToken) {
                    $sAccessToken = $oInput->post(static::ACCESS_TOKEN_POST_PARAM);
                }

                if (!$sAccessToken) {
                    $sAccessToken = $oInput->get(static::ACCESS_TOKEN_GET_PARAM);
                }

                if ($sAccessToken) {

                    $this->sAccessToken = $sAccessToken;
                    $oAccessToken       = $oUserAccessTokenModel->getByValidToken($sAccessToken);

                    if ($oAccessToken) {
                        /** @var Auth\Model\User $oUserModel */
                        $oUserModel = Factory::model('User', Auth\Constants::MODULE_SLUG);
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
                        'namespace' => 'App\\',
                    ],
                ];
                foreach (Components::modules() as $oModule) {
                    if (!empty($oModule->data->{Constants::MODULE_SLUG}->namespace)) {
                        $sNamespace = $oModule->data->{Constants::MODULE_SLUG}->namespace;
                        if (array_key_exists($sNamespace, $aNamespaces)) {
                            throw new NailsException(
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
                if (!empty($oNamespace->data->{Constants::MODULE_SLUG}->{'controller-map'})) {

                    $aMap             = (array) $oNamespace->data->{Constants::MODULE_SLUG}->{'controller-map'};
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
                            $oResponse = call_user_func_array([$oInstance, $sMethod], [$this->sMethod]);
                        } else {
                            $oResponse = call_user_func([$oInstance, $sMethod]);
                        }
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
                    throw new NailsException(
                        'Return object must be an instance of \Nails\Api\Factory\ApiResponse',
                        $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
                    );
                }

                $aOut = [
                    'status' => $oResponse->getCode(),
                    'data'   => $oResponse->getData(),
                    'meta'   => $oResponse->getMeta(),
                ];

            } catch (ValidationException $e) {

                $aOut = [
                    'status'  => $e->getCode() ?: $oHttpCodes::STATUS_BAD_REQUEST,
                    'error'   => $e->getMessage() ?: 'An unkown validation error occurred',
                    'details' => $e->getData() ?: [],
                ];
                if (isSuperuser()) {
                    $aOut['exception'] = (object) array_filter([
                        'type' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }

                $this->writeLog($aOut);

            } catch (ApiException $e) {

                $aOut = [
                    'status'  => $e->getCode() ?: $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR,
                    'error'   => $e->getMessage() ?: 'An unkown error occurred',
                    'details' => $e->getData() ?: [],
                ];
                if (isSuperuser()) {
                    $aOut['exception'] = (object) array_filter([
                        'type' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }

                $this->writeLog($aOut);

            } catch (\Exception $e) {
                /**
                 * When running in PRODUCTION we want the global error handler to catch exceptions so that they
                 * can be handled proeprly and reported if necessary. In other environments we want to show the
                 * developer the error quickly and with as much info as possible.
                 */
                if (Environment::is(Environment::ENV_PROD)) {
                    throw $e;
                } else {
                    $aOut = [
                        'status'    => $e->getCode() ?: $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR,
                        'error'     => $e->getMessage() ?: 'An unkown error occurred',
                        'exception' => (object) array_filter([
                            'type' => get_class($e),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
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
     * @param array $aOut The data to output to the browser
     */
    protected function output($aOut = [])
    {
        /** @var \Nails\Common\Service\Input $oInput */
        $oInput = Factory::service('Input');
        /** @var \Nails\Common\Service\Output $oOutput */
        $oOutput = Factory::service('Output');
        /** @var \Nails\Common\Service\HttpCodes $oHttpCodes */
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
            case static::FORMAT_TXT:
                $sOut = $this->outputTxt($aOut);
                break;

            case static::FORMAT_JSON:
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
     * @param array $aOut The result of the API call
     *
     * @return string
     */
    private function outputTxt($aOut)
    {
        /** @var \Nails\Common\Service\Output $oOutput */
        $oOutput = Factory::service('Output');
        $oOutput->set_content_type('text/html');
        if (Environment::not(Environment::ENV_PROD) && defined('JSON_PRETTY_PRINT')) {
            return json_encode($aOut, JSON_PRETTY_PRINT);
        } else {
            return json_encode($aOut);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Formats $aOut as a JSON string
     *
     * @param array $aOut The result of the API call
     *
     * @return string
     */
    private function outputJson($aOut)
    {
        /** @var \Nails\Common\Service\Output $oOutput */
        $oOutput = Factory::service('Output');
        $oOutput->set_content_type('application/json');
        if (Environment::not(Environment::ENV_PROD) && defined('JSON_PRETTY_PRINT')) {
            return json_encode($aOut, JSON_PRETTY_PRINT);
        } else {
            return json_encode($aOut);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Sets the output format
     *
     * @param string $sFormat The format to use
     *
     * @return bool
     */
    public function outputSetFormat($sFormat): bool
    {
        if (static::isValidFormat($sFormat)) {
            $this->sOutputFormat = strtoupper($sFormat);
            return true;
        }

        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets whether the status header should be sent or not
     *
     * @param bool $sendHeader Whether the header should be sent or not
     */
    public function outputSendHeader($sendHeader): bool
    {
        $this->bOutputSendHeader = !empty($sendHeader);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the format is valid
     *
     * @param string $sFormat The format to check
     *
     * @return bool
     */
    private static function isValidFormat($sFormat): bool
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

    // --------------------------------------------------------------------------

    /**
     * Returns the current request method
     *
     * @return string
     */
    public function getRequestMethod(): string
    {
        return $this->sRequestMethod;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the request is a GET request
     *
     * @return bool
     */
    public function isGetRequest(): bool
    {
        return $this->getRequestMethod() === static::REQUEST_METHOD_GET;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the request is a PUT request
     *
     * @return bool
     */
    public function isPutRequest(): bool
    {
        return $this->getRequestMethod() === static::REQUEST_METHOD_PUT;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the request is a POST request
     *
     * @return bool
     */
    public function isPostRequest(): bool
    {
        return $this->getRequestMethod() === static::REQUEST_METHOD_POST;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the request is a DELETE request
     *
     * @return bool
     */
    public function isDeleteRequest(): bool
    {
        return $this->getRequestMethod() === static::REQUEST_METHOD_DELETE;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the current Access Token
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        return $this->sAccessToken;
    }
}
