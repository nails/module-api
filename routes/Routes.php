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

namespace Nails\Routes\Api;

use Nails\Common\Model\BaseRoutes;

class Routes extends BaseRoutes
{
    /**
     * Returns an array of routes for this module
     *
     * @return array
     */
    public function getRoutes()
    {
        return [
            'api/(:any)' => 'api/apiRouter/index',
        ];
    }
}
