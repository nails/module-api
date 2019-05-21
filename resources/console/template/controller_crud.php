<?php

/**
 * This file is the template for the contents of API CRUD controllers
 * Used by the console command when creating API CRUD controllers.
 */

return <<<'EOD'
<?php

/**
 * The {{MODEL_NAME}} API CRUD controller
 *
 * @package  App
 * @category API Controller
 */

namespace App\Api\Controller;

use Nails\Api\Controller\CrudController;

class {{MODEL_NAME}} extends CrudController
{
    const CONFIG_MODEL_NAME     = '{{MODEL_NAME}}';
    const CONFIG_MODEL_PROVIDER = '{{MODEL_PROVIDER}}';
}

EOD;
