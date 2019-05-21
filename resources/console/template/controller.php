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

use Nails\Api\Controller\Base;
use Nails\Api\Factory\ApiResponse;
use Nails\Common\Exception\FactoryException;
use Nails\Factory;

class {{CLASS_NAME}} extends Base
{
    /**
     * GET: /api/app/{{CLASS_NAME}}
     *
     * @return ApiResponse
     * @throws FactoryException
     */
    public function getIndex(): ApiResponse
    {
        // @todo - update this stub

        /** @var ApiResponse $oApiResponse */
        $oApiResponse = Factory::factory('ApiResponse', 'nails/module-api');
        $oApiResponse->setData(['foo' => 'bar']);

        return $oApiResponse;
    }
}

EOD;
