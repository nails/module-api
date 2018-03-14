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

use Nails\Api\Events;
use Nails\Factory;

// --------------------------------------------------------------------------

/**
 * Allow the app to add functionality, if needed
 */
if (class_exists('\App\Api\Controller\Base')) {
    class BaseMiddle extends \App\Api\Controller\Base
    {
    }
} else {
    class BaseMiddle
    {
    }
}

// --------------------------------------------------------------------------

abstract class Base extends BaseMiddle
{
    /**
     * Require the user be authenticated to use any endpoint
     */
    const REQUIRE_AUTH = false;

    // --------------------------------------------------------------------------

    protected $data;
    protected $oApiRouter;

    // --------------------------------------------------------------------------

    /**
     * Construct the controller, load all the admin assets, etc
     */
    public function __construct($oApiRouter)
    {
        parent::__construct();

        //  Setup Events
        $oEventService = Factory::service('Event');

        //  Call the API:STARTUP event, admin is constructing
        $oEventService->trigger(Events::API_STARTUP, 'nailsapp/module-api');

        // --------------------------------------------------------------------------

        $this->data       =& getControllerData();
        $this->oApiRouter = $oApiRouter;

        // --------------------------------------------------------------------------

        //  Call the API:READY event, API is all geared up and ready to go
        $oEventService->trigger(Events::API_READY, 'nailsapp/module-api');
    }

    // --------------------------------------------------------------------------

    /**
     * Common method
     * @param  string $method the method which was not found
     * @return array
     */
    protected function methodNotFound($method)
    {
        $this->writeLog('"' . $method . '" is not a valid API route.');

         return array(
            'status' => 404,
            'error'  => '"' . $method . '" is not a valid API route.'
         );
    }

    // --------------------------------------------------------------------------

    /**
     * Writes a line to the log
     * @param string $sLine the line to write
     */
    protected function writeLog($sLine)
    {
        $this->oApiRouter->writeLog($sLine);
    }

    // --------------------------------------------------------------------------

    /**
     * Whether the user is authenticated.
     * @param  string $sHttpMethod The HTTP Method protocol being used
     * @param  string $sMethod     The controller method to execute
     * @return mixed               Boolean true or false. Can also return an array
     *                             where the two elements (status and error) which
     *                             will customise the response code and message.
     */
    public static function isAuthenticated($sHttpMethod = '', $sMethod = '')
    {
        return static::REQUIRE_AUTH && !isLoggedIn() ? false : true;
    }
}
