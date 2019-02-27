<?php

/**
 * This file is the template for the contents of API controllers
 * Used by the console command when creating API controllers.
 */

return <<<'EOD'
<?php

/**
 * The {{MODEL_NAME}} API controller
 *
 * @package  App
 * @category controller
 */

namespace App\Api\Controller;

use Nails\Api\Controller\DefaultController;

class {{MODEL_NAME}} extends DefaultController
{
    const CONFIG_MODEL_NAME     = '{{MODEL_NAME}}';
    const CONFIG_MODEL_PROVIDER = '{{MODEL_PROVIDER}}';
}

EOD;
