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
     * Fired when API starts
     */
    const API_STARTUP = 'API:STARTUP';

    /**
     * Fired when API is ready
     */
    const API_READY = 'API:READY';
}
