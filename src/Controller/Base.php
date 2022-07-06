<?php

/**
 * This class is the base class of all API controllers.
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Api\Controller;

// --------------------------------------------------------------------------

use ApiRouter;
use Nails\Api\Events;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Service\Event;
use Nails\Common\Service\Input;
use Nails\Factory;

// --------------------------------------------------------------------------

/**
 * Allow the app to add functionality, if needed
 * Negative conditional helps with static analysis
 */
if (!class_exists('\App\Api\Controller\Base')) {
    abstract class BaseMiddle
    {
        public function __construct()
        {
        }
    }
} else {
    abstract class BaseMiddle extends \App\Api\Controller\Base
    {
    }
}

// --------------------------------------------------------------------------

/**
 * Class Base
 *
 * @package Nails\Api\Controller
 */
abstract class Base extends BaseMiddle
{
    /**
     * Require the user be authenticated to use any endpoint
     *
     * @var bool
     */
    const REQUIRE_AUTH = false;

    /**
     * Require the user's access token to have a particular scope
     *
     * @var string|null
     */
    const REQUIRE_SCOPE = null;

    // --------------------------------------------------------------------------

    /**
     * The Api Router instance
     *
     * @var ApiRouter
     */
    protected $oApiRouter;

    // --------------------------------------------------------------------------

    /**
     * Base constructor.
     *
     * @param ApiRouter $oApiRouter The ApiRouter controller
     *
     * @throws FactoryException
     * @throws NailsException
     * @throws \ReflectionException
     */
    public function __construct(ApiRouter $oApiRouter)
    {
        parent::__construct();

        /** @var Event $oEventService */
        $oEventService = Factory::service('Event');

        //  Call the API:STARTUP event, API is constructing
        $oEventService->trigger(Events::API_STARTUP, Events::getEventNamespace());

        // --------------------------------------------------------------------------

        $this->oApiRouter = $oApiRouter;

        // --------------------------------------------------------------------------

        //  Call the API:READY event, API is all geared up and ready to go
        $oEventService->trigger(Events::API_READY, Events::getEventNamespace());
    }

    // --------------------------------------------------------------------------

    /**
     * Writes a line to the log
     *
     * @param string $sLine The line to write
     */
    protected function writeLog($sLine)
    {
        $this->oApiRouter->writeLog($sLine);
    }

    // --------------------------------------------------------------------------

    /**
     * Whether the user is authenticated.
     *
     * @param string $sHttpMethod The HTTP Method protocol being used
     * @param string $sMethod     The controller method being executed
     *
     * @return bool|array       Boolean true or false. Can also return an array
     *                             with two elements (status and error) which
     *                             will customise the response code and message.
     */
    public static function isAuthenticated($sHttpMethod = '', $sMethod = '')
    {
        return static::REQUIRE_AUTH && !isLoggedIn() ? false : true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the raw request body
     *
     * @return string
     */
    protected function getRequestBody(): string
    {
        return stream_get_contents(fopen('php://input', 'r'));
    }

    // --------------------------------------------------------------------------

    /**
     * Gets the request data from the POST vars, falling back to the request body
     *
     * @return array
     * @throws FactoryException
     */
    protected function getRequestData(): array
    {
        /**
         * First check the $_POST superglobal, if that's empty then fall back to
         * the body of the request assuming it is JSON.
         */
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        $aData  = $oInput->post();

        if (empty($aData)) {
            $sData = $this->getRequestBody();
            $aData = json_decode($sData, true) ?: [];
        }

        return $aData;
    }
}
