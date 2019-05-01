<?php

/**
 * The class provides a summary of the events fired by this module
 *
 * @package     Nails
 * @subpackage  module-common
 * @category    Events
 * @author      Nails Dev Team
 */

namespace Nails\Api;

use Nails\Common\Events\Base;

class Events extends Base
{
    /**
     * Fired when the API starts
     */
    const API_STARTUP = 'API:STARTUP';

    /**
     * Fired when the API is ready but before the controller is executed
     */
    const API_READY = 'API:READY';

    // --------------------------------------------------------------------------

    /**
     * Returns the namespace for events fired by this module
     *
     * @return stirng
     */
    public static function getEventNamespace(): string
    {
        return 'nails/module-api';
    }
}
