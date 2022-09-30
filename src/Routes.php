<?php

/**
 * Generates API routes
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Api;

use Nails\Common\Interfaces\RouteGenerator;

class Routes implements RouteGenerator
{
    /**
     * Returns an array of routes for this module
     *
     * @return string[]
     */
    public static function generate(): array
    {
        return [
            'api(/(.+))?' => 'api/apiRouter/index',
        ];
    }
}
