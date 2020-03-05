<?php

/**
 * This file is the template for the contents of API controllers
 * Used by the console command when creating API controllers.
 */

return <<<'EOD'
<?php

/**
 * The {{CLASS_NAME}} API controller
 *
 * @package  App
 * @category API Controller
 */

namespace App\Api\Controller;

use Nails\Api;
use Nails\Common\Exception\FactoryException;
use Nails\Factory;

class {{CLASS_NAME}} extends Api\Controller\Base
{
    /**
     * GET: /api/app/{{CLASS_NAME}}
     *
     * @return Api\Factory\ApiResponse
     * @throws FactoryException
     */
    public function getIndex(): Api\Factory\ApiResponse
    {
        // @todo - update this stub

        /** @var Api\Factory\ApiResponse $oApiResponse */
        $oApiResponse = Factory::factory('ApiResponse', Api\Constants::MODULE_SLUG);
        $oApiResponse->setData(['foo' => 'bar']);

        return $oApiResponse;
    }
}

EOD;
