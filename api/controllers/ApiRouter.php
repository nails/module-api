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
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Factory\HttpRequest;
use Nails\Common\Factory\Logger;
use Nails\Common\Service\HttpCodes;
use Nails\Common\Service\Input;
use Nails\Common\Service\Output;
use Nails\Components;
use Nails\Environment;
use Nails\Factory;

// --------------------------------------------------------------------------

/**
 * Allow the app to add functionality, if needed
 * Negative conditional helps with static analysis
 */
if (!class_exists('\App\Api\Controller\BaseRouter')) {
    abstract class BaseMiddle extends \Nails\Common\Controller\Base
    {
    }
} else {
    abstract class BaseMiddle extends \App\Api\Controller\BaseRouter
    {
        public function __construct()
        {
            if (!classExtends(parent::class, \Nails\Common\Controller\Base::class)) {
                throw new NailsException(sprintf(
                    'Class %s must extend %s',
                    parent::class,
                    \Nails\Common\Controller\Base::class
                ));
            }
            parent::__construct();
        }
    }
}

// --------------------------------------------------------------------------

/**
 * Class ApiRouter
 */
class ApiRouter extends BaseMiddle
{
    const DEFAULT_FORMAT                   = \Nails\Api\Api\Output\Json::SLUG;
    const REQUEST_METHOD_GET               = HttpRequest\Get::HTTP_METHOD;
    const REQUEST_METHOD_PUT               = HttpRequest\Put::HTTP_METHOD;
    const REQUEST_METHOD_POST              = HttpRequest\Post::HTTP_METHOD;
    const REQUEST_METHOD_DELETE            = HttpRequest\Delete::HTTP_METHOD;
    const REQUEST_METHOD_OPTIONS           = HttpRequest\Options::HTTP_METHOD;
    const ACCESS_TOKEN_HEADER              = 'X-Access-Token';
    const ACCESS_TOKEN_POST_PARAM          = 'accessToken';
    const ACCESS_TOKEN_GET_PARAM           = 'accessToken';
    const ACCESS_CONTROL_ALLOW_ORIGIN      = '*';
    const ACCESS_CONTROL_ALLOW_CREDENTIALS = 'true';
    const ACCESS_CONTROL_MAX_AGE           = 86400;
    const ACCESS_CONTROL_ALLOW_HEADERS     = ['*'];
    const ACCESS_CONTROL_ALLOW_METHODS     = [
        self::REQUEST_METHOD_GET,
        self::REQUEST_METHOD_PUT,
        self::REQUEST_METHOD_POST,
        self::REQUEST_METHOD_DELETE,
        self::REQUEST_METHOD_OPTIONS,
    ];
    const OUTPUT_FORMAT_PATTERN            = '/\.([a-z]*)$/';

    // --------------------------------------------------------------------------

    /** @var array */
    protected static $aOutputValidFormats = [];

    /** @var string */
    protected $sRequestMethod;

    /** @var string */
    protected $sModuleName;

    /** @var string */
    protected $sClassName;

    /** @var string */
    protected $sMethod;

    /** @var string */
    protected $sOutputFormat;

    /** @var bool */
    protected $bOutputSendHeader = true;

    /** @var Logger */
    protected $oLogger;

    /** @var string */
    protected $sAccessToken;

    /** @var Auth\Resource\User\AccessToken */
    protected $oAccessToken;

    // --------------------------------------------------------------------------

    /**
     * ApiRouter constructor.
     *
     * @throws FactoryException
     */
    public function __construct()
    {
        parent::__construct();
        $this
            ->configureLogging()
            ->detectRequestMethod()
            ->discoverOutputFormats()
            ->detectUriSegments();

        /**
         * Calls to the API will load the base controller, this will instanciate the
         * UserFeedback class which will empty the session of flashdata. Flashdata
         * is never used as part of the API so any flashdata is not
         * relevant/intended for the API, so it should be persisted.
         *
         * This was demonstrated as a race condition in module-admin where unloading
         * the page resulted in an API call which would consume the flashdata, meaning
         * the subsequent page not having any status messages.
         *
         * Pablo — 2021-11-03
         */
        $this->oUserFeedback->persist();
    }

    // --------------------------------------------------------------------------

    /**
     * Route the call to the correct place
     */
    public function index()
    {
        //  Handle OPTIONS CORS pre-flight requests
        if ($this->sRequestMethod === static::REQUEST_METHOD_OPTIONS) {

            $this
                ->setCorsHeaders()
                ->setCorsStatusHeader();
            return;

        } else {

            try {

                /** @var HttpCodes $oHttpCodes */
                $oHttpCodes = Factory::service('HttpCodes');

                // --------------------------------------------------------------------------

                $this->verifyAccessToken();

                // --------------------------------------------------------------------------

                $sFormat = $this->outputGetFormat();
                if (!static::isValidFormat($sFormat)) {
                    $this->invalidApiFormat();
                }

                // --------------------------------------------------------------------------

                $aControllerMap = $this->discoverApiControllers();
                $oModule        = $aControllerMap[$this->sModuleName] ?? null;

                if (empty($oModule)) {
                    $this->invalidApiRoute();
                }

                $sController = $this->normaliseControllerClass($oModule);

                if (!class_exists($sController)) {
                    $this->invalidApiRoute();
                }

                $this->checkControllerAuth($sController);

                $oResponse = $this->callControllerMethod(
                    new $sController($this)
                );

                $aOut = [
                    'status' => $oResponse->getCode(),
                    'body'   => $oResponse->getBody(),
                    'data'   => $oResponse->getData(),
                    'meta'   => $oResponse->getMeta(),
                ];

            } catch (ValidationException $e) {

                $aOut = [
                    'status'  => $e->getCode() ?: $oHttpCodes::STATUS_BAD_REQUEST,
                    'error'   => $e->getMessage() ?: 'An unkown validation error occurred',
                    'details' => $e->getData() ?: [],
                ];
                if (isSuperUser()) {
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
                if (isSuperUser()) {
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
     * Configures API logging
     *
     * @return $this
     * @throws FactoryException
     */
    protected function configureLogging(): self
    {
        /** @var \Nails\Common\Resource\DateTime $oNow */
        $oNow = Factory::factory('DateTime');
        /** @var Logger oLogger */
        $this->oLogger = Factory::factory('Logger');
        $this->oLogger->setFile('api-' . $oNow->format('Y-m-d') . '.php');

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Detects the request method being used
     *
     * @return $this
     * @throws FactoryException
     */
    protected function detectRequestMethod(): self
    {
        /** @var Input $oInput */
        $oInput               = Factory::service('Input');
        $this->sRequestMethod = $oInput->server('REQUEST_METHOD') ?: static::REQUEST_METHOD_GET;

        return $this;
    }

    // --------------------------------------------------------------------------

    protected function discoverOutputFormats(): self
    {
        //  Look for valid output formats
        $aComponents = Components::available();

        //  Shift the app onto the end so it overrides any module supplied formats
        $oApp = array_shift($aComponents);
        array_push($aComponents, $oApp);

        foreach ($aComponents as $oComponent) {

            $oClasses = $oComponent
                ->findClasses('Api\Output')
                ->whichImplement(\Nails\Api\Interfaces\Output::class);

            foreach ($oClasses as $sClass) {
                static::$aOutputValidFormats[strtoupper($sClass::getSlug())] = $sClass;
            }
        }

        return $this;
    }

    /**
     * Detects and validates the output format from the URL
     *
     * @return $this
     * @throws NailsException
     */
    protected function detectOutputFormat(): self
    {

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Extracts the module, controller and method segments from the URI
     *
     * @return $this
     */
    protected function detectUriSegments(): self
    {
        /**
         * In order to work out the next few parts we'll analyse the URI string manually.
         * We're doing this because of the optional return type at the end of the string;
         * it's easier to regex that quickly, remove it, then split up the segments.
         */
        $sUri = preg_replace(static::OUTPUT_FORMAT_PATTERN, '', uri_string());

        //  Remove the module prefix (i.e "api/") then explode into segments
        //  Using regex as some systems will report a leading slash (e.g CLI)
        $sUri = preg_replace('#^/?api/#', '', $sUri);
        $aUri = explode('/', $sUri);

        $this->sModuleName = getFromArray(0, $aUri);
        $this->sClassName  = getFromArray(1, $aUri, $this->sModuleName);
        $this->sMethod     = getFromArray(2, $aUri, 'index');

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Verifies the access token, if supplied. Passing the token via the header is
     * preferred, but fallback to the GET and POST arrays.
     *
     * @return $this
     * @throws ApiException
     * @throws NailsException
     * @throws ReflectionException
     * @throws FactoryException
     * @throws ModelException
     */
    protected function verifyAccessToken(): self
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var HttpCodes $oHttpCodes */
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
            $this->oAccessToken = $oUserAccessTokenModel->getByValidToken($sAccessToken);

            if ($this->oAccessToken) {
                /** @var Auth\Model\User $oUserModel */
                $oUserModel = Factory::model('User', Auth\Constants::MODULE_SLUG);
                $oUserModel->setLoginData($this->oAccessToken->user_id, false);

            } else {
                throw new ApiException(
                    'Invalid access token',
                    $oHttpCodes::STATUS_UNAUTHORIZED
                );
            }
        }

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Discovers API controllers
     *
     * @return array
     * @throws ApiException
     * @throws NailsException
     */
    protected function discoverApiControllers(): array
    {
        $aControllerMap = [];
        foreach (Components::available() as $oModule) {

            $aClasses = $oModule
                ->findClasses('Api\\Controller')
                ->whichExtend(\Nails\Api\Controller\Base::class);

            $sNamespace = $oModule->slug === Components::$sAppSlug
                ? Components::$sAppSlug
                : ($oModule->data->{Constants::MODULE_SLUG}->namespace ?? null);

            if (empty($sNamespace) && count($aClasses)) {
                throw new ApiException(
                    sprintf(
                        'Found API controllers for module %s, but module does not define an API namespace',
                        $oModule->slug
                    )
                );

            } elseif (!count($aClasses)) {
                continue;
            }

            if (array_key_exists($sNamespace, $aControllerMap)) {
                throw new NailsException(
                    sprintf(
                        'Conflicting API namespace "%s" in use by "%s" and "%s"',
                        $sNamespace,
                        $oModule->slug,
                        $aControllerMap[$sNamespace]->module->slug
                    )
                );
            }

            $aControllerMap[$sNamespace] = (object) [
                'module'      => $oModule,
                'controllers' => [],
            ];

            foreach ($aClasses as $sClass) {
                $aControllerMap[$sNamespace]->controllers[strtolower($sClass)] = $sClass;
            }
        }

        return $aControllerMap;
    }

    // --------------------------------------------------------------------------

    /**
     * Normalises the controller class name, taking into account any defined remapping
     *
     * @param stdClass $oModule The module as created by discoverApiControllers
     *
     * @return string
     * @throws ApiException
     * @throws FactoryException
     */
    protected function normaliseControllerClass(stdClass $oModule): string
    {
        $aRemap = (array) ($oModule->module->data->{Constants::MODULE_SLUG}->{'controller-map'} ?? []);

        if (!empty($aRemap)) {

            $aNormalisedMapKeys   = array_change_key_case($aRemap, CASE_LOWER);
            $aNormalisedMapValues = array_map('strtolower', $aRemap);
            $sNormalisedClass     = strtolower($this->sClassName);
            $sOriginalClass       = strtolower($this->sClassName);

            if (array_key_exists($sNormalisedClass, $aNormalisedMapKeys)) {
                $this->sClassName = $aNormalisedMapKeys[$sNormalisedClass];
            }

            if (array_search($sOriginalClass, $aNormalisedMapValues)) {
                $this->invalidApiRoute();
            }
        }

        $sController = $oModule->module->namespace . 'Api\\Controller\\' . $this->sClassName;
        $sController = $oModule->controllers[strtolower($sController)] ?? $sController;

        return $sController;
    }

    // --------------------------------------------------------------------------

    /**
     * Checks the controllers auth requirements
     *
     * @param string $sController The Controller class name
     *
     * @throws ApiException
     * @throws FactoryException
     */
    protected function checkControllerAuth(string $sController): void
    {
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');
        /** @var Auth\Model\User\AccessToken $oUserAccessTokenModel */
        $oUserAccessTokenModel = Factory::model('UserAccessToken', Auth\Constants::MODULE_SLUG);

        $mAuth = $sController::isAuthenticated($this->sRequestMethod, $this->sMethod);
        if ($mAuth !== true) {

            if (is_array($mAuth)) {
                throw new ApiException(
                    getFromArray('error', $mAuth, $oHttpCodes::getByCode($oHttpCodes::STATUS_UNAUTHORIZED)),
                    (int) getFromArray('status', $mAuth, $oHttpCodes::STATUS_UNAUTHORIZED)
                );

            } else {
                throw new ApiException(
                    'You must be logged in to access this resource',
                    $oHttpCodes::STATUS_UNAUTHORIZED
                );
            }
        }

        if (!empty($sController::REQUIRE_SCOPE)) {
            if (!$this->oAccessToken->hasScope($sController::REQUIRE_SCOPE)) {
                throw new ApiException(
                    sprintf(
                        'Access token with "%s" scope is required.',
                        $sController::REQUIRE_SCOPE
                    ),
                    $oHttpCodes::STATUS_UNAUTHORIZED
                );
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Calls the appropriate controller method
     *
     * @param \Nails\Api\Controller\Base $oController The controller instance
     *
     * @return ApiResponse
     * @throws ApiException
     * @throws FactoryException
     */
    protected function callControllerMethod(\Nails\Api\Controller\Base $oController): ApiResponse
    {
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
                'method'   => strtolower($this->sRequestMethod) . 'Remap',
                'is_remap' => true,
            ],
            [
                'method'   => strtolower($this->sRequestMethod) . ucfirst($this->sMethod),
                'is_remap' => false,
            ],
            [
                'method'   => 'anyRemap',
                'is_remap' => true,
            ],
            [
                'method'   => 'any' . ucfirst($this->sMethod),
                'is_remap' => false,
            ],
        ];

        foreach ($aMethods as $aMethod) {
            if (is_callable([$oController, $aMethod['method']])) {
                /**
                 * If the method we're trying to call is a remap method, then the first
                 * param should be the name of the method being called
                 */
                if ($aMethod['is_remap']) {
                    return call_user_func_array(
                        [
                            $oController,
                            $aMethod['method'],
                        ],
                        [$this->sMethod]);

                } else {
                    return call_user_func([
                        $oController,
                        $aMethod['method'],
                    ]);
                }
            }
        }

        $this->invalidApiRoute();
    }

    // --------------------------------------------------------------------------

    /**
     * Throws an invalid API route 404 exception
     *
     * @throws ApiException
     * @throws FactoryException
     */
    protected function invalidApiRoute(): void
    {
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        $i404Status = $oHttpCodes::STATUS_NOT_FOUND;
        $s404Error  = sprintf(
            '"%s: %s/%s/%s" is not a valid API route.',
            $this->getRequestMethod(),
            strtolower($this->sModuleName),
            strtolower($this->sClassName),
            strtolower($this->sMethod)
        );

        throw new ApiException($s404Error, $i404Status);
    }

    // --------------------------------------------------------------------------

    /**
     * Throws an invalid API format 400 exception
     *
     * @throws ApiException
     */
    protected function invalidApiFormat(): void
    {
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        throw new ApiException(
            sprintf(
                '"%s" is not a valid format.',
                $this->outputGetFormat()
            ),
            $oHttpCodes::STATUS_BAD_REQUEST
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Sends $aOut to the browser in the desired format
     *
     * @param array $aOut The data to output to the browser
     */
    protected function output($aOut = [])
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Output $oOutput */
        $oOutput = Factory::service('Output');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        //  Set cache headers
        $oOutput
            ->setHeader('Cache-Control: no-store, no-cache, must-revalidate')
            ->setHeader('Expires: Mon, 26 Jul 1997 05:00:00 GMT')
            ->setHeader('Pragma: no-cache');

        //  Set access control headers
        $this->setCorsHeaders();

        // --------------------------------------------------------------------------

        //  Send the correct status header, default to 200 OK
        if ($this->bOutputSendHeader) {
            $sProtocol   = $oInput->server('SERVER_PROTOCOL');
            $iHttpCode   = getFromArray('status', $aOut, $oHttpCodes::STATUS_OK);
            $sHttpString = $oHttpCodes::getByCode($iHttpCode);
            $oOutput->setHeader($sProtocol . ' ' . $iHttpCode . ' ' . $sHttpString);
        }

        // --------------------------------------------------------------------------

        //  Output content
        $sOutputClass = static::$aOutputValidFormats[$this->outputGetFormat()];
        $oOutput->setContentType($sOutputClass::getContentType());

        if (array_key_exists('body', $aOut) && $aOut['body'] !== null) {
            $sOut = $aOut['body'];
        } else {
            unset($aOut['body']);
            $sOut = $sOutputClass::render($aOut);
        }

        $oOutput->setOutput($sOut);
    }

    // --------------------------------------------------------------------------

    /**
     * Sets CORS headers
     *
     * @return $this
     * @throws FactoryException
     */
    protected function setCorsHeaders(): self
    {
        /** @var Output $oOutput */
        $oOutput = Factory::service('Output');
        $oOutput
            ->setHeader('Access-Control-Allow-Origin: ' . static::ACCESS_CONTROL_ALLOW_ORIGIN)
            ->setHeader('Access-Control-Allow-Credentials: ' . static::ACCESS_CONTROL_ALLOW_CREDENTIALS)
            ->setHeader('Access-Control-Allow-Headers: ' . implode(', ', static::ACCESS_CONTROL_ALLOW_HEADERS))
            ->setHeader('Access-Control-Allow-Methods: ' . implode(', ', static::ACCESS_CONTROL_ALLOW_METHODS))
            ->setHeader('Access-Control-Max-Age: ' . static::ACCESS_CONTROL_MAX_AGE);

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Set the CORS status header
     *
     * @return $this
     * @throws FactoryException
     */
    protected function setCorsStatusHeader(): self
    {
        /** @var Output $oOutput */
        $oOutput = Factory::service('Output');
        $oOutput->setStatusHeader(HttpCodes::STATUS_NO_CONTENT);
        return $this;
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
     * Returns the putput format
     *
     * @return string
     */
    public function outputGetFormat(): string
    {
        if (empty($this->sOutputFormat)) {
            $this->sOutputFormat = static::parseOutputFormatFromUri();
        }

        return $this->sOutputFormat;
    }

    // --------------------------------------------------------------------------

    /**
     * Parses the outputformat from the URI
     *
     * @return string
     */
    public static function parseOutputFormatFromUri(): string
    {
        preg_match(static::OUTPUT_FORMAT_PATTERN, uri_string(), $aMatches);
        $sFormat = !empty($aMatches[1]) ? strtoupper($aMatches[1]) : null;

        return static::isValidFormat($sFormat)
            ? $sFormat
            : static::DEFAULT_FORMAT;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets whether the status header should be sent or not
     *
     * @param bool $sendHeader Whether the header should be sent or not
     */
    public function outputSendHeader($bSendHeader): bool
    {
        $this->bOutputSendHeader = !empty($bSendHeader);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the format is valid
     *
     * @param string $sFormat The format to check
     *
     * @return bool
     */
    private static function isValidFormat(?string $sFormat): bool
    {
        return in_array(strtoupper($sFormat ?? ''), array_keys(static::$aOutputValidFormats));
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
     * Confirms whether the request is of the supplied type
     *
     * @param string $sMethod The request method to check
     *
     * @return bool
     */
    protected function isRequestMethod(string $sMethod): bool
    {
        return $this->getRequestMethod() === $sMethod;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the request is a GET request
     *
     * @return bool
     */
    public function isGetRequest(): bool
    {
        return $this->isRequestMethod(static::REQUEST_METHOD_GET);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the request is a PUT request
     *
     * @return bool
     */
    public function isPutRequest(): bool
    {
        return $this->isRequestMethod(static::REQUEST_METHOD_PUT);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the request is a POST request
     *
     * @return bool
     */
    public function isPostRequest(): bool
    {
        return $this->isRequestMethod(static::REQUEST_METHOD_POST);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the request is a DELETE request
     *
     * @return bool
     */
    public function isDeleteRequest(): bool
    {
        return $this->isRequestMethod(static::REQUEST_METHOD_DELETE);
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
