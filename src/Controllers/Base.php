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

namespace Nails\Api\Controllers;

class Base extends \MX_Controller
{
    protected $data;
    protected $oApiRouter;

    // --------------------------------------------------------------------------

    /**
     * Whether the controller requires that the user be authenticated or not
     * @var boolean
     */
    public static $requiresAuthentication = false;

    // --------------------------------------------------------------------------

    /**
     * Construct the controller, load all the admin assets, etc
     */
    public function __construct($oApiRouter)
    {
        parent::__construct();
        $this->data       =& getControllerData();
        $this->oApiRouter = $oApiRouter;
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
}
